<?php

namespace Tests\Feature\Api\V1\Exams;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected int $ownerUserId;
    protected int $otherUserId;
    protected int $courseOfferingId;
    protected int $roomId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = DB::table('roles')->insertGetId([
            'name' => RoleName::DOCENTE->value, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        // Docente Titular
        $ownerTeacher = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->ownerUserId = $ownerTeacher->id;
        DB::table('role_user')->insert(['user_id' => $this->ownerUserId, 'role_id' => $roleId, 'status' => 'ACTIVE']);
        
        $ownerTeacherId = DB::table('teachers')->insertGetId([
            'user_id' => $this->ownerUserId,
            'institutional_code' => 'DOC-001',
            'identity_number' => '12345678',
            'first_names' => 'John',
            'last_names' => 'Doe',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Docente Ajeno (Para IDOR)
        $otherTeacher = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->otherUserId = $otherTeacher->id;
        DB::table('role_user')->insert(['user_id' => $this->otherUserId, 'role_id' => $roleId, 'status' => 'ACTIVE']);
        
        DB::table('teachers')->insertGetId([
            'user_id' => $this->otherUserId,
            'institutional_code' => 'DOC-002',
            'identity_number' => '87654321',
            'first_names' => 'Jane',
            'last_names' => 'Smith',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_terms')->insert(['id' => 1, 'name' => '2026-I', 'start_date' => '2026-02-01', 'end_date' => '2026-07-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subjects')->insert(['id' => 1, 'code' => 'CS101', 'name' => 'Software Engineering', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $this->courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_id' => $ownerTeacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->roomId = DB::table('rooms')->insertGetId([
            'code' => 'AUD-1', 'name' => 'Main Auditorium', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);
    }

    public function test_it_validates_required_fields_and_business_rules(): void
    {
        $ownerTeacher = User::find($this->ownerUserId);
        Sanctum::actingAs($ownerTeacher, ['*']);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/exams", [
            'name' => '', 
            'exam_date' => now()->subDay()->format('Y-m-d'), 
            'start_time' => '08:00:00',
            'duration_minutes' => -10, 
            'room_id' => 999, 
            'rules' => 'No calculators allowed.'
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'exam_date', 'duration_minutes', 'room_id', 'evaluation_type']);
    }

    public function test_it_prevents_idor_when_scheduling_exam(): void
    {
        $otherTeacher = User::find($this->otherUserId);
        Sanctum::actingAs($otherTeacher, ['*']);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/exams", [
            'name' => 'First Midterm',
            'exam_date' => now()->addDays(5)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'room_id' => $this->roomId,
            'evaluation_type' => 'partial',
            'rules' => 'Standard rules apply.'
        ]);

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Forbidden - You do not own this course offering.']);
    }

    public function test_teacher_can_schedule_exam_successfully_with_default_status(): void
    {
        $ownerTeacher = User::find($this->ownerUserId);
        Sanctum::actingAs($ownerTeacher, ['*']);

        $futureDate = now()->addDays(5)->format('Y-m-d');

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/exams", [
            'name' => 'First Midterm',
            'exam_date' => $futureDate,
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'room_id' => $this->roomId,
            'evaluation_type' => 'partial',
            'rules' => 'Standard rules apply.'
        ]);

        $response->assertStatus(201)
                 ->assertJson(['message' => 'Exam scheduled successfully.']);

        $this->assertDatabaseHas('exams', [
            'course_offering_id' => $this->courseOfferingId,
            'room_id' => $this->roomId,
            'name' => 'First Midterm',
            'exam_date' => $futureDate,
            'duration_minutes' => 90,
            'evaluation_type' => 'partial',
            'rules' => 'Standard rules apply.',
            'status' => 'SCHEDULED'
        ]);
    }

    public function test_scheduling_exam_creates_eligible_records_for_active_enrolled_students(): void
    {
        $this->createEnrollmentForStudent('202600001');
        $this->createEnrollmentForStudent('202600002');
        $this->createEnrollmentForStudent('202600003');

        Sanctum::actingAs(User::find($this->ownerUserId), ['*']);

        $response = $this->postJson(
            "/api/v1/course-offerings/{$this->courseOfferingId}/exams",
            $this->validExamPayload('Eligibility Exam')
        );

        $response->assertStatus(201);

        $this->assertDatabaseCount('exam_eligibilities', 3);
        $this->assertSame(
            3,
            DB::table('exam_eligibilities')
                ->where('exam_id', $response->json('data.id'))
                ->where('status', 'ELIGIBLE')
                ->count()
        );
    }

    public function test_scheduling_exam_does_not_create_eligibility_for_inactive_enrollment(): void
    {
        $activeStudentId = $this->createEnrollmentForStudent('202600004');
        $inactiveEnrollmentStudentId = $this->createEnrollmentForStudent(
            '202600005',
            enrollmentStatus: 'INACTIVE'
        );

        Sanctum::actingAs(User::find($this->ownerUserId), ['*']);

        $response = $this->postJson(
            "/api/v1/course-offerings/{$this->courseOfferingId}/exams",
            $this->validExamPayload('Inactive Enrollment Exam')
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('exam_eligibilities', [
            'exam_id' => $response->json('data.id'),
            'student_id' => $activeStudentId,
            'status' => 'ELIGIBLE',
        ]);
        $this->assertDatabaseMissing('exam_eligibilities', [
            'exam_id' => $response->json('data.id'),
            'student_id' => $inactiveEnrollmentStudentId,
        ]);
    }

    public function test_scheduling_exam_does_not_create_eligibility_for_inactive_student(): void
    {
        $activeStudentId = $this->createEnrollmentForStudent('202600006');
        $inactiveStudentId = $this->createEnrollmentForStudent(
            '202600007',
            studentStatus: 'INACTIVE'
        );

        Sanctum::actingAs(User::find($this->ownerUserId), ['*']);

        $response = $this->postJson(
            "/api/v1/course-offerings/{$this->courseOfferingId}/exams",
            $this->validExamPayload('Inactive Student Exam')
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('exam_eligibilities', [
            'exam_id' => $response->json('data.id'),
            'student_id' => $activeStudentId,
            'status' => 'ELIGIBLE',
        ]);
        $this->assertDatabaseMissing('exam_eligibilities', [
            'exam_id' => $response->json('data.id'),
            'student_id' => $inactiveStudentId,
        ]);
    }

    public function test_scheduling_exam_without_enrolled_students_creates_exam_without_eligibilities(): void
    {
        Sanctum::actingAs(User::find($this->ownerUserId), ['*']);

        $response = $this->postJson(
            "/api/v1/course-offerings/{$this->courseOfferingId}/exams",
            $this->validExamPayload('Empty Offering Exam')
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('exams', [
            'id' => $response->json('data.id'),
            'name' => 'Empty Offering Exam',
            'status' => 'SCHEDULED',
        ]);
        $this->assertDatabaseMissing('exam_eligibilities', [
            'exam_id' => $response->json('data.id'),
        ]);
    }

    private function validExamPayload(string $name): array
    {
        return [
            'name' => $name,
            'exam_date' => now()->addDays(5)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'room_id' => $this->roomId,
            'evaluation_type' => 'partial',
            'rules' => 'Standard rules apply.',
        ];
    }

    private function createEnrollmentForStudent(
        string $sisCode,
        string $enrollmentStatus = 'ACTIVE',
        string $studentStatus = 'ACTIVE'
    ): int {
        $careerId = DB::table('careers')->insertGetId([
            'code' => "CAR-{$sisCode}",
            'name' => "Career {$sisCode}",
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentUser = User::factory()->create([
            'status' => $studentStatus,
            'must_change_password' => false,
        ]);

        $studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id,
            'sis_code' => $sisCode,
            'identity_number' => "CI-{$sisCode}",
            'first_names' => 'Student',
            'last_names' => $sisCode,
            'career_id' => $careerId,
            'status' => $studentStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('enrollments')->insert([
            'course_offering_id' => $this->courseOfferingId,
            'student_id' => $studentId,
            'status' => $enrollmentStatus,
            'registered_by' => $this->ownerUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $studentId;
    }
}
