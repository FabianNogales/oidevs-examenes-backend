<?php

namespace Tests\Feature\Api\V1\Enrollments;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnrollmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected int $ownerTeacherId;
    protected int $otherTeacherId;
    protected int $courseOfferingId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Docente', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);
        $studentRoleId = DB::table('roles')->insertGetId([
            'name' => 'Estudiante', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        // 2. Docente Titular (Dueño de la materia)
        $ownerTeacher = User::factory()->create(['status' => 'ACTIVE']);
        $this->ownerTeacherId = $ownerTeacher->id;
        DB::table('role_user')->insert(['user_id' => $this->ownerTeacherId, 'role_id' => $roleId, 'status' => 'ACTIVE', 'assigned_at' => now()]);

        // 3. Docente Ajeno (Para probar el IDOR)
        $otherTeacher = User::factory()->create(['status' => 'ACTIVE']);
        $this->otherTeacherId = $otherTeacher->id;
        DB::table('role_user')->insert(['user_id' => $this->otherTeacherId, 'role_id' => $roleId, 'status' => 'ACTIVE', 'assigned_at' => now()]);

        // 4. Gestión y Materia
        DB::table('academic_terms')->insert(['id' => 1, 'name' => '2026-I', 'start_date' => '2026-02-01', 'end_date' => '2026-07-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subjects')->insert(['id' => 1, 'code' => 'CS101', 'name' => 'Programming', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $this->courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_user_id' => $this->ownerTeacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Estudiante
        $careerId = DB::table('careers')->insertGetId(['code' => 'SE-101', 'name' => 'Software', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $studentUser = User::factory()->create(['status' => 'ACTIVE']);
        DB::table('role_user')->insert(['user_id' => $studentUser->id, 'role_id' => $studentRoleId, 'status' => 'ACTIVE', 'assigned_at' => now()]);
        
        DB::table('students')->insert([
            'user_id' => $studentUser->id,
            'sis_code' => '202600001',
            'identity_number' => '1234567',
            'first_names' => 'John',
            'last_names' => 'Doe',
            'career_id' => $careerId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function testEnrollmentIsRejectedForNonTeachers(): void
    {
        $student = User::factory()->create(['status' => 'ACTIVE']);
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/manual", [
            'sisCode' => '202600001'
        ]);

        // Si el usuario no es docente, se bloquea
        $response->assertStatus(403);
    }

    public function testEnrollmentIsPreventedByIdorIfTeacherDoesNotOwnCourse(): void
    {
        $otherTeacher = User::find($this->otherTeacherId);
        Sanctum::actingAs($otherTeacher, ['*']);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/manual", [
            'sisCode' => '202600001'
        ]);

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Forbidden - You do not own this course offering.']);
    }

    public function testSuccessfulEnrollmentGeneratesAuditLog(): void
    {
        $ownerTeacher = User::find($this->ownerTeacherId);
        Sanctum::actingAs($ownerTeacher, ['*']);

        $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/manual", [
            'sisCode' => '202600001'
        ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->ownerTeacherId,
            'action' => 'WRITE',
            'entity_type' => 'Enrollment'
        ]);
    }
}