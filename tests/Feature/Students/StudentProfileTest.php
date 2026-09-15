<?php

namespace Tests\Feature\Students;

use App\Enums\RoleName;
use App\Models\Career;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $studentUser;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // Crear rol ESTUDIANTE
        $roleId = DB::table('roles')->insertGetId([
            'name' => RoleName::ESTUDIANTE->value,
            'description' => 'Rol Estudiante',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->studentUser = User::factory()->create([
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);

        DB::table('role_user')->insert([
            'role_id' => $roleId,
            'user_id' => $this->studentUser->id,
            'assigned_at' => now(),
            'status' => 'ACTIVE',
        ]);

        $career = Career::create([
            'code' => 'SIS-01',
            'name' => 'Ingeniería de Sistemas',
            'status' => 'ACTIVE',
        ]);

        $this->student = Student::create([
            'user_id' => $this->studentUser->id,
            'career_id' => $career->id,
            'sis_code' => '202011223',
            'identity_number' => '12345678',
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_student_can_get_their_profile(): void
    {
        $response = $this->actingAs($this->studentUser, 'sanctum')
            ->getJson('/api/v1/students/profile');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Perfil obtenido exitosamente',
            ])
            ->assertJsonStructure([
                'data',
                'message',
            ]);
    }
    
    public function test_student_can_upload_profile_photo(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 400, 400);

        $response = $this->actingAs($this->studentUser, 'sanctum')
            ->postJson('/api/v1/students/profile/photo', [
                'photo' => $file,
            ]);

        $response->assertStatus(200);

        $this->studentUser->refresh();
        $this->assertNotNull($this->studentUser->profile_photo);
        Storage::disk('public')->assertExists($this->studentUser->profile_photo);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->studentUser->id,
            'action' => 'UPDATE_PROFILE_PHOTO',
        ]);
    }

    public function test_profile_photo_validation_rejects_invalid_file(): void
    {
        // Archivo no permitido (TXT)
        $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $response = $this->actingAs($this->studentUser, 'sanctum')
            ->postJson('/api/v1/students/profile/photo', [
                'photo' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_unauthenticated_user_cannot_access_profile(): void
    {
        $response = $this->getJson('/api/v1/students/profile');

        $response->assertStatus(401);
    }
}