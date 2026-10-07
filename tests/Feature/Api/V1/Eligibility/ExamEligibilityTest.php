<?php

namespace Tests\Feature\Api\V1\Eligibility;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ExamEligibility;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExamEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;
    private Teacher $teacher;
    private Exam $exam;
    private Student $student;
    private ExamEligibility $eligibility;

    /**
     * Auxiliar para crear el rol DOCENTE activo y asignarlo activo al usuario
     */
    private function assignTeacherRole(User $user): void
    {
        $roleName = RoleName::DOCENTE->value;
        $activeStatus = UserStatus::ACTIVE->value;

        // 1. Obtener o crear el rol DOCENTE activo
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['status' => $activeStatus]
        );

        // 2. Adjuntar el rol en la pivote role_user con status ACTIVE
        $user->roles()->attach($role->id, [
            'status' => $activeStatus,
            'assigned_at' => now(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Crear Usuario Docente activo y sin cambio de contraseña pendiente
        /** @var User $teacherUser */
        $this->teacherUser = User::factory()->create([
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
        ]);

        // 2. Asignar el rol DOCENTE
        $this->assignTeacherRole($this->teacherUser);

        // 3. Crear modelo Teacher vinculado al usuario
        $this->teacher = Teacher::create([
            'user_id' => $this->teacherUser->id,
            'institutional_code' => 'DOC-001',
            'identity_number' => '44556677',
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
            'status' => 'ACTIVE',
        ]);

        // 4. Tablas maestras requeridas para la oferta académica
        $subjectId = DB::table('subjects')->insertGetId([
            'code' => 'SIS-101',
            'name' => 'Programación I',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $termId = DB::table('academic_terms')->insertGetId([
            'name' => '2-2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-20',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $careerId = DB::table('careers')->insertGetId([
            'code' => 'SYS',
            'name' => 'Ingeniería de Sistemas',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roomId = DB::table('rooms')->insertGetId([
            'code' => 'A-101',
            'name' => 'Aula 101',
            'location' => 'Edificio Central - Piso 1',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Oferta académica explícitamente ligada al Teacher ID
        $courseOffering = CourseOffering::create([
            'subject_id' => $subjectId,
            'academic_term_id' => $termId,
            'teacher_id' => $this->teacher->id,
            'status' => 'ACTIVE',
        ]);

        // 6. Examen vinculado a la oferta académica
        $this->exam = Exam::create([
            'course_offering_id' => $courseOffering->id,
            'room_id' => $roomId,
            'name' => 'Primer Examen Parcial',
            'exam_date' => '2026-10-15',
            'start_time' => '08:00:00',
            'duration_minutes' => 90,
            'status' => 'SCHEDULED',
            'created_by' => $this->teacherUser->id,
            'evaluation_type' => 'PARTIAL',
        ]);

        // 7. Estudiante
        $studentUser = User::factory()->create(['email' => 'estudiante@test.com']);
        $this->student = Student::create([
            'user_id' => $studentUser->id,
            'career_id' => $careerId,
            'sis_code' => '20230001',
            'identity_number' => '12345678',
            'first_names' => 'Carlos',
            'last_names' => 'Pérez',
            'status' => 'ACTIVE',
        ]);

        // 8. Estado de elegibilidad del estudiante
        $this->eligibility = ExamEligibility::create([
            'exam_id' => $this->exam->id,
            'student_id' => $this->student->id,
            'status' => 'ELIGIBLE',
        ]);
    }

    /** @test */
    public function teacher_can_list_student_eligibilities_for_their_exam(): void
    {
        $response = $this->actingAs($this->teacherUser)
            ->getJson("/api/v1/exams/{$this->exam->id}/eligibilities");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'student_id',
                        'sis_code',
                        'identity_number',
                        'first_names',
                        'last_names',
                        'email',
                        'status',
                        'reason',
                        'evaluated_by',
                        'evaluated_at',
                    ]
                ]
            ]);
    }

    /** @test */
    public function teacher_can_filter_eligibilities_by_status_and_search(): void
    {
        $studentUser2 = User::factory()->create();
        $careerId = DB::table('careers')->first()->id;

        $student2 = Student::create([
            'user_id' => $studentUser2->id,
            'career_id' => $careerId,
            'sis_code' => '20230002',
            'identity_number' => '87654321',
            'first_names' => 'Ana',
            'last_names' => 'Gómez',
            'status' => 'ACTIVE',
        ]);

        ExamEligibility::create([
            'exam_id' => $this->exam->id,
            'student_id' => $student2->id,
            'status' => 'INELIGIBLE',
            'reason' => 'Sanción administrativa',
        ]);

        $responseFilter = $this->actingAs($this->teacherUser)
            ->getJson("/api/v1/exams/{$this->exam->id}/eligibilities?status=INELIGIBLE");

        $responseFilter->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['sis_code' => '20230002']);
    }

    /** @test */
    public function teacher_can_ineligible_a_student_with_a_reason(): void
    {
        $payload = [
            'status' => 'INELIGIBLE',
            'reason' => 'No cumple con el porcentaje mínimo de asistencia.',
        ];

        $response = $this->actingAs($this->teacherUser)
            ->patchJson("/api/v1/exams/{$this->exam->id}/eligibilities/{$this->student->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'INELIGIBLE')
            ->assertJsonPath('data.reason', 'No cumple con el porcentaje mínimo de asistencia.');
    }

    /** @test */
    public function ineligibility_requires_a_reason(): void
    {
        $payload = [
            'status' => 'INELIGIBLE',
            'reason' => '',
        ];

        $response = $this->actingAs($this->teacherUser)
            ->patchJson("/api/v1/exams/{$this->exam->id}/eligibilities/{$this->student->id}", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    /** @test */
    public function another_teacher_cannot_manage_eligibilities_of_an_unassigned_exam(): void
    {
        /** @var User $otherTeacherUser */
        $otherTeacherUser = User::factory()->create([
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
        ]);
        $this->assignTeacherRole($otherTeacherUser);

        Teacher::create([
            'user_id' => $otherTeacherUser->id,
            'institutional_code' => 'DOC-002',
            'identity_number' => '99887766',
            'first_names' => 'Maria',
            'last_names' => 'Lopez',
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($otherTeacherUser)
            ->getJson("/api/v1/exams/{$this->exam->id}/eligibilities");

        $response->assertStatus(403);
    }
}