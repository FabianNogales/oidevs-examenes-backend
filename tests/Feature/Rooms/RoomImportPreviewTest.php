<?php

namespace Tests\Feature\Rooms;

use App\Models\Role;
use App\Models\Room;
use App\Models\RoomImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class RoomImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const ENDPOINT = '/api/v1/admin/rooms/import/preview';

    private const HEADER = "codigo,nombre,descripcion,capacidad,piso\n";

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
    }

    public function test_preview_persists_owner_hash_report_and_expiry_without_creating_rooms(): void
    {
        $text = self::HEADER."A-01,Aula principal,,50,PB\nB-02,Otra aula,,,\n";
        $response = $this->preview($text)->assertOk()->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 2)->assertJsonPath('data.error_rows', 0)
            ->assertJsonPath('data.rows.0.data.capacity', '50')->assertJsonPath('data.rows.1.data.capacity', '')
            ->assertJsonPath('data.rows.0.row', 2)->assertJsonPath('data.rows.1.row', 3);
        $import = RoomImport::firstOrFail();
        $this->assertSame($this->admin->id, $import->user_id);
        $this->assertSame(hash('sha256', $text), $import->file_hash);
        $this->assertSame($response->json('data.preview_id'), $import->preview_id);
        $this->assertSame('PENDING', $import->status);
        $this->assertEquals($response->json('data.rows'), $import->preview_report['rows']);
        $this->assertStringEndsWith('-04:00', $response->json('data.expires_at'));
        $this->assertSame(CarbonImmutable::parse($response->json('data.expires_at'))->timestamp, $import->expires_at->timestamp);
        $this->assertTrue($import->expires_at->isFuture());
        $this->assertNull($import->result_report);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_existing_and_internal_duplicates_are_reported_including_inactive_rooms(): void
    {
        Room::create(['code' => 'OLD', 'name' => 'Nombre ocupado', 'status' => 'INACTIVE']);
        $this->preview(self::HEADER."NEW,Nueva aula,,50,PB\nOLD,Nombre distinto,,,\nB,Nombre ocupado,,,\nD,Duplicado,,,\nD,Duplicado,,,\n")
            ->assertOk()->assertJsonPath('data.valid_rows', 1)->assertJsonPath('data.error_rows', 4)
            ->assertJsonPath('data.rows.0.valid', true)->assertJsonPath('data.rows.4.valid', false);
        $this->assertDatabaseCount('rooms', 1);
    }

    public function test_bom_quoted_headers_commas_escaped_quotes_and_multiline_descriptions(): void
    {
        $text = "\xEF\xBB\xBF\" CODIGO \",\"Nombre\",DESCRIPCION, capacidad ,PISO\r\n".
            "A-01,Principal,\"Línea 1, con coma\r\nLínea 2 con \"\"comillas\"\"\",10,Sótano\r\nB-02,Segunda,,,\r\n";
        $this->preview($text)->assertOk()->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.rows.0.data.description', "Línea 1, con coma\r\nLínea 2 con \"comillas\"")
            ->assertJsonPath('data.rows.1.row', 4);
    }

    public function test_invalid_file_structure_encoding_and_upload_are_rejected_without_preview(): void
    {
        foreach (['', self::HEADER, "wrong,headers\nA,B\n", self::HEADER."A,Nombre,\xFF,,\n"] as $text) {
            $this->preview($text)->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->postJson(self::ENDPOINT, [])->assertUnprocessable();
        $this->postJson(self::ENDPOINT, ['file' => 'not-a-file'])->assertUnprocessable();
        $this->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->createWithContent('rooms.txt', self::HEADER)])
            ->assertUnprocessable();
        $this->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->create('rooms.csv', 10241, 'text/csv')])->assertStatus(413);
        $this->assertDatabaseCount('room_imports', 0);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_invalid_and_empty_rows_have_errors_and_consistent_counters(): void
    {
        $response = $this->preview(self::HEADER."A,Nombre,,0,PB\nB,Otro,,1.5,PB\n\nC,Tercero,desc,50,PB,extra\nD,Cuarto,\"sin cerrar,,\n")
            ->assertOk()->assertJsonPath('data.valid_rows', 0)->assertJsonPath('data.error_rows', 5)
            ->assertJsonPath('data.total_rows', 5);
        foreach ($response->json('data.rows') as $row) {
            $this->assertFalse($row['valid']);
            $this->assertNotEmpty($row['errors']);
            foreach ($row['data'] as $value) {
                $this->assertIsString($value);
            }
        }
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_non_administrator_cannot_create_preview(): void
    {
        $this->admin->roles()->detach();
        $this->preview(self::HEADER."A,Nombre,,,\n")->assertForbidden();
        $this->assertDatabaseCount('room_imports', 0);
    }

    private function preview(string $text)
    {
        return $this->postJson(self::ENDPOINT, ['file' => UploadedFile::fake()->createWithContent('rooms.csv', $text)]);
    }
}
