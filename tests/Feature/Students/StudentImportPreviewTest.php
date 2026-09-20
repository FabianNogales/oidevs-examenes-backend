<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Career;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StudentImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_student_import(): void
    {
        $career = Career::create([
            'code' => 'SIS',
            'name' => 'Sistemas',
            'status' => 'ACTIVE',
        ]);

        $role = Role::create([
            'name' => RoleName::ADMINISTRADOR->value,
            'description' => 'Administrador',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $admin = User::forceCreate([
            'email' => 'admin@umss.edu.bo',
            'password' => 'Password1',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $admin->roles()->attach($role->id, [
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
            ->post('/api/v1/admin/students/import/preview', [
                'file' => $file,
            ]);

        $response->assertOk();

        $response->assertJsonPath(
            'data.total_rows',
            1
        );

        $response->assertJsonPath(
            'data.valid_rows',
            1
        );

        $response->assertJsonPath(
            'data.error_rows',
            0
        );

        $this->assertDatabaseCount('students', 0);
    }
}