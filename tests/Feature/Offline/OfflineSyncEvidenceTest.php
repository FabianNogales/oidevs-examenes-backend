<?php

namespace Tests\Feature\Offline;

use App\Models\ExamCollaborator;
use App\Models\MobileDevice;
use App\Models\StudentQrToken;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\MobileTokenPermissions;
use App\Services\QrV3\QrV3KeyRing;
use App\Services\QrV3\QrV3Service;
use App\Services\Students\StudentQrService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedMobileDatabase;
use Tests\Support\OfflineExamFixture;
use Tests\Support\QrV3TestKeys;
use Tests\TestCase;

class OfflineSyncEvidenceTest extends TestCase
{
    use IsolatedMobileDatabase, OfflineExamFixture;

    protected function setUp(): void
    {
        parent::setUp(); $this->refreshDatabase(); $this->createOfflineFixture();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'offline_sync.qr_v3_verify_enabled' => false]);
        $this->actingAs($this->fixture['user']);
    }

    private function package(): array
    {
        $this->postJson($this->offlineBase().'/prepare')->assertOk();
        return $this->getJson($this->offlineBase().'/package')->assertOk()->json('data');
    }

    private function evidenceEvent(?int $student = null, array $extra = []): array
    {
        return $this->offlineEvent($student, [...$extra, 'time_evidence' => [
            'serverMs' => now()->timestamp * 1000, 'boot' => '3', 'elapsed' => 100000,
            'capturedElapsed' => 100000, 'source' => 'SERVER_ANCHORED_ESTIMATE']]);
    }

    private function batch(array $package, array $events): array
    {
        return ['protocol_version' => 2] + $this->offlineBatch($package, $events);
    }

    private function sync(array $batch)
    {
        return $this->postJson($this->offlineBase().'/sync', $batch);
    }

    public function test_v2_receipt_is_atomic_idempotent_and_keeps_original_encrypted_evidence(): void
    {
        $package = $this->package(); $event = $this->evidenceEvent(); $batch = $this->batch($package, [$event]);
        $first = $this->sync($batch)->assertOk()->assertJsonPath('data.protocol_version', 2)
            ->assertJsonPath('data.results.0.status', 'ACCEPTED')->assertJsonPath('data.results.0.review_required', false)->json();
        $this->sync($batch)->assertExactJson($first);
        $stored = DB::table('exam_offline_sync_events')->first();
        $evidence = json_decode(Crypt::decryptString($stored->evidence_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($event, $evidence['event']);
        $this->assertSame($this->fixture['user']->id, $evidence['authenticated_actor_id']);
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'OFFLINE_EVENT_PROCESSED')->count());
        $batch['events'][0]['time_evidence']['capturedElapsed']++;
        $this->sync($batch)->assertOk()->assertJsonPath('data.results.0.code', 'EVENT_ID_REUSED');
        $this->assertSame($stored->evidence_ciphertext, DB::table('exam_offline_sync_events')->value('evidence_ciphertext'));
        $conflict = DB::table('audit_logs')->where('action', 'OFFLINE_EVENT_ID_CONFLICT')->first();
        $this->assertStringContainsString('attempt_evidence_ciphertext', $conflict->new_values);
    }

    public function test_100_event_limit_mixed_results_and_structural_errors(): void
    {
        $package = $this->package();
        $events = array_map(fn () => $this->evidenceEvent(), range(1, 100));
        $response = $this->sync($this->batch($package, $events))->assertOk()
            ->assertJsonCount(100, 'data.results')->assertJsonPath('data.results.0.status', 'ACCEPTED')
            ->assertJsonPath('data.results.1.status', 'DUPLICATE');
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertDatabaseCount('exam_offline_sync_events', 100);
        $this->sync($this->batch($package, [...$events, $this->evidenceEvent()]))->assertUnprocessable();
        $bad = $this->batch($package, [$this->evidenceEvent()]); $bad['events'][0]['time_evidence']['source'] = 'PHONE_TRUSTED';
        $this->sync($bad)->assertUnprocessable();
        $this->sync($this->batch($package, [$this->evidenceEvent(999999), $this->evidenceEvent(null, ['method' => 'QR', 'qr_token' => 'bad'])]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'STUDENT_NOT_ASSIGNED')->assertJsonPath('data.results.1.code', 'INVALID_QR');
    }

    public function test_legacy_contract_has_no_new_public_fields_and_qr_v2_still_works(): void
    {
        $package = $this->package();
        $qr = app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id)['token'];
        $result = $this->sync($this->offlineBatch($package, [$this->offlineEvent(null, ['method' => 'QR', 'qr_token' => $qr])]))
            ->assertOk()->assertJsonPath('data.results.0.status', 'ACCEPTED')->json('data');
        $this->assertArrayNotHasKey('protocol_version', $result);
        $this->assertSame(['event_id', 'status', 'code', 'message', 'entry_id'], array_keys($result['results'][0]));
        $this->assertStringNotContainsString($qr, DB::table('exam_offline_sync_events')->get()->toJson());
    }

    public function test_late_evidence_is_retained_and_audited_without_trusting_phone_time(): void
    {
        $package = $this->package(); $event = $this->evidenceEvent(); $batch = $this->batch($package, [$event]);
        $this->travelTo(now()->addHours(2));
        $first = $this->sync($batch)->assertOk()->assertJsonPath('data.results.0.code', 'EXAM_ENDED')
            ->assertJsonPath('data.results.0.review_required', true)->json();
        $this->sync($batch)->assertExactJson($first);
        $stored = DB::table('exam_offline_sync_events')->first();
        $this->assertTrue((bool) $stored->review_required);
        $this->assertSame($event, json_decode(Crypt::decryptString($stored->evidence_ciphertext), true)['event']);
        $this->assertDatabaseCount('exam_entries', 0);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'OFFLINE_EVIDENCE_REVIEW_REQUIRED')->count());
        $this->getJson($this->offlineBase().'/sync-capabilities')->assertOk()->assertJsonPath('data.late_policy', 'REVIEW_ONLY');
    }

    public function test_list_conflict_revoked_collaborator_and_foreign_package_do_not_create_attendance(): void
    {
        $collaborator = $this->offlineCollaborator();
        $this->postJson($this->offlineBase().'/prepare')->assertOk();
        $package = $this->actingAs($collaborator)->getJson($this->offlineBase().'/package')->assertOk()->json('data');
        $batch = $this->batch($package, [$this->evidenceEvent()]); $batch['version'] = 2;
        $this->sync($batch)->assertOk()->assertJsonPath('data.results.0.code', 'LIST_VERSION_MISMATCH');
        ExamCollaborator::where('user_id', $collaborator->id)->update(['status' => 'REVOKED']);
        $this->getJson($this->offlineBase().'/sync-capabilities')->assertOk();
        $this->sync($this->batch($package, [$this->evidenceEvent()]))->assertOk()->assertJsonPath('data.results.0.code', 'AUTHORIZATION_CHANGED');
        $this->actingAs($this->fixture['user']);
        $this->sync($this->batch($package, [$this->evidenceEvent()]))->assertForbidden()->assertJsonPath('code', 'PACKAGE_NOT_OWNED');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    private function verifierFixture(): array
    {
        $private = QrV3TestKeys::rsa(); $details = openssl_pkey_get_details($private);
        $path = tempnam(sys_get_temp_dir(), 'eida_sync_public_'); file_put_contents($path, $details['key']);
        $this->beforeApplicationDestroyed(fn () => is_file($path) ? unlink($path) : null);
        config(['offline_sync.qr_v3_verify_enabled' => true, 'qr_v3.kid' => 'sync-test', 'qr_v3.public_key_path' => $path,
            'qr_v3.issuer' => 'https://eida.example.test', 'qr_v3.audience' => 'eida-control-qr']);
        $service = new QrV3Service(new QrV3KeyRing(['sync-test' => $details['key']], 'sync-test', $private),
            'https://eida.example.test', 'eida-control-qr');
        $jti = (string) Str::uuid();
        StudentQrToken::create(['token' => $jti, 'exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'],
            'status' => 'ACTIVE', 'generated_at' => now('America/La_Paz')->subMinute()->format('Y-m-d H:i:s')]);
        return [$service, $jti];
    }

    public function test_server_verifies_v3_signature_subject_exam_and_registry_without_enabling_issuance(): void
    {
        $package = $this->package(); [$service, $jti] = $this->verifierFixture();
        $token = $service->issue($jti, $this->fixture['studentId'], $this->exam->id, now()->timestamp - 60, now()->timestamp - 60, now()->timestamp + 600);
        $this->getJson($this->offlineBase().'/sync-capabilities')->assertOk()->assertJsonPath('data.qr_versions', [2, 3]);
        $this->sync($this->batch($package, [$this->evidenceEvent(null, ['method' => 'QR', 'qr_token' => $token.'x'])]))
            ->assertOk()->assertJsonPath('data.results.0.status', 'REJECTED');
        $wrong = $service->issue($jti, $this->fixture['studentId'], $this->exam->id + 1, now()->timestamp - 60, now()->timestamp - 60, now()->timestamp + 600);
        $this->sync($this->batch($package, [$this->evidenceEvent(null, ['method' => 'QR', 'qr_token' => $wrong])]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'QR_MISMATCH');
        $this->sync($this->batch($package, [$this->evidenceEvent(null, ['method' => 'QR', 'qr_token' => $token])]))
            ->assertOk()->assertJsonPath('data.results.0.status', 'ACCEPTED');
        $this->assertFalse((bool) config('mobile_offline.qr_v3_issued'));
    }

    public function test_expired_v3_is_authentic_evidence_but_never_automatically_confirms_a_late_entry(): void
    {
        $package = $this->package(); [$service, $jti] = $this->verifierFixture();
        $token = $service->issue($jti, $this->fixture['studentId'], $this->exam->id, now()->timestamp - 60, now()->timestamp - 60, now()->timestamp + 600);
        $event = $this->evidenceEvent(null, ['method' => 'QR', 'qr_token' => $token]);
        $this->travelTo(now()->addHours(2));
        $this->sync($this->batch($package, [$event]))->assertOk()->assertJsonPath('data.results.0.code', 'EXAM_ENDED');
        $evidence = json_decode(Crypt::decryptString(DB::table('exam_offline_sync_events')->value('evidence_ciphertext')), true);
        $this->assertTrue($evidence['server_qr_assessment']['authentic']);
        $this->assertSame($token, $evidence['event']['qr_token']); $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_v3_requires_registry_subject_current_authorization_and_nonexpired_server_time(): void
    {
        $package = $this->package(); [$service, $jti] = $this->verifierFixture();
        $issue = fn ($tokenId, $student, $end) => $service->issue($tokenId, $student, $this->exam->id,
            now()->timestamp - 120, now()->timestamp - 120, $end);
        $unknown = $issue((string) Str::uuid(), $this->fixture['studentId'], now()->timestamp + 600);
        $wrong = $issue($jti, $this->fixture['studentId'] + 999, now()->timestamp + 600);
        foreach ([$unknown, $wrong] as $token) {
            $this->sync($this->batch($package, [$this->evidenceEvent(null, ['method' => 'QR', 'qr_token' => $token])]))
                ->assertOk()->assertJsonPath('data.results.0.code', 'QR_MISMATCH');
        }
        $token = $issue($jti, $this->fixture['studentId'], now()->timestamp + 600);
        StudentQrToken::where('token', $jti)->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->sync($this->batch($package, [$this->evidenceEvent(null, ['method' => 'QR', 'qr_token' => $token])]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'QR_REVOKED');
        StudentQrToken::where('token', $jti)->update(['status' => 'ACTIVE', 'revoked_at' => null]);
        $expired = $issue($jti, $this->fixture['studentId'], now()->timestamp - 1);
        $this->sync($this->batch($package, [$this->evidenceEvent(null, ['method' => 'QR', 'qr_token' => $expired])]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'QR_EXPIRED_UNVERIFIABLE');
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_audit_failure_rolls_back_event_receipt_and_official_entry(): void
    {
        $package = $this->package();
        $audit = \Mockery::mock(AuditLogService::class); $audit->shouldReceive('log')->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->app->instance(AuditLogService::class, $audit); $this->withoutExceptionHandling();
        try { $this->sync($this->batch($package, [$this->evidenceEvent()])); $this->fail('Expected audit failure'); }
        catch (\RuntimeException $e) { $this->assertSame('Synthetic audit failure', $e->getMessage()); }
        $this->assertDatabaseCount('exam_entries', 0); $this->assertDatabaseCount('exam_offline_sync_events', 0);
    }

    public function test_mobile_device_and_account_revocation_block_sync_without_stealing_the_original_actor(): void
    {
        $package = $this->package(); $actor = $this->fixture['user'];
        $device = MobileDevice::create(['user_id' => $actor->id, 'installation_id' => (string) Str::uuid(), 'name' => 'Synthetic device', 'last_login_at' => now()]);
        $token = $actor->createToken('eida-control:sync-test', MobileTokenPermissions::ABILITIES, now()->addHours(4));
        $token->accessToken->forceFill(['mobile_device_id' => $device->id])->save();
        $this->app['auth']->forgetGuards(); $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);
        $device->forceFill(['revoked_at' => now()])->save();
        $this->sync($this->batch($package, [$this->evidenceEvent()]))->assertUnauthorized()->assertJsonPath('code', 'MOBILE_DEVICE_REVOKED');
        $device->forceFill(['revoked_at' => null])->save(); $actor->forceFill(['status' => 'INACTIVE'])->save(); $this->app['auth']->forgetGuards();
        $this->sync($this->batch($package, [$this->evidenceEvent()]))->assertUnauthorized()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertDatabaseCount('exam_entries', 0); $this->assertDatabaseCount('exam_offline_sync_events', 0);
    }

    public function test_mobile_package_first_processing_requires_its_original_device_binding(): void
    {
        $actor = $this->fixture['user'];
        $second = $this->addOfflineStudent('Segundo');
        $credential = function () use ($actor) {
            $device = MobileDevice::create(['user_id' => $actor->id, 'installation_id' => (string) Str::uuid(),
                'name' => 'Bound synthetic device', 'last_login_at' => now()]);
            $token = $actor->createToken('eida-control:bound-sync-test', MobileTokenPermissions::ABILITIES, now()->addHours(4));
            $token->accessToken->forceFill(['mobile_device_id' => $device->id])->save();
            return [$device, $token->plainTextToken];
        };
        [$original, $token] = $credential(); [, $otherToken] = $credential();
        $call = function ($bearer, $method, $suffix, $body = []) {
            $this->app['auth']->forgetGuards();
            return $this->withHeader('Authorization', 'Bearer '.$bearer)->json($method, $this->offlineBase().$suffix, $body);
        };
        $call($token, 'POST', '/prepare')->assertOk();
        $package = $call($token, 'GET', '/package?schema_version=2')->assertOk()->json('data');
        $event = $this->evidenceEvent(null, ['device_id' => $original->installation_id]);
        $call($otherToken, 'POST', '/sync', $this->batch($package, [$event]))->assertOk()->assertJsonPath('data.results.0.code', 'DEVICE_BINDING_MISMATCH');
        $call($token, 'POST', '/sync', $this->batch($package, [$this->evidenceEvent($second->id, ['device_id' => $original->installation_id])]))
            ->assertOk()->assertJsonPath('data.results.0.status', 'ACCEPTED');
        $this->assertDatabaseCount('exam_entries', 1);
    }
}
