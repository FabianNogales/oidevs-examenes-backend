<?php

namespace Tests\Feature\Api\V1\Enrollments;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected int $teacherUserId;
    protected int $teacherProfileId;
    protected int $courseOfferingId;
    protected int $studentId;
    protected int $careerId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Crear el usuario con los middlewares evadidos
        $teacherUser = User::factory()->create([
            'status' => 'ACTIVE',
            'must_change_password' => false
        ]);
        $this->teacherUserId = $teacherUser->id;

        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Docente',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert([
            'user_id' => $teacherUser->id,
            'role_id' => $roleId,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        // 2. Crear el perfil en la nueva tabla teachers
        $this->teacherProfileId = DB::table('teachers')->insertGetId([
            'user_id' => $teacherUser->id,
            'institutional_code' => 'DOC-001',
            'identity_number' => '12345678',
            'first_names' => 'Carlos',
            'last_names' => 'Perez',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_terms')->insert([
            'id' => 1, 'name' => '2026-I', 'start_date' => '2026-02-01', 'end_date' => '2026-07-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('subjects')->insert([
            'id' => 1, 'code' => 'CS101', 'name' => 'Introduction to Programming', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 3. Vincular la materia usando teacher_id
        $this->courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_id' => $this->teacherProfileId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->careerId = DB::table('careers')->insertGetId([
            'code' => 'SE-101', 'name' => 'Software Engineering', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $studentUser = User::factory()->create([
            'status' => 'ACTIVE',
            'must_change_password' => false
        ]);
        
        $this->studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id,
            'sis_code' => '202600001',
            'identity_number' => '1234567',
            'first_names' => 'John',
            'last_names' => 'Doe',
            'career_id' => $this->careerId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        Sanctum::actingAs($teacherUser, ['*']);
    }

    public function testManualEnrollmentRejectsNonExistentSisCode(): void
    {
        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/manual", [
            'sisCode' => '999999999'
        ]);

        $response->assertStatus(404)
                 ->assertJson(['message' => 'Student not found in the institutional registry.']);
    }

    public function testManualEnrollmentSucceedsAndPreventsDuplicates(): void
    {
        $response1 = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/manual", [
            'sisCode' => '202600001'
        ]);

        $response1->assertStatus(201)
                  ->assertJson(['message' => 'Student enrolled successfully.']);

        $this->assertDatabaseHas('enrollments', [
            'course_offering_id' => $this->courseOfferingId,
            'student_id' => $this->studentId,
            'registered_by' => $this->teacherUserId,
            'status' => 'ACTIVE',
        ]);

        $response2 = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/manual", [
            'sisCode' => '202600001'
        ]);

        $response2->assertStatus(422)
                  ->assertJson(['message' => 'The student is already enrolled in this course offering.']);
    }

    public function testBulkCsvUploadValidatesFormatAndProcessesRecordsInTransaction(): void
    {
        $studentUser2 = User::factory()->create([
            'status' => 'ACTIVE',
            'must_change_password' => false
        ]);
        
        $student2Id = DB::table('students')->insertGetId([
            'user_id' => $studentUser2->id,
            'sis_code' => '202600002',
            'identity_number' => '7654321',
            'first_names' => 'Jane',
            'last_names' => 'Smith',
            'career_id' => $this->careerId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $csvContent = "sisCode\n202600002\n999999999\n\n202600002";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csvContent);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/bulk", [
            'file' => $file
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'totalProcessed',
                         'successfulRecords',
                         'duplicateRecords',
                         'failedCount',
                         'failedRecords' => [
                             '*' => ['row', 'sisCode', 'status', 'reason']
                         ]
                     ]
                 ]);

        $this->assertDatabaseHas('enrollments', [
            'course_offering_id' => $this->courseOfferingId,
            'student_id' => $student2Id,
            'registered_by' => $this->teacherUserId,
            'status' => 'ACTIVE',
        ]);
    }
}