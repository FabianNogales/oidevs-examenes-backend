<?php

namespace Tests\Feature\Security;

use App\Models\MobileDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\IsolatedMobileDatabase;
use Tests\Support\OfflineExamFixture;
use Tests\TestCase;

class SessionTokenPolicySecurityTest extends TestCase
{
    use IsolatedMobileDatabase, OfflineExamFixture;

    private const PASSWORD = 'MobilePass1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
        $this->createOfflineFixture();
        $this->fixture['user']->forceFill(['email' => 'sec02.tokens@umss.edu.bo', 'password' => self::PASSWORD])->save();
        config(['eida.institutional_email_domains' => ['umss.edu.bo'], 'sanctum.expiration' => null,
            'mobile.token_ttl_minutes' => 720]);
    }

    private function login(?string $installation = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->postJson('/api/v1/auth/mobile/login', [
            'identifier' => $this->fixture['user']->email, 'password' => self::PASSWORD,
            'installation_id' => $installation ?? (string) Str::uuid(), 'device_name' => 'SEC02 isolated device',
        ]);
    }

    private function mobile(string $bearer, string $method = 'GET', string $path = '/me')
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($bearer)->json($method, '/api/v1'.$path);
    }

    public function test_mobile_tokens_have_explicit_expiry_without_replacing_the_web_session(): void
    {
        $actor = $this->fixture['user'];
        $actor->forceFill(['active_session_id' => 'existing-web-marker'])->save();
        $issuedAt = now();
        $data = $this->login()->assertOk()->json('data');
        $token = PersonalAccessToken::findToken($data['access_token']);

        $this->assertNotNull($token->expires_at);
        $this->assertSame($issuedAt->addMinutes(720)->timestamp, $token->expires_at->timestamp);
        $this->assertSame($token->expires_at->timestamp, \Carbon\CarbonImmutable::parse($data['expires_at'])->timestamp);
        $this->assertSame($data['device']['id'], $token->mobile_device_id);
        $this->assertSame('existing-web-marker', $actor->refresh()->active_session_id);
        $this->assertNull(config('sanctum.expiration'));
        $this->mobile($data['access_token'])->assertOk()->assertJsonPath('data.id', $actor->id);
    }

    public function test_mobile_maximum_ttl_is_honored_and_expiration_does_not_delete_packages(): void
    {
        config(['mobile.token_ttl_minutes' => 10080]);
        $issuedAt = now();
        $data = $this->login()->assertOk()->json('data');
        $this->mobile($data['access_token'], 'POST', $this->offlineBaseSuffix().'/prepare')->assertOk();
        $this->mobile($data['access_token'], 'GET', $this->offlineBaseSuffix().'/package')->assertOk();
        $packages = DB::table('exam_offline_packages')->orderBy('id')->get()->toJson();

        $this->travelTo($issuedAt->copy()->addMinutes(721));
        $this->mobile($data['access_token'])->assertOk();
        $this->travelTo($issuedAt->copy()->addMinutes(10080)->addSecond());
        $this->mobile($data['access_token'])->assertUnauthorized();
        $this->assertSame($packages, DB::table('exam_offline_packages')->orderBy('id')->get()->toJson());
        $this->assertNotNull(PersonalAccessToken::findToken($data['access_token']));
    }

    public function test_invalid_mobile_lifetimes_never_create_a_device_or_unbounded_token(): void
    {
        foreach ([0, 10081, null, '720'] as $invalid) {
            config(['mobile.token_ttl_minutes' => $invalid]);
            $this->login()->assertStatus(503)->assertJsonPath('code', 'MOBILE_CONFIGURATION_INVALID');
            $this->assertDatabaseCount('personal_access_tokens', 0);
            $this->assertDatabaseCount('mobile_devices', 0);
        }
    }

    public function test_mobile_token_with_missing_or_past_expiry_is_rejected(): void
    {
        $data = $this->login()->assertOk()->json('data');
        $token = PersonalAccessToken::findToken($data['access_token']);
        $token->forceFill(['expires_at' => null])->save();
        $this->mobile($data['access_token'])->assertUnauthorized()->assertJsonPath('code', 'MOBILE_TOKEN_INVALID');
        $token->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->mobile($data['access_token'])->assertUnauthorized();
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_mobile_revocation_is_checked_even_before_expiry(): void
    {
        $data = $this->login()->assertOk()->json('data');
        MobileDevice::whereKey($data['device']['id'])->update(['revoked_at' => now()]);
        $this->mobile($data['access_token'])->assertUnauthorized()->assertJsonPath('code', 'MOBILE_DEVICE_REVOKED');
        $this->assertTrue(PersonalAccessToken::findToken($data['access_token'])->expires_at->isFuture());
    }

    public function test_reauthentication_rotates_one_installation_without_revoking_another(): void
    {
        $installation = (string) Str::uuid();
        $first = $this->login($installation)->assertOk()->json('data');
        $other = $this->login()->assertOk()->json('data');
        $replacement = $this->login($installation)->assertOk()->json('data');

        $this->assertSame($first['device']['id'], $replacement['device']['id']);
        $this->mobile($first['access_token'])->assertUnauthorized();
        $this->mobile($other['access_token'])->assertOk();
        $this->mobile($replacement['access_token'])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    private function offlineBaseSuffix(): string
    {
        return '/exams/'.$this->exam->id.'/offline';
    }
}
