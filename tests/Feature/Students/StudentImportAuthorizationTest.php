<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

use App\Models\Career;
use App\Models\Role;
use App\Models\User;

use App\Enums\RoleName;
use App\Enums\UserStatus;

class StudentImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_docente_cannot_access_student_import(): void
    {
        $role = Role::create([
            'name' => RoleName::DOCENTE->value,
            'description' => 'Docente',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $user = User::forceCreate([
            'email' => 'docente@umss.edu.bo',
            'password' => 'Password1',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);

        $response = $this->actingAs($user)
            ->post('/api/v1/admin/students/import/preview');

        $response->assertForbidden();
    }

    public function test_estudiante_cannot_access_student_import(): void
    {
        $role = Role::create([
            'name' => RoleName::ESTUDIANTE->value,
            'description' => 'Estudiante',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $user = User::forceCreate([
            'email' => 'estudiante@umss.edu.bo',
            'password' => 'Password1',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);

        $response = $this->actingAs($user)
            ->post('/api/v1/admin/students/import/preview');

        $response->assertForbidden();
    }
    public function test_student_import_is_audited(): void
{
    $career = Career::create([
        'code' => 'SIS',
        'name' => 'Sistemas',
        'status' => 'ACTIVE',
    ]);

    $adminRole = Role::create([
        'name' => RoleName::ADMINISTRADOR->value,
        'description' => 'Administrador',
        'status' => UserStatus::ACTIVE->value,
    ]);

    Role::create([
        'name' => RoleName::ESTUDIANTE->value,
        'description' => 'Estudiante',
        'status' => UserStatus::ACTIVE->value,
    ]);

    $admin = User::forceCreate([
        'email' => 'admin@umss.edu.bo',
        'password' => 'Password1',
        'status' => UserStatus::ACTIVE->value,
    ]);

    $admin->roles()->attach($adminRole->id, [
        'assigned_at' => now(),
        'status' => UserStatus::ACTIVE->value,
    ]);

    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,juan.perez@umss.edu.bo,Sistemas,juan.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $response = $this->actingAs($admin)
        ->post('/api/v1/admin/students/import/confirm', [
            'file' => $file,
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'STUDENT_IMPORT',
        'entity_type' => 'STUDENT_IMPORT',
    ]);
}
}