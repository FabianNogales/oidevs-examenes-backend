<?php

namespace Tests\Feature\Api\V1\Entries;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $claims = array_replace(['v' => 2,
            'exp' => now()->addHours(2)->timestamp, 'jti' => $uuid], $overrides);
        StudentQrToken::create(['student_id' => $this->student->id,
            'exam_id' => $this->exam->id, 'token' => $uuid, 'status' => 'ACTIVE', 'generated_at' => now()]);

        return $this->signClaims($claims);
    }

    private function signClaims(array $claims, array $header = ['alg' => 'HS256', 'typ' => 'JWT']): string
    {
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $input = $encode($header).'.'.$encode($claims);

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

    public static function incompatibleQrContracts(): array
    {
        return [
            'version absent' => [[], ['v']],
            'explicit v1' => [['v' => 1]],
            'unknown version' => [['v' => 3]],
            'string version' => [['v' => '2']],
            'floating version' => [['v' => 2.5]],
            'boolean version' => [['v' => true]],
            'null version' => [['v' => null]],
            'legacy student claim' => [['std' => 999]],
            'legacy exam claim' => [['exm' => 999]],
            'null legacy student claim' => [['std' => null]],
            'null legacy exam claim' => [['exm' => null]],
            'additional personal claim' => [['sis' => '202600001']],
            'expiration absent' => [[], ['exp']],
            'string expiration' => [['exp' => '9999999999']],
            'floating expiration' => [['exp' => 9999999999.5]],
            'null expiration' => [['exp' => null]],
            'uuid absent' => [[], ['jti']],
            'invalid uuid' => [['jti' => 'not-a-uuid']],
            'uuid with trailing newline' => [['jti' => "12345678-1234-1234-1234-123456789abc\n"]],
            'null uuid' => [['jti' => null]],
            'array uuid' => [['jti' => []]],
            'unapproved algorithm' => [[], [], ['alg' => 'HS512', 'typ' => 'JWT']],
            'unsigned algorithm' => [[], [], ['alg' => 'none', 'typ' => 'JWT']],
            'incorrect type' => [[], [], ['alg' => 'HS256', 'typ' => 'OTHER']],
        ];
    }

    #[DataProvider('incompatibleQrContracts')]
    public function test_rejects_signed_incompatible_contracts_without_personal_data(
        array $overrides, array $remove = [], array $header = ['alg' => 'HS256', 'typ' => 'JWT'],
    ): void {
        $token = $this->token();
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        $claims = array_replace($claims, $overrides);
        foreach ($remove as $claim) {
            unset($claims[$claim]);
        }
        $this->actingAs($this->teacherUser);
        $response = $this->scan($this->signClaims($claims, $header))->assertUnprocessable();
        $response->assertExactJson([
            'result' => 'INVALID_QR', 'message' => 'QR inválido.',
            'processed_at' => $response->json('processed_at'), 'data' => null,
        ]);
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_existing_uuid_emits_v2_but_both_legacy_formats_remain_invalid(): void
    {
        $this->exam->update(['exam_date' => now('America/La_Paz')->toDateString(),
            'start_time' => now('America/La_Paz')->format('H:i:s'), 'duration_minutes' => 120]);
        $uuid = (string) Str::uuid();
        $record = StudentQrToken::create(['student_id' => $this->student->id, 'exam_id' => $this->exam->id,
            'token' => $uuid, 'status' => 'ACTIVE', 'generated_at' => now()->subHour()]);
        $before = $record->fresh()->getRawOriginal();
        $legacyClaims = ['std' => $this->student->id, 'exm' => $this->exam->id,
            'exp' => now()->addHour()->timestamp, 'jti' => $uuid];
        $legacyTokens = [$this->signClaims($legacyClaims), $this->signClaims(['v' => 1] + $legacyClaims)];
        $this->actingAs($this->teacherUser);
        foreach ($legacyTokens as $legacy) {
            $this->scan($legacy)->assertUnprocessable()->assertJsonPath('result', 'INVALID_QR')->assertJsonPath('data', null);
        }

        $qr = app(StudentQrService::class)->getOrGenerateForExam($this->student->id, $this->exam->id);
        $claims = json_decode(base64_decode(strtr(explode('.', $qr['token'])[1], '-_', '+/')), true);
        $this->assertSame(2, $claims['v']);
        $this->assertSame($uuid, $claims['jti']);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->assertDatabaseCount('student_qr_tokens', 1);
        $this->assertDatabaseCount('exam_entries', 0);
        $this->scan($qr['token'])->assertCreated()->assertJsonPath('result', 'AUTHORIZED')
            ->assertJsonPath('data.student.student_id', $record->student_id);
        foreach ($legacyTokens as $legacy) {
            $this->scan($legacy)->assertUnprocessable()->assertJsonPath('result', 'INVALID_QR')->assertJsonPath('data', null);
        }
        $this->assertDatabaseCount('exam_entries', 1);
    }

    public function test_tampering_with_signed_v2_payload_or_structure_never_registers_entry(): void
    {
        $token = $this->token();
        [$header, $payload, $signature] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['exp'] += 3600;
        $alteredPayload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $this->actingAs($this->teacherUser);
        foreach ([$header.'.'.$alteredPayload.'.'.$signature, $token.'.extra',
            $header.'..'.$signature, $header.'.*.'.$signature] as $invalid) {
            $this->scan($invalid)->assertUnprocessable()->assertJsonPath('result', 'INVALID_QR')->assertJsonPath('data', null);
        }
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_valid_other_exam_token_does_not_disclose_student(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->actingAs($this->teacherUser);
        $token = $this->token();
        $record = StudentQrToken::firstOrFail();
        $other = $this->exam->replicate();
        $other->name = 'Otro examen';
        $other->save();
        $endpoint = '/api/v1/exams/'.$other->id.'/entry-control';
        $history = $this->getJson($endpoint.'/entries')->assertOk()->json();
        $summary = $this->getJson($endpoint.'/students')->assertOk()->json('summary');
        $tokens = DB::table('student_qr_tokens')->get()->toJson();
        $eligibilities = DB::table('exam_eligibilities')->get()->toJson();
        $enrollments = DB::table('enrollments')->get()->toJson();

        foreach ([1, 2] as $expectedCount) {
            $this->postJson($endpoint.'/qr', ['qr_token' => $token, 'room_id' => $other->room_id,
                'student_id' => 99999, 'qr_exam_id' => 99999, 'verified_by' => 99999])
                ->assertUnprocessable()->assertExactJson([
                    'result' => 'WRONG_EXAM', 'message' => 'QR de otro examen.',
                    'processed_at' => now()->toISOString(), 'data' => null,
                ]);
            $this->assertSame($expectedCount, AuditLog::where('action', 'ENTRY_QR_WRONG_EXAM')->count());
        }

        foreach (AuditLog::where('action', 'ENTRY_QR_WRONG_EXAM')->get() as $event) {
            $this->assertSame($this->teacherUser->id, $event->user_id);
            $this->assertSame('Exam', $event->entity_type);
            $this->assertSame($other->id, $event->entity_id);
            $this->assertNull($event->old_values);
            $this->assertSame(['qr_exam_id' => $record->exam_id, 'student_id' => $record->student_id,
                'reason' => 'WRONG_EXAM', 'method' => 'QR'], $event->new_values);
            $this->assertTrue($event->created_at->equalTo(now()));
            $this->assertSame('127.0.0.1', $event->ip_address);
            foreach ([$token, $record->token, $this->student->sis_code, $this->student->identity_number,
                $this->student->first_names, $this->student->last_names, $this->student->user->email,
                config('app.key')] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $event->toJson());
            }
        }
        $this->assertSame($history, $this->getJson($endpoint.'/entries')->assertOk()->json());
        $this->assertSame($summary, $this->getJson($endpoint.'/students')->assertOk()->json('summary'));
        $this->assertSame($tokens, DB::table('student_qr_tokens')->get()->toJson());
        $this->assertSame($eligibilities, DB::table('exam_eligibilities')->get()->toJson());
        $this->assertSame($enrollments, DB::table('enrollments')->get()->toJson());
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public static function invalidOtherExamCredentials(): array
    {
        return array_map(fn ($case) => [$case], [
            'v1', 'tampered', 'expired', 'revoked', 'revoked_at', 'inactive', 'unknown',
        ]);
    }

    #[DataProvider('invalidOtherExamCredentials')]
    public function test_invalid_credentials_of_other_exam_are_not_audited(string $case): void
    {
        $token = $this->token($case === 'v1' ? ['v' => 1] : ($case === 'expired' ? ['exp' => now()->timestamp] : []));
        $record = StudentQrToken::firstOrFail();
        if ($case === 'tampered') {
            $parts = explode('.', $token);
            $token = $parts[0].'.'.$parts[1].'.'.str_repeat('A', 43);
        } elseif ($case === 'revoked') {
            $record->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        } elseif ($case === 'revoked_at') {
            $record->update(['revoked_at' => now()]);
        } elseif ($case === 'inactive') {
            $record->update(['status' => 'INACTIVE']);
        } elseif ($case === 'unknown') {
            $record->delete();
        }
        $other = $this->exam->replicate();
        $other->save();
        $this->actingAs($this->teacherUser);
        $this->postJson('/api/v1/exams/'.$other->id.'/entry-control/qr',
            ['qr_token' => $token, 'room_id' => $other->room_id])->assertUnprocessable()
            ->assertJsonPath('result', 'INVALID_QR')->assertJsonPath('data', null);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ENTRY_QR_WRONG_EXAM']);
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_wrong_exam_records_authorized_collaborator_as_actor_without_granting_foreign_access(): void
    {
        $token = $this->token();
        $other = $this->exam->replicate();
        $other->save();
        $collaborator = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->actingAs($this->teacherUser);
        $this->postJson('/api/v1/exams/'.$other->id.'/collaborators', ['user_id' => $collaborator->id])->assertCreated();
        $this->actingAs($collaborator);
        $this->postJson('/api/v1/exams/'.$other->id.'/entry-control/qr',
            ['qr_token' => $token, 'room_id' => $other->room_id])->assertUnprocessable()
            ->assertJsonPath('result', 'WRONG_EXAM')->assertJsonPath('data', null);
        $this->assertSame($collaborator->id, AuditLog::where('action', 'ENTRY_QR_WRONG_EXAM')->sole()->user_id);
        $this->getJson($this->endpoint().'/students/'.$this->student->id)->assertForbidden();
        DB::table('exam_collaborators')->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->postJson('/api/v1/exams/'.$other->id.'/entry-control/qr',
            ['qr_token' => $token, 'room_id' => $other->room_id])->assertForbidden();
        $this->assertSame(1, AuditLog::where('action', 'ENTRY_QR_WRONG_EXAM')->count());
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_unauthorized_and_invalid_requests_do_not_create_wrong_exam_events(): void
    {
        $token = $this->token();
        $other = $this->exam->replicate();
        $other->save();
        $endpoint = '/api/v1/exams/'.$other->id.'/entry-control/qr';
        $payload = ['qr_token' => $token, 'room_id' => $other->room_id];
        $this->postJson($endpoint, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]));
        $this->postJson($endpoint, $payload)->assertForbidden();
        $this->actingAs($this->teacherUser);
        $this->postJson('/api/v1/exams/999999/entry-control/qr', $payload)->assertNotFound();
        $this->postJson($endpoint, [])->assertUnprocessable()->assertJsonValidationErrors(['qr_token', 'room_id']);
        $this->teacherUser->update(['status' => 'INACTIVE']);
        $this->postJson($endpoint, $payload)->assertUnauthorized();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ENTRY_QR_WRONG_EXAM']);
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_sql_audit_failure_keeps_rejection_and_logs_only_exception_type(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite fault-injection trigger; PostgreSQL verification is separate.');
        }
        $this->actingAs($this->teacherUser);
        $token = $this->token();
        $other = $this->exam->replicate();
        $other->save();
        DB::unprepared("CREATE TRIGGER fail_wrong_exam_audit BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'ENTRY_QR_WRONG_EXAM'
            BEGIN SELECT RAISE(ABORT, 'Simulated audit insert failure'); END");
        Log::shouldReceive('error')->once()->with('ENTRY_QR_WRONG_EXAM audit persistence failed.',
            ['exception_type' => QueryException::class]);
        $level = DB::transactionLevel();
        $response = $this->postJson('/api/v1/exams/'.$other->id.'/entry-control/qr',
            ['qr_token' => $token, 'room_id' => $other->room_id])->assertUnprocessable();
        $response->assertExactJson(['result' => 'WRONG_EXAM', 'message' => 'QR de otro examen.',
            'processed_at' => $response->json('processed_at'), 'data' => null]);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ENTRY_QR_WRONG_EXAM']);
        $this->assertDatabaseCount('exam_entries', 0);
        // The connection remains usable after the failed insert has been rolled back.
        $this->scan($token)->assertCreated()->assertJsonPath('result', 'AUTHORIZED');
        $this->assertDatabaseCount('exam_entries', 1);
    }

    public function test_other_entry_outcomes_including_manual_never_create_wrong_exam_events(): void
    {
        $this->actingAs($this->teacherUser);
        $token = $this->token();
        $this->eligibility->update(['status' => 'INELIGIBLE']);
        $this->scan($token)->assertUnprocessable()->assertJsonPath('result', 'DENIED');
        $this->eligibility->update(['status' => 'ELIGIBLE']);
        $this->scan($token)->assertCreated()->assertJsonPath('result', 'AUTHORIZED');
        $this->scan($token)->assertConflict()->assertJsonPath('result', 'ALREADY_REGISTERED');
        DB::table('exam_entries')->update(['status' => 'ANNULLED']);
        $this->postJson($this->endpoint().'/students/'.$this->student->id.'/entries',
            ['room_id' => $this->exam->room_id])->assertCreated()
            ->assertJsonPath('data.entry.verification_method', 'MANUAL');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ENTRY_QR_WRONG_EXAM']);
        $this->assertDatabaseCount('exam_entries', 2);
        $this->assertSame(1, DB::table('exam_entries')->where('status', 'VALID')->count());
    }

    public function test_valid_qr_without_either_association_is_denied_without_personal_data(): void
    {
        $token = $this->token();
        DB::table('enrollments')->where('student_id', $this->student->id)->delete();
        $this->eligibility->delete();
        $tokensBefore = DB::table('student_qr_tokens')->orderBy('id')->get()->toJson();
        $this->actingAs($this->teacherUser);

        $response = $this->scan($token)->assertUnprocessable();
        $response->assertExactJson([
            'result' => 'DENIED', 'message' => 'Ingreso no autorizado.',
            'processed_at' => $response->json('processed_at'), 'data' => null,
        ]);
        $this->getJson($this->endpoint().'/students/'.$this->student->id)->assertNotFound()
            ->assertExactJson(['message' => 'Estudiante no encontrado.']);
        $this->assertSame($tokensBefore, DB::table('student_qr_tokens')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_qr_with_eligibility_only_retains_authorized_detail_and_denies_entry(): void
    {
        $token = $this->token();
        DB::table('enrollments')->where('student_id', $this->student->id)->delete();
        $this->actingAs($this->teacherUser);

        $this->scan($token)->assertUnprocessable()->assertJsonPath('result', 'DENIED')
            ->assertJsonPath('data.student.student_id', $this->student->id)
            ->assertJsonPath('data.eligibility_status', 'ELIGIBLE')->assertJsonFragment(['code' => 'NOT_ENROLLED']);
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
