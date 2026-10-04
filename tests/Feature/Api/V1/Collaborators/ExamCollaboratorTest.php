<?php

namespace Tests\Feature\Api\V1\Collaborators;

use App\Models\Career;
use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExamCollaboratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    /**
     * Crea un profesor válido en la tabla `teachers`.
     */
    private function createTeacher(?User $user = null): Teacher
    {
        $user =$user ?? User::factory()->create();

        return Teacher::create([
            'user_id' => $user->id,
            'institutional_code' => 'PROF-' . rand(1000, 9999),
            'identity_number' => (string) rand(1000000, 9999999),
            'first_names' => 'Carlos',
            'last_names' => 'Docente',
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Crea una carrera de prueba.
     */
    private function createCareer(): Career
    {
        return Career::firstOrCreate(
            ['code' => 'SIS'],
            [
                'name' => 'Ingeniería de Sistemas',
                'status' => 'ACTIVE',
            ]
        );
    }

    /**
     * Crea un estudiante válido en la tabla `students`.
     */
    private function createStudent(?User $user = null): Student
    {
        $user =$user ?? User::factory()->create();
        $career =$this->createCareer();

        return Student::create([
            'user_id' => $user->id,
            'career_id' => $career->id,
            'sis_code' => '2020' . rand(10000, 99999),
            'identity_number' => (string) rand(1000000, 9999999),
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Crea un aula válida en la tabla `rooms`.
     */
    private function createRoom(): int
    {
        return DB::table('rooms')->insertGetId([
            'code' => 'A-617',
            'name' => 'Aula 617',
            'location' => 'Edificio Nuevo - Bloque A',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Crea una oferta de curso relacionando materia, gestión académica y profesor.
     */
    private function createCourseOffering(?Teacher $teacher = null): int
    {
        if (!$teacher) {
            $teacher =$this->createTeacher();
        }

        $subjectId = DB::table('subjects')->insertGetId([
            'code' => 'MAT-' . rand(100, 999),
            'name' => 'Materia de Prueba',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $academicTermId = DB::table('academic_terms')->insertGetId([
            'name' => 'Gestión 2/2026',
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('course_offerings')->insertGetId([
            'subject_id' => $subjectId,
            'academic_term_id' => $academicTermId,
            'teacher_id' => $teacher->id,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Crea un examen de prueba con todos sus campos obligatorios.
     */
    private function createExam(?Teacher $teacher = null): Exam
    {
        $teacher =$teacher ?? $this->createTeacher();$courseOfferingId = $this->createCourseOffering($teacher);
        $roomId =$this->createRoom();

        return Exam::create([
            'course_offering_id' => $courseOfferingId,
            'room_id' => $roomId,
            'name' => 'Examen Parcial',
            'exam_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '08:15:00',
            'duration_minutes' => 90,
            'status' => 'SCHEDULED',
            'evaluation_type' => 'partial',
            'created_by' => $teacher->user_id,
        ]);
    }

    /** @test */
    public function teacher_can_assign_a_student_as_collaborator(): void
    {
        /** @var User $teacherUser */$teacherUser = User::factory()->create();
        $teacher =$this->createTeacher($teacherUser);$student = $this->createStudent();$exam = $this->createExam($teacher);

        $response = $this->actingAs($teacherUser)
            ->postJson("/api/v1/exams/{$exam->id}/collaborators", [
                'student_id' => $student->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Colaborador designado exitosamente');

        $this->assertDatabaseHas('exam_collaborators', [
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'status' => 'ACTIVE',
        ]);
    }

    /** @test */
    public function teacher_can_revoke_collaborator_access(): void
    {
        /** @var User $teacherUser */$teacherUser = User::factory()->create();
        $teacher =$this->createTeacher($teacherUser);$student = $this->createStudent();$exam = $this->createExam($teacher);

        $collaborator = ExamCollaborator::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'assigned_by' => $teacherUser->id,
            'access_code_hash' => hash('sha256', 'test_code'),
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($teacherUser)
            ->deleteJson("/api/v1/exams/{$exam->id}/collaborators/{$collaborator->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Autorización de colaborador revocada exitosamente');

        $this->assertDatabaseHas('exam_collaborators', [
            'id' => $collaborator->id,
            'status' => 'REVOKED',
        ]);
    }

    /** @test */
    public function student_can_view_their_assigned_collaborations(): void
    {
        /** @var User $teacherUser */
        $teacherUser = User::factory()->create();$teacher = $this->createTeacher($teacherUser);

        /** @var User $studentUser */$studentUser = User::factory()->create();
        $student =$this->createStudent($studentUser);$exam = $this->createExam($teacher);

        ExamCollaborator::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'assigned_by' => $teacherUser->id,
            'access_code_hash' => hash('sha256', 'test_code'),
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($studentUser)
            ->getJson('/api/v1/student/collaborations');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}