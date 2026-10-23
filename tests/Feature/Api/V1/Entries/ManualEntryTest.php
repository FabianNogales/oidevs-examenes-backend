<?php

namespace Tests\Feature\Api\V1\Entries;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ExamEligibility;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentQrToken;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualEntryTest extends TestCase
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
            'exam_date' => now()->addDays(2)->toDateString(),
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
        DB::table('enrollments')->insert(['student_id' => $this->student->id, 'course_offering_id' => $this->exam->course_offering_id, 'registered_by' => $this->teacherUser->id, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function token(array $overrides = []): string
    {
        $uuid = (string) Str::uuid();
        $claims = array_replace(['v' => 2,
            'exp' => now()->addHours(2)->timestamp, 'jti' => $uuid], $overrides);
        StudentQrToken::create(['student_id' => $this->student->id,
            'exam_id' => $this->exam->id, 'token' => $uuid, 'status' => 'ACTIVE', 'generated_at' => now()]);
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $input = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($claims);

        return $input.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $input, config('app.key'), true)), '+/', '-_'), '=');
    }

    private function base(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/entry-control';
    }

    private function manual(array $payload = [], ?int $studentId = null)
    {
        return $this->postJson($this->base().'/students/'.($studentId ?? $this->student->id).'/entries',
            $payload + ['room_id' => $this->exam->room_id]);
    }

    public function test_manual_registers_without_qr_and_ignores_client_audit_fields(): void
    {
        $this->actingAs($this->teacherUser);
        $eligibilityBefore = DB::table('exam_eligibilities')->get()->toJson();
        $enrollmentBefore = DB::table('enrollments')->get()->toJson();
        $this->manual(['verification_method' => 'QR', 'verified_by' => 999, 'status' => 'ANNULLED',
            'entered_at' => '2000-01-01', 'student_id' => 999])->assertCreated()
            ->assertJsonPath('result', 'AUTHORIZED')->assertJsonPath('data.entry.verification_method', 'MANUAL')
            ->assertJsonPath('data.entry.verified_by', $this->teacherUser->id)->assertJsonPath('data.entry.status', 'VALID')
            ->assertJsonPath('data.entry.student_id', $this->student->id)->assertJsonPath('data.entry_status', 'REGISTERED')
            ->assertJsonPath('data.blockers', [])->assertJsonStructure(['processed_at', 'data' => ['entry' => ['entered_at']]]);
        $this->assertDatabaseCount('student_qr_tokens', 0);
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertSame($eligibilityBefore, DB::table('exam_eligibilities')->get()->toJson());
        $this->assertSame($enrollmentBefore, DB::table('enrollments')->get()->toJson());
        $this->getJson($this->base().'/students')->assertOk()->assertJsonPath('summary.registered', 1)->assertJsonPath('summary.pending', 0);
        $this->getJson($this->base().'/students/'.$this->student->id)->assertOk()->assertJsonPath('data.entry_status', 'REGISTERED')
            ->assertJsonPath('data.blockers.0.code', 'ALREADY_REGISTERED');
        $this->getJson($this->base().'/entries?search=Carlos')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.entry.verification_method', 'MANUAL');
    }

    public function test_manual_and_qr_share_duplicate_protection_in_both_orders(): void
    {
        $this->actingAs($this->teacherUser);
        $token = $this->token();
        $first = $this->manual()->assertCreated()->json('data.entry.id');
        $this->manual()->assertConflict()->assertJsonPath('data.entry.id', $first);
        $this->postJson($this->base().'/qr', ['qr_token' => $token, 'room_id' => $this->exam->room_id])
            ->assertConflict()->assertJsonPath('result', 'ALREADY_REGISTERED')->assertJsonPath('data.entry.id', $first)
            ->assertJsonPath('data.entry.verification_method', 'MANUAL');
        DB::table('exam_entries')->where('id', $first)->update(['status' => 'ANNULLED']);
        $second = $this->postJson($this->base().'/qr', ['qr_token' => $token, 'room_id' => $this->exam->room_id])
            ->assertCreated()->json('data.entry.id');
        $this->manual()->assertConflict()->assertJsonPath('data.entry.id', $second)->assertJsonPath('data.entry.verification_method', 'QR');
        DB::table('exam_entries')->where('id', $second)->update(['status' => 'ANNULLED']);
        $this->manual()->assertCreated();
        $this->assertDatabaseCount('exam_entries', 3);
        $this->assertSame(1, DB::table('exam_entries')->where('status', 'VALID')->count());
    }

    public function test_each_condition_is_rechecked_after_favorable_detail(): void
    {
        $this->actingAs($this->teacherUser);
        $this->getJson($this->base().'/students/'.$this->student->id)->assertOk()->assertJsonPath('data.meets_entry_conditions', true);
        $this->student->update(['status' => 'INACTIVE']);
        $this->manual()->assertUnprocessable()->assertJsonPath('result', 'DENIED')->assertJsonPath('data.blockers.0.code', 'STUDENT_INACTIVE');
        $this->student->update(['status' => 'ACTIVE']);
        DB::table('enrollments')->update(['status' => 'INACTIVE']);
        $this->manual()->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'NOT_ENROLLED');
        DB::table('enrollments')->update(['status' => 'ACTIVE']);
        $this->eligibility->update(['status' => 'INELIGIBLE', 'reason' => 'Pendiente', 'observations' => 'Revisar']);
        $this->manual()->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'INELIGIBLE')
            ->assertJsonPath('data.reason', 'Pendiente')->assertJsonPath('data.observations', 'Revisar')->assertJsonPath('data.entry', null);
        $this->eligibility->delete();
        $this->manual()->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'ELIGIBILITY_PENDING');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_wrong_room_and_unavailable_exam_never_create_an_entry(): void
    {
        $this->actingAs($this->teacherUser);
        $room = DB::table('rooms')->insertGetId(['code' => 'B-2', 'name' => 'Otro', 'status' => 'ACTIVE']);
        $this->manual(['room_id' => $room])->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'ROOM_MISMATCH');
        foreach (['CANCELLED', 'FINISHED'] as $status) {
            $this->exam->update(['status' => $status]);
            $this->manual()->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'EXAM_UNAVAILABLE');
        }
        $this->exam->update(['status' => 'SCHEDULED', 'exam_date' => now()->subDays(2)->toDateString()]);
        $this->manual()->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'EXAM_UNAVAILABLE');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_validation_missing_student_and_unassociated_student(): void
    {
        config(['app.debug' => true]);
        $this->actingAs($this->teacherUser);
        $this->postJson($this->base().'/students/'.$this->student->id.'/entries', [])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->manual(['room_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $missingResponse = $this->manual([], 999999)->assertNotFound()
            ->assertExactJson(['message' => 'Estudiante no encontrado.']);
        DB::table('enrollments')->delete();
        $this->eligibility->delete();
        $outsideResponse = $this->manual()->assertNotFound()
            ->assertExactJson(['message' => 'Estudiante no encontrado.']);
        $this->assertSame($missingResponse->getContent(), $outsideResponse->getContent());
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_eligibility_alone_allows_detail_but_not_manual_entry_without_enrollment(): void
    {
        DB::table('enrollments')->where('student_id', $this->student->id)->delete();
        $this->actingAs($this->teacherUser);

        $this->getJson($this->base().'/students/'.$this->student->id)->assertOk()
            ->assertJsonPath('data.enrollment_status', null)->assertJsonPath('data.eligibility_status', 'ELIGIBLE')
            ->assertJsonFragment(['code' => 'NOT_ENROLLED']);
        $this->manual()->assertUnprocessable()->assertJsonPath('result', 'DENIED')
            ->assertJsonPath('data.student.student_id', $this->student->id)
            ->assertJsonFragment(['code' => 'NOT_ENROLLED']);
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_permissions_collaborator_revocation_and_exam_id_manipulation(): void
    {
        $this->manual()->assertUnauthorized();
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->actingAs($user);
        $this->manual()->assertForbidden();
        $this->actingAs($this->teacherUser);
        $this->postJson('/api/v1/exams/'.$this->exam->id.'/collaborators', ['user_id' => $user->id])->assertCreated();
        $other = $this->exam->replicate();
        $other->save();
        $this->actingAs($user);
        $this->postJson('/api/v1/exams/'.$other->id.'/entry-control/students/'.$this->student->id.'/entries', ['room_id' => $other->room_id])->assertForbidden();
        $this->manual()->assertCreated()->assertJsonPath('data.entry.verified_by', $user->id);
        DB::table('exam_collaborators')->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->manual()->assertForbidden();
        $this->assertDatabaseCount('exam_entries', 1);
    }
}
