<?php

namespace Tests\Feature\ExamManagement;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Role;
use App\Models\StudentQrToken;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Entries\QrEntryService;
use App\Services\Students\StudentQrService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class ExamCancellationTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private array $fixture;

    private Exam $exam;

    private string $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $this->exam = Exam::create(array_replace($this->examPayload($this->fixture['room']->id), ['exam_date' => '2026-10-16',
            'start_time' => '10:00:00', 'course_offering_id' => $this->fixture['offering']->id, 'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED', 'rules' => null]));
        $this->version = $this->exam->fresh()->updated_at->toISOString();
        Sanctum::actingAs($this->fixture['user']);
    }

    public function test_cancel_preserves_resource_and_history_revokes_qr_and_audits_once(): void
    {
        $qr = $this->eligibleQr();
        $collaborator = User::factory()->create();
        DB::table('exam_collaborators')->insert(['exam_id' => $this->exam->id, 'user_id' => $collaborator->id,
            'assigned_by' => $this->fixture['user']->id, 'access_code_hash' => hash('sha256', 'historical'), 'status' => 'ACTIVE', 'assigned_at' => now()]);
        $entryId = DB::table('exam_entries')->insertGetId(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'],
            'room_id' => $this->fixture['room']->id, 'verified_by' => $this->fixture['user']->id, 'verification_method' => 'QR', 'entered_at' => now(), 'status' => 'ANNULLED']);
        $before = $this->getJson($this->detail())->assertOk()->json('data');
        $this->travel(2)->seconds();
        $response = $this->cancel()->assertOk()->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.can_edit', false)->assertJsonPath('data.can_cancel', false);
        foreach (['id', 'course_offering_id', 'room', 'subject_code', 'subject_name', 'academic_term_name', 'name', 'exam_date', 'start_time', 'duration_minutes', 'evaluation_type', 'rules'] as $key) {
            $this->assertSame($before[$key], $response->json('data.'.$key));
        }
        $this->assertNotSame($before['updated_at'], $response->json('data.updated_at'));
        $this->assertDatabaseHas('student_qr_tokens', ['exam_id' => $this->exam->id, 'status' => 'REVOKED']);
        $this->assertNotNull(StudentQrToken::firstOrFail()->revoked_at);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('exam_eligibilities', 1);
        $this->assertDatabaseHas('exam_collaborators', ['exam_id' => $this->exam->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('exam_entries', ['id' => $entryId, 'status' => 'ANNULLED']);
        $audit = DB::table('audit_logs')->where('action', 'EXAM_CANCELLED')->first();
        $this->assertSame($this->fixture['user']->id, (int) $audit->user_id);
        $this->assertSame($this->exam->id, (int) $audit->entity_id);
        $this->assertSame('SCHEDULED', json_decode($audit->old_values, true)['status']);
        $this->assertSame('CANCELLED', json_decode($audit->new_values, true)['status']);
        $this->assertSame(1, json_decode($audit->new_values, true)['revoked_qr_tokens']);
        $this->assertStringNotContainsString($qr['token'], $audit->new_values);
        $this->getJson($this->detail())->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->getJson('/api/v1/teacher/exams?view=cancelled')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/teacher/exams?view=all')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/teacher/exams')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/rooms?exam_date=2026-10-16&start_time=10:00&duration_minutes=60')->assertOk()->assertJsonCount(1, 'data');
        $this->cancel()->assertStatus(409)->assertJsonPath('code', 'EXAM_ALREADY_CANCELLED');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_CANCELLED')->count());
    }

    public function test_repeated_cancellation_with_same_second_version_cannot_apply_twice(): void
    {
        $this->cancel()->assertOk();
        $this->cancel()->assertStatus(409);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_CANCELLED')->count());
        $this->assertDatabaseCount('exams', 1);
    }

    public function test_stale_version_does_not_cancel_revoke_or_audit(): void
    {
        $this->eligibleQr();
        $this->exam->update(['updated_at' => now()->addSecond()]);
        $this->cancel()->assertStatus(409)->assertJsonPath('code', 'EXAM_VERSION_CONFLICT');
        $this->assertSame('SCHEDULED', $this->exam->fresh()->status);
        $this->assertDatabaseHas('student_qr_tokens', ['status' => 'ACTIVE', 'revoked_at' => null]);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_CANCELLED')->count());
    }

    public function test_started_closed_or_inactive_offering_states_are_rejected(): void
    {
        foreach (['IN_PROGRESS', 'COMPLETED', 'FINISHED'] as $state) {
            $this->exam->update(['status' => $state]);
            $this->cancel()->assertStatus(409);
        }
        $this->exam->update(['status' => 'SCHEDULED']);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00', 'America/La_Paz'));
        $this->cancel()->assertStatus(409);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/La_Paz'));
        foreach (['offering', 'subject', 'term', 'teacher'] as $key) {
            $this->fixture[$key]->update(['status' => 'INACTIVE']);
            $this->cancel()->assertUnprocessable();
            $this->fixture[$key]->update(['status' => 'ACTIVE']);
        }
        $this->assertSame('SCHEDULED', $this->exam->fresh()->status);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_CANCELLED')->count());
    }

    public function test_foreign_collaborator_and_invalid_ids_cannot_cancel(): void
    {
        $other = Teacher::factory()->create();
        $offering = CourseOffering::create(['teacher_id' => $other->id, 'subject_id' => $this->fixture['subject']->id,
            'academic_term_id' => $this->fixture['term']->id, 'status' => 'ACTIVE']);
        $foreign = $this->exam->replicate();
        $foreign->course_offering_id = $offering->id;
        $foreign->save();
        DB::table('exam_collaborators')->insert(['exam_id' => $foreign->id, 'user_id' => $this->fixture['user']->id,
            'assigned_by' => $other->user_id, 'access_code_hash' => hash('sha256', 'test'), 'status' => 'ACTIVE', 'assigned_at' => now()]);
        foreach ([$foreign->id, 999999, 0, -1, 'abc', '999999999999999999999999'] as $id) {
            $this->postJson('/api/v1/teacher/exams/'.$id.'/cancel', ['expected_updated_at' => $this->version])->assertNotFound()->assertJsonMissingPath('data');
        }
        $this->assertSame('SCHEDULED', $foreign->fresh()->status);
    }

    public function test_cancel_payload_is_strict_and_does_not_require_reason(): void
    {
        foreach ([[], ['expected_updated_at' => 'invalid'], ['expected_updated_at' => '2026-02-30T09:00:00Z']] as $payload) {
            $this->postJson($this->detail().'/cancel', $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_updated_at');
        }
        foreach (['status', 'reason', 'course_offering_id', 'room_id', 'created_by'] as $key) {
            $this->postJson($this->detail().'/cancel', ['expected_updated_at' => $this->version, $key => 'value'])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->cancel()->assertOk();
    }

    public function test_inactive_user_revoked_role_or_first_access_cannot_cancel(): void
    {
        $user = $this->fixture['user'];
        $user->update(['status' => 'INACTIVE']);
        Sanctum::actingAs($user->fresh());
        $this->cancel()->assertUnauthorized();
        $user->forceFill(['status' => 'ACTIVE', 'must_change_password' => true])->save();
        Sanctum::actingAs($user->fresh());
        $this->cancel()->assertForbidden();
        $user->forceFill(['must_change_password' => false])->save();
        $user->roles()->updateExistingPivot(Role::where('name', 'DOCENTE')->firstOrFail()->id, ['status' => 'INACTIVE']);
        Sanctum::actingAs($user->fresh());
        $this->cancel()->assertForbidden();
        $this->assertSame('SCHEDULED', $this->exam->fresh()->status);
    }

    public function test_audit_failure_rolls_back_cancellation_and_qr_revocation(): void
    {
        $this->eligibleQr();
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('private SQL detail')));
        $response = $this->cancel()->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('private SQL detail', $response->getContent());
        $this->assertSame('SCHEDULED', $this->exam->fresh()->status);
        $this->assertSame($this->version, $this->exam->fresh()->updated_at->toISOString());
        $this->assertDatabaseHas('student_qr_tokens', ['status' => 'ACTIVE', 'revoked_at' => null]);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_CANCELLED')->count());
    }

    public function test_previous_signed_qr_is_denied_and_new_generation_is_blocked_after_cancel(): void
    {
        $qr = $this->eligibleQr();
        $this->cancel()->assertOk();
        $result = app(QrEntryService::class)->process($this->exam->id, $this->fixture['user'], ['qr_token' => $qr['token'], 'room_id' => $this->fixture['room']->id]);
        $this->assertSame(422, $result->getStatusCode());
        // State validation must also block entry if revocation has not been reflected yet.
        StudentQrToken::query()->update(['status' => 'ACTIVE', 'revoked_at' => null]);
        $result = app(QrEntryService::class)->process($this->exam->id, $this->fixture['user'], ['qr_token' => $qr['token'], 'room_id' => $this->fixture['room']->id]);
        $this->assertSame(422, $result->getStatusCode());
        $this->assertContains('EXAM_UNAVAILABLE', array_column($result->getData(true)['data']['blockers'], 'code'));
        $this->assertDatabaseCount('exam_entries', 0);
        $this->expectException(ModelNotFoundException::class);
        app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id);
    }

    private function eligibleQr(): array
    {
        DB::table('enrollments')->insert(['course_offering_id' => $this->fixture['offering']->id, 'student_id' => $this->fixture['studentId'], 'registered_by' => $this->fixture['user']->id, 'status' => 'ACTIVE']);
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE',
            'evaluated_by' => $this->fixture['user']->id, 'evaluated_at' => now()]);

        return app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id);
    }

    private function detail(): string
    {
        return '/api/v1/teacher/exams/'.$this->exam->id;
    }

    private function cancel()
    {
        return $this->postJson($this->detail().'/cancel', ['expected_updated_at' => $this->version]);
    }
}
