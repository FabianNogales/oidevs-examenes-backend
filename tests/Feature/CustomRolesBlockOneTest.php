<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use App\Services\Admin\RolePermissionCatalog;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\LoginUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class CustomRolesBlockOneTest extends TestCase
{
    use RefreshDatabase, CourseOfferingFixture;

    private User $administrator;

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.connections.sqlite');
        if (! $app->environment('testing') || $app->configurationIsCached() || $app['config']->get('database.default') !== 'sqlite'
            || $connection['database'] !== ':memory:' || ! empty($connection['url'])
            || $app['config']->get('session.driver') !== 'array' || $app['config']->get('cache.default') !== 'array') {
            throw new \RuntimeException('These tests require isolated in-memory SQLite and array session/cache.');
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
        config(['eida.institutional_email_domains' => ['umss.edu.bo']]);
        foreach (['ADMINISTRADOR', 'DOCENTE', 'ESTUDIANTE'] as $name) {
            Role::create(['name' => $name, 'status' => 'ACTIVE']);
        }
        $this->administrator = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->administrator->roles()->attach(Role::where('name', 'ADMINISTRADOR')->value('id'), ['status' => 'ACTIVE']);
        Sanctum::actingAs($this->administrator);
    }

    private function customRole(string $name = 'Apoyo de instalaciones', array $keys = ['rooms.view']): Role
    {
        $id = $this->postJson('/api/v1/admin/roles', ['name' => $name, 'permission_keys' => $keys])
            ->assertCreated()->json('data.id');

        return Role::findOrFail($id);
    }

    private function staffPayload(Role $role): array
    {
        return ['first_names' => 'Ana', 'last_names' => 'Rojas', 'identity_number' => '00987654',
            'email' => 'personal@umss.edu.bo', 'role_id' => $role->id];
    }

    public function test_catalog_preserves_the_three_room_actions_enabled_by_block_two(): void
    {
        $catalog = $this->getJson('/api/v1/admin/permissions')->assertOk()->json('data');
        $data = array_values(array_filter($catalog, fn ($permission) => str_starts_with($permission['key'], 'rooms.')));
        $this->assertSame(['rooms.view', 'rooms.create', 'rooms.update'], array_column($data, 'key'));
        foreach ($data as $permission) {
            $this->assertTrue($permission['is_configurable']);
            $this->assertTrue($permission['is_enabled']);
        }
        $this->assertDatabaseCount('roles', 3);
    }

    public function test_create_edit_and_revision_are_audited_without_changing_role_identity_or_assignments(): void
    {
        $role = $this->customRole();
        $this->getJson('/api/v1/admin/roles/'.$role->id)->assertOk()->assertJsonPath('data.is_custom', true);
        $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => 'Apoyo operativo',
            'permission_keys' => ['rooms.create'], 'revision' => 1])->assertOk()
            ->assertJsonPath('data.id', $role->id)->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.permission_keys', ['rooms.create']);
        $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => 'Nombre obsoleto',
            'permission_keys' => [], 'revision' => 1])->assertConflict();
        $this->assertSame('Apoyo operativo', $role->refresh()->name);
        $this->assertSame(1, AuditLog::where('action', 'ROLE_CREATED')->count());
        $this->assertSame(1, AuditLog::where('action', 'ROLE_UPDATED')->count());
        $this->assertSame(2, AuditLog::where('action', 'ROLE_PERMISSIONS_CHANGED')->count());
    }

    public function test_creation_and_editing_preserve_four_permissions_from_different_modules_when_reopened(): void
    {
        $keys = ['rooms.view', 'subjects.view', 'academic_terms.create', 'careers.view'];
        $role = $this->customRole('Consulta de varios módulos', $keys);
        sort($keys);
        $this->getJson('/api/v1/admin/roles/'.$role->id)->assertOk()
            ->assertJsonCount(4, 'data.permission_keys')->assertJsonPath('data.permission_keys', $keys);
        $this->assertSame($keys, $role->permissions()->orderBy('key')->pluck('key')->all());

        $updatedKeys = ['rooms.create', 'subjects.update', 'academic_terms.view', 'course_offerings.view'];
        $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => $role->name,
            'permission_keys' => $updatedKeys, 'revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        sort($updatedKeys);
        $this->getJson('/api/v1/admin/roles/'.$role->id)->assertOk()
            ->assertJsonCount(4, 'data.permission_keys')->assertJsonPath('data.permission_keys', $updatedKeys);
        $this->assertSame($updatedKeys, $role->permissions()->orderBy('key')->pluck('key')->all());
    }

    public function test_creation_and_editing_accept_the_entire_current_configurable_catalog(): void
    {
        $keys = app(RolePermissionCatalog::class)->configurable()->pluck('key')->sort()->values()->all();
        $this->assertGreaterThan(3, count($keys));
        $role = $this->customRole('Catálogo completo', $keys);
        $this->getJson('/api/v1/admin/roles/'.$role->id)->assertOk()->assertJsonPath('data.permission_keys', $keys);
        $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => 'Catálogo completo actualizado',
            'permission_keys' => $keys, 'revision' => 1])->assertOk();
        $this->getJson('/api/v1/admin/roles/'.$role->id)->assertOk()->assertJsonPath('data.permission_keys', $keys);
    }

    public function test_catalog_sized_limit_still_rejects_unknown_non_configurable_and_repeated_keys_without_partial_writes(): void
    {
        $role = $this->customRole();
        Permission::where('key', 'subjects.view')->update(['is_configurable' => false]);
        foreach ([
            [['rooms.view', 'rooms.view'], 'permission_keys.0'],
            [['rooms.view', 'does.not.exist'], 'permission_keys'],
            [['rooms.view', 'subjects.view'], 'permission_keys'],
        ] as [$keys, $field]) {
            $this->postJson('/api/v1/admin/roles', ['name' => 'No guardar', 'permission_keys' => $keys])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => 'No cambiar',
                'permission_keys' => $keys, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->getJson('/api/v1/admin/roles/'.$role->id)->assertOk()
                ->assertJsonPath('data.name', $role->name)->assertJsonPath('data.permission_keys', ['rooms.view'])
                ->assertJsonPath('data.revision', 1);
        }
        $this->assertDatabaseCount('roles', 4);
    }

    public function test_unknown_repeated_and_administrative_permissions_are_rejected(): void
    {
        foreach ([['rooms.view', 'rooms.view'], ['users.roles.assign'], ['rooms.delete']] as $keys) {
            $this->postJson('/api/v1/admin/roles', ['name' => 'Acceso inválido', 'permission_keys' => $keys])
                ->assertUnprocessable();
        }
        Permission::forceCreate(['key' => 'users.roles.assign', 'module' => 'Usuarios', 'label' => 'Inyectado',
            'description' => 'No pertenece al catálogo controlado', 'is_configurable' => true, 'is_enabled' => true]);
        $this->postJson('/api/v1/admin/roles', ['name' => 'Inyección', 'permission_keys' => ['users.roles.assign']])->assertUnprocessable();
        $this->getJson('/api/v1/admin/permissions')->assertOk()->assertJsonCount(count(RolePermissionCatalog::ENABLED), 'data');
        $this->assertDatabaseCount('roles', 3);
    }

    public function test_base_names_aliases_collaborator_and_normalized_duplicates_are_protected(): void
    {
        foreach (['ADMINISTRADOR', ' administrador ', 'Admin', 'Docente', 'ESTUDIANTE', 'Colaborador', 'COLLABORATOR'] as $name) {
            $this->postJson('/api/v1/admin/roles', ['name' => $name, 'permission_keys' => []])->assertUnprocessable();
        }
        $this->customRole('Apoyo operativo');
        $this->postJson('/api/v1/admin/roles', ['name' => ' apoyo   OPERATIVO ', 'permission_keys' => []])->assertUnprocessable();
        foreach (Role::where('is_custom', false)->get() as $base) {
            $this->patchJson('/api/v1/admin/roles/'.$base->id, ['name' => 'Renombrado',
                'permission_keys' => ['rooms.create'], 'revision' => 1])->assertForbidden();
            $this->assertFalse($base->refresh()->is_custom);
            $this->assertSame(0, $base->permissions()->count());
        }
    }

    public function test_invalid_permission_edits_preserve_existing_configuration(): void
    {
        $role = $this->customRole();
        foreach ([['rooms.create', 'rooms.create'], ['users.roles.assign']] as $keys) {
            $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => $role->name,
                'permission_keys' => $keys, 'revision' => 1])->assertUnprocessable();
        }
        $this->assertSame(['rooms.view'], $role->permissions()->pluck('key')->all());
        $this->assertSame(1, $role->refresh()->revision);
    }

    public function test_hu22_assigns_withdraws_and_reactivates_the_same_custom_pivot_and_revokes_tokens(): void
    {
        $role = $this->customRole();
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false, 'active_session_id' => 'old']);
        $user->createToken('prior');
        $path = '/api/v1/admin/users/'.$user->id.'/roles';
        $this->postJson($path, ['role_id' => $role->id])->assertOk();
        $pivot = DB::table('role_user')->where('user_id', $user->id)->sole();
        $this->assertSame($this->administrator->id, $pivot->assigned_by);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotSame('old', $user->refresh()->active_session_id);
        $this->postJson($path, ['role_id' => $role->id])->assertConflict();
        $this->deleteJson($path.'/'.$role->id)->assertOk();
        $this->assertFalse($user->hasRole($role->name));
        $this->postJson($path, ['role_id' => $role->id])->assertOk();
        $this->assertSame($pivot->id, DB::table('role_user')->where('user_id', $user->id)->sole()->id);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('teachers', 0);
        $this->assertSame(3, AuditLog::whereIn('action', ['USER_ROLE_ASSIGNED', 'USER_ROLE_REVOKED'])->count());
    }

    public function test_staff_creation_is_atomic_has_no_academic_profile_and_reuses_first_access(): void
    {
        $role = $this->customRole();
        $payload = $this->staffPayload($role);
        $id = $this->postJson('/api/v1/admin/users/staff', $payload)->assertCreated()
            ->assertJsonPath('data.full_name', 'Ana Rojas')->assertJsonPath('data.student_id', null)
            ->assertJsonPath('data.teacher_id', null)->json('data.id');
        $user = User::findOrFail($id);
        $this->assertTrue(Hash::check($payload['identity_number'], $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasRole($role->name));
        $this->assertSame($id, app(LoginUserResolver::class)->resolve($user->email, $payload['identity_number'])->id);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('teachers', 0);
        $this->assertSame(1, AuditLog::where('action', 'STAFF_ACCOUNT_CREATED')->count());
        $this->assertSame(1, AuditLog::where('action', 'USER_ROLE_ASSIGNED')->where('entity_id', $id)->count());
        $evidence = AuditLog::all()->toJson();
        foreach ([$payload['identity_number'], $user->password, 'password', 'token', 'active_session_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $evidence);
        }
        // /me web must use the web guard, not Sanctum's mocked personal access token.
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->withAccessToken(null), 'web');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.display_name', 'Ana Rojas')
            ->assertJsonPath('data.roles', [$role->name])->assertJsonPath('data.permissions', ['rooms.view']);
        $this->getJson('/api/v1/admin/roles')->assertForbidden();
    }

    public function test_inactive_custom_roles_can_be_withdrawn_but_not_assigned_until_reactivated(): void
    {
        $role = $this->customRole();
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $path = '/api/v1/admin/users/'.$user->id.'/roles';
        $this->postJson($path, ['role_id' => $role->id])->assertOk();
        $role->update(['status' => 'INACTIVE']);
        $this->deleteJson($path.'/'.$role->id)->assertOk();
        $this->postJson($path, ['role_id' => $role->id])->assertUnprocessable();
        $role->update(['status' => 'ACTIVE']);
        $this->postJson($path, ['role_id' => $role->id])->assertOk();
        $this->assertSame(1, DB::table('role_user')->where('user_id', $user->id)->count());
    }

    public function test_assignment_failure_rolls_back_the_entire_staff_account(): void
    {
        $role = $this->customRole();
        $usersBefore = User::count();
        $pivotsBefore = DB::table('role_user')->count();
        $this->mock(AdminUserService::class)->shouldReceive('role')->once()->andThrow(new \RuntimeException('Assignment failed'));
        $this->postJson('/api/v1/admin/users/staff', $this->staffPayload($role))->assertStatus(500);
        $this->assertSame($usersBefore, User::count());
        $this->assertSame($pivotsBefore, DB::table('role_user')->count());
        $this->assertSame(0, AuditLog::where('action', 'STAFF_ACCOUNT_CREATED')->count());
    }

    public function test_staff_can_login_by_email_change_initial_password_and_logout_without_a_base_role(): void
    {
        $role = $this->customRole();
        $payload = $this->staffPayload($role);
        $id = $this->postJson('/api/v1/admin/users/staff', $payload)->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->postJson('/login', ['identifier' => $payload['email'], 'password' => $payload['identity_number']])->assertOk();
        $user = User::findOrFail($id);
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->putJson('/user/password', ['current_password' => $payload['identity_number'],
            'password' => 'PersonalSeguro123', 'password_confirmation' => 'PersonalSeguro123'])->assertOk();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', false)
            ->assertJsonPath('data.roles', [$role->name])->assertJsonPath('data.permissions', ['rooms.view']);
        $this->postJson('/api/v1/auth/logout')->assertSuccessful();
    }

    public function test_assignment_audit_failure_rolls_back_staff_account_and_pivot(): void
    {
        $role = $this->customRole();
        $usersBefore = User::count();
        $pivotsBefore = DB::table('role_user')->count();
        $this->mock(AuditLogService::class)->shouldReceive('log')->andThrow(new \RuntimeException('Audit failed'));
        $this->postJson('/api/v1/admin/users/staff', $this->staffPayload($role))->assertStatus(500);
        $this->assertSame($usersBefore, User::count());
        $this->assertSame($pivotsBefore, DB::table('role_user')->count());
    }

    public function test_staff_cannot_be_created_with_base_inactive_role_or_invalid_identity_email(): void
    {
        $role = $this->customRole();
        $payload = $this->staffPayload($role);
        $this->postJson('/api/v1/admin/users/staff', array_replace($payload,
            ['role_id' => Role::where('name', 'ADMINISTRADOR')->value('id')]))->assertUnprocessable();
        $role->update(['status' => 'INACTIVE']);
        $this->postJson('/api/v1/admin/users/staff', $payload)->assertUnprocessable();
        $role->update(['status' => 'ACTIVE']);
        $this->postJson('/api/v1/admin/users/staff', array_replace($payload, ['identity_number' => 1234]))->assertUnprocessable();
        $this->postJson('/api/v1/admin/users/staff', array_replace($payload, ['email' => 'personal@example.com']))->assertUnprocessable();
        $this->postJson('/api/v1/admin/users/staff', $payload)->assertCreated();
        $this->postJson('/api/v1/admin/users/staff', array_replace($payload, ['email' => 'PERSONAL@UMSS.EDU.BO']))->assertUnprocessable();
        $this->assertSame(2, User::count());
    }

    public function test_non_administrators_cannot_manage_roles_permissions_or_personnel_even_with_configured_permissions(): void
    {
        $role = $this->customRole();
        foreach (['DOCENTE', 'ESTUDIANTE', $role->name] as $name) {
            $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
            $user->roles()->attach(Role::where('name', $name)->value('id'), ['status' => 'ACTIVE']);
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/admin/permissions')->assertForbidden();
            $this->getJson('/api/v1/admin/roles')->assertForbidden();
            $this->getJson('/api/v1/admin/roles/'.$role->id)->assertForbidden();
            $this->postJson('/api/v1/admin/roles', ['name' => 'No permitido', 'permission_keys' => []])->assertForbidden();
            $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => 'No permitido', 'permission_keys' => [], 'revision' => 1])->assertForbidden();
            $this->postJson('/api/v1/admin/users/staff', $this->staffPayload($role))->assertForbidden();
        }
    }

    public function test_configured_but_disabled_room_permissions_still_deny_access(): void
    {
        $role = $this->customRole('Operación configurada', ['rooms.view', 'rooms.create', 'rooms.update']);
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->postJson('/api/v1/admin/users/'.$user->id.'/roles', ['role_id' => $role->id])->assertOk();
        // Configuring a role alone is insufficient while persisted enablement is off.
        DB::table('permissions')->update(['is_enabled' => false]);
        $room = Room::create(['code' => 'A-PRUEBA', 'name' => 'Aula de prueba', 'status' => 'ACTIVE']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->refresh()->withAccessToken(null), 'web');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.permissions', []);
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
        $this->getJson('/api/v1/admin/rooms/'.$room->id)->assertForbidden();
        $this->postJson('/api/v1/admin/rooms', ['code' => 'NO', 'name' => 'No permitido'])->assertForbidden();
        $this->putJson('/api/v1/admin/rooms/'.$room->id, ['code' => 'NO', 'name' => 'No permitido'])->assertForbidden();
        $this->patchJson('/api/v1/admin/rooms/'.$room->id.'/status', ['status' => 'INACTIVE'])->assertForbidden();
        $this->postJson('/api/v1/admin/rooms/import/preview')->assertForbidden();
        $this->postJson('/api/v1/admin/rooms/import/confirm')->assertForbidden();
    }

    public function test_guests_cannot_use_new_administrative_endpoints(): void
    {
        $role = $this->customRole();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/permissions')->assertUnauthorized();
        $this->getJson('/api/v1/admin/roles/'.$role->id)->assertUnauthorized();
        $this->postJson('/api/v1/admin/roles', ['name' => 'No permitido', 'permission_keys' => []])->assertUnauthorized();
        $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => 'No permitido', 'permission_keys' => [], 'revision' => 1])->assertUnauthorized();
        $this->postJson('/api/v1/admin/users/staff', $this->staffPayload($role))->assertUnauthorized();
    }

    public function test_permissions_use_current_active_roles_and_assignments_and_union_without_duplicates(): void
    {
        $first = $this->customRole('Consulta configurada', ['rooms.view']);
        $second = $this->customRole('Registro configurado', ['rooms.view', 'rooms.create']);
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $user->roles()->attach([$first->id => ['status' => 'ACTIVE'], $second->id => ['status' => 'ACTIVE']]);
        $catalog = app(RolePermissionCatalog::class);
        $user->load('activeRoles');
        $this->assertSame(['rooms.create', 'rooms.view'], $catalog->configured($user));
        DB::table('role_user')->where('user_id', $user->id)->where('role_id', $second->id)->update(['status' => 'INACTIVE']);
        $this->assertSame(['rooms.view'], $catalog->configured($user));
        $first->update(['status' => 'INACTIVE']);
        $this->assertSame([], $catalog->configured($user));
        $first->update(['status' => 'ACTIVE']);
        $user->forceFill(['status' => 'INACTIVE'])->save();
        $this->assertSame([], $catalog->configured($user));
    }

    public function test_editing_assigned_role_revokes_sessions_and_rolls_back_on_audit_failure(): void
    {
        $role = $this->customRole();
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false, 'active_session_id' => 'before']);
        $user->roles()->attach($role->id, ['status' => 'ACTIVE']);
        $user->createToken('previous');
        $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => $role->name,
            'permission_keys' => [], 'revision' => 1])->assertOk();
        $this->assertNotSame('before', $user->refresh()->active_session_id);
        $this->assertSame(0, $user->tokens()->count());
        $marker = $user->active_session_id;
        $user->createToken('preserved');
        $this->mock(AuditLogService::class)->shouldReceive('log')->andThrow(new \RuntimeException('Audit failed'));
        $this->patchJson('/api/v1/admin/roles/'.$role->id, ['name' => 'No guardar',
            'permission_keys' => ['rooms.create'], 'revision' => 2])->assertStatus(500);
        $this->assertSame($role->name, $role->refresh()->name);
        $this->assertSame($marker, $user->refresh()->active_session_id);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_hu22_keeps_base_profiles_last_administrator_and_teacher_responsibilities(): void
    {
        $target = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        foreach (['DOCENTE', 'ESTUDIANTE'] as $name) {
            $this->postJson('/api/v1/admin/users/'.$target->id.'/roles',
                ['role_id' => Role::where('name', $name)->value('id')])->assertUnprocessable();
        }
        $adminRole = Role::where('name', 'ADMINISTRADOR')->sole();
        $this->deleteJson('/api/v1/admin/users/'.$this->administrator->id.'/roles/'.$adminRole->id)->assertConflict();
        $this->patchJson('/api/v1/admin/users/'.$this->administrator->id.'/status', ['status' => 'INACTIVE'])->assertConflict();
        $fixture = $this->offeringFixture();
        $this->deleteJson('/api/v1/admin/users/'.$fixture['user']->id.'/roles/'.Role::where('name', 'DOCENTE')->value('id'))->assertConflict();
        $this->assertTrue($fixture['user']->hasRole('DOCENTE'));
    }

    public function test_new_management_endpoints_keep_first_access_inactive_and_expired_session_guards(): void
    {
        $this->administrator->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/v1/admin/permissions')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->postJson('/api/v1/admin/roles', ['name' => 'No guardar', 'permission_keys' => []])->assertForbidden();
        $this->administrator->forceFill(['must_change_password' => false, 'status' => 'INACTIVE'])->save();
        $this->getJson('/api/v1/admin/permissions')->assertUnauthorized();
        $this->administrator->forceFill(['status' => 'ACTIVE', 'active_session_id' => 'different-session'])->save();
        $this->actingAs($this->administrator, 'web')->withSession(['guard_check' => true])
            ->getJson('/api/v1/admin/permissions')->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
    }
}
