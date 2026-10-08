<?php

namespace Tests\Feature\Api\V1\Eligibility;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ExamEligibility;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
                    ],
                ],
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

    private function uploadCsv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('habilitaciones.csv', $content);
    }

    private function secondEligibility(string $sis = '001234567'): ExamEligibility
    {
        $student = Student::create([
            'user_id' => User::factory()->create()->id,
            'career_id' => $this->student->career_id,
            'sis_code' => $sis, 'identity_number' => '987654321',
            'first_names' => 'Ana', 'last_names' => 'Gómez', 'status' => 'ACTIVE',
        ]);

        return ExamEligibility::create(['exam_id' => $this->exam->id, 'student_id' => $student->id, 'status' => 'ELIGIBLE']);
    }

    public function test_catalog_selection_observations_and_clearing_are_persisted_and_audited(): void
    {
        $base = "/api/v1/exams/{$this->exam->id}/eligibilities";
        $this->actingAs($this->teacherUser)->getJson($base.'/reasons')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['code' => 'INSTITUTIONAL_REQUIREMENT_PENDING', 'label' => 'Requisito institucional pendiente']);
        $this->patchJson($base.'/'.$this->student->id, [
            'status' => 'INELIGIBLE',
            'reason_code' => 'INSTITUTIONAL_REQUIREMENT_PENDING',
            'observations' => 'Documento pendiente',
            'reason' => 'Texto que no debe reemplazar la etiqueta',
        ])->assertOk()->assertJsonPath('data.reason', 'Requisito institucional pendiente')
            ->assertJsonPath('data.observations', 'Documento pendiente');
        $this->getJson($base)->assertOk()->assertJsonPath('data.0.reason_code', 'INSTITUTIONAL_REQUIREMENT_PENDING')
            ->assertJsonPath('data.0.observations', 'Documento pendiente');
        $this->assertDatabaseHas('exam_eligibilities', [
            'id' => $this->eligibility->id, 'evaluated_by' => $this->teacherUser->id,
            'reason_code' => 'INSTITUTIONAL_REQUIREMENT_PENDING', 'observations' => 'Documento pendiente',
        ]);
        $this->patchJson($base.'/'.$this->student->id, ['status' => 'ELIGIBLE'])
            ->assertOk()->assertJsonPath('data.reason', null)->assertJsonPath('data.reason_code', null)
            ->assertJsonPath('data.observations', null);
        $this->assertDatabaseHas('exam_eligibilities', ['id' => $this->eligibility->id, 'reason' => null, 'reason_code' => null, 'observations' => null]);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'EXAM_ELIGIBILITY_UPDATED')->count());
    }

    public function test_invalid_codes_missing_reasons_and_character_limits_are_rejected(): void
    {
        $url = "/api/v1/exams/{$this->exam->id}/eligibilities/{$this->student->id}";
        $this->actingAs($this->teacherUser);
        $this->patchJson($url, ['status' => 'INELIGIBLE', 'reason_code' => 'UNKNOWN', 'reason' => 'Legacy'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason_code');
        $this->patchJson($url, ['status' => 'INELIGIBLE'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'INELIGIBLE', 'reason' => str_repeat('x', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($url, ['status' => 'INELIGIBLE', 'reason_code' => 'OTHER', 'observations' => str_repeat('á', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('observations');
        $this->patchJson($url, ['status' => 'INELIGIBLE', 'reason_code' => 'OTHER', 'observations' => str_repeat('á', 500)])
            ->assertOk();
    }

    public function test_legacy_reason_and_all_photo_representations_remain_available(): void
    {
        $this->eligibility->update(['status' => 'INELIGIBLE', 'reason' => 'Motivo histórico']);
        $base = "/api/v1/exams/{$this->exam->id}/eligibilities";
        $this->actingAs($this->teacherUser)->getJson($base)->assertOk()
            ->assertJsonPath('data.0.reason', 'Motivo histórico')
            ->assertJsonPath('data.0.reason_code', null)->assertJsonPath('data.0.observations', null)
            ->assertJsonPath('data.0.profile_photo_url', null);
        $user = $this->student->user;
        $user->update(['profile_photo' => 'profile-photos/student.png']);
        $this->getJson($base)->assertOk()->assertJsonPath('data.0.profile_photo_url', asset('storage/profile-photos/student.png'));
        $user->update(['profile_photo' => 'https://example.com/student.png']);
        $this->getJson($base)->assertOk()->assertJsonPath('data.0.profile_photo_url', 'https://example.com/student.png');
        $this->patchJson($base.'/'.$this->student->id, ['status' => 'INELIGIBLE', 'reason' => 'Texto libre anterior'])
            ->assertOk()->assertJsonPath('data.reason', 'Texto libre anterior')->assertJsonPath('data.reason_code', null);
    }

    public function test_bulk_updates_valid_rows_and_reports_invalid_rows_without_creating_records(): void
    {
        $second = $this->secondEligibility();
        $csv = "sis_code,status,reason_code,observations\n{$this->student->sis_code},INELIGIBLE,OTHER,\"Comentario, adicional\"\n001234567,ELIGIBLE,,\n999999999,INELIGIBLE,OTHER,Sin registro\n";
        $this->actingAs($this->teacherUser)->postJson("/api/v1/exams/{$this->exam->id}/eligibilities/bulk", ['file' => $this->uploadCsv($csv)])
            ->assertOk()->assertJsonPath('data.total_rows', 3)->assertJsonPath('data.updated_rows', 2)
            ->assertJsonPath('data.failed_rows', 1)->assertJsonPath('data.errors.0.row', 4)
            ->assertJsonPath('data.errors.0.sis_code', '999999999');
        $this->assertDatabaseHas('exam_eligibilities', ['id' => $this->eligibility->id, 'reason_code' => 'OTHER', 'observations' => 'Comentario, adicional']);
        $this->assertDatabaseHas('exam_eligibilities', ['id' => $second->id, 'status' => 'ELIGIBLE', 'reason' => null]);
        $this->assertDatabaseCount('exam_eligibilities', 2);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'EXAM_ELIGIBILITY_UPDATED')->count());
    }

    public function test_bulk_rejects_every_duplicate_sis_row(): void
    {
        $sis = $this->student->sis_code;
        $csv = "sis_code,status,reason_code,observations\n{$sis},INELIGIBLE,OTHER,Uno\n{$sis},ELIGIBLE,,\n";
        $this->actingAs($this->teacherUser)->postJson("/api/v1/exams/{$this->exam->id}/eligibilities/bulk", ['file' => $this->uploadCsv($csv)])
            ->assertOk()->assertJsonPath('data.updated_rows', 0)->assertJsonPath('data.failed_rows', 2);
        $this->assertDatabaseHas('exam_eligibilities', ['id' => $this->eligibility->id, 'status' => 'ELIGIBLE']);
    }

    public function test_bulk_row_errors_do_not_change_existing_states(): void
    {
        $second = $this->secondEligibility();
        $csv = "sis_code,status,reason_code,observations\n{$this->student->sis_code},INELIGIBLE,INVALID,\n001234567,INELIGIBLE,,\nmissing-columns\n";
        $this->actingAs($this->teacherUser)->postJson("/api/v1/exams/{$this->exam->id}/eligibilities/bulk", ['file' => $this->uploadCsv($csv)])
            ->assertOk()->assertJsonPath('data.updated_rows', 0)->assertJsonPath('data.failed_rows', 3)
            ->assertJsonStructure(['data' => ['errors' => [['messages' => ['reason_code']]]]]);
        $this->assertSame('ELIGIBLE', $this->eligibility->fresh()->status);
        $this->assertSame('ELIGIBLE', $second->fresh()->status);
    }

    public function test_invalid_files_headers_and_record_limits_do_not_write_data(): void
    {
        $url = "/api/v1/exams/{$this->exam->id}/eligibilities/bulk";
        $header = "sis_code,status,reason_code,observations\n";
        $this->actingAs($this->teacherUser);
        foreach (['bad,header\n', $header, $header."\xFF", $header.str_repeat("999999999,ELIGIBLE,,\n", 1001)] as $csv) {
            $this->postJson($url, ['file' => $this->uploadCsv($csv)])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->postJson($url, ['file' => UploadedFile::fake()->create('too-large.csv', 5121, 'text/csv')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame('ELIGIBLE', $this->eligibility->fresh()->status);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_ELIGIBILITY_UPDATED')->count());
    }

    public function test_bulk_accepts_bom_blank_lines_and_physical_error_line_numbers(): void
    {
        $csv = "\xEF\xBB\xBFsis_code,status,reason_code,observations\r\n\r\n{$this->student->sis_code},INELIGIBLE,OTHER,\"Primera línea\r\nSegunda línea\"\r\n999999999,ELIGIBLE,,\r\n";
        $this->actingAs($this->teacherUser)->postJson("/api/v1/exams/{$this->exam->id}/eligibilities/bulk", ['file' => $this->uploadCsv($csv)])
            ->assertOk()->assertJsonPath('data.total_rows', 2)->assertJsonPath('data.updated_rows', 1)
            ->assertJsonPath('data.errors.0.row', 5);
    }

    public function test_bulk_rolls_back_all_updates_if_persistence_or_audit_fails(): void
    {
        $second = $this->secondEligibility();
        $this->mock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('log')->once()->andReturn(new AuditLog);
            $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Simulated persistence failure'));
        });
        $csv = "sis_code,status,reason_code,observations\n{$this->student->sis_code},INELIGIBLE,OTHER,Uno\n001234567,INELIGIBLE,OTHER,Dos\n";
        $this->actingAs($this->teacherUser)->postJson("/api/v1/exams/{$this->exam->id}/eligibilities/bulk", ['file' => $this->uploadCsv($csv)])
            ->assertStatus(500);
        $this->assertSame('ELIGIBLE', $this->eligibility->fresh()->status);
        $this->assertSame('ELIGIBLE', $second->fresh()->status);
    }

    public function test_catalog_bulk_and_update_are_restricted_to_responsible_teacher(): void
    {
        $base = "/api/v1/exams/{$this->exam->id}/eligibilities";
        $this->getJson($base.'/reasons')->assertUnauthorized();
        $this->postJson($base.'/bulk')->assertUnauthorized();
        $other = User::factory()->create();
        $this->assignTeacherRole($other);
        $this->actingAs($other)->getJson($base.'/reasons')->assertForbidden();
        $this->postJson($base.'/bulk')->assertForbidden();
        $this->patchJson($base.'/'.$this->student->id, ['status' => 'ELIGIBLE'])->assertForbidden();
        $this->actingAs($this->student->user)->getJson($base.'/reasons')->assertForbidden();
        $this->postJson($base.'/bulk')->assertForbidden();
    }

    public function test_bulk_never_updates_eligibilities_belonging_to_another_exam(): void
    {
        $otherExam = $this->exam->replicate();
        $otherExam->save();
        $second = $this->secondEligibility();
        $second->update(['exam_id' => $otherExam->id]);
        $csv = "sis_code,status,reason_code,observations\n001234567,INELIGIBLE,OTHER,Otro examen\n";
        $this->actingAs($this->teacherUser)->postJson("/api/v1/exams/{$this->exam->id}/eligibilities/bulk", ['file' => $this->uploadCsv($csv)])
            ->assertOk()->assertJsonPath('data.updated_rows', 0)->assertJsonPath('data.failed_rows', 1);
        $this->assertSame('ELIGIBLE', $second->fresh()->status);
    }

    public function test_migration_preserves_existing_free_text_reasons(): void
    {
        $migration = require database_path('migrations/2026_10_08_000002_add_reason_code_and_observations_to_exam_eligibilities.php');
        $migration->down();
        DB::table('exam_eligibilities')->where('id', $this->eligibility->id)
            ->update(['status' => 'INELIGIBLE', 'reason' => 'Motivo antiguo']);
        $migration->up();
        $this->assertDatabaseHas('exam_eligibilities', [
            'id' => $this->eligibility->id, 'status' => 'INELIGIBLE',
            'reason' => 'Motivo antiguo', 'reason_code' => null, 'observations' => null,
        ]);
    }

    public function test_malformed_csv_cannot_write_even_preceding_valid_rows(): void
    {
        $second = $this->secondEligibility();
        $csv = "sis_code,status,reason_code,observations\n{$this->student->sis_code},INELIGIBLE,OTHER,Válido\n001234567,INELIGIBLE,OTHER,\"Sin cierre";
        $this->actingAs($this->teacherUser)->postJson("/api/v1/exams/{$this->exam->id}/eligibilities/bulk", ['file' => $this->uploadCsv($csv)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame('ELIGIBLE', $this->eligibility->fresh()->status);
        $this->assertSame('ELIGIBLE', $second->fresh()->status);
    }
}
