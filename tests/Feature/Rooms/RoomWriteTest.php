<?php

namespace Tests\Feature\Rooms;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Rooms\RoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoomWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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

    public function test_create_persists_optional_fields_and_audit(): void
    {
        $response = $this->postJson('/api/v1/admin/rooms', $this->payload(['code' => ' A-01 ', 'description' => "Primera línea\nSegunda línea"]))
            ->assertCreated()->assertJsonPath('data.code', 'A-01')->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.capacity', 50);
        $id = $response->json('data.id');
        $this->getJson('/api/v1/admin/rooms/'.$id)->assertOk()->assertJsonPath('data.floor', 'PB');
        $this->assertDatabaseHas('audit_logs', ['action' => 'ROOM_CREATED', 'entity_id' => $id, 'user_id' => $this->admin->id]);
    }

    public function test_edit_preserves_id_state_and_clears_optional_fields(): void
    {
        $room = Room::create($this->payload() + ['status' => 'INACTIVE']);
        $this->putJson('/api/v1/admin/rooms/'.$room->id, $this->payload([
            'name' => 'Aula actualizada', 'location' => null, 'description' => null, 'capacity' => null, 'floor' => null,
        ]))->assertOk()->assertJsonPath('data.id', $room->id)->assertJsonPath('data.status', 'INACTIVE')
            ->assertJsonPath('data.capacity', null)->assertJsonPath('data.floor', null);
        $audit = AuditLog::where('action', 'ROOM_UPDATED')->firstOrFail();
        $this->assertSame('Aula principal', $audit->old_values['name']);
        $this->assertSame('Aula actualizada', $audit->new_values['name']);
        $this->assertSame(1, Room::count());
    }

    public function test_duplicates_include_inactive_rooms_and_edit_excludes_own_id(): void
    {
        $room = Room::create($this->payload() + ['status' => 'INACTIVE']);
        $this->postJson('/api/v1/admin/rooms', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['code', 'name']);
        $this->putJson('/api/v1/admin/rooms/'.$room->id, $this->payload())->assertOk();
        $other = Room::create($this->payload(['code' => 'B-02', 'name' => 'Otra aula']) + ['status' => 'ACTIVE']);
        $this->putJson('/api/v1/admin/rooms/'.$other->id, $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['code', 'name']);
    }

    public function test_invalid_fields_are_rejected_without_writes(): void
    {
        foreach ([0, -1, 1.5, 2147483648, 'abc'] as $capacity) {
            $this->postJson('/api/v1/admin/rooms', $this->payload(['capacity' => $capacity]))
                ->assertUnprocessable()->assertJsonValidationErrors('capacity');
        }
        $this->postJson('/api/v1/admin/rooms', [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name']);
        $this->postJson('/api/v1/admin/rooms', $this->payload(['code' => "A\t01", 'name' => "Aula\n01", 'floor' => str_repeat('x', 51), 'description' => str_repeat('x', 1001)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'name', 'floor', 'description']);
        $this->postJson('/api/v1/admin/rooms', $this->payload() + ['status' => 'INACTIVE', 'availability' => 'AVAILABLE', 'current_exam' => ['id' => 2]])
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'availability', 'current_exam']);
        $this->assertDatabaseCount('rooms', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_missing_id_and_non_administrator_are_rejected(): void
    {
        $this->putJson('/api/v1/admin/rooms/9999', $this->payload())->assertNotFound();
        $this->admin->update(['status' => 'INACTIVE']);
        $this->postJson('/api/v1/admin/rooms', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_database_uniqueness_is_translated_after_validation_race(): void
    {
        Room::create($this->payload() + ['status' => 'INACTIVE']);
        $request = Request::create('/api/v1/admin/rooms', 'POST');
        $request->setUserResolver(fn () => $this->admin);
        try {
            app(RoomService::class)->save($this->payload(['name' => 'Otro nombre']), $request);
            $this->fail('Expected unique constraint rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertDatabaseCount('rooms', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_creation_and_edit(): void
    {
        $room = Room::create($this->payload() + ['status' => 'ACTIVE']);
        $this->mock(AuditLogService::class)->shouldReceive('log')->twice()->andThrow(new \RuntimeException('Audit unavailable'));
        $request = Request::create('/api/v1/admin/rooms', 'POST');
        $request->setUserResolver(fn () => $this->admin);
        foreach ([null, $room] as $target) {
            try {
                app(RoomService::class)->save($this->payload(['code' => 'B-02', 'name' => 'Nueva aula']), $request, $target);
                $this->fail('Expected audit failure.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Audit unavailable', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('rooms', 1);
        $this->assertSame('A-01', $room->refresh()->code);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['code' => 'A-01', 'name' => 'Aula principal', 'location' => 'Edificio nuevo',
            'description' => null, 'capacity' => 50, 'floor' => 'PB'], $overrides);
    }
}
