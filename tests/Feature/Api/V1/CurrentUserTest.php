<?php

namespace Tests\Feature\Api\V1;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'login.test@oipass.local')
            ->assertJsonPath('data.display_name', 'login.test@oipass.local')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonPath('data.roles', []);
    }

    public function test_current_user_returns_teacher_display_name(): void
    {
        $user = User::factory()->create([
            'email' => 'teacher.display@umss.edu.bo',
        ]);
        $this->attachRole($user, RoleName::DOCENTE);

        Teacher::query()->create([
            'user_id' => $user->id,
            'institutional_code' => 'DOC-DISPLAY',
            'identity_number' => '100200',
            'first_names' => 'Juan Carlos',
            'last_names' => 'Perez Lopez',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Juan Carlos Perez Lopez');
    }

    public function test_current_user_returns_student_display_name(): void
    {
        $user = User::factory()->create([
            'email' => 'student.display@umss.edu.bo',
        ]);
        $this->attachRole($user, RoleName::ESTUDIANTE);

        $this->createStudent($user, [
            'sis_code' => '202300123',
            'identity_number' => 'CI-202300123',
            'first_names' => 'Maria Fernanda',
            'last_names' => 'Gomez Rios',
        ]);

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Maria Fernanda Gomez Rios');
    }

    public function test_current_user_returns_email_as_display_name_for_administrator_without_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'admin.no.profile@umss.edu.bo',
        ]);
        $this->attachRole($user, RoleName::ADMINISTRADOR);

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'admin.no.profile@umss.edu.bo');
    }

    public function test_current_user_multirol_administrator_docente_uses_teacher_display_name(): void
    {
        $user = User::factory()->create([
            'email' => 'admin.teacher@umss.edu.bo',
        ]);
        $this->attachRole($user, RoleName::ADMINISTRADOR);
        $this->attachRole($user, RoleName::DOCENTE);

        Teacher::query()->create([
            'user_id' => $user->id,
            'institutional_code' => 'DOC-MULTI',
            'identity_number' => '200300',
            'first_names' => 'Carlos',
            'last_names' => 'Mendoza',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Carlos Mendoza');
    }

    public function test_current_user_multirol_administrator_estudiante_uses_student_display_name(): void
    {
        $user = User::factory()->create([
            'email' => 'admin.student@umss.edu.bo',
        ]);
        $this->attachRole($user, RoleName::ADMINISTRADOR);
        $this->attachRole($user, RoleName::ESTUDIANTE);

        $this->createStudent($user, [
            'sis_code' => '202300124',
            'identity_number' => 'CI-202300124',
            'first_names' => 'Lucia',
            'last_names' => 'Vargas',
        ]);

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Lucia Vargas');
    }

    public function test_current_user_prefers_teacher_display_name_when_teacher_and_student_profiles_exist(): void
    {
        $user = User::factory()->create([
            'email' => 'dual.profile@umss.edu.bo',
        ]);

        Teacher::query()->create([
            'user_id' => $user->id,
            'institutional_code' => 'DOC-DUAL',
            'identity_number' => '300400',
            'first_names' => 'Teacher',
            'last_names' => 'Profile',
            'status' => UserStatus::ACTIVE->value,
        ]);
        $this->createStudent($user, [
            'sis_code' => '202300125',
            'identity_number' => 'CI-202300125',
            'first_names' => 'Student',
            'last_names' => 'Profile',
        ]);

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Teacher Profile');
    }

    public function test_current_user_uses_email_when_profile_name_is_empty(): void
    {
        $user = User::factory()->create([
            'email' => 'empty.profile@umss.edu.bo',
        ]);

        Teacher::query()->create([
            'user_id' => $user->id,
            'institutional_code' => 'DOC-EMPTY',
            'identity_number' => '400500',
            'first_names' => '',
            'last_names' => '',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'empty.profile@umss.edu.bo');
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

        $this->actingAsCurrentSession($user)
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

        $this->actingAsCurrentSession($user)
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

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.active_session_id')
            ->assertJsonMissingPath('data.identity_number')
            ->assertJsonMissingPath('data.sis_code')
            ->assertJsonMissingPath('data.institutional_code');
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

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.roles', [
                RoleName::ADMINISTRADOR->value,
            ]);
    }

    private function actingAsCurrentSession(User $user): self
    {
        $this->startSession();

        $user->forceFill([
            'active_session_id' => $this->app['session']->getId(),
        ])->save();

        return $this->actingAs($user);
    }

    private function attachRole(User $user, RoleName $roleName): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName->value],
            [
                'description' => $roleName->value,
                'status' => UserStatus::ACTIVE->value,
            ],
        );

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createStudent(User $user, array $attributes): Student
    {
        $careerId = DB::table('careers')->insertGetId([
            'code' => 'CAREER-'.$attributes['sis_code'],
            'name' => 'Informatica',
            'status' => UserStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Student::query()->create(array_merge([
            'user_id' => $user->id,
            'career_id' => $careerId,
            'status' => UserStatus::ACTIVE->value,
        ], $attributes));
    }
}
