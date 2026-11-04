<?php

namespace Tests\Feature\Offline;

use App\Models\AuditLog;
use App\Models\ExamCollaborator;
use App\Models\StudentQrToken;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Entries\QrTokenValidator;
use App\Services\Students\StudentQrService;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedOfflineDatabase;
use Tests\Support\OfflineExamFixture;
use Tests\TestCase;

class OfflineBackendTest extends TestCase
{
    use IsolatedOfflineDatabase, OfflineExamFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
        $this->createOfflineFixture();
        $this->actingAs($this->fixture['user']);
    }

    private function preparePackage(): array
    {
        $this->postJson($this->offlineBase().'/prepare')->assertOk();

        return $this->getJson($this->offlineBase().'/package')->assertOk()->json('data');
    }

    public function test_balanced_deterministic_exclusive_distribution_and_idempotent_preparation(): void
    {
        $collaborators = collect(range(1, 3))->map(fn () => $this->offlineCollaborator());
        for ($i = 0; $i < 99; $i++) {
            $this->addOfflineStudent($i < 50 ? 'Álvarez' : 'Pérez', $i % 2 ? 'Ana' : 'Juan');
        }
        $first = $this->postJson($this->offlineBase().'/prepare')->assertOk()->json('data');
        $this->assertSame([34, 33, 33], array_column($first['lists'], 'student_count'));
        $this->assertSame($collaborators->pluck('id')->all(), array_column($first['lists'], 'responsible_id'));
        $students = collect($first['lists'])->flatMap(fn ($list) => $list['students']);
        $this->assertCount(100, $students->pluck('student_id')->unique());
        $expected = $students->sort(function ($a, $b) {
            foreach (['last_names', 'first_names', 'sis_code'] as $field) {
                if ($result = strcmp(mb_strtolower($a[$field]), mb_strtolower($b[$field]))) {
                    return $result;
                }
            }

            return $a['student_id'] <=> $b['student_id'];
        })->pluck('student_id')->all();
        $this->assertSame($expected, $students->pluck('student_id')->all());
        $this->postJson($this->offlineBase().'/prepare')->assertOk()->assertExactJson(['data' => $first]);
        $this->getJson($this->offlineBase().'/assignments')->assertOk()->assertExactJson(['data' => $first]);
        $this->assertDatabaseCount('exam_offline_distributions', 1);
        $this->assertDatabaseCount('exam_offline_members', 100);
        $this->assertSame(1, AuditLog::where('action', 'OFFLINE_LISTS_PREPARED')->count());
    }

    public function test_owner_fallback_and_frozen_distribution(): void
    {
        $package = $this->preparePackage();
        $this->assertSame($this->fixture['user']->id, $package['responsible_id']);
        $this->addOfflineStudent();
        $this->postJson($this->offlineBase().'/prepare')->assertConflict()->assertJsonPath('code', 'DISTRIBUTION_FROZEN');
        $this->assertDatabaseCount('exam_offline_members', 1);
    }

    public function test_only_owner_prepares_and_consults_distribution(): void
    {
        $collaborator = $this->offlineCollaborator();
        $this->actingAs($collaborator)->postJson($this->offlineBase().'/prepare')->assertForbidden();
        $this->getJson($this->offlineBase().'/assignments')->assertForbidden();
        $this->actingAs(User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]));
        $this->getJson($this->offlineBase().'/package')->assertForbidden();
    }

    public function test_package_only_contains_own_list_and_minimal_versioned_data(): void
    {
        $a = $this->offlineCollaborator();
        $b = $this->offlineCollaborator();
        $this->addOfflineStudent('Zeta');
        $lists = $this->postJson($this->offlineBase().'/prepare')->assertOk()->json('data.lists');
        $package = $this->actingAs($a)->getJson($this->offlineBase().'/package?list_id='.$lists[1]['list_id'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertSame($lists[0]['list_id'], $package['list_id']);
        $this->assertSame(1, $package['version']);
        $this->assertSame($a->id, $package['responsible_id']);
        $this->assertSame('America/La_Paz', $package['timezone']);
        $this->assertCount(1, $package['students']);
        $this->assertSame(['student_id', 'first_names', 'last_names', 'sis_code', 'identity_number', 'student_status',
            'enrollment_status', 'eligibility_status', 'eligibility_revision', 'entry_status'], array_keys($package['students'][0]));
        $this->assertStringNotContainsString('authorization_hash', json_encode($package));
        $this->actingAs($b)->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent()]))->assertForbidden();
        $this->actingAs($this->fixture['user'])->getJson($this->offlineBase().'/package')->assertForbidden();
    }

    public function test_revocation_and_reassignment_block_downloads_and_conflict_on_sync(): void
    {
        $actor = $this->offlineCollaborator();
        $this->postJson($this->offlineBase().'/prepare')->assertOk();
        $package = $this->actingAs($actor)->getJson($this->offlineBase().'/package')->assertOk()->json('data');
        ExamCollaborator::where('user_id', $actor->id)->update(['status' => 'REVOKED']);
        $this->getJson($this->offlineBase().'/package')->assertForbidden();
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent()]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'AUTHORIZATION_CHANGED');
        ExamCollaborator::where('user_id', $actor->id)->update(['status' => 'ACTIVE', 'access_code_hash' => hash('sha256', 'new-episode')]);
        $this->getJson($this->offlineBase().'/package')->assertConflict()->assertJsonPath('code', 'DISTRIBUTION_STALE');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_valid_sync_replay_id_reuse_and_duplicate_use_official_history(): void
    {
        $package = $this->preparePackage();
        $event = $this->offlineEvent();
        $batch = $this->offlineBatch($package, [$event]);
        $first = $this->postJson($this->offlineBase().'/sync', $batch)->assertOk()
            ->assertJsonPath('data.results.0.status', 'ACCEPTED')->json();
        $this->postJson($this->offlineBase().'/sync', $batch)->assertOk()->assertExactJson($first);
        $batch['events'][0]['device_id'] = 'device-B';
        $this->postJson($this->offlineBase().'/sync', $batch)->assertOk()->assertJsonPath('data.results.0.code', 'EVENT_ID_REUSED');
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent()]))
            ->assertOk()->assertJsonPath('data.results.0.status', 'DUPLICATE');
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertDatabaseCount('exam_offline_sync_events', 2);
        $this->assertDatabaseHas('exam_entries', ['student_id' => $event['student_id'], 'verified_by' => $this->fixture['user']->id, 'status' => 'VALID']);
        $this->getJson('/api/v1/exams/'.$this->exam->id.'/entry-control/entries')->assertOk()->assertJsonPath('data.0.entry.id', $first['data']['results'][0]['entry_id']);
        $this->assertSame(2, AuditLog::where('action', 'OFFLINE_EVENT_PROCESSED')->count());
        $this->exam->update(['status' => 'CANCELLED']);
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$event]))->assertOk()->assertExactJson($first);
    }

    public function test_mixed_batch_persists_individual_results(): void
    {
        $invalid = $this->addOfflineStudent();
        $package = $this->preparePackage();
        DB::table('exam_eligibilities')->where('student_id', $invalid->id)->update(['status' => 'INELIGIBLE']);
        $events = [$this->offlineEvent(), $this->offlineEvent($invalid->id), $this->offlineEvent(999999)];
        $response = $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, $events))->assertOk();
        $this->assertSame(['ACCEPTED', 'REJECTED', 'REJECTED'], array_column($response->json('data.results'), 'status'));
        $this->assertDatabaseCount('exam_offline_sync_events', 3);
        $this->assertDatabaseCount('exam_entries', 1);
    }

    public function test_student_from_other_list_is_rejected(): void
    {
        $a = $this->offlineCollaborator();
        $this->offlineCollaborator();
        $this->addOfflineStudent('Zeta');
        $lists = $this->postJson($this->offlineBase().'/prepare')->assertOk()->json('data.lists');
        $package = $this->actingAs($a)->getJson($this->offlineBase().'/package')->assertOk()->json('data');
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent($lists[1]['students'][0]['student_id'])]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'STUDENT_NOT_ASSIGNED');
    }

    public function test_cancelled_exam_blocks_download_and_sync(): void
    {
        $package = $this->preparePackage();
        // Query update isolates the state condition from the separate exam revision check.
        DB::table('exams')->where('id', $this->exam->id)->update(['status' => 'CANCELLED']);
        $this->getJson($this->offlineBase().'/package')->assertConflict()->assertJsonPath('code', 'EXAM_CANCELLED');
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent()]))
            ->assertOk()->assertJsonPath('data.results.0.status', 'CONFLICT')->assertJsonPath('data.results.0.code', 'EXAM_CANCELLED');
    }

    public function test_wrong_version_room_and_changed_eligibility_are_not_accepted(): void
    {
        $package = $this->preparePackage();
        $batch = $this->offlineBatch($package, [$this->offlineEvent()]);
        $batch['version'] = 2;
        $this->postJson($this->offlineBase().'/sync', $batch)->assertOk()->assertJsonPath('data.results.0.code', 'LIST_VERSION_MISMATCH');
        $batch = $this->offlineBatch($package, [$this->offlineEvent()]);
        $batch['room_id'] = 999;
        $this->postJson($this->offlineBase().'/sync', $batch)->assertOk()->assertJsonPath('data.results.0.code', 'ROOM_MISMATCH');
        DB::table('exam_eligibilities')->where('exam_id', $this->exam->id)->increment('revision');
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent()]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'STUDENT_DATA_CHANGED');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_impossible_capture_and_late_sync_are_conflicts(): void
    {
        $package = $this->preparePackage();
        $future = $this->offlineEvent(null, ['captured_at' => now()->addMinute()->toISOString()]);
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$future]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'CAPTURE_TIME_UNVERIFIABLE');
        $event = $this->offlineEvent();
        $this->travelTo(now()->addHours(2));
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$event]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'EXAM_ENDED');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_qr_authenticity_expiration_and_revocation(): void
    {
        $package = $this->preparePackage();
        $token = app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id)['token'];
        $bad = $this->offlineEvent(null, ['method' => 'QR', 'qr_token' => $token.'x']);
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$bad]))->assertOk()->assertJsonPath('data.results.0.code', 'INVALID_QR');
        $claims = app(QrTokenValidator::class)->decode($token);
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $claims['exp'] = now()->subSecond()->timestamp;
        $input = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($claims);
        $expired = $input.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $input, config('app.key'), true)), '+/', '-_'), '=');
        $this->assertNull(app(QrTokenValidator::class)->decode($expired));
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent(null, ['method' => 'QR', 'qr_token' => $expired])]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'QR_EXPIRED_UNVERIFIABLE');
        StudentQrToken::query()->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent(null, ['method' => 'QR', 'qr_token' => $token])]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'QR_REVOKED');
        StudentQrToken::query()->update(['status' => 'ACTIVE', 'revoked_at' => null]);
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent(null, ['method' => 'QR', 'qr_token' => $token])]))
            ->assertOk()->assertJsonPath('data.results.0.status', 'ACCEPTED');
        $this->assertStringNotContainsString($token, DB::table('exam_offline_sync_events')->get()->toJson());
    }

    public function test_schema_integrity_and_authentication_validation(): void
    {
        $package = $this->preparePackage();
        $batch = $this->offlineBatch($package, [$this->offlineEvent(null, ['captured_at' => '2026-11-02 10:05:00'])]);
        $this->postJson($this->offlineBase().'/sync', $batch)->assertUnprocessable();
        $this->assertDatabaseCount('exam_offline_sync_events', 0);
        $indexes = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND name IN ('uq_exam_entries_valid_student', 'offline_member_exam_student_unique')");
        $this->assertCount(2, $indexes);
        $this->assertStringContainsString("WHERE status = 'VALID'", $indexes[0]->sql.$indexes[1]->sql);
        auth()->forgetGuards();
        $this->getJson($this->offlineBase().'/package')->assertUnauthorized();
    }

    public function test_empty_lists_and_changed_exam_are_handled(): void
    {
        DB::table('enrollments')->update(['status' => 'INACTIVE']);
        $package = $this->preparePackage();
        $this->assertSame([], $package['students']);
        $this->assertSame(['from' => null, 'to' => null], $package['surname_range']);
        $this->exam->update(['room_id' => $this->exam->room_id, 'name' => 'Examen cambiado']);
        $this->getJson($this->offlineBase().'/package')->assertConflict()->assertJsonPath('code', 'DISTRIBUTION_STALE');
    }

    public function test_audit_failure_rolls_back_attendance_and_idempotency_event(): void
    {
        $package = $this->preparePackage();
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent()]));
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('exam_entries', 0);
        $this->assertDatabaseCount('exam_offline_sync_events', 0);
    }

    public function test_changed_student_identification_inactive_enrollment_and_missing_qr_evidence(): void
    {
        $package = $this->preparePackage();
        $studentId = $this->fixture['studentId'];
        DB::table('students')->where('id', $studentId)->update(['identity_number' => 'changed']);
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent()]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'STUDENT_DATA_CHANGED');
        DB::table('students')->where('id', $studentId)->update(['identity_number' => $package['students'][0]['identity_number']]);
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent(null, ['method' => 'QR'])]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'INVALID_QR');
        DB::table('enrollments')->where('student_id', $studentId)->update(['status' => 'INACTIVE']);
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [$this->offlineEvent()]))
            ->assertOk()->assertJsonPath('data.results.0.status', 'REJECTED');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_authorized_collaborator_accepts_own_students_and_cannot_reuse_another_actors_event(): void
    {
        $a = $this->offlineCollaborator();
        $b = $this->offlineCollaborator();
        $this->addOfflineStudent('Zeta');
        $this->postJson($this->offlineBase().'/prepare')->assertOk();
        $packageA = $this->actingAs($a)->getJson($this->offlineBase().'/package')->assertOk()->json('data');
        $eventA = $this->offlineEvent($packageA['students'][0]['student_id']);
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($packageA, [$eventA]))
            ->assertOk()->assertJsonPath('data.results.0.status', 'ACCEPTED');
        $this->assertDatabaseHas('exam_entries', ['verified_by' => $a->id, 'student_id' => $eventA['student_id']]);
        $packageB = $this->actingAs($b)->getJson($this->offlineBase().'/package')->assertOk()->json('data');
        $eventB = $this->offlineEvent($packageB['students'][0]['student_id'], ['event_id' => $eventA['event_id']]);
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($packageB, [$eventB]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'EVENT_ID_REUSED')->assertJsonPath('data.results.0.entry_id', null);
        $this->assertDatabaseCount('exam_offline_sync_events', 1);
        $this->assertDatabaseCount('exam_entries', 1);
    }

    public function test_more_collaborators_than_students_yields_safe_empty_lists(): void
    {
        $this->offlineCollaborator();
        $this->offlineCollaborator();
        $this->offlineCollaborator();
        $lists = $this->postJson($this->offlineBase().'/prepare')->assertOk()->json('data.lists');
        $this->assertSame([1, 0, 0], array_column($lists, 'student_count'));
        $this->assertSame(['from' => null, 'to' => null], $lists[2]['surname_range']);
    }

    public function test_qr_must_match_student_and_cannot_predate_its_generation(): void
    {
        $other = $this->addOfflineStudent();
        $package = $this->preparePackage();
        $captured = now()->toISOString();
        $this->travelTo(now()->addMinute());
        $token = app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id)['token'];
        $this->postJson($this->offlineBase().'/sync', $this->offlineBatch($package, [
            $this->offlineEvent($other->id, ['method' => 'QR', 'qr_token' => $token]),
            $this->offlineEvent(null, ['method' => 'QR', 'qr_token' => $token, 'captured_at' => $captured]),
        ]))->assertOk()->assertJsonPath('data.results.0.code', 'QR_MISMATCH')
            ->assertJsonPath('data.results.1.code', 'CAPTURE_TIME_UNVERIFIABLE');
        $this->assertDatabaseCount('exam_entries', 0);
    }
}
