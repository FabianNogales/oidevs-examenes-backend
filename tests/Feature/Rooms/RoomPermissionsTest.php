<?php

namespace Tests\Feature\Rooms;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Services\Admin\CustomRoleService;
use App\Services\Admin\RolePermissionCatalog;
use App\Services\Rooms\RoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class RoomPermissionsTest extends TestCase
{
    use RefreshDatabase, CourseOfferingFixture;

    private Room $room;

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.connections.sqlite');
        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'sqlite' || $connection['database'] !== ':memory:'
            || ! empty($connection['url']) || $app['config']->get('session.driver') !== 'array'
            || $app['config']->get('cache.default') !== 'array') {
            throw new \RuntimeException('Room permission tests require isolated in-memory SQLite and array session/cache.');
        }
        foreach ($app['db']->connection()->getPdo()->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC) as $database) {
            if ($database['file'] !== '') {
                throw new \RuntimeException('Persistent test connection rejected.');
            }
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->room = Room::create(['code' => 'PERM-01', 'name' => 'Aula principal', 'location' => 'Bloque A',
            'floor' => 'PB', 'capacity' => 40, 'status' => 'ACTIVE']);
    }

    private function operator(array $keys): User
    {
        $role = new Role;
        $role->forceFill(['name' => 'Acceso '.Role::count(), 'status' => 'ACTIVE', 'is_custom' => true,
            'custom_name_key' => 'ACCESO '.Role::count()])->save();
        $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id')->all());
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $user->roles()->attach($role->id, ['status' => 'ACTIVE']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function administrator(): User
    {
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $user->roles()->attach($role->id, ['status' => 'ACTIVE']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['code' => 'A-02', 'name' => 'Aula nueva', 'location' => 'Bloque B',
            'description' => 'Descripción', 'floor' => '1', 'capacity' => 30], $changes);
    }

    public function test_activation_reuses_the_original_catalog_without_extra_permissions(): void
    {
        $this->administrator();
        $catalog = $this->getJson('/api/v1/admin/permissions')->assertOk()->json('data');
        $rows = array_values(array_filter($catalog, fn ($permission) => str_starts_with($permission['key'], 'rooms.')));
        $this->assertSame(['rooms.view', 'rooms.create', 'rooms.update'], array_column($rows, 'key'));
        $this->assertSame([true, true, true], array_column($rows, 'is_enabled'));
        $this->assertDatabaseCount('permissions', count(RolePermissionCatalog::ENABLED));
    }

    public function test_view_only_reads_the_full_existing_catalog_and_details_but_cannot_write(): void
    {
        $this->operator(['rooms.view']);
        Room::create(['code' => 'INACTIVA', 'name' => 'Archivo', 'status' => 'INACTIVE']);
        $this->getJson('/api/v1/admin/rooms')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/rooms/'.$this->room->id)->assertOk()->assertJsonPath('data.id', $this->room->id);
        $this->postJson('/api/v1/admin/rooms', $this->payload())->assertForbidden();
        $this->putJson('/api/v1/admin/rooms/'.$this->room->id, $this->payload())->assertForbidden();
        $this->getJson('/api/v1/admin/rooms/'.$this->room->id.'/edit')->assertForbidden();
        $this->assertDatabaseCount('rooms', 2);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_view_and_create_can_register_but_not_edit(): void
    {
        $this->operator(['rooms.view', 'rooms.create']);
        $this->postJson('/api/v1/admin/rooms', $this->payload())->assertCreated()->assertJsonPath('data.code', 'A-02');
        $this->getJson('/api/v1/admin/rooms')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson('/api/v1/admin/rooms/'.$this->room->id, $this->payload())->assertForbidden();
        $this->assertSame(1, AuditLog::where('action', 'ROOM_CREATED')->count());
        $this->assertSame(0, AuditLog::where('action', 'ROOM_UPDATED')->count());
    }

    public function test_create_only_needs_no_listing_or_detail_permission(): void
    {
        $this->operator(['rooms.create']);
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
        $this->getJson('/api/v1/admin/rooms/'.$this->room->id)->assertForbidden();
        $response = $this->postJson('/api/v1/admin/rooms', $this->payload())->assertCreated();
        $this->getJson('/api/v1/admin/rooms/'.$response->json('data.id'))->assertForbidden();
        $this->assertDatabaseCount('teachers', 0);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_update_only_reads_exact_editor_fields_and_does_not_gain_catalog_consultation(): void
    {
        $this->operator(['rooms.update']);
        $data = $this->getJson('/api/v1/admin/rooms/'.$this->room->id.'/edit')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['id', 'code', 'name', 'location', 'description', 'capacity', 'floor', 'building_id'], array_keys($data));
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
        $this->getJson('/api/v1/admin/rooms/'.$this->room->id)->assertForbidden();
        $this->putJson('/api/v1/admin/rooms/'.$this->room->id, $this->payload())->assertOk()->assertJsonPath('data.name', 'Aula nueva');
        $this->postJson('/api/v1/admin/rooms', $this->payload(['code' => 'A-03']))->assertForbidden();
    }

    public function test_no_permissions_or_inactive_assignments_and_roles_cannot_authorize(): void
    {
        $this->operator([]);
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
        $this->getJson('/api/v1/admin/rooms/'.$this->room->id)->assertForbidden();
        $this->getJson('/api/v1/admin/rooms/'.$this->room->id.'/edit')->assertForbidden();
        $this->postJson('/api/v1/admin/rooms', $this->payload())->assertForbidden();
        $this->putJson('/api/v1/admin/rooms/'.$this->room->id, $this->payload())->assertForbidden();
        $user = $this->operator(['rooms.view']);
        $user->load('activeRoles');
        DB::table('role_user')->where('user_id', $user->id)->update(['status' => 'INACTIVE']);
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
        DB::table('role_user')->where('user_id', $user->id)->update(['status' => 'ACTIVE']);
        $role = $user->roles()->firstOrFail();
        $role->update(['status' => 'INACTIVE']);
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
    }

    public function test_permission_removal_denies_the_next_request_even_when_user_roles_are_already_loaded(): void
    {
        $user = $this->operator(['rooms.view', 'rooms.update']);
        $user->load('activeRoles');
        $this->getJson('/api/v1/admin/rooms')->assertOk();
        $role = $user->roles()->firstOrFail();
        $role->permissions()->detach(Permission::where('key', 'rooms.view')->value('id'));
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
        $this->getJson('/api/v1/admin/rooms/'.$this->room->id)->assertForbidden();
        $this->getJson('/api/v1/admin/rooms/'.$this->room->id.'/edit')->assertOk();
        $this->assertSame(['rooms.update'], app(RolePermissionCatalog::class)->effective($user));
    }

    public function test_effective_permissions_are_the_union_of_current_active_role_assignments(): void
    {
        $user = $this->operator(['rooms.view']);
        $otherUser = $this->operator(['rooms.create']);
        $creationRole = $otherUser->roles()->firstOrFail();
        $user->roles()->attach($creationRole->id, ['status' => 'ACTIVE']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/rooms')->assertOk();
        $this->postJson('/api/v1/admin/rooms', $this->payload())->assertCreated();
        $this->putJson('/api/v1/admin/rooms/'.$this->room->id, $this->payload())->assertForbidden();
        $user->roles()->updateExistingPivot($creationRole->id, ['status' => 'INACTIVE']);
        $this->getJson('/api/v1/admin/rooms')->assertOk();
        $this->postJson('/api/v1/admin/rooms', $this->payload(['code' => 'A-03']))->assertForbidden();
    }

    public function test_delegated_permissions_never_authorize_csv_status_or_payload_status_changes(): void
    {
        $this->operator(['rooms.view', 'rooms.create', 'rooms.update']);
        $this->postJson('/api/v1/admin/rooms/import/preview', ['file' => UploadedFile::fake()->create('aulas.csv')])->assertForbidden();
        $this->postJson('/api/v1/admin/rooms/import/confirm')->assertForbidden();
        $this->patchJson('/api/v1/admin/rooms/'.$this->room->id.'/status', ['status' => 'INACTIVE'])->assertForbidden();
        $this->putJson('/api/v1/admin/rooms/'.$this->room->id, $this->payload(['status' => 'INACTIVE']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/v1/admin/rooms', $this->payload(['status' => 'INACTIVE']))->assertUnprocessable();
        $this->assertSame('ACTIVE', $this->room->refresh()->status);
        $this->assertDatabaseCount('room_imports', 0);
    }

    public function test_role_edit_revokes_a_live_token_and_relogin_cannot_restore_the_removed_permission(): void
    {
        $user = $this->operator(['rooms.view']);
        $token = $user->createToken('permission-regression')->plainTextToken;
        $role = $user->roles()->firstOrFail();
        $this->administrator();
        $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => $role->name,
            'permission_keys' => [], 'revision' => 1])->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/rooms')->assertUnauthorized();
        $this->withHeader('Authorization', '');
        Sanctum::actingAs($user->refresh());
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
    }

    public function test_role_edit_invalidates_the_active_web_session_for_the_next_room_request(): void
    {
        $user = $this->operator(['rooms.view']);
        $role = $user->roles()->firstOrFail();
        $administrator = $this->administrator();
        config(['sanctum.stateful' => ['127.0.0.1:5173']]);
        $this->startSession();
        $previousSessionId = $this->app['session']->getId();
        $user->forceFill(['active_session_id' => $previousSessionId])->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $previousSessionId)->getJson('/api/v1/admin/rooms')->assertOk();

        $request = Request::create('/api/v1/admin/roles/'.$role->id, 'PATCH');
        $request->setUserResolver(fn () => $administrator);
        app(CustomRoleService::class)->save(['name' => $role->name, 'permission_keys' => [], 'revision' => 1], $request, $role);
        $this->assertNotSame($previousSessionId, $user->refresh()->active_session_id);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->getJson('/api/v1/admin/rooms')
            ->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
    }

    public function test_admin_keeps_existing_actions_even_if_the_permission_catalog_is_disabled(): void
    {
        $this->administrator();
        DB::table('permissions')->update(['is_enabled' => false]);
        $this->getJson('/api/v1/admin/rooms')->assertOk();
        $this->postJson('/api/v1/admin/rooms', $this->payload())->assertCreated();
        $this->putJson('/api/v1/admin/rooms/'.$this->room->id, $this->payload(['code' => 'A-03', 'name' => 'Aula editada']))->assertOk();
        $this->patchJson('/api/v1/admin/rooms/'.$this->room->id.'/status', ['status' => 'INACTIVE'])->assertOk();
        // Validation errors (not 403) demonstrate import access is still reserved and available to admin.
        $this->postJson('/api/v1/admin/rooms/import/preview')->assertUnprocessable();
        $this->postJson('/api/v1/admin/rooms/import/confirm')->assertUnprocessable();
    }

    public function test_account_first_access_and_session_guards_remain_in_force(): void
    {
        $user = $this->operator(['rooms.view', 'rooms.create', 'rooms.update']);
        $user->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/v1/admin/rooms')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->forceFill(['must_change_password' => false, 'status' => 'INACTIVE'])->save();
        $this->getJson('/api/v1/admin/rooms')->assertUnauthorized();
        $user->forceFill(['status' => 'ACTIVE', 'active_session_id' => 'old-session'])->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withSession(['room_guard' => true])->getJson('/api/v1/admin/rooms')
            ->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
    }

    public function test_write_service_rechecks_current_permissions_without_trusting_a_previous_route_check(): void
    {
        $user = $this->operator(['rooms.create']);
        $user->roles()->firstOrFail()->permissions()->detach();
        $request = Request::create('/api/v1/admin/rooms', 'POST');
        $request->setUserResolver(fn () => $user);
        try {
            app(RoomService::class)->save($this->payload(), $request);
            $this->fail('A withdrawn permission must not authorize saving.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('rooms', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_teacher_scheduling_availability_is_unchanged_and_does_not_require_administrative_room_permissions(): void
    {
        $fixture = $this->offeringFixture();
        $teacher = $fixture['user'];
        $teacher->roles()->detach(Role::where('name', 'ADMINISTRADOR')->value('id'));
        Sanctum::actingAs($teacher->refresh());
        DB::table('permissions')->update(['is_enabled' => false]);
        $inactive = Room::create(['code' => 'OFF', 'name' => 'Deshabilitada', 'status' => 'INACTIVE']);
        $exam = DB::table('exams')->insertGetId(['course_offering_id' => $fixture['offering']->id,
            'room_id' => $this->room->id, 'name' => 'Parcial', 'exam_date' => '2027-05-10', 'start_time' => '09:45:00',
            'duration_minutes' => 90, 'evaluation_type' => 'partial', 'status' => 'SCHEDULED', 'created_by' => $teacher->id]);
        $query = ['exam_date' => '2027-05-10', 'start_time' => '09:45', 'duration_minutes' => 90,
            'course_offering_id' => $fixture['offering']->id];
        $data = $this->getJson('/api/v1/rooms?'.http_build_query($query))->assertOk()->json('data');
        $this->assertNotContains($this->room->id, array_column($data, 'id'));
        $this->assertNotContains($inactive->id, array_column($data, 'id'));
        $available = $this->getJson('/api/v1/rooms?'.http_build_query($query + ['exclude_exam_id' => $exam]))
            ->assertOk()->json('data');
        $this->assertContains($this->room->id, array_column($available, 'id'));
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
    }
}
