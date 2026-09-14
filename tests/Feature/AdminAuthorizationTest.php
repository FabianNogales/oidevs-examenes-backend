<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_access_admin_route(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
        ]);

        $role = Role::query()->create([
            'name' => RoleName::ADMINISTRADOR->value,
            'description' => 'Administrador.',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/admin/test')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function actingAsCurrentSession(User $user): self
    {
        $this->startSession();

        $user->forceFill([
            'active_session_id' => $this->app['session']->getId(),
        ])->save();

        return $this->actingAs($user);
    }

    public function test_docente_cannot_access_admin_route(): void
{
    $user = User::factory()->create([
        'status' => UserStatus::ACTIVE->value,
        'must_change_password' => false,
    ]);

    $role = Role::query()->create([
        'name' => RoleName::DOCENTE->value,
        'description' => 'Docente.',
        'status' => UserStatus::ACTIVE->value,
    ]);

    $user->roles()->attach($role->id, [
        'assigned_at' => now(),
        'status' => UserStatus::ACTIVE->value,
    ]);

    $this->actingAsCurrentSession($user)
        ->getJson('/api/v1/admin/test')
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
}

public function test_unauthenticated_user_cannot_access_admin_route(): void
{
    $this->getJson('/api/v1/admin/test')
        ->assertUnauthorized();
}

public function test_first_access_cannot_access_admin_route(): void
{
    $user = User::factory()->create([
        'status' => UserStatus::ACTIVE->value,
        'must_change_password' => true,
    ]);

    $role = Role::query()->create([
        'name' => RoleName::ADMINISTRADOR->value,
        'description' => 'Administrador.',
        'status' => UserStatus::ACTIVE->value,
    ]);

    $user->roles()->attach($role->id, [
        'assigned_at' => now(),
        'status' => UserStatus::ACTIVE->value,
    ]);

    $this->actingAsCurrentSession($user)
        ->getJson('/api/v1/admin/test')
        ->assertForbidden()
        ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
}
}