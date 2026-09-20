<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Career;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentImportConfirmTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_creates_user_student_and_estudiante_role(): void
    {
        $career = Career::create([
            'code' => 'SIS',
            'name' => 'Sistemas',
            'status' => 'ACTIVE',
        ]);

        $role = Role::create([
            'name' => RoleName::ESTUDIANTE->value,
            'description' => 'Estudiante',
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

        $service = app(StudentCsvImportService::class);

        $result = $service->import($file);

        $this->assertEquals(1, $result['imported_rows']);
        $this->assertEquals(0, $result['failed_rows']);

        $user = User::where(
            'email',
            'juan.perez@umss.edu.bo'
        )->first();

        $this->assertNotNull($user);

        $this->assertTrue(
            $user->hasRole(RoleName::ESTUDIANTE)
        );

        $this->assertTrue(
            $user->must_change_password
        );

        $this->assertTrue(
            Hash::check('1234567', $user->password)
        );

        $this->assertDatabaseHas('students', [
            'user_id' => $user->id,
            'sis_code' => '20260001',
            'identity_number' => '1234567',
            'first_names' => 'Juan',
            'last_names' => 'Perez',
            'career_id' => $career->id,
            'status' => 'ACTIVE',
        ]);
    }
    public function test_admin_can_confirm_student_import(): void
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

    $response->assertJsonPath(
        'data.imported_rows',
        1
    );

    $this->assertDatabaseHas('students', [
        'sis_code' => '20260001',
    ]);
}
}