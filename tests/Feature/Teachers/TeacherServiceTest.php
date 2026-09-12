<?php

namespace Tests\Feature\Teachers;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Teachers\TeacherService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeacherServiceTest extends TestCase
{
    use RefreshDatabase;

    private TeacherService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eida.institutional_email_domains' => ['umss.edu.bo'],
        ]);

        $this->service = app(TeacherService::class);
    }

    public function test_create_teacher_creates_user_assigns_role_initial_password_and_audit(): void
    {
        $actor = User::factory()->create();
        $role = $this->createTeacherRole();

        $teacher = $this->service->create([
            'institutional_code' => 'DOC-001',
            'identity_number' => '7654321',
            'first_names' => 'Ana Maria',
            'last_names' => 'Rojas',
            'email' => 'Ana.Rojas@UMSS.EDU.BO',
        ], $actor);

        $teacher->load('user');

        $this->assertSame('ana.rojas@umss.edu.bo', $teacher->user->email);
        $this->assertSame(UserStatus::ACTIVE, $teacher->status);
        $this->assertSame(UserStatus::ACTIVE->value, $teacher->user->status);
        $this->assertTrue(Hash::check('7654321', $teacher->user->password));
        $this->assertNotSame('7654321', $teacher->user->password);
        $this->assertTrue($teacher->user->must_change_password);
        $this->assertTrue($teacher->user->hasRole(RoleName::DOCENTE));

        $this->assertDatabaseHas('role_user', [
            'user_id' => $teacher->user_id,
            'role_id' => $role->id,
            'assigned_by' => $actor->id,
            'status' => UserStatus::ACTIVE->value,
        ]);

        $auditLog = AuditLog::query()->where('action', 'TEACHER_CREATED')->firstOrFail();

        $this->assertSame($actor->id, $auditLog->user_id);
        $this->assertSame(Teacher::class, $auditLog->entity_type);
        $this->assertSame($teacher->id, $auditLog->entity_id);
        $this->assertNull($auditLog->old_values);
        $this->assertSame('DOC-001', $auditLog->new_values['institutional_code']);
        $this->assertArrayNotHasKey('password', $auditLog->new_values);
        $this->assertArrayNotHasKey('identity_number', $auditLog->new_values);
    }

    public function test_create_teacher_rolls_back_when_docente_role_is_missing(): void
    {
        $actor = User::factory()->create();

        $this->expectException(DomainException::class);

        try {
            $this->service->create([
                'institutional_code' => 'DOC-002',
                'identity_number' => '8765432',
                'first_names' => 'Luis',
                'last_names' => 'Mendez',
                'email' => 'luis.mendez@umss.edu.bo',
            ], $actor);
        } finally {
            $this->assertDatabaseMissing('users', [
                'email' => 'luis.mendez@umss.edu.bo',
            ]);
            $this->assertDatabaseMissing('teachers', [
                'institutional_code' => 'DOC-002',
            ]);
            $this->assertDatabaseMissing('audit_logs', [
                'action' => 'TEACHER_CREATED',
            ]);
        }
    }

    public function test_paginate_teachers_searches_names_email_and_institutional_code(): void
    {
        $first = Teacher::factory()->create([
            'institutional_code' => 'DOC-ALPHA',
            'first_names' => 'Carla',
            'last_names' => 'Perez',
        ]);
        $first->user->forceFill(['email' => 'carla.perez@umss.edu.bo'])->save();

        $second = Teacher::factory()->create([
            'institutional_code' => 'DOC-BETA',
            'first_names' => 'Mario',
            'last_names' => 'Quiroga',
        ]);
        $second->user->forceFill(['email' => 'mario.quiroga@umss.edu.bo'])->save();

        $this->assertSame([$first->id], $this->service->paginate(['search' => 'car'])->pluck('id')->all());
        $this->assertSame([$second->id], $this->service->paginate(['search' => 'quiroga'])->pluck('id')->all());
        $this->assertSame([$first->id], $this->service->paginate(['search' => 'perez@umss'])->pluck('id')->all());
        $this->assertSame([$second->id], $this->service->paginate(['search' => 'beta'])->pluck('id')->all());
    }

    public function test_paginate_teachers_limits_per_page(): void
    {
        Teacher::factory()->count(3)->create();

        $teachers = $this->service->paginate(['per_page' => 500]);

        $this->assertSame(100, $teachers->perPage());
    }

    public function test_update_teacher_changes_allowed_fields_without_resetting_password(): void
    {
        $actor = User::factory()->create();
        $teacher = Teacher::factory()->create([
            'institutional_code' => 'DOC-003',
            'identity_number' => '1111111',
            'first_names' => 'Old',
            'last_names' => 'Name',
        ]);
        $teacher->user->forceFill([
            'email' => 'old.name@umss.edu.bo',
            'password' => 'Password1',
        ])->save();
        $passwordBefore = $teacher->user->password;

        $updated = $this->service->update($teacher, [
            'institutional_code' => 'DOC-004',
            'identity_number' => '2222222',
            'first_names' => 'New',
            'last_names' => 'Teacher',
            'email' => 'new.teacher@umss.edu.bo',
        ], $actor);

        $this->assertSame('DOC-004', $updated->institutional_code);
        $this->assertSame('2222222', $updated->identity_number);
        $this->assertSame('New', $updated->first_names);
        $this->assertSame('Teacher', $updated->last_names);
        $this->assertSame('new.teacher@umss.edu.bo', $updated->user->email);
        $this->assertSame($passwordBefore, $updated->user->password);

        $auditLog = AuditLog::query()->where('action', 'TEACHER_UPDATED')->firstOrFail();

        $this->assertSame($actor->id, $auditLog->user_id);
        $this->assertSame('DOC-003', $auditLog->old_values['institutional_code']);
        $this->assertSame('DOC-004', $auditLog->new_values['institutional_code']);
        $this->assertArrayNotHasKey('password', $auditLog->new_values);
        $this->assertArrayNotHasKey('identity_number', $auditLog->new_values);
    }

    public function test_change_status_keeps_teacher_and_user_consistent_and_blocks_login(): void
    {
        $teacher = Teacher::factory()->create();
        $teacher->user->forceFill([
            'email' => 'status.teacher@umss.edu.bo',
            'password' => 'Password1',
        ])->save();

        $this->service->changeStatus($teacher, UserStatus::INACTIVE);

        $teacher->refresh()->load('user');

        $this->assertSame(UserStatus::INACTIVE, $teacher->status);
        $this->assertSame(UserStatus::INACTIVE->value, $teacher->user->status);
        $this->postJson('/login', [
            'identifier' => 'status.teacher@umss.edu.bo',
            'password' => 'Password1',
        ])->assertUnprocessable();

        $this->service->changeStatus($teacher, UserStatus::ACTIVE);

        $teacher->refresh()->load('user');

        $this->assertSame(UserStatus::ACTIVE, $teacher->status);
        $this->assertSame(UserStatus::ACTIVE->value, $teacher->user->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TEACHER_DEACTIVATED',
            'entity_id' => $teacher->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TEACHER_ACTIVATED',
            'entity_id' => $teacher->id,
        ]);
        $this->assertDatabaseHas('teachers', [
            'id' => $teacher->id,
        ]);
    }

    private function createTeacherRole(): Role
    {
        return Role::query()->create([
            'name' => RoleName::DOCENTE->value,
            'description' => 'Gestion docente dentro del sistema EIDA.',
            'status' => UserStatus::ACTIVE->value,
        ]);
    }
}
