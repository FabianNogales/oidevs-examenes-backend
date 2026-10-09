<?php

namespace Tests\Feature\Subjects;

use App\Models\Career;
use App\Models\Role;
use App\Models\Subject;
use App\Models\SubjectImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class SubjectImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/subjects/import/preview';
    private const HEADER = "codigo_materia,nombre_materia,codigo_carrera\n";
    private User $admin;
    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
        $this->admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::create(['name' => 'ADMINISTRADOR', 'status' => 'ACTIVE']);
        $this->admin->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $this->admin->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($this->admin)->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->career = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
    }

    public function test_preview_persists_owner_hash_expiry_and_report_without_catalog_writes(): void
    {
        $this->freezeTime();
        $text = self::HEADER."inf-01,Programación I,SIS\nINF-01,Programación I,SIS\nINF-02,Álgebra II,UNKNOWN\n";
        $response = $this->preview($text)->assertOk()->assertJsonPath('data.summary', ['total' => 3, 'valid' => 1, 'invalid' => 1, 'omitted' => 1])
            ->assertJsonPath('data.rows.0.row_number', 2)->assertJsonPath('data.rows.0.status', 'VALID')
            ->assertJsonPath('data.rows.0.data.codigo_materia', 'inf-01')
            ->assertJsonPath('data.rows.1.status', 'OMITTED')->assertJsonPath('data.rows.2.status', 'ERROR');
        $import = SubjectImport::firstOrFail();
        $this->assertSame($this->admin->id, $import->user_id);
        $this->assertSame(hash('sha256', $text), $import->file_hash);
        $this->assertSame($response->json('data.preview_id'), $import->preview_id);
        $this->assertSame(now()->addMinutes(30)->timestamp, $import->expires_at->timestamp);
        $this->assertSame($response->json('data.rows'), $import->preview_report['rows']);
        $this->assertSame('PENDING', $import->status);
        $this->assertNull($import->result_report);
        $this->assertNull($import->completed_at);
        $this->assertDatabaseCount('subjects', 0);
        $this->assertDatabaseCount('career_subject', 0);
        $this->assertDatabaseCount('room_imports', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_same_subject_can_have_multiple_careers_and_existing_pair_is_omitted(): void
    {
        Career::create(['code' => 'INF', 'name' => 'Informática', 'status' => 'ACTIVE']);
        $subject = Subject::create(['code' => 'MAT-01', 'name' => 'Matemáticas', 'status' => 'ACTIVE']);
        $subject->careers()->attach($this->career->id);
        $this->preview(self::HEADER."MAT-01,Matemáticas,SIS\nMAT-01,Matemáticas,INF\nNEW,Programación,SIS\nNEW,Programación,INF\n")
            ->assertOk()->assertJsonPath('data.summary.valid', 3)->assertJsonPath('data.summary.omitted', 1);
        $this->assertDatabaseCount('subjects', 1);
        $this->assertDatabaseCount('career_subject', 1);
    }

    public function test_conflicting_names_reject_all_rows_for_code_including_duplicates(): void
    {
        $response = $this->preview(self::HEADER."INF-01,Programación I,SIS\ninf-01,Programación II,SIS\nINF-01,Programación I,SIS\n")
            ->assertOk()->assertJsonPath('data.summary.invalid', 3)->assertJsonPath('data.summary.valid', 0);
        foreach ($response->json('data.rows') as $row) {
            $this->assertContains('El mismo código tiene nombres diferentes dentro del archivo.', $row['errors']);
        }
    }

    public function test_existing_inactive_subject_name_conflict_and_inactive_career_are_errors(): void
    {
        Subject::create(['code' => 'OLD', 'name' => 'Anterior', 'status' => 'INACTIVE']);
        Subject::create(['code' => 'NAME', 'name' => 'Original', 'status' => 'ACTIVE']);
        Career::create(['code' => 'OLD', 'name' => 'Histórica', 'status' => 'INACTIVE']);
        $this->preview(self::HEADER."OLD,Anterior,SIS\nNAME,Distinto,SIS\nNEW,Nueva,OLD\n")
            ->assertOk()->assertJsonPath('data.summary.invalid', 3)->assertJsonPath('data.summary.omitted', 0);
        $this->assertDatabaseHas('subjects', ['code' => 'OLD', 'status' => 'INACTIVE']);
        $this->assertDatabaseHas('subjects', ['code' => 'NAME', 'name' => 'Original']);
    }

    public function test_bom_quotes_and_physical_line_numbers_are_supported(): void
    {
        $text = "\xEF\xBB\xBF".self::HEADER."INF-01,\"Programación, \"\"básica\"\"\",SIS\nBAD,\"Nombre\nlargo\",SIS\nINF-02,Álgebra II,SIS\n";
        $this->preview($text)->assertOk()->assertJsonPath('data.summary.valid', 2)->assertJsonPath('data.summary.invalid', 1)
            ->assertJsonPath('data.rows.0.data.nombre_materia', 'Programación, "básica"')
            ->assertJsonPath('data.rows.2.row_number', 5);
    }

    public function test_bad_uploads_encoding_headers_and_empty_files_do_not_persist_preview(): void
    {
        foreach (['', self::HEADER, "CODIGO_MATERIA,nombre_materia,codigo_carrera\n", self::HEADER."NEW,\xFF,SIS\n", "nombre_materia,codigo_materia,codigo_carrera\n"] as $text) {
            $this->preview($text)->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->postJson(self::ENDPOINT, [])->assertUnprocessable();
        $this->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->createWithContent('subjects.txt', self::HEADER)])->assertUnprocessable();
        $this->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->create('subjects.csv', 10241, 'text/csv')])->assertStatus(413);
        $this->assertDatabaseCount('subject_imports', 0);
    }

    public function test_invalid_rows_and_malformed_quotes_have_consistent_counts(): void
    {
        $response = $this->preview(self::HEADER."\nBAD,123,SIS\nNEW,Nombre,SIS,extra\nINF-01,\"sin cerrar,SIS\n")
            ->assertOk()->assertJsonPath('data.summary.total', 4)->assertJsonPath('data.summary.invalid', 4);
        foreach ($response->json('data.rows') as $row) {
            $this->assertSame('ERROR', $row['status']);
            $this->assertNotEmpty($row['errors']);
        }
    }

    public function test_invalid_first_row_does_not_omit_a_later_valid_association(): void
    {
        $this->preview(self::HEADER."NEW,Nombre,SIS,extra\nNEW,Nombre,SIS\n")
            ->assertOk()->assertJsonPath('data.rows.0.status', 'ERROR')->assertJsonPath('data.rows.1.status', 'VALID');
    }

    public function test_unauthorized_users_cannot_persist_preview(): void
    {
        $this->admin->roles()->detach();
        $this->preview(self::HEADER."INF-01,Programación,SIS\n")->assertForbidden();
        $this->assertDatabaseCount('subject_imports', 0);
    }

    private function preview(string $text)
    {
        return $this->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->createWithContent('subjects.csv', $text)]);
    }
}
