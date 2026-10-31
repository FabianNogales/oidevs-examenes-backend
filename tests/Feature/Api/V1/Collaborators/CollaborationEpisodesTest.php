<?php

namespace Tests\Feature\Api\V1\Collaborators;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentQrToken;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Collaborators\CollaborationAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class CollaborationEpisodesTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private array $fixture;

    private Exam $exam;

    private User $collaborator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->fixture = $this->offeringFixture();
        $this->exam = Exam::create($this->examPayload($this->fixture['room']->id) + [
            'course_offering_id' => $this->fixture['offering']->id,
            'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED',
        ]);
        $this->collaborator = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $candidateRole = Role::firstOrCreate(['name' => 'ESTUDIANTE'], ['status' => 'ACTIVE']);
        $this->collaborator->roles()->attach($candidateRole->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->actingAs($this->fixture['user']);
    }

    private function endpoint(?Exam $exam = null): string
    {
        return '/api/v1/exams/'.($exam ?? $this->exam)->id.'/collaborators';
    }

    private function assign(?Exam $exam = null)
    {
        return $this->postJson($this->endpoint($exam), ['user_id' => $this->collaborator->id,
            'assigned_by' => 99999, 'opening_event_id' => 99999, 'status' => 'REVOKED']);
    }

    private function revoke(?Exam $exam = null)
    {
        return $this->deleteJson($this->endpoint($exam).'/'.$this->collaborator->id);
    }

    private function events()
    {
        return AuditLog::where('entity_type', 'ExamCollaborator')->orderBy('id')->get();
    }

    private function legacy(string $status = 'ACTIVE'): ExamCollaborator
    {
        return ExamCollaborator::create(['exam_id' => $this->exam->id, 'user_id' => $this->collaborator->id,
            'assigned_by' => $this->fixture['user']->id, 'access_code_hash' => hash('sha256', 'legacy-credential'),
            'status' => $status, 'assigned_at' => now()->subMonth(),
            'revoked_at' => $status === 'REVOKED' ? now()->subWeek() : null]);
    }

    public function test_two_episodes_in_the_same_second_keep_distinct_opening_ids_and_actors(): void
    {
        $assigned = $this->assign()->assertCreated()->assertJsonPath('message', 'Colaborador asignado exitosamente.');
        $id = $assigned->json('data.id');
        $this->assertSame(['id', 'exam_id', 'user_id', 'assigned_at', 'assigned_by', 'assigned_by_name'],
            array_keys($assigned->json('data')));
        $newOwner = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::where('name', 'DOCENTE')->firstOrFail();
        $newOwner->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $teacher = Teacher::factory()->create(['user_id' => $newOwner->id]);
        $this->fixture['offering']->update(['teacher_id' => $teacher->id]);
        $this->actingAs($newOwner);
        $this->revoke()->assertOk()->assertExactJson(['message' => 'Autorización de colaborador revocada exitosamente.']);
        $this->assign()->assertCreated()->assertJsonPath('data.id', $id);
        $this->revoke()->assertOk();

        $events = $this->events();
        $this->assertSame([CollaborationAuditService::ASSIGNED, CollaborationAuditService::REVOKED,
            CollaborationAuditService::REASSIGNED, CollaborationAuditService::REVOKED], $events->pluck('action')->all());
        $this->assertSame($events[0]->id, $events[1]->new_values['opening_event_id']);
        $this->assertSame($events[2]->id, $events[3]->new_values['opening_event_id']);
        $this->assertNotSame($events[0]->id, $events[2]->id);
        $this->assertSame($this->fixture['user']->id, $events[0]->user_id);
        $this->assertSame([$newOwner->id, $newOwner->id, $newOwner->id], $events->slice(1)->pluck('user_id')->all());
        $this->assertNull($events[0]->old_values);
        $this->assertSame($events[0]->new_values, $events[1]->old_values);
        $this->assertSame('REVOKED', $events[2]->old_values['status']);
        $this->assertSame($events[2]->new_values, $events[3]->old_values);
        foreach ($events as $event) {
            $this->assertSame($id, $event->entity_id);
            $this->assertSame($this->exam->id, $event->new_values['exam_id']);
            $this->assertSame($this->collaborator->id, $event->new_values['collaborator_user_id']);
            $this->assertTrue($event->created_at->equalTo(now()));
        }
        $this->assertTrue($events[1]->new_values['history_complete']);
        $this->assertTrue($events[3]->new_values['history_complete']);
        $this->assertSame($events[0]->new_values['assigned_at'], $events[2]->new_values['assigned_at']);
        $this->assertDatabaseCount('exam_collaborators', 1);
        $this->assertDatabaseHas('exam_collaborators', ['id' => $id, 'status' => 'REVOKED', 'assigned_by' => $newOwner->id]);
    }

    public function test_duplicates_missing_revocations_and_validation_errors_have_no_transition_event(): void
    {
        $this->revoke()->assertNotFound();
        $this->assertCount(0, $this->events());
        $this->assign()->assertCreated();
        $before = ExamCollaborator::firstOrFail()->getRawOriginal();
        $this->assign()->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertSame($before, ExamCollaborator::firstOrFail()->getRawOriginal());
        $this->assertCount(1, $this->events());
        $this->revoke()->assertOk();
        $this->revoke()->assertNotFound();
        $this->postJson($this->endpoint(), ['user_id' => 999999])->assertUnprocessable();
        $this->assertCount(2, $this->events());
    }

    public function test_legacy_revocation_is_explicitly_incomplete_and_next_episode_is_complete(): void
    {
        $legacy = $this->legacy();
        $assignedAt = $legacy->assigned_at->toISOString();
        $this->getJson($this->endpoint())->assertOk();
        $this->assertCount(0, $this->events());
        $this->revoke()->assertOk();
        $closed = $this->events()->sole();
        $this->assertSame(CollaborationAuditService::REVOKED, $closed->action);
        $this->assertNull($closed->new_values['opening_event_id']);
        $this->assertFalse($closed->new_values['history_complete']);
        $this->assertSame($assignedAt, $closed->old_values['assigned_at']);
        $this->assertSame($this->fixture['user']->id, $closed->user_id);
        $this->assign()->assertCreated()->assertJsonPath('data.id', $legacy->id);
        $this->revoke()->assertOk();
        $events = $this->events();
        $this->assertSame($events[1]->id, $events[2]->new_values['opening_event_id']);
        $this->assertTrue($events[2]->new_values['history_complete']);
        $this->assertDatabaseCount('exam_collaborators', 1);
    }

    public function test_reassigning_legacy_revoked_row_preserves_only_the_observed_previous_dates(): void
    {
        $legacy = $this->legacy('REVOKED');
        $snapshot = app(CollaborationAuditService::class)->snapshot($legacy);
        $this->assign()->assertCreated();
        $event = $this->events()->sole();
        $this->assertSame(CollaborationAuditService::REASSIGNED, $event->action);
        $this->assertEquals($snapshot, $event->old_values);
        $this->assertNull($event->new_values['revoked_at']);
    }

    public function test_opening_lookup_accepts_jsonb_key_reordering_but_not_a_mismatched_snapshot(): void
    {
        $legacy = $this->legacy();
        $audit = app(CollaborationAuditService::class);
        $event = AuditLog::create(['user_id' => $this->fixture['user']->id, 'entity_type' => 'ExamCollaborator',
            'entity_id' => $legacy->id, 'action' => CollaborationAuditService::ASSIGNED,
            'new_values' => array_reverse($audit->snapshot($legacy), true), 'created_at' => now()]);
        $this->assertSame($event->id, $audit->openingId($legacy));
        $legacy->update(['assigned_at' => now()->subDays(3)]);
        $this->assertCount(1, $this->events());
        $this->revoke()->assertOk();
        $this->assertNull($this->events()->last()->new_values['opening_event_id']);
        $this->assertFalse($this->events()->last()->new_values['history_complete']);
    }

    public function test_a_closed_event_cannot_be_reused_as_a_later_opening(): void
    {
        $this->assign()->assertCreated();
        $this->revoke()->assertOk();
        // A change outside the transition service must not fabricate history.
        DB::table('exam_collaborators')->update(['status' => 'ACTIVE', 'revoked_at' => null]);
        $this->assertCount(2, $this->events());
        $this->revoke()->assertOk();
        $this->assertNull($this->events()->last()->new_values['opening_event_id']);
    }

    public function test_audit_payload_is_an_explicit_whitelist_without_credentials_or_personal_fields(): void
    {
        $this->assign()->assertCreated();
        $hash = ExamCollaborator::firstOrFail()->access_code_hash;
        $this->revoke()->assertOk();
        $keys = ['exam_id', 'collaborator_user_id', 'status', 'assigned_by', 'assigned_at', 'revoked_at'];
        foreach ($this->events() as $event) {
            $expected = $event->action === CollaborationAuditService::REVOKED
                ? [...$keys, 'opening_event_id', 'history_complete'] : $keys;
            $this->assertEqualsCanonicalizing($expected, array_keys($event->new_values));
            if ($event->old_values !== null) {
                $this->assertEqualsCanonicalizing($keys, array_keys($event->old_values));
            }
            foreach ([$hash, $this->collaborator->email, $this->collaborator->password,
                $this->fixture['teacher']->identity_number, config('app.key')] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $event->toJson());
            }
        }
    }

    public static function failedTransitions(): array
    {
        return [['assign'], ['reassign'], ['revoke']];
    }

    #[DataProvider('failedTransitions')]
    public function test_real_sql_audit_failure_rolls_back_transition_and_can_be_retried(string $operation): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite trigger fault injection; PostgreSQL verification is separate.');
        }
        if ($operation !== 'assign') {
            $this->assign()->assertCreated();
        }
        if ($operation === 'reassign') {
            $this->revoke()->assertOk();
        }
        $before = DB::table('exam_collaborators')->get()->toJson();
        $eventsBefore = $this->events()->toJson();
        DB::unprepared("CREATE TRIGGER fail_collaboration_audit BEFORE INSERT ON audit_logs
            WHEN NEW.entity_type = 'ExamCollaborator'
            BEGIN SELECT RAISE(ABORT, 'Simulated private SQL failure'); END");
        Log::shouldReceive('error')->once()->with('Exam collaborator transition failed.',
            ['exception_type' => QueryException::class]);
        $response = $operation === 'revoke' ? $this->revoke() : $this->assign();
        $response->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertStringContainsString('la autorización conserva su estado anterior', $response->json('message'));
        $this->assertStringNotContainsString('SQL', $response->getContent());
        $this->assertSame($before, DB::table('exam_collaborators')->get()->toJson());
        $this->assertSame($eventsBefore, $this->events()->toJson());
        $this->actingAs($this->collaborator)->getJson('/api/v1/me/collaborations')->assertOk()
            ->assertJsonCount($operation === 'revoke' ? 1 : 0, 'data');
        $this->actingAs($this->fixture['user']);
        DB::unprepared('DROP TRIGGER fail_collaboration_audit');
        if ($operation === 'revoke') {
            $this->revoke()->assertOk();
        } else {
            $this->assign()->assertCreated();
        }
    }

    public function test_failure_after_a_real_audit_insert_rolls_back_both_writes(): void
    {
        $real = new AuditLogService;
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andReturnUsing(function (...$arguments) use ($real) {
            $real->log(...$arguments);
            throw new RuntimeException('private diagnostic that must not be exposed');
        });
        Log::shouldReceive('error')->once()->with('Exam collaborator transition failed.',
            ['exception_type' => RuntimeException::class]);
        $this->assign()->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertDatabaseCount('exam_collaborators', 0);
        $this->assertCount(0, $this->events());
    }

    public static function baseRoles(): array
    {
        return [['ESTUDIANTE'], ['DOCENTE']];
    }

    #[DataProvider('baseRoles')]
    public function test_roles_and_other_exam_remain_intact_through_all_transitions(string $name): void
    {
        $role = Role::firstOrCreate(['name' => $name], ['status' => 'ACTIVE']);
        $this->collaborator->roles()->syncWithoutDetaching([$role->id => ['status' => 'ACTIVE', 'assigned_at' => now()]]);
        if ($name === 'ESTUDIANTE') {
            Student::findOrFail($this->fixture['studentId'])->replicate()->fill([
                'user_id' => $this->collaborator->id, 'sis_code' => '999900001', 'identity_number' => '99990001',
            ])->save();
        } else {
            Teacher::factory()->create(['user_id' => $this->collaborator->id]);
        }
        $roles = DB::table('role_user')->where('user_id', $this->collaborator->id)->get()->toJson();
        $other = $this->exam->replicate();
        $other->save();
        $this->assign()->assertCreated();
        $this->assign($other)->assertCreated();
        $otherBefore = ExamCollaborator::where('exam_id', $other->id)->firstOrFail()->getRawOriginal();
        $this->revoke()->assertOk();
        $this->actingAs($this->collaborator)->getJson('/api/v1/me/collaborations')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.exam_id', $other->id);
        $this->actingAs($this->fixture['user']);
        $this->assign()->assertCreated();
        $this->actingAs($this->collaborator)->getJson('/api/v1/me/collaborations')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($roles, DB::table('role_user')->where('user_id', $this->collaborator->id)->get()->toJson());
        $this->assertSame($otherBefore, ExamCollaborator::where('exam_id', $other->id)->firstOrFail()->getRawOriginal());
        $this->getJson($this->endpoint())->assertForbidden();
        $this->getJson('/api/v1/exams/'.$this->exam->id.'/eligibilities')->assertForbidden();
    }

    public function test_revocation_removes_hu13_hu14_and_manual_access_without_changing_attendance(): void
    {
        DB::table('enrollments')->insert(['student_id' => $this->fixture['studentId'],
            'course_offering_id' => $this->fixture['offering']->id, 'registered_by' => $this->fixture['user']->id, 'status' => 'ACTIVE']);
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id,
            'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE']);
        $uuid = (string) Str::uuid();
        StudentQrToken::create(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'],
            'token' => $uuid, 'status' => 'ACTIVE', 'generated_at' => now()]);
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $input = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode(['v' => 2, 'jti' => $uuid,
            'exp' => CarbonImmutable::parse($this->exam->exam_date.' '.$this->exam->start_time, 'America/La_Paz')->addMinutes($this->exam->duration_minutes)->timestamp]);
        $token = $input.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $input, config('app.key'), true)), '+/', '-_'), '=');
        $this->assign()->assertCreated();
        $control = '/api/v1/exams/'.$this->exam->id.'/entry-control';
        $payload = ['room_id' => $this->exam->room_id, 'qr_token' => $token];
        $this->actingAs($this->collaborator)->getJson($control.'/students')->assertOk();
        $this->travelTo(CarbonImmutable::parse($this->exam->exam_date.' '.$this->exam->start_time, 'America/La_Paz'));
        $this->postJson($control.'/qr', $payload)->assertCreated();
        $this->actingAs($this->fixture['user']);
        $this->revoke()->assertOk();
        $this->actingAs($this->collaborator)->getJson($control.'/students')->assertForbidden();
        $this->getJson($control.'/students/'.$this->fixture['studentId'])->assertForbidden();
        $this->postJson($control.'/qr', $payload)->assertForbidden();
        $this->postJson($control.'/students/'.$this->fixture['studentId'].'/entries', $payload)->assertForbidden();
        $this->getJson($control.'/entries')->assertForbidden();
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ENTRY_QR_WRONG_EXAM']);
    }

    public function test_cancel_and_candidate_changes_do_not_fabricate_collaboration_transitions(): void
    {
        $this->assign()->assertCreated();
        $before = ExamCollaborator::firstOrFail()->getRawOriginal();
        $this->exam->update(['status' => 'CANCELLED']);
        $this->getJson($this->endpoint())->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->collaborator)->getJson('/api/v1/me/collaborations')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($before, ExamCollaborator::firstOrFail()->getRawOriginal());
        $this->assertCount(1, $this->events());
        $this->actingAs($this->fixture['user']);
        $this->revoke()->assertOk();
    }

    public function test_unauthorized_or_inactive_actors_and_candidates_cannot_write_events(): void
    {
        $this->collaborator->update(['status' => 'INACTIVE']);
        $this->assign()->assertUnprocessable();
        $this->collaborator->update(['status' => 'ACTIVE']);
        $this->actingAs($this->collaborator);
        $this->assign()->assertForbidden();
        $this->revoke()->assertForbidden();
        $this->fixture['user']->update(['status' => 'INACTIVE']);
        $this->actingAs($this->fixture['user']);
        $this->assign()->assertUnauthorized();
        $this->assertCount(0, $this->events());
        $this->assertDatabaseCount('exam_collaborators', 0);
    }

    public function test_delayed_revocation_cannot_close_a_reassigned_period_simulated_interleaving(): void
    {
        $this->assign()->assertCreated();
        $interleaved = false;
        DB::listen(function ($event) use (&$interleaved) {
            if (! $interleaved && str_contains($event->sql, 'from "exam_collaborators"')
                && str_contains($event->sql, '"status" = ?') && ! str_contains($event->sql, 'for update')) {
                $interleaved = true;
                $this->revoke()->assertOk();
                $this->assign()->assertCreated();
            }
        });
        // Injection uses one SQLite connection; it does not prove PostgreSQL locking.
        $this->revoke()->assertNotFound();
        $this->assertTrue($interleaved);
        $this->assertSame('ACTIVE', ExamCollaborator::firstOrFail()->status);
        $this->assertSame([CollaborationAuditService::ASSIGNED, CollaborationAuditService::REVOKED,
            CollaborationAuditService::REASSIGNED], $this->events()->pluck('action')->all());
    }
}
