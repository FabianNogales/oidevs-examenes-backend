<?php

namespace Tests\Feature\Rooms;

use App\Models\Role;
use App\Models\Room;
use App\Models\RoomImport;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Rooms\RoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class RoomImportConfirmTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const CSV = "codigo,nombre,descripcion,capacidad,piso\nA-01,Principal,,50,PB\nB-02,Segunda,,,\nC-03,Inválida,,-1,\n";

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

    public function test_mixed_confirm_persists_rows_audit_and_complete_report(): void
    {
        $id = $this->preview();
        $response = $this->confirm($id)->assertOk()->assertJsonPath('data.imported_rows', 2)
            ->assertJsonPath('data.failed_rows', 1)->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.error_rows', 1)->assertJsonCount(3, 'data.rows')
            ->assertJsonPath('data.rows.0.valid', true)->assertJsonPath('data.rows.2.valid', false);
        $this->assertDatabaseCount('rooms', 2);
        $this->assertDatabaseHas('rooms', ['code' => 'A-01', 'capacity' => 50, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('rooms', ['code' => 'B-02', 'capacity' => null]);
        $this->assertDatabaseCount('audit_logs', 2);
        $import = RoomImport::firstOrFail();
        $this->assertSame('COMPLETED', $import->status);
        $this->assertNotNull($import->completed_at);
        $this->assertEquals($response->json('data'), $import->result_report);
    }

    public function test_retry_recovers_identical_report_even_after_expiry_without_new_audit(): void
    {
        $id = $this->preview();
        $first = $this->confirm($id)->assertOk()->json('data');
        RoomImport::where('preview_id', $id)->firstOrFail()->update(['expires_at' => now()->subDay()]);
        $this->confirm($id)->assertOk()->assertExactJson(['data' => $first]);
        $this->assertDatabaseCount('rooms', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_expired_different_file_and_unknown_preview_never_insert(): void
    {
        $id = $this->preview();
        $this->confirm($id, self::CSV."D,Extra,,,\n")->assertStatus(409);
        $this->confirm('00000000-0000-4000-8000-000000000000')->assertStatus(409);
        RoomImport::where('preview_id', $id)->firstOrFail()->update(['expires_at' => now()->subMinute()]);
        $this->confirm($id)->assertStatus(410);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_owner_and_file_are_checked_also_for_completed_results(): void
    {
        $id = $this->preview();
        $this->confirm($id)->assertOk();
        $this->confirm($id, self::CSV."D,Extra,,,\n")->assertStatus(409);
        RoomImport::where('preview_id', $id)->update(['user_id' => User::factory()->create()->id]);
        $this->confirm($id)->assertForbidden();
        $this->assertDatabaseCount('rooms', 2);
    }

    public function test_conflicts_created_since_preview_are_revalidated(): void
    {
        $id = $this->preview();
        Room::create(['code' => 'A-01', 'name' => 'Otro nombre', 'status' => 'INACTIVE']);
        Room::create(['code' => 'OTHER', 'name' => 'Segunda', 'status' => 'ACTIVE']);
        $this->confirm($id)->assertOk()->assertJsonPath('data.imported_rows', 0)
            ->assertJsonPath('data.failed_rows', 3);
        $this->assertDatabaseCount('rooms', 2);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_interruption_rolls_back_rooms_audits_and_processing_state_then_retry_succeeds(): void
    {
        $id = $this->preview();
        $realAudit = app(AuditLogService::class);
        $calls = 0;
        $this->mock(AuditLogService::class)->shouldReceive('log')->andReturnUsing(function (...$args) use (&$calls, $realAudit) {
            if (++$calls === 2) {
                throw new \RuntimeException('Simulated interruption');
            }

            return $realAudit->log(...$args);
        });
        $this->withoutExceptionHandling();
        try {
            $this->confirm($id);
            $this->fail('Expected interruption.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated interruption', $exception->getMessage());
        }
        $this->assertDatabaseCount('rooms', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $import = RoomImport::firstOrFail();
        $this->assertSame('PENDING', $import->status);
        $this->assertNull($import->result_report);
        $this->assertNull($import->completed_at);
        $this->instance(AuditLogService::class, $realAudit);
        $this->app->forgetInstance(RoomService::class);
        $this->confirm($id)->assertOk()->assertJsonPath('data.imported_rows', 2);
    }

    public function test_general_preview_errors_block_confirmation(): void
    {
        $id = $this->preview();
        $import = RoomImport::firstOrFail();
        $report = $import->preview_report;
        $report['errors'] = ['Archivo inválido'];
        $import->update(['preview_report' => $report]);
        $this->confirm($id)->assertStatus(409);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_bad_payload_and_non_administrator_are_rejected(): void
    {
        $this->postJson('/api/v1/admin/rooms/import/confirm', [])->assertUnprocessable();
        $id = $this->preview();
        $this->admin->roles()->detach();
        $this->confirm($id)->assertForbidden();
        $this->assertDatabaseCount('rooms', 0);
    }

    private function preview(): string
    {
        return $this->postJson('/api/v1/admin/rooms/import/preview', ['file' => $this->file(self::CSV)])
            ->assertOk()->json('data.preview_id');
    }

    private function confirm(string $id, string $csv = self::CSV)
    {
        return $this->postJson('/api/v1/admin/rooms/import/confirm', ['file' => $this->file($csv), 'preview_id' => $id]);
    }

    private function file(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('rooms.csv', $csv);
    }
}
