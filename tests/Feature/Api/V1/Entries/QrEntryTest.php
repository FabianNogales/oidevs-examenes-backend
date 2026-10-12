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
use App\Services\Students\StudentQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QrEntryTest extends TestCase
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

    private function endpoint(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/entry-control';
    }

    private function token(array $overrides = []): string
    {
        $uuid = (string) Str::uuid();
        $claims = array_replace(['std' => $this->student->id, 'exm' => $this->exam->id,
            'exp' => now()->addHours(2)->timestamp, 'jti' => $uuid], $overrides);
        StudentQrToken::create(['student_id' => $this->student->id,
            'exam_id' => $this->exam->id, 'token' => $uuid, 'status' => 'ACTIVE', 'generated_at' => now()]);
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $input = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($claims);

        return $input.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $input, config('app.key'), true)), '+/', '-_'), '=');
    }

    private function scan(string $token, ?int $room = null)
    {
        return $this->postJson($this->endpoint().'/qr', ['qr_token' => $token, 'room_id' => $room ?? $this->exam->room_id]);
    }

    public function test_registers_once_and_updates_hu13_without_changing_eligibility_or_enrollment(): void
    {
        $this->actingAs($this->teacherUser);
        $token = $this->token();
        $before = DB::table('exam_eligibilities')->get()->toJson();
        $enrollments = DB::table('enrollments')->get()->toJson();
        $response = $this->scan($token)->assertCreated()->assertJsonPath('result', 'AUTHORIZED')
            ->assertJsonPath('data.entry.verification_method', 'QR')->assertJsonPath('data.entry.status', 'VALID')
            ->assertJsonPath('data.entry.verified_by', $this->teacherUser->id)->assertJsonPath('data.blockers', []);
        $this->scan($token)->assertConflict()->assertJsonPath('result', 'ALREADY_REGISTERED')
            ->assertJsonPath('data.entry.id', $response->json('data.entry.id'));
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertSame($before, DB::table('exam_eligibilities')->get()->toJson());
        $this->assertSame($enrollments, DB::table('enrollments')->get()->toJson());
        $this->getJson($this->endpoint().'/students')->assertOk()->assertJsonPath('summary.registered', 1)
            ->assertJsonPath('summary.pending', 0)->assertJsonPath('data.0.entry_status', 'REGISTERED');
    }

    public function test_rejects_invalid_expired_revoked_and_mismatched_claims(): void
    {
        $this->actingAs($this->teacherUser);
        foreach (['garbage', $this->token(['exp' => now()->timestamp]), $this->token(['std' => 999]),
            $this->token(['exm' => 999]), $this->token(['jti' => (string) Str::uuid()])] as $token) {
            $this->scan($token)->assertUnprocessable()->assertJsonPath('result', 'INVALID_QR')->assertJsonPath('data', null);
        }
        $token = $this->token();
        $parts = explode('.', $token);
        $this->scan($parts[0].'.'.$parts[1].'.'.str_repeat('A', 43))->assertUnprocessable()->assertJsonPath('result', 'INVALID_QR');
        StudentQrToken::query()->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->scan($token)->assertUnprocessable()->assertJsonPath('result', 'INVALID_QR');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_valid_other_exam_token_does_not_disclose_student(): void
    {
        $this->actingAs($this->teacherUser);
        $token = $this->token();
        $other = $this->exam->replicate();
        $other->name = 'Otro examen';
        $other->save();
        $this->postJson('/api/v1/exams/'.$other->id.'/entry-control/qr', ['qr_token' => $token, 'room_id' => $other->room_id])
            ->assertUnprocessable()->assertJsonPath('result', 'WRONG_EXAM')->assertJsonPath('data', null);
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_revalidates_each_condition_and_returns_reason(): void
    {
        $this->actingAs($this->teacherUser);
        $token = $this->token();
        $this->student->update(['status' => 'INACTIVE']);
        $this->scan($token)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'STUDENT_INACTIVE');
        $this->student->update(['status' => 'ACTIVE']);
        DB::table('enrollments')->update(['status' => 'INACTIVE']);
        $this->scan($token)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'NOT_ENROLLED');
        DB::table('enrollments')->update(['status' => 'ACTIVE']);
        $this->eligibility->update(['status' => 'INELIGIBLE', 'reason' => 'Pendiente', 'observations' => 'Revisar']);
        $this->scan($token)->assertUnprocessable()->assertJsonPath('result', 'DENIED')
            ->assertJsonPath('data.blockers.0.code', 'INELIGIBLE')->assertJsonPath('data.reason', 'Pendiente')
            ->assertJsonPath('data.observations', 'Revisar');
        $this->eligibility->delete();
        $this->scan($token)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'ELIGIBILITY_PENDING');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_denies_wrong_room_and_unavailable_exam_and_validates_parameters(): void
    {
        $this->actingAs($this->teacherUser);
        $token = $this->token();
        $room = DB::table('rooms')->insertGetId(['code' => 'B-2', 'name' => 'Otro', 'status' => 'ACTIVE']);
        $this->scan($token, $room)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'ROOM_MISMATCH');
        $this->exam->update(['status' => 'CANCELLED']);
        $this->scan($token)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'EXAM_UNAVAILABLE');
        $this->exam->update(['status' => 'SCHEDULED', 'exam_date' => now()->subDays(2)->toDateString()]);
        $this->scan($token)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'EXAM_UNAVAILABLE');
        $this->postJson($this->endpoint().'/qr', [])->assertUnprocessable()->assertJsonValidationErrors(['qr_token', 'room_id']);
        $this->scan($token, 99999)->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_history_is_scoped_searchable_paginated_and_includes_annulled_entries(): void
    {
        $this->actingAs($this->teacherUser);
        $this->scan($this->token())->assertCreated();
        DB::table('exam_entries')->update(['status' => 'ANNULLED']);
        $this->scan($this->token())->assertCreated();
        foreach (['Carlos', 'Pérez', 'Carlos Pérez', '20230001', '12345678'] as $search) {
            $this->getJson($this->endpoint().'/entries?per_page=1&search='.urlencode($search))
                ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
                ->assertJsonPath('data.0.entry.status', 'VALID');
        }
        $this->getJson($this->endpoint().'/entries?page=2&per_page=1')->assertOk()->assertJsonPath('data.0.entry.status', 'ANNULLED');
        $this->getJson($this->endpoint().'/entries?search=nadie')->assertOk()->assertJsonPath('meta.total', 0);
        $other = $this->exam->replicate();
        $other->save();
        $this->getJson('/api/v1/exams/'.$other->id.'/entry-control/entries')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_authorization_and_current_collaborator_revocation_protect_both_routes(): void
    {
        $token = $this->token();
        $this->scan($token)->assertUnauthorized();
        $this->getJson($this->endpoint().'/entries')->assertUnauthorized();
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->actingAs($user);
        $this->scan($token)->assertForbidden();
        $this->getJson($this->endpoint().'/entries')->assertForbidden();
        $this->actingAs($this->teacherUser);
        $this->postJson('/api/v1/exams/'.$this->exam->id.'/collaborators', ['user_id' => $user->id])->assertCreated();
        $this->actingAs($user);
        $this->scan($token)->assertCreated()->assertJsonPath('data.entry.verified_by', $user->id);
        $this->getJson($this->endpoint().'/entries')->assertOk();
        DB::table('exam_collaborators')->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->scan($token)->assertForbidden();
        $this->getJson($this->endpoint().'/entries')->assertForbidden();
    }

    public function test_consumes_qr_generated_by_existing_student_service(): void
    {
        $this->exam->update(['exam_date' => now('America/La_Paz')->toDateString(),
            'start_time' => now('America/La_Paz')->format('H:i:s'), 'duration_minutes' => 120]);
        $qr = app(StudentQrService::class)->getOrGenerateForExam($this->student->id, $this->exam->id);
        $this->actingAs($this->teacherUser);
        $this->scan($qr['token'])->assertCreated()->assertJsonPath('result', 'AUTHORIZED');
    }
}
