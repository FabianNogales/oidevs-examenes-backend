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
    protected int $ownerTeacherProfileId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Docente', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);
        $studentRoleId = DB::table('roles')->insertGetId([
            'name' => 'Estudiante', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        $ownerTeacher = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->ownerTeacherId = $ownerTeacher->id;
        DB::table('role_user')->insert(['user_id' => $this->ownerTeacherId, 'role_id' => $roleId, 'status' => 'ACTIVE', 'assigned_at' => now()]);

        $this->ownerTeacherProfileId = DB::table('teachers')->insertGetId([
            'user_id' => $this->ownerTeacherId,
            'institutional_code' => 'DOC-001',
            'identity_number' => '111111',
            'first_names' => 'Owner',
            'last_names' => 'Teacher',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherTeacher = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->otherTeacherId = $otherTeacher->id;
        DB::table('role_user')->insert(['user_id' => $this->otherTeacherId, 'role_id' => $roleId, 'status' => 'ACTIVE', 'assigned_at' => now()]);

        $otherTeacherProfileId = DB::table('teachers')->insertGetId([
            'user_id' => $this->otherTeacherId,
            'institutional_code' => 'DOC-002',
            'identity_number' => '222222',
            'first_names' => 'Other',
            'last_names' => 'Teacher',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_terms')->insert(['id' => 1, 'name' => '2026-I', 'start_date' => '2026-02-01', 'end_date' => '2026-07-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subjects')->insert(['id' => 1, 'code' => 'CS101', 'name' => 'Programming', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $this->courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_id' => $this->ownerTeacherProfileId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $careerId = DB::table('careers')->insertGetId(['code' => 'SE-101', 'name' => 'Software', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $studentUser = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
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
        $student = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/manual", [
            'sisCode' => '202600001'
        ]);

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