<?php

namespace Tests\Feature\CourseOfferings;

use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\CourseOfferingImport;
use App\Models\Role;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CourseOfferingImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/course-offerings/import/preview';

    private const HEADER = "correo_docente,codigo_materia\n";

    private User $admin;

    private Teacher $teacher;

    private AcademicTerm $term;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
        $this->admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::create(['name' => 'ADMINISTRADOR', 'status' => 'ACTIVE']);
        $this->admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->startSession();
        $this->admin->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($this->admin)->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->teacher = Teacher::factory()->create(['user_id' => User::factory()->create(['email' => 'ana@umss.edu.bo', 'status' => 'ACTIVE'])->id]);
        $teacherRole = Role::create(['name' => 'DOCENTE', 'status' => 'ACTIVE']);
        $this->teacher->user->roles()->attach($teacherRole->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->term = AcademicTerm::create(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'ACTIVE']);
        $this->subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'ACTIVE']);
    }

    public function test_preview_binds_owner_period_file_and_expiry_without_creating_offerings(): void
    {
        $this->freezeTime();
        $text = self::HEADER."ANA@UMSS.EDU.BO,inf-01\nana@umss.edu.bo,INF-01\nno@umss.edu.bo,INF-01\n";
        $response = $this->preview($text)->assertOk()
            ->assertJsonPath('data.academic_term_id', $this->term->id)
            ->assertJsonPath('data.summary', ['total' => 3, 'valid' => 1, 'invalid' => 1, 'omitted' => 1])
            ->assertJsonPath('data.rows.0.row_number', 2)->assertJsonPath('data.rows.0.data.correo_docente', 'ANA@UMSS.EDU.BO')
            ->assertJsonPath('data.rows.1.status', 'OMITTED')->assertJsonPath('data.rows.2.status', 'ERROR');
        $import = CourseOfferingImport::firstOrFail();
        $this->assertSame($this->admin->id, $import->user_id);
        $this->assertSame(hash('sha256', $text), $import->file_hash);
        $this->assertSame($response->json('data.preview_id'), $import->preview_id);
        $this->assertSame($this->term->id, $import->preview_report['academic_term_id']);
        $this->assertEquals($response->json('data.rows'), $import->preview_report['rows']);
        $this->assertSame(now()->addMinutes(30)->timestamp, $import->expires_at->timestamp);
        $this->assertSame('PENDING', $import->status);
        $this->assertNull($import->result_report);
        $this->assertDatabaseCount('course_offerings', 0);
        $this->assertDatabaseCount('teachers', 1);
        $this->assertDatabaseCount('subjects', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('room_imports', 0);
        $this->assertDatabaseCount('subject_imports', 0);
    }

    public function test_existing_active_or_inactive_assignment_is_omitted_and_never_reactivated(): void
    {
        $offering = CourseOffering::create(['teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id,
            'academic_term_id' => $this->term->id, 'status' => 'ACTIVE']);
        foreach (['ACTIVE', 'INACTIVE'] as $status) {
            $offering->update(['status' => $status]);
            $this->preview(self::HEADER."ana@umss.edu.bo,INF-01\n")->assertOk()->assertJsonPath('data.summary.omitted', 1)->assertJsonPath('data.rows.0.status', 'OMITTED');
            $this->assertSame($status, $offering->fresh()->status);
        }
        $this->assertDatabaseCount('course_offerings', 1);
    }

    public function test_reference_states_unknown_codes_and_bad_emails_are_row_errors(): void
    {
        Subject::create(['code' => 'OLD', 'name' => 'Anterior', 'status' => 'INACTIVE']);
        $this->preview(self::HEADER."ana@umss.edu.bo,OLD\nana@umss.edu.bo,UNKNOWN\ninvalid,INF-01\n")
            ->assertOk()->assertJsonPath('data.summary.invalid', 3);
        foreach (['teacher', 'account', 'role', 'assignment'] as $case) {
            $role = Role::where('name', 'DOCENTE')->firstOrFail();
            $this->teacher->update(['status' => 'ACTIVE']);
            $this->teacher->user->update(['status' => 'ACTIVE']);
            $role->update(['status' => 'ACTIVE']);
            $this->teacher->user->roles()->updateExistingPivot($role->id, ['status' => 'ACTIVE']);
            if ($case === 'teacher') {
                $this->teacher->update(['status' => 'INACTIVE']);
            } elseif ($case === 'account') {
                $this->teacher->user->update(['status' => 'INACTIVE']);
            } elseif ($case === 'role') {
                $role->update(['status' => 'INACTIVE']);
            } else {
                $this->teacher->user->roles()->updateExistingPivot($role->id, ['status' => 'INACTIVE']);
            }
            $this->preview(self::HEADER."ana@umss.edu.bo,INF-01\n")->assertOk()->assertJsonPath('data.summary.invalid', 1);
        }
        $this->assertDatabaseCount('course_offerings', 0);
    }

    public function test_inactive_or_inconsistent_period_rejects_without_preview(): void
    {
        $this->term->update(['status' => 'INACTIVE']);
        $this->preview(self::HEADER."ana@umss.edu.bo,INF-01\n")->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_INACTIVE');
        $this->term->update(['status' => 'ACTIVE']);
        AcademicTerm::create(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'ACTIVE']);
        $this->preview(self::HEADER."ana@umss.edu.bo,INF-01\n")->assertStatus(409)->assertJsonPath('code', 'ACADEMIC_TERM_STATE_INCONSISTENT');
        $this->assertDatabaseCount('course_offering_imports', 0);
    }

    public function test_bom_quoted_fields_crlf_and_logical_record_numbers_are_supported(): void
    {
        $text = "\xEF\xBB\xBFcorreo_docente,codigo_materia\r\n\"ana@umss.edu.bo\",\"INF-01\"\r\n\"ana,\"\"nombre\"\"\r\nresto\",INF-01\r\nana@umss.edu.bo,INF-01\r\n";
        $this->preview($text)->assertOk()->assertJsonPath('data.summary', ['total' => 3, 'valid' => 1, 'invalid' => 1, 'omitted' => 1])
            ->assertJsonPath('data.rows.1.row_number', 3)->assertJsonPath('data.rows.1.data.correo_docente', "ana,\"nombre\"\r\nresto")
            ->assertJsonPath('data.rows.2.row_number', 4);
    }

    public function test_empty_bad_encoding_wrong_header_or_upload_are_rejected(): void
    {
        foreach (['', self::HEADER, "correo_docente,codigo_materia,periodo\nana@umss.edu.bo,INF-01,I/2027\n", self::HEADER."\xFF,INF-01\n"] as $text) {
            $this->preview($text)->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->postJson(self::ENDPOINT, ['academic_term_id' => $this->term->id])->assertUnprocessable();
        $this->postJson(self::ENDPOINT, ['academic_term_id' => $this->term->id, 'file' => UploadedFile::fake()->create('offerings.csv', 10241, 'text/csv')])->assertStatus(413);
        $this->postJson(self::ENDPOINT, ['academic_term_id' => $this->term->id, 'file' => UploadedFile::fake()->createWithContent('offerings.txt', self::HEADER)])->assertUnprocessable();
        $this->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->createWithContent('offerings.csv', self::HEADER)])->assertUnprocessable()->assertJsonValidationErrors('academic_term_id');
        $this->assertDatabaseCount('course_offering_imports', 0);
    }

    public function test_invalid_column_counts_and_unclosed_quotes_report_errors_without_losing_data(): void
    {
        $response = $this->preview(self::HEADER."\nana@umss.edu.bo,INF-01,extra\n\"sin cerrar,INF-01\n")
            ->assertOk()->assertJsonPath('data.summary.invalid', 3)->assertJsonPath('data.summary.total', 3);
        foreach ($response->json('data.rows') as $row) {
            $this->assertNotEmpty($row['errors']);
            $this->assertIsString($row['data']['correo_docente']);
            $this->assertIsString($row['data']['codigo_materia']);
        }
    }

    public function test_unauthorized_administrator_cannot_create_preview(): void
    {
        $this->admin->roles()->detach();
        $this->preview(self::HEADER."ana@umss.edu.bo,INF-01\n")->assertForbidden();
        $this->assertDatabaseCount('course_offering_imports', 0);
    }

    private function preview(string $text)
    {
        return $this->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->createWithContent('offerings.csv', $text),
            'academic_term_id' => (string) $this->term->id]);
    }
}
