<?php

namespace Tests\Feature\Offline;

use App\Models\MobileDevice;
use App\Services\Auth\MobileTokenPermissions;
use App\Services\Students\StudentQrService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConfiguresQrV3Issuer;
use Tests\Support\IsolatedMobileDatabase;
use Tests\Support\OfflineExamFixture;
use Tests\TestCase;

class MobileOfflineSyncSecurityTest extends TestCase
{
    use ConfiguresQrV3Issuer, IsolatedMobileDatabase, OfflineExamFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
        $this->createOfflineFixture();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
    }

    private function credential(): array
    {
        $actor = $this->fixture['user'];
        $device = MobileDevice::create(['user_id' => $actor->id, 'installation_id' => (string) Str::uuid(),
            'name' => 'SEC-01 synthetic device', 'last_login_at' => now()]);
        $token = $actor->createToken('eida-control:sec-01', MobileTokenPermissions::ABILITIES, now()->addHours(4));
        $token->accessToken->forceFill(['mobile_device_id' => $device->id])->save();

        return [$device, $token->plainTextToken];
    }

    private function callAs(?string $bearer, string $method, string $suffix, array $body = [])
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        if ($bearer === null) {
            $this->actingAs($this->fixture['user']);
        } else {
            $this->withHeader('Authorization', 'Bearer '.$bearer);
        }

        return $this->json($method, $this->offlineBase().$suffix, $body);
    }

    private function boundPackage(): array
    {
        [$device, $bearer] = $this->credential();
        $this->callAs($bearer, 'POST', '/prepare')->assertOk();
        $package = $this->callAs($bearer, 'GET', '/package?schema_version=2')->assertOk()->json('data');

        return [$device, $bearer, $package];
    }

    private function event(MobileDevice $device, array $changes = []): array
    {
        return $this->offlineEvent(null, array_replace([
            'device_id' => $device->installation_id,
            'time_evidence' => ['serverMs' => now()->timestamp * 1000, 'boot' => '3', 'elapsed' => 100000,
                'capturedElapsed' => 100000, 'source' => 'SERVER_ANCHORED_ESTIMATE'],
        ], $changes));
    }

    private function batch(array $package, array $event): array
    {
        return ['protocol_version' => 2] + $this->offlineBatch($package, [$event]);
    }

    public function test_mobile_package_rejects_missing_and_legacy_protocol_without_claiming_the_uuid(): void
    {
        [$device, $bearer, $package] = $this->boundPackage();
        $event = $this->event($device);
        $legacy = $event;
        unset($legacy['time_evidence']);

        foreach ([null, 1] as $protocol) {
            $batch = $this->offlineBatch($package, [$legacy]);
            if ($protocol !== null) {
                $batch['protocol_version'] = $protocol;
            }
            $this->callAs($bearer, 'POST', '/sync', $batch)->assertUnprocessable()
                ->assertJsonPath('code', 'MOBILE_SYNC_PROTOCOL_REQUIRED');
            $this->callAs(null, 'POST', '/sync', $batch)->assertUnprocessable()
                ->assertJsonPath('code', 'MOBILE_SYNC_PROTOCOL_REQUIRED');
            $this->assertDatabaseCount('exam_entries', 0);
            $this->assertDatabaseCount('exam_offline_sync_events', 0);
        }

        // Rejected transport does not consume or replace the original UUID.
        $this->callAs($bearer, 'POST', '/sync', $this->batch($package, $event))->assertOk()
            ->assertJsonPath('data.results.0.event_id', $event['event_id'])
            ->assertJsonPath('data.results.0.status', 'ACCEPTED');
    }

    public function test_another_installation_and_web_cookie_cannot_replay_an_accepted_mobile_receipt(): void
    {
        [$device, $bearer, $package] = $this->boundPackage();
        [, $otherBearer] = $this->credential();
        $batch = $this->batch($package, $this->event($device));
        $first = $this->callAs($bearer, 'POST', '/sync', $batch)->assertOk()
            ->assertJsonPath('data.results.0.status', 'ACCEPTED')->json();
        $stored = DB::table('exam_offline_sync_events')->first();

        foreach ([$otherBearer, null] as $credential) {
            $this->callAs($credential, 'POST', '/sync', $batch)->assertOk()
                ->assertJsonPath('data.results.0.status', 'CONFLICT')
                ->assertJsonPath('data.results.0.code', 'DEVICE_BINDING_MISMATCH');
            $this->assertSame($stored->result, DB::table('exam_offline_sync_events')->value('result'));
            $this->assertSame($stored->evidence_ciphertext, DB::table('exam_offline_sync_events')->value('evidence_ciphertext'));
        }

        $downgrade = $batch;
        $downgrade['protocol_version'] = 1;
        unset($downgrade['events'][0]['time_evidence']);
        $this->callAs($bearer, 'POST', '/sync', $downgrade)->assertUnprocessable()
            ->assertJsonPath('code', 'MOBILE_SYNC_PROTOCOL_REQUIRED');

        $this->callAs($bearer, 'POST', '/sync', $batch)->assertExactJson($first);
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertDatabaseCount('exam_offline_sync_events', 1);
    }

    public function test_revoked_device_cannot_replay_a_mobile_receipt_with_token_or_web_cookie(): void
    {
        [$device, $bearer, $package] = $this->boundPackage();
        $batch = $this->batch($package, $this->event($device));
        $this->callAs($bearer, 'POST', '/sync', $batch)->assertOk()->assertJsonPath('data.results.0.status', 'ACCEPTED');
        $stored = DB::table('exam_offline_sync_events')->first();
        $device->update(['revoked_at' => now()]);

        $this->callAs($bearer, 'POST', '/sync', $batch)->assertUnauthorized()->assertJsonPath('code', 'MOBILE_DEVICE_REVOKED');
        $this->callAs(null, 'POST', '/sync', $batch)->assertOk()->assertJsonPath('data.results.0.code', 'DEVICE_BINDING_MISMATCH');
        $this->assertSame($stored->result, DB::table('exam_offline_sync_events')->value('result'));
        $this->assertSame($stored->evidence_ciphertext, DB::table('exam_offline_sync_events')->value('evidence_ciphertext'));
        $this->assertDatabaseCount('exam_entries', 1);
    }

    public function test_device_conflict_receipt_never_becomes_accepted_when_retried_with_the_original_device(): void
    {
        [$device, $bearer, $package] = $this->boundPackage();
        [, $otherBearer] = $this->credential();
        $batch = $this->batch($package, $this->event($device));
        $first = $this->callAs($otherBearer, 'POST', '/sync', $batch)->assertOk()
            ->assertJsonPath('data.results.0.status', 'CONFLICT')
            ->assertJsonPath('data.results.0.code', 'DEVICE_BINDING_MISMATCH')->json();

        $this->callAs($bearer, 'POST', '/sync', $batch)->assertExactJson($first);
        $this->assertDatabaseCount('exam_entries', 0);
        $this->assertDatabaseCount('exam_offline_sync_events', 1);
    }

    public function test_valid_mobile_v3_sync_is_idempotent_and_keeps_qr_evidence(): void
    {
        $this->configureQrV3Issuer();
        [$device, $bearer, $package] = $this->boundPackage();
        $qr = app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id, 3)['token'];
        $this->callAs($bearer, 'POST', '/sync', $this->batch($package, $this->event($device, ['method' => 'QR', 'qr_token' => $qr.'x'])))
            ->assertOk()->assertJsonPath('data.results.0.status', 'REJECTED')->assertJsonPath('data.results.0.code', 'INVALID_QR');
        $this->assertDatabaseCount('exam_entries', 0);
        $event = $this->event($device, ['method' => 'QR', 'qr_token' => $qr]);
        $batch = $this->batch($package, $event);
        $first = $this->callAs($bearer, 'POST', '/sync', $batch)->assertOk()
            ->assertJsonPath('data.protocol_version', 2)->assertJsonPath('data.results.0.status', 'ACCEPTED')->json();
        $this->callAs($bearer, 'POST', '/sync', $batch)->assertExactJson($first);

        $ciphertext = DB::table('exam_offline_sync_events')->where('event_id', $event['event_id'])->value('evidence_ciphertext');
        $evidence = json_decode(Crypt::decryptString($ciphertext), true);
        $this->assertSame($event['event_id'], $evidence['event']['event_id']);
        $this->assertSame($qr, $evidence['event']['qr_token']);
        $this->assertTrue($evidence['server_qr_assessment']['authentic']);
        $this->assertSame(3, $evidence['server_qr_assessment']['version']);
        $this->callAs($bearer, 'POST', '/sync', $this->batch($package, $this->event($device)))
            ->assertOk()->assertJsonPath('data.results.0.status', 'DUPLICATE');
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertDatabaseCount('exam_offline_sync_events', 3);
    }

    public function test_non_mobile_web_package_keeps_the_legacy_protocol_contract(): void
    {
        $this->callAs(null, 'POST', '/prepare')->assertOk();
        $package = $this->callAs(null, 'GET', '/package')->assertOk()->json('data');
        $event = $this->offlineEvent();
        $batch = $this->offlineBatch($package, [$event]);
        $first = $this->callAs(null, 'POST', '/sync', $batch)->assertOk()
            ->assertJsonPath('data.results.0.status', 'ACCEPTED')->json();
        $this->assertArrayNotHasKey('protocol_version', $first['data']);
        $batch['protocol_version'] = 1;
        $this->callAs(null, 'POST', '/sync', $batch)->assertExactJson($first);
        $this->assertDatabaseCount('exam_entries', 1);
    }
}
