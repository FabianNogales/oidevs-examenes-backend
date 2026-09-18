<?php

namespace Tests\Feature\Api\V1\Students;

use App\Models\User;
use App\Services\Students\StudentQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentQrStatusTest extends TestCase
{
    use RefreshDatabase;

    protected int $studentId;
    protected int $examId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Usuarios base
        $adminUser = User::factory()->create(['status' => 'ACTIVE']);
        $studentUser = User::factory()->create(['status' => 'ACTIVE']);
        $teacherUser = User::factory()->create(['status' => 'ACTIVE']);

        // 2. Carrera
        $careerId = DB::table('careers')->insertGetId([
            'code' => 'SIS-101',
            'name' => 'Ingeniería de Sistemas',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Estudiante
        $this->studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id,
            'sis_code' => '20260001',
            'identity_number' => '1234567',
            'first_names' => 'Carlos',
            'last_names' => 'Student',
            'career_id' => $careerId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Profesor
        $teacherId = DB::table('teachers')->insertGetId([
            'user_id' => $teacherUser->id,
            'institutional_code' => 'T-101',
            'identity_number' => '7654321',
            'first_names' => 'Profesor',
            'last_names' => 'Docente',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Aula (Room)
        $roomId = DB::table('rooms')->insertGetId([
            'code' => 'LAB-1',
            'name' => 'Laboratorio 1',
            'location' => 'Bloque A',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 6. Materia y Gestión Académica
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

        // 7. Oferta de Curso
        $courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => $subjectId,
            'academic_term_id' => $academicTermId,
            'teacher_id' => $teacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 8. Inscripción del Estudiante
        DB::table('enrollments')->insert([
            'course_offering_id' => $courseOfferingId,
            'student_id' => $this->studentId,
            'status' => 'ACTIVE',
            'registered_by' => $adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 9. Examen (15 de Junio de 2026, 10:00 AM, 90 min)
        $this->examId = DB::table('exams')->insertGetId([
            'course_offering_id' => $courseOfferingId,
            'room_id' => $roomId,
            'name' => 'Midterm Test',
            'exam_date' => '2026-06-15',
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'rules' => 'None',
            'status' => 'ACTIVE',
            'created_by' => $teacherUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_returns_upcoming_status_more_than_24h_before_exam(): void
    {
        // 15 Junio 09:59 AM del día anterior -> Falta 24h y 1 minuto
        Carbon::setTestNow(Carbon::parse('2026-06-14 09:59:00', 'America/La_Paz'));

        $service = new StudentQrService();
        $exams = $service->getExamsForStudent($this->studentId);

        $this->assertEquals('UPCOMING', $exams->first()['qr_status']);
        $this->assertFalse($exams->first()['is_qr_available']);
        $this->assertNull($exams->first()['qr_code_base64']);
    }

    public function test_it_returns_available_status_within_24h_window_and_during_exam(): void
    {
        // Exactamente 24 horas antes (14 Junio 10:00 AM)
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00', 'America/La_Paz'));

        $service = new StudentQrService();
        $exams = $service->getExamsForStudent($this->studentId);

        $this->assertEquals('AVAILABLE', $exams->first()['qr_status']);
        $this->assertTrue($exams->first()['is_qr_available']);
        $this->assertNotNull($exams->first()['qr_code_base64']);
    }

    public function test_it_returns_finished_status_after_exam_ends(): void
    {
        // Examen finaliza a las 11:30 AM (10:00 + 90 min). Probamos 11:31 AM
        Carbon::setTestNow(Carbon::parse('2026-06-15 11:31:00', 'America/La_Paz'));

        $service = new StudentQrService();
        $exams = $service->getExamsForStudent($this->studentId);

        $this->assertEquals('FINISHED', $exams->first()['qr_status']);
        $this->assertFalse($exams->first()['is_qr_available']);
        $this->assertNull($exams->first()['qr_code_base64']);
    }
}