<?php

namespace Tests\Feature\Offline;

use App\Models\ExamCollaborator;
use App\Models\ExamOfflinePackage;
use App\Models\MobileDevice;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\MobileTokenPermissions;
use App\Services\QrV3\QrV3KeyRing;
use App\Services\QrV3\QrV3Service;
use App\Services\Students\StudentQrService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ConfiguresQrV3Issuer;
use Tests\Support\IsolatedMobileDatabase;
use Tests\Support\OfflineExamFixture;
use Tests\Support\QrV3TestKeys;
use Tests\TestCase;

// Written for isolated SQLite :memory:. Never run against an application database.
class MobileOfflinePackageTest extends TestCase
{
    use ConfiguresQrV3Issuer, IsolatedMobileDatabase, OfflineExamFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
        $this->createOfflineFixture();
        config(['mobile_offline.qr_v3_issued' => false]);
    }

    private function bearer(User $user, ?array $abilities = null, ?string $installation = null): string
    {
        $device = MobileDevice::create(['user_id' => $user->id, 'installation_id' => $installation ?? (string) Str::uuid(),
            'name' => 'Phase 4 test', 'platform' => 'android', 'last_login_at' => now()]);
        $token = $user->createToken('eida-control:phase4-test', $abilities ?? MobileTokenPermissions::ABILITIES, now()->addHours(2));
        $token->accessToken->forceFill(['mobile_device_id' => $device->id])->save();
        return $token->plainTextToken;
    }

    private function mobile(string $token, string $method, string $suffix): TestResponse
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', 'Bearer '.$token)->json($method, $this->offlineBase().$suffix);
    }

    private function manifest(TestResponse $response): array
    {
        $mobile = $response->assertOk()->json('data.mobile');
        $this->assertSame(1, $mobile['schema_version']);
        $this->assertSame(hash('sha256', $mobile['manifest_json']), $mobile['manifest_sha256']);
        return json_decode($mobile['manifest_json'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function photograph(int $studentId, string $kind = 'historical'): string
    {
        // Public synthetic one-pixel fixture, never real student material.
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aVZ0AAAAASUVORK5CYII=');
        $id = (string) Str::uuid();
        DB::table('stored_images')->insert(['id' => $id, 'purpose' => $kind, 'student_id' => $studentId,
            'mime_type' => 'image/png', 'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'width' => 1, 'height' => 1, 'uploaded_at' => now(), 'content' => $bytes]);
        $student = Student::findOrFail($studentId);
        if ($kind === 'historical') {
            $student->forceFill(['historical_image_id' => $id, 'historical_photo_revision' => 1])->save();
        } else {
            $student->user->forceFill(['profile_image_id' => $id])->save();
        }
        return $bytes;
    }

    public function test_owner_prepares_exclusive_frozen_lists_and_web_contract_is_unchanged(): void
    {
        $this->offlineCollaborator(); $this->offlineCollaborator(); $this->addOfflineStudent('Zeta');
        $token = $this->bearer($this->fixture['user']);
        $first = $this->mobile($token, 'POST', '/prepare')->assertOk()->json('data');
        $this->mobile($token, 'GET', '/assignments')->assertExactJson(['data' => $first]);
        $members = collect($first['lists'])->flatMap(fn ($list) => $list['students'])->pluck('student_id');
        $this->assertCount(2, $members->unique());
        $this->assertCount(1, $first['lists'][0]['students']);
        $this->assertArrayNotHasKey('schema_version', $first);
        $this->addOfflineStudent('Zulu');
        $this->mobile($token, 'POST', '/prepare')->assertConflict()->assertJsonPath('code', 'DISTRIBUTION_FROZEN');
        $this->mobile($token, 'GET', '/mobile-context')->assertOk()->assertJsonPath('data.stale', true)->assertJsonPath('data.frozen', true);
        $this->assertDatabaseCount('exam_offline_distributions', 1);
    }

    public function test_collaborator_cannot_prepare_or_inspect_other_lists_and_owner_can_supervise_without_package(): void
    {
        $a = $this->offlineCollaborator(); $this->offlineCollaborator(); $this->addOfflineStudent('Zeta');
        $owner = $this->bearer($this->fixture['user']); $collaborator = $this->bearer($a);
        $this->mobile($owner, 'POST', '/prepare')->assertOk();
        $this->mobile($collaborator, 'POST', '/prepare')->assertForbidden();
        $this->mobile($collaborator, 'GET', '/assignments')->assertForbidden();
        $c = $this->mobile($collaborator, 'GET', '/mobile-context')->assertOk()->json('data');
        $this->assertFalse($c['can_prepare']); $this->assertCount(1, $c['lists']);
        $this->assertSame((string) $a->id, $c['lists'][0]['responsible_id']);
        $this->mobile($owner, 'GET', '/mobile-context')->assertOk()->assertJsonPath('data.own_list_id', null);
        $this->mobile($owner, 'GET', '/package?schema_version=2')->assertForbidden();
    }

    public function test_mobile_snapshot_is_versioned_bound_hashed_and_default_web_payload_has_no_extension(): void
    {
        $token = $this->bearer($this->fixture['user']);
        $this->mobile($token, 'POST', '/prepare')->assertOk();
        $legacy = $this->mobile($token, 'GET', '/package')->assertOk()->json('data');
        $this->assertArrayNotHasKey('mobile', $legacy);
        $response = $this->mobile($token, 'GET', '/package?schema_version=2');
        $m = $this->manifest($response);
        $this->assertSame(1, $m['schema_version']); $this->assertSame(1, $m['version']);
        $this->assertSame((string) $this->fixture['user']->id, $m['binding']['user_id']);
        $this->assertSame($m['package_id'], $m['authorization_id']);
        $this->assertFalse($m['qr']['available']); $this->assertSame([], $m['qr']['keys']);
        $this->assertNull($m['qr']['revoked_jtis']);
        $this->assertSame(['ABSENT', 'ABSENT'], array_column($m['students'][0]['photos'], 'state'));
        $this->assertStringNotContainsString('authorization_hash', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());
        $count = ExamOfflinePackage::count();
        $resume = $this->mobile($token, 'GET', '/packages/'.$m['package_id'])->assertOk();
        $this->assertSame($response->json('data.mobile'), $resume->json('data.mobile'));
        $this->assertSame($count, ExamOfflinePackage::count());
    }

    public function test_photos_are_original_bytes_authorized_by_own_package_and_member(): void
    {
        $a = $this->offlineCollaborator(); $b = $this->offlineCollaborator(); $other = $this->addOfflineStudent('Zeta');
        $historical = $this->photograph($this->fixture['studentId']);
        $profile = $this->photograph($this->fixture['studentId'], 'profile');
        $owner = $this->bearer($this->fixture['user']); $ta = $this->bearer($a); $tb = $this->bearer($b);
        $this->mobile($owner, 'POST', '/prepare')->assertOk();
        $m = $this->manifest($this->mobile($ta, 'GET', '/package?schema_version=2'));
        foreach (['profile' => $profile, 'historical' => $historical] as $kind => $bytes) {
            $photo = collect($m['students'][0]['photos'])->firstWhere('kind', $kind);
            $this->assertSame(hash('sha256', $bytes), $photo['sha256']);
            $path = '/packages/'.$m['package_id'].'/photos/'.$this->fixture['studentId'].'/'.$kind;
            $r = $this->mobile($ta, 'GET', $path)->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->assertSame($bytes, $r->streamedContent());
            $this->mobile($tb, 'GET', $path)->assertForbidden();
        }
        $this->mobile($ta, 'GET', '/packages/'.$m['package_id'].'/photos/'.$other->id.'/historical')->assertForbidden();
        $this->mobile($owner, 'GET', '/packages/'.$m['package_id'])->assertForbidden();
        $newDevice = $this->bearer($a);
        $this->mobile($newDevice, 'GET', '/packages/'.$m['package_id'])->assertForbidden();
    }

    public function test_mobile_permissions_and_device_revocation_are_enforced(): void
    {
        $owner = $this->fixture['user']; $full = $this->bearer($owner);
        $this->mobile($full, 'POST', '/prepare')->assertOk();
        $limited = $this->bearer($owner, ['control:read']);
        $this->mobile($limited, 'GET', '/mobile-context')->assertOk();
        $this->mobile($limited, 'GET', '/package?schema_version=2')->assertForbidden()->assertJsonPath('code', 'MOBILE_ABILITY_REQUIRED');
        $m = $this->manifest($this->mobile($full, 'GET', '/package?schema_version=2'));
        $this->mobile($limited, 'GET', '/packages/'.$m['package_id'])->assertForbidden();
        MobileDevice::whereKey($m['binding']['device_id'])->update(['revoked_at' => now()]);
        $this->mobile($full, 'GET', '/packages/'.$m['package_id'])->assertUnauthorized();
    }

    public function test_revoked_authorization_frozen_inputs_and_expiry_block_resumption(): void
    {
        $a = $this->offlineCollaborator(); $owner = $this->bearer($this->fixture['user']); $ta = $this->bearer($a);
        $this->mobile($owner, 'POST', '/prepare')->assertOk();
        $m = $this->manifest($this->mobile($ta, 'GET', '/package?schema_version=2'));
        ExamCollaborator::where('user_id', $a->id)->update(['status' => 'REVOKED']);
        $this->mobile($ta, 'GET', '/packages/'.$m['package_id'])->assertForbidden();
        ExamCollaborator::where('user_id', $a->id)->update(['status' => 'ACTIVE']);
        $this->addOfflineStudent('Zeta');
        $this->mobile($ta, 'GET', '/packages/'.$m['package_id'])->assertConflict()->assertJsonPath('code', 'DISTRIBUTION_STALE');
        $this->travelTo(\Carbon\CarbonImmutable::parse(substr((string) $this->exam->exam_date, 0, 10).' 12:00:00', 'America/La_Paz'));
        $this->mobile($ta, 'GET', '/packages/'.$m['package_id'])->assertConflict();
        $this->assertDatabaseCount('exam_offline_packages', 1);
    }

    public function test_web_cookie_clients_keep_legacy_package_and_cannot_impersonate_a_mobile_binding(): void
    {
        $this->actingAs($this->fixture['user']);
        $this->postJson($this->offlineBase().'/prepare')->assertOk();
        $legacy = $this->getJson($this->offlineBase().'/package')->assertOk()->json('data');
        $this->assertArrayNotHasKey('mobile', $legacy);
        $this->assertSame('SERVER_ON_SYNC', $legacy['qr_validation']);
        $this->getJson($this->offlineBase().'/package?schema_version=2')->assertForbidden()->assertJsonPath('code', 'MOBILE_TOKEN_REQUIRED');
        $this->getJson($this->offlineBase().'/mobile-context')->assertForbidden();
    }

    public function test_experimental_flag_alone_never_advertises_production_qr_capability(): void
    {
        config(['qr_v3.enabled' => true, 'mobile_offline.qr_v3_issued' => false]);
        $token = $this->bearer($this->fixture['user']);
        $this->mobile($token, 'POST', '/prepare')->assertOk();
        $m = $this->manifest($this->mobile($token, 'GET', '/package?schema_version=2'));
        $this->assertFalse($m['qr']['available']);
        $this->assertSame([], $m['qr']['keys']);
    }

    public function test_qr_v3_is_published_only_in_new_authorized_mobile_packages(): void
    {
        $owner = $this->bearer($this->fixture['user']);
        $other = $this->bearer(User::factory()->create());
        $this->mobile($owner, 'POST', '/prepare')->assertOk();
        $download = '/package?schema_version=2';
        $previous = $this->mobile($owner, 'GET', $download);
        $previousManifest = $this->manifest($previous);
        $this->assertFalse($previousManifest['qr']['available']);

        $publicPem = $this->configureQrV3Issuer();
        $response = $this->mobile($owner, 'GET', $download);
        $manifest = $this->manifest($response);
        $this->assertSame([
            'available' => true, 'reason' => null, 'issuer' => 'https://eida.example.test',
            'audience' => 'eida-control-qr', 'keys' => [['kid' => 'offline-test', 'algorithm' => 'RS256', 'pem' => $publicPem]],
            'revoked_jtis' => null,
        ], $manifest['qr']);
        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());
        $this->assertSame($previousManifest['students'], $manifest['students']);
        $this->assertSame($previousManifest['list_id'], $manifest['list_id']);
        $this->assertNotSame($previousManifest['package_id'], $manifest['package_id']);
        $qr = app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id, 3);
        $key = $manifest['qr']['keys'][0];
        $verifier = new QrV3Service(new QrV3KeyRing([$key['kid'] => $key['pem']]),
            $manifest['qr']['issuer'], $manifest['qr']['audience']);
        $claims = $verifier->verify($qr['token'], $this->fixture['studentId'], $this->exam->id, now()->timestamp);
        $this->assertSame($manifest['students'][0]['student_id'], $claims['sub']);

        $this->mobile($owner, 'GET', '/sync-capabilities')->assertOk()->assertJsonPath('data.qr_versions', [2, 3]);
        $this->mobile($other, 'GET', $download)->assertForbidden();
        $this->mobile($other, 'GET', '/packages/'.$manifest['package_id'])->assertForbidden();
        $resume = $this->mobile($owner, 'GET', '/packages/'.$previousManifest['package_id'])->assertOk();
        $this->assertSame($previous->json('data.mobile'), $resume->json('data.mobile'));
    }

    public function test_mobile_manifest_fails_closed_for_incomplete_or_incompatible_v3_configuration(): void
    {
        $this->configureQrV3Issuer();
        $owner = $this->bearer($this->fixture['user']);
        $this->mobile($owner, 'POST', '/prepare')->assertOk();

        foreach ([
            'qr_v3.enabled' => false,
            'mobile_offline.qr_v3_issued' => false,
            'offline_sync.qr_v3_verify_enabled' => false,
            'qr_v3.issuer' => '',
            'qr_v3.kid' => 'invalid kid',
            'qr_v3.max_lifetime_seconds' => 3600,
        ] as $setting => $value) {
            $previous = config($setting);
            config([$setting => $value]);
            $manifest = $this->manifest($this->mobile($owner, 'GET', '/package?schema_version=2'));
            $this->assertFalse($manifest['qr']['available'], $setting);
            $this->assertSame([], $manifest['qr']['keys'], $setting);
            $this->assertNull($manifest['qr']['issuer'], $setting);
            config([$setting => $previous]);
        }

        $this->setQrKeyEnvironment('EIDA_QR_V3_PRIVATE_KEY', '');
        $manifest = $this->manifest($this->mobile($owner, 'GET', '/package?schema_version=2'));
        $this->assertFalse($manifest['qr']['available']);
        $this->assertSame([], $manifest['qr']['keys']);
        $this->mobile($owner, 'GET', '/sync-capabilities')->assertOk()->assertJsonPath('data.qr_versions', [2, 3]);
    }

    public function test_manifest_does_not_publish_keys_for_invalid_or_mismatched_pem(): void
    {
        $this->configureQrV3Issuer();
        $owner = $this->bearer($this->fixture['user']);
        $this->mobile($owner, 'POST', '/prepare')->assertOk();
        $otherPublic = openssl_pkey_get_details(QrV3TestKeys::rsa())['key'];

        foreach (['', 'not a PEM', "-----BEGIN PUBLIC KEY-----\ninvalid\n-----END PUBLIC KEY-----", $otherPublic] as $index => $pem) {
            $this->setQrKeyEnvironment('EIDA_QR_V3_PUBLIC_KEY', $pem);
            $manifest = $this->manifest($this->mobile($owner, 'GET', '/package?schema_version=2'));
            $this->assertFalse($manifest['qr']['available']);
            $this->assertSame('PRODUCTION_QR_V3_NOT_CONFIRMED', $manifest['qr']['reason']);
            $this->assertSame([], $manifest['qr']['keys']);
            $this->mobile($owner, 'GET', '/sync-capabilities')->assertOk()
                ->assertJsonPath('data.qr_versions', $index === 3 ? [2, 3] : [2]);
        }
    }
}
