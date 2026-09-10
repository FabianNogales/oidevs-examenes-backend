<?php

namespace Tests\Feature\Api\V1;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_user_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_current_user_returns_empty_roles_for_authenticated_user_without_roles(): void
    {
        $user = User::factory()->create([
            'email' => 'login.test@oipass.local',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'login.test@oipass.local')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.roles', []);
    }

    public function test_current_user_returns_administrator_role(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => RoleName::ADMINISTRADOR->value,
            'description' => 'Administrador del sistema.',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.roles', [
                RoleName::ADMINISTRADOR->value,
            ]);
    }

    public function test_current_user_returns_multiple_role_names(): void
    {
        $user = User::factory()->create();
        $administrator = Role::query()->create([
            'name' => RoleName::ADMINISTRADOR->value,
            'description' => 'Administrador del sistema.',
            'status' => UserStatus::ACTIVE->value,
        ]);
        $teacher = Role::query()->create([
            'name' => RoleName::DOCENTE->value,
            'description' => 'Docente.',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $user->roles()->attach($administrator->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);
        $user->roles()->attach($teacher->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.roles', [
                RoleName::ADMINISTRADOR->value,
                RoleName::DOCENTE->value,
            ]);
    }

    public function test_current_user_response_does_not_expose_sensitive_fields(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
            'remember_token' => 'remember-token',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_current_user_ignores_inactive_roles_and_inactive_role_assignments(): void
    {
        $user = User::factory()->create();
        $activeRole = Role::query()->create([
            'name' => RoleName::ADMINISTRADOR->value,
            'description' => 'Administrador del sistema.',
            'status' => UserStatus::ACTIVE->value,
        ]);
        $inactiveRole = Role::query()->create([
            'name' => RoleName::DOCENTE->value,
            'description' => 'Docente.',
            'status' => UserStatus::INACTIVE->value,
        ]);
        $inactiveAssignmentRole = Role::query()->create([
            'name' => RoleName::ESTUDIANTE->value,
            'description' => 'Estudiante.',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $user->roles()->attach($activeRole->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);
        $user->roles()->attach($inactiveRole->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);
        $user->roles()->attach($inactiveAssignmentRole->id, [
            'assigned_at' => now(),
            'status' => UserStatus::INACTIVE->value,
        ]);

        $this->assertTrue($user->hasRole(RoleName::ADMINISTRADOR));
        $this->assertFalse($user->hasRole(RoleName::DOCENTE));
        $this->assertFalse($user->hasRole(RoleName::ESTUDIANTE));

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.roles', [
                RoleName::ADMINISTRADOR->value,
            ]);
    }
}
