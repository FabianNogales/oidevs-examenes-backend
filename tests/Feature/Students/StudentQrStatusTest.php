<?php

namespace Tests\Feature\Api\V1\Students;

use App\Enums\RoleName;
use App\Models\User;
use App\Services\Students\StudentQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentQrStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $studentUser;
    protected User $teacherUser;
    protected int $studentId;
    protected int $courseOfferingId;
    protected int $roomId;
    protected int $examId;

    protected function setUp(): void
    {
        parent::setUp();

        $studentRoleId = DB::table('roles')->insertGetId([
            'name' => RoleName::ESTUDIANTE->value,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacherRoleId = DB::table('roles')->insertGetId([
            'name' => RoleName::DOCENTE->value,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminUser = User::factory()->create(['status' => 'ACTIVE']);
        $this->studentUser = User::factory()->create(['status' => 'ACTIVE']);
        $this->teacherUser = User::factory()->create(['status' => 'ACTIVE']);

        DB::table('role_user')->insert([
            [
                'user_id' => $this->studentUser->id,
                'role_id' => $studentRoleId,
                'status' => 'ACTIVE',
                'assigned_at' => now(),
            ],
            [
                'user_id' => $this->teacherUser->id,
                'role_id' => $teacherRoleId,
                'status' => 'ACTIVE',
                'assigned_at' => now(),
            ],
        ]);

        $careerId = DB::table('careers')->insertGetId([
            'code' => 'SIS-101',
            'name' => 'Ingenieria de Sistemas',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->studentId = DB::table('students')->insertGetId([
            'user_id' => $this->studentUser->id,
            'sis_code' => '20260001',
            'identity_number' => '1234567',
            'first_names' => 'Carlos',
            'last_names' => 'Student',
            'career_id' => $careerId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacherId = DB::table('teachers')->insertGetId([
            'user_id' => $this->teacherUser->id,
            'institutional_code' => 'T-101',
            'identity_number' => '7654321',
            'first_names' => 'Profesor',
            'last_names' => 'Docente',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->roomId = DB::table('rooms')->insertGetId([
            'code' => 'LAB-1',
            'name' => 'Laboratorio 1',
            'location' => 'Bloque A',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subjectId = DB::table('subjects')->insertGetId([
            'code' => 'CS101',
            'name' => 'Software Engineering',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $academicTermId = DB::table('academic_terms')->insertGetId([
            'name' => '2026-I',
            'start_date' => '2026-02-01',
            'end_date' => '2026-07-01',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => $subjectId,
            'academic_term_id' => $academicTermId,
            'teacher_id' => $teacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('enrollments')->insert([
            'course_offering_id' => $this->courseOfferingId,
            'student_id' => $this->studentId,
            'status' => 'ACTIVE',
            'registered_by' => $adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->examId = $this->createExam('ACTIVE');
        $this->setEligibility($this->studentId, $this->examId, 'ELIGIBLE');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_returns_upcoming_status_more_than_24h_before_exam(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 09:59:00', 'America/La_Paz'));

        $exams = app(StudentQrService::class)->getExamsForStudent($this->studentId);

        $this->assertEquals('UPCOMING', $exams->first()['qr_status']);
        $this->assertFalse($exams->first()['is_qr_available']);
        $this->assertNull($exams->first()['qr_code_base64']);
    }

    public function test_it_returns_available_status_within_24h_window_and_during_exam(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00', 'America/La_Paz'));

        $exams = app(StudentQrService::class)->getExamsForStudent($this->studentId);

        $this->assertEquals('AVAILABLE', $exams->first()['qr_status']);
        $this->assertTrue($exams->first()['is_qr_available']);
        $this->assertNotNull($exams->first()['qr_code_base64']);
    }

    public function test_it_returns_finished_status_after_exam_ends(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 11:31:00', 'America/La_Paz'));

        $exams = app(StudentQrService::class)->getExamsForStudent($this->studentId);

        $this->assertEquals('FINISHED', $exams->first()['qr_status']);
        $this->assertFalse($exams->first()['is_qr_available']);
        $this->assertNull($exams->first()['qr_code_base64']);
    }

    public function test_scheduled_exam_created_by_teacher_is_visible_and_generates_qr_for_eligible_student(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00', 'America/La_Paz'));
        Sanctum::actingAs($this->teacherUser, ['*']);
        // The baseline exam already reserves LAB-1 at this time.
        $integrationRoomId = DB::table('rooms')->insertGetId([
            'code' => 'QR-INTEGRATION', 'name' => 'Aula integración QR', 'status' => 'ACTIVE',
        ]);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/exams", [
            'name' => 'Scheduled Integration Exam',
            'exam_date' => '2026-06-15',
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'room_id' => $integrationRoomId,
            'evaluation_type' => 'partial',
            'rules' => 'Portar carnet de identidad.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'SCHEDULED');

        $examId = $response->json('data.id');
        $this->setEligibility($this->studentId, $examId, 'ELIGIBLE');

        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson('/api/v1/students/exams')
            ->assertStatus(200)
            ->assertJsonFragment([
                'exam_id' => $examId,
                'qr_status' => 'AVAILABLE',
                'is_qr_available' => true,
            ]);

        $this->getJson("/api/v1/students/exams/{$examId}/qr")
            ->assertStatus(200)
            ->assertJsonPath('data.exam_id', $examId)
            ->assertJsonPath('data.subject', 'Software Engineering')
            ->assertJsonStructure([
                'data' => [
                    'qr_code_base64',
                    'token',
                ],
            ]);
    }

    public function test_enrolled_student_without_eligibility_record_sees_exam_but_qr_is_denied(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00', 'America/La_Paz'));
        DB::table('exam_eligibilities')
            ->where('student_id', $this->studentId)
            ->where('exam_id', $this->examId)
            ->delete();

        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson('/api/v1/students/exams')
            ->assertStatus(200)
            ->assertJsonFragment([
                'exam_id' => $this->examId,
                'qr_status' => 'PENDING_ELIGIBILITY',
                'is_qr_available' => false,
            ]);

        $this->getJson("/api/v1/students/exams/{$this->examId}/qr")
            ->assertStatus(403)
            ->assertJson([
                'message' => 'El estudiante no se encuentra habilitado para este examen.',
            ]);
    }

    public function test_not_eligible_student_sees_exam_but_qr_is_denied(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00', 'America/La_Paz'));
        $this->setEligibility($this->studentId, $this->examId, 'NOT_ELIGIBLE');

        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson('/api/v1/students/exams')
            ->assertStatus(200)
            ->assertJsonFragment([
                'exam_id' => $this->examId,
                'qr_status' => 'NOT_ELIGIBLE',
                'is_qr_available' => false,
            ]);

        $this->getJson("/api/v1/students/exams/{$this->examId}/qr")
            ->assertStatus(403)
            ->assertJson([
                'message' => 'El estudiante no se encuentra habilitado para este examen.',
            ]);
    }

    public function test_ineligible_student_cannot_get_qr(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00', 'America/La_Paz'));
        $this->setEligibility($this->studentId, $this->examId, 'NOT_ELIGIBLE');

        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson("/api/v1/students/exams/{$this->examId}/qr")
            ->assertStatus(403)
            ->assertJson([
                'message' => 'El estudiante no se encuentra habilitado para este examen.',
            ]);
    }

    public function test_student_without_enrollment_cannot_get_qr(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00', 'America/La_Paz'));
        [$otherUser, $otherStudentId] = $this->createStudent('20260002');
        $this->setEligibility($otherStudentId, $this->examId, 'ELIGIBLE');

        Sanctum::actingAs($otherUser, ['*']);

        $this->getJson("/api/v1/students/exams/{$this->examId}/qr")
            ->assertStatus(403);
    }

    public function test_qr_is_denied_before_24h_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 09:59:00', 'America/La_Paz'));
        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson("/api/v1/students/exams/{$this->examId}/qr")
            ->assertStatus(403);
    }

    public function test_qr_is_denied_after_exam_has_finished(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 11:31:00', 'America/La_Paz'));
        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson("/api/v1/students/exams/{$this->examId}/qr")
            ->assertStatus(403)
            ->assertJson([
                'message' => 'El examen ya ha finalizado.',
            ]);
    }

    public function test_student_without_own_eligibility_record_cannot_get_another_students_qr(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00', 'America/La_Paz'));
        [, $otherStudentId] = $this->createStudent('20260003');
        DB::table('enrollments')->insert([
            'course_offering_id' => $this->courseOfferingId,
            'student_id' => $otherStudentId,
            'status' => 'ACTIVE',
            'registered_by' => $this->teacherUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $examId = $this->createExam('SCHEDULED', 'Other Student Exam');
        $this->setEligibility($otherStudentId, $examId, 'ELIGIBLE');

        Sanctum::actingAs($this->studentUser, ['*']);

        $this->getJson("/api/v1/students/exams/{$examId}/qr")
            ->assertStatus(403)
            ->assertJson([
                'message' => 'El estudiante no se encuentra habilitado para este examen.',
            ]);
    }

    private function createExam(string $status, string $name = 'Midterm Test'): int
    {
        return DB::table('exams')->insertGetId([
            'course_offering_id' => $this->courseOfferingId,
            'room_id' => $this->roomId,
            'name' => $name,
            'exam_date' => '2026-06-15',
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'rules' => 'None',
            'status' => $status,
            'created_by' => $this->teacherUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setEligibility(int $studentId, int $examId, string $status): void
    {
        DB::table('exam_eligibilities')->updateOrInsert(
            [
                'exam_id' => $examId,
                'student_id' => $studentId,
            ],
            [
                'status' => $status,
                'reason' => null,
                'evaluated_by' => $this->teacherUser->id,
                'evaluated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function createStudent(string $sisCode): array
    {
        $roleId = DB::table('roles')
            ->where('name', RoleName::ESTUDIANTE->value)
            ->value('id');

        $careerId = DB::table('careers')->value('id');

        $user = User::factory()->create(['status' => 'ACTIVE']);

        DB::table('role_user')->insert([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'user_id' => $user->id,
            'sis_code' => $sisCode,
            'identity_number' => "CI-{$sisCode}",
            'first_names' => 'Otro',
            'last_names' => 'Estudiante',
            'career_id' => $careerId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $studentId];
    }
}
