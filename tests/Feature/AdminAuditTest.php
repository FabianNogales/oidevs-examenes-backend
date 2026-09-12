<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_access_is_audited(): void
    {
        $admin = User::factory()->create();

        $admin->forceFill([
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
            'password' => Hash::make('Password1'),
        ])->save();

        $role = Role::query()->firstOrCreate(
            ['name' => RoleName::ADMINISTRADOR->value],
            [
                'description' => 'Administrador',
                'status' => UserStatus::ACTIVE->value,
            ]
        );

        $admin->roles()->attach($role->id, [
            'status' => UserStatus::ACTIVE->value,
            'assigned_at' => now(),
        ]);

        $this->startSession();

        $admin->forceFill([
            'active_session_id' => $this->app['session']->getId(),
        ])->save();

        $response = $this
            ->actingAs($admin)
            ->getJson('/api/v1/admin/test');

        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'ADMIN_ACCESS_GRANTED',
            'entity_type' => 'ADMIN_PANEL',
        ]);
    }

    public function test_docente_access_denied_is_audited(): void
{
    $docente = User::factory()->create();

    $docente->forceFill([
        'status' => UserStatus::ACTIVE->value,
        'must_change_password' => false,
        'password' => Hash::make('Password1'),
    ])->save();

    $role = Role::query()->firstOrCreate(
        ['name' => RoleName::DOCENTE->value],
        [
            'description' => 'Docente',
            'status' => UserStatus::ACTIVE->value,
        ]
    );

    $docente->roles()->attach($role->id, [
        'status' => UserStatus::ACTIVE->value,
        'assigned_at' => now(),
    ]);

    $this->startSession();

    $docente->forceFill([
        'active_session_id' => $this->app['session']->getId(),
    ])->save();

    $response = $this
        ->actingAs($docente)
        ->getJson('/api/v1/admin/test');

    $response->assertStatus(403);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $docente->id,
        'action' => 'ADMIN_ACCESS_DENIED',
        'entity_type' => 'ADMIN_PANEL',
    ]);
}

public function test_admin_access_stores_ip_and_user_agent(): void
{
    $admin = User::factory()->create();

    $admin->forceFill([
        'status' => UserStatus::ACTIVE->value,
        'must_change_password' => false,
        'password' => Hash::make('Password1'),
    ])->save();

    $role = Role::query()->firstOrCreate(
        ['name' => RoleName::ADMINISTRADOR->value],
        [
            'description' => 'Administrador',
            'status' => UserStatus::ACTIVE->value,
        ]
    );

    $admin->roles()->attach($role->id, [
        'status' => UserStatus::ACTIVE->value,
        'assigned_at' => now(),
    ]);

    $this->startSession();

    $admin->forceFill([
        'active_session_id' => $this->app['session']->getId(),
    ])->save();

    $response = $this
        ->withServerVariables([
            'REMOTE_ADDR' => '192.168.1.100',
        ])
        ->withHeaders([
            'User-Agent' => 'TestBrowser/1.0',
        ])
        ->actingAs($admin)
        ->getJson('/api/v1/admin/test');

    $response->assertStatus(200);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'ADMIN_ACCESS_GRANTED',
        'ip_address' => '192.168.1.100',
        'user_agent' => 'TestBrowser/1.0',
    ]);
}

public function test_admin_audit_does_not_store_sensitive_information(): void
{
    $admin = User::factory()->create();

    $admin->forceFill([
        'status' => UserStatus::ACTIVE->value,
        'must_change_password' => false,
        'password' => Hash::make('Password1'),
    ])->save();

    $role = Role::query()->firstOrCreate(
        ['name' => RoleName::ADMINISTRADOR->value],
        [
            'description' => 'Administrador',
            'status' => UserStatus::ACTIVE->value,
        ]
    );

    $admin->roles()->attach($role->id, [
        'status' => UserStatus::ACTIVE->value,
        'assigned_at' => now(),
    ]);

    $this->startSession();

    $admin->forceFill([
        'active_session_id' => $this->app['session']->getId(),
    ])->save();

    $response = $this
        ->actingAs($admin)
        ->getJson('/api/v1/admin/test');

    $response->assertStatus(200);

    $audit = AuditLog::query()
        ->where('user_id', $admin->id)
        ->where('action', 'ADMIN_ACCESS_GRANTED')
        ->latest('id')
        ->first();

    $this->assertNotNull($audit);
    $this->assertNull($audit->old_values);
    $this->assertNull($audit->new_values);

    $auditData = json_encode($audit->toArray());

    $this->assertStringNotContainsString('Password1', $auditData);
}
}