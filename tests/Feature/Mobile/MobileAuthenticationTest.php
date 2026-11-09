<?php

namespace Tests\Feature\Mobile;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\MobileTokenPermissions;
use App\Services\QrV3\QrV3Exception;
use App\Services\QrV3\QrV3Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\IsolatedMobileDatabase;
use Tests\Support\OfflineExamFixture;
use Tests\TestCase;

class MobileAuthenticationTest extends TestCase
{
    use IsolatedMobileDatabase, OfflineExamFixture;

    private const PASSWORD = 'MobilePass1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
        $this->createOfflineFixture();
        $this->fixture['user']->forceFill(['email' => 'teacher@umss.edu.bo', 'password' => self::PASSWORD])->save();
        config(['eida.institutional_email_domains' => ['umss.edu.bo'], 'sanctum.stateful' => ['spa.test']]);
    }

    private function credentials(?User $user = null, ?string $installation = null): array
    {
        return ['identifier' => ($user ?? $this->fixture['user'])->email, 'password' => self::PASSWORD,
            'installation_id' => $installation ?? (string) Str::uuid(), 'device_name' => 'Android de prueba', 'app_version' => '0.1.0'];
    }

    private function loginMobile(?array $credentials = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');

        return $this->postJson('/api/v1/auth/mobile/login', $credentials ?? $this->credentials());
    }

    private function mobile(string $token, string $method, string $path, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token);

        return $this->json($method, '/api/v1'.$path, $data);
    }

    public function test_login_issues_hashed_expiring_linked_token_and_preserves_web_session_marker(): void
    {
        $user = $this->fixture['user'];
        $user->forceFill(['active_session_id' => 'existing-web-session'])->save();
        $response = $this->loginMobile()->assertOk()->assertJsonPath('data.token_type', 'Bearer');
        $data = $response->json('data');
        $token = PersonalAccessToken::findToken($data['access_token']);
        $this->assertNotNull($token);
        $this->assertSame($data['device']['id'], $token->mobile_device_id);
        $this->assertSame(MobileTokenPermissions::ABILITIES, $token->abilities);
        $this->assertSame(now()->addMinutes(720)->timestamp, $token->expires_at->timestamp);
        $this->assertSame('existing-web-session', $user->refresh()->active_session_id);
        $this->assertGuest('web');
        $this->assertSame([], $response->headers->getCookies());
        $response->assertDontSee(self::PASSWORD)->assertDontSee($user->password);
        $audits = DB::table('audit_logs')->get()->toJson();
        $this->assertStringNotContainsString($data['access_token'], $audits);
        $this->assertStringNotContainsString(self::PASSWORD, $audits);
        $this->mobile($data['access_token'], 'GET', '/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_mobile_login_does_not_create_cookies_even_with_spa_origin(): void
    {
        $response = $this->withHeader('Origin', 'http://spa.test')->loginMobile()->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($this->fixture['user']->refresh()->active_session_id);
        $this->assertGuest('web');
    }

    public function test_institutional_teacher_code_reuses_existing_resolver(): void
    {
        $data = $this->credentials();
        $data['identifier'] = $this->fixture['teacher']->institutional_code;
        $this->loginMobile($data)->assertOk();
    }

    public function test_wrong_password_is_rejected_without_device_or_token(): void
    {
        $data = $this->credentials();
        $data['password'] = 'Incorrect1';
        $this->loginMobile($data)->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $this->assertDatabaseCount('mobile_devices', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_account_cannot_login(): void
    {
        $this->fixture['user']->update(['status' => 'INACTIVE']);
        $this->loginMobile()->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_active_account_without_authorized_function_cannot_login(): void
    {
        $user = User::factory()->create(['email' => 'other@umss.edu.bo', 'password' => self::PASSWORD,
            'status' => 'ACTIVE', 'must_change_password' => false]);
        $this->loginMobile($this->credentials($user))->assertForbidden()->assertJsonPath('code', 'MOBILE_OPERATOR_REQUIRED');
    }

    public function test_password_change_required_and_external_domain_are_rejected(): void
    {
        $this->fixture['user']->forceFill(['must_change_password' => true])->save();
        $this->loginMobile()->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $data = $this->credentials();
        $data['identifier'] = 'teacher@example.com';
        $this->loginMobile($data)->assertUnprocessable();
    }

    public function test_login_rate_limit_normalizes_identifier(): void
    {
        $data = $this->credentials();
        $data['password'] = 'Incorrect1';
        for ($i = 0; $i < 5; $i++) {
            $data['identifier'] = $i % 2 ? ' TEACHER@umss.edu.bo ' : 'teacher@umss.edu.bo';
            $this->loginMobile($data)->assertUnprocessable();
        }
        $this->loginMobile($data)->assertStatus(429);
    }

    public function test_same_installation_rotates_its_token_but_other_installation_survives(): void
    {
        $installation = (string) Str::uuid();
        $a = $this->loginMobile($this->credentials(null, $installation))->assertOk()->json('data');
        $b = $this->loginMobile()->assertOk()->json('data');
        $again = $this->loginMobile($this->credentials(null, strtoupper($installation)))->assertOk()->json('data');
        $this->assertSame($a['device']['id'], $again['device']['id']);
        $this->assertDatabaseCount('mobile_devices', 2);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->mobile($a['access_token'], 'GET', '/me')->assertUnauthorized();
        $this->mobile($b['access_token'], 'GET', '/me')->assertOk();
        $this->mobile($again['access_token'], 'GET', '/me')->assertOk();
    }

    public function test_individual_revocation_is_owned_and_does_not_revoke_other_device(): void
    {
        $a = $this->loginMobile()->assertOk()->json('data');
        $b = $this->loginMobile()->assertOk()->json('data');
        $this->mobile($b['access_token'], 'GET', '/auth/devices')->assertOk()->assertJsonCount(2, 'data');
        $this->mobile($b['access_token'], 'DELETE', '/auth/devices/'.$a['device']['id'])->assertNoContent();
        $this->mobile($a['access_token'], 'GET', '/me')->assertUnauthorized();
        $this->mobile($b['access_token'], 'GET', '/me')->assertOk();
        $other = User::factory()->create();
        $foreign = MobileDevice::create(['user_id' => $other->id, 'installation_id' => (string) Str::uuid(),
            'name' => 'Ajeno', 'last_login_at' => now()]);
        $this->mobile($b['access_token'], 'DELETE', '/auth/devices/'.$foreign->id)->assertNotFound();
        $this->mobile($b['access_token'], 'DELETE', '/auth/devices/9223372036854775808')->assertNotFound();
        $this->mobile($b['access_token'], 'DELETE', '/auth/devices/0')->assertNotFound();
        $this->mobile($b['access_token'], 'GET', '/auth/devices')->assertJsonCount(2, 'data')
            ->assertJsonMissing(['installation_id' => $foreign->installation_id]);
    }

    public function test_logout_revokes_current_installation_only_and_reauthentication_can_recover_it(): void
    {
        $a = $this->loginMobile()->assertOk()->json('data');
        $b = $this->loginMobile()->assertOk()->json('data');
        $this->mobile($a['access_token'], 'POST', '/auth/logout')->assertOk();
        $this->mobile($a['access_token'], 'GET', '/me')->assertUnauthorized();
        $this->mobile($b['access_token'], 'GET', '/me')->assertOk();
        $this->assertNotNull(MobileDevice::find($a['device']['id'])->revoked_at);
        $again = $this->loginMobile($this->credentials(null, $a['device']['installation_id']))->assertOk()->json('data');
        $this->assertSame($a['device']['id'], $again['device']['id']);
        $this->mobile($again['access_token'], 'GET', '/me')->assertOk();
    }

    public function test_expired_token_and_missing_expiry_are_rejected(): void
    {
        $data = $this->loginMobile()->assertOk()->json('data');
        $token = PersonalAccessToken::findToken($data['access_token']);
        $token->forceFill(['expires_at' => null])->save();
        $this->mobile($data['access_token'], 'GET', '/me')->assertUnauthorized();
        $token->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->mobile($data['access_token'], 'GET', '/me')->assertUnauthorized();
    }

    public function test_inactive_account_and_revoked_or_detached_device_cannot_use_existing_token(): void
    {
        $data = $this->loginMobile()->assertOk()->json('data');
        $user = $this->fixture['user'];
        $user->update(['status' => 'INACTIVE']);
        $this->mobile($data['access_token'], 'GET', '/me')->assertUnauthorized()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $user->update(['status' => 'ACTIVE']);
        MobileDevice::find($data['device']['id'])->update(['revoked_at' => now()]);
        $this->mobile($data['access_token'], 'GET', '/me')->assertUnauthorized()->assertJsonPath('code', 'MOBILE_DEVICE_REVOKED');
        PersonalAccessToken::findToken($data['access_token'])->forceFill(['mobile_device_id' => null])->save();
        $this->mobile($data['access_token'], 'GET', '/me')->assertUnauthorized();
    }

    public function test_abilities_are_enforced_and_wildcard_does_not_open_admin_or_student_apis(): void
    {
        $data = $this->loginMobile()->assertOk()->json('data');
        $token = PersonalAccessToken::findToken($data['access_token']);
        $token->forceFill(['abilities' => ['mobile:session']])->save();
        $this->mobile($data['access_token'], 'GET', '/auth/devices')->assertForbidden()->assertJsonPath('code', 'MOBILE_ABILITY_REQUIRED');
        $this->mobile($data['access_token'], 'POST', '/exams/'.$this->exam->id.'/offline/prepare')->assertForbidden();
        $token->forceFill(['abilities' => ['*']])->save();
        $this->mobile($data['access_token'], 'GET', '/admin/test')->assertForbidden();
        $this->mobile($data['access_token'], 'GET', '/students/exams')->assertForbidden();
        $this->mobile($data['access_token'], 'GET', '/teacher/exams')->assertOk();
    }

    public function test_bearer_can_prepare_download_and_sync_using_unchanged_hu37_contract(): void
    {
        $token = $this->loginMobile()->assertOk()->json('data.access_token');
        $base = '/exams/'.$this->exam->id.'/offline';
        $this->mobile($token, 'POST', $base.'/prepare')->assertOk();
        $packet = $this->mobile($token, 'GET', $base.'/package')->assertOk()->json('data');
        $batch = $this->offlineBatch($packet, [$this->offlineEvent()]);
        $first = $this->mobile($token, 'POST', $base.'/sync', $batch)->assertOk()
            ->assertJsonPath('data.results.0.status', 'ACCEPTED')->json();
        $this->mobile($token, 'POST', $base.'/sync', $batch)->assertOk()->assertExactJson($first);
    }

    public function test_collaborator_login_keeps_exam_authorization_and_revocation_conflicts(): void
    {
        $collaborator = $this->offlineCollaborator();
        $collaborator->forceFill(['email' => 'collab@umss.edu.bo', 'password' => self::PASSWORD])->save();
        $ownerToken = $this->loginMobile()->assertOk()->json('data.access_token');
        $token = $this->loginMobile($this->credentials($collaborator))->assertOk()->json('data.access_token');
        $base = '/exams/'.$this->exam->id.'/offline';
        $this->mobile($token, 'GET', '/me/collaborations')->assertOk();
        $this->mobile($token, 'POST', $base.'/prepare')->assertForbidden();
        $this->mobile($ownerToken, 'POST', $base.'/prepare')->assertOk();
        $packet = $this->mobile($token, 'GET', $base.'/package')->assertOk()->json('data');
        DB::table('exam_collaborators')->where('user_id', $collaborator->id)->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->mobile($token, 'GET', $base.'/package')->assertForbidden();
        $this->mobile($token, 'POST', $base.'/sync', $this->offlineBatch($packet, [$this->offlineEvent()]))
            ->assertOk()->assertJsonPath('data.results.0.code', 'AUTHORIZATION_CHANGED');
        $this->loginMobile($this->credentials($collaborator))->assertForbidden();
    }

    public function test_generic_personal_token_and_web_authority_keep_existing_permissions(): void
    {
        $generic = $this->fixture['user']->createToken('existing-client')->plainTextToken;
        $this->mobile($generic, 'GET', '/admin/test')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');
        $this->actingAs($this->fixture['user'], 'web')->getJson('/api/v1/admin/test')->assertOk();
        $this->getJson('/api/v1/auth/devices')->assertOk();
    }

    public function test_mobile_token_cannot_hide_behind_web_authentication(): void
    {
        $token = $this->loginMobile()->assertOk()->json('data.access_token');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->fixture['user'], 'web');
        $this->withToken($token)->getJson('/api/v1/admin/test')->assertUnauthorized()
            ->assertJsonPath('code', 'MOBILE_TOKEN_INVALID');
    }

    public function test_password_reset_revokes_mobile_tokens_only(): void
    {
        $token = $this->loginMobile()->assertOk()->json('data.access_token');
        $generic = $this->fixture['user']->createToken('existing-client');
        app(ResetUserPassword::class)->reset($this->fixture['user'],
            ['password' => 'ChangedPassword1', 'password_confirmation' => 'ChangedPassword1']);
        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertNotNull($generic->accessToken->fresh());
        $this->assertNotNull(MobileDevice::first()->revoked_at);
    }

    public function test_web_password_change_revokes_mobile_tokens_without_replacing_web_marker(): void
    {
        $token = $this->loginMobile()->assertOk()->json('data.access_token');
        $user = $this->fixture['user']->forceFill(['active_session_id' => 'web-marker']);
        $user->save();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->actingAs($user, 'web');
        app(UpdateUserPassword::class)->update($user,
            ['current_password' => self::PASSWORD, 'password' => 'ChangedPassword1', 'password_confirmation' => 'ChangedPassword1']);
        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertSame('web-marker', $user->refresh()->active_session_id);
    }

    public function test_audit_failure_rolls_back_mobile_login_and_token_rotation(): void
    {
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('audit unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->loginMobile();
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('mobile_devices', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_effective_expiration_respects_sanctum_and_invalid_configuration_issues_nothing(): void
    {
        config(['mobile.token_ttl_minutes' => 60, 'sanctum.expiration' => 15]);
        $data = $this->loginMobile()->assertOk()->json('data');
        $this->assertSame(now()->addMinutes(15)->timestamp, PersonalAccessToken::findToken($data['access_token'])->expires_at->timestamp);
        config(['mobile.token_ttl_minutes' => 0]);
        $this->loginMobile()->assertStatus(503)->assertJsonPath('code', 'MOBILE_CONFIGURATION_INVALID');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_password_reset_audit_failure_rolls_back_password_tokens_and_device_revocation(): void
    {
        $data = $this->loginMobile()->assertOk()->json('data');
        $hash = $this->fixture['user']->refresh()->getAuthPassword();
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('audit unavailable'));
        try {
            app(ResetUserPassword::class)->reset($this->fixture['user'],
                ['password' => 'ChangedPassword1', 'password_confirmation' => 'ChangedPassword1']);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertSame($hash, $this->fixture['user']->refresh()->getAuthPassword());
        $this->assertNotNull(PersonalAccessToken::findToken($data['access_token']));
        $this->assertNull(MobileDevice::first()->revoked_at);
    }

    public function test_qr_v3_container_service_is_disabled_by_default(): void
    {
        $this->expectException(QrV3Exception::class);
        $this->expectExceptionMessage('EXPERIMENT_DISABLED');
        app(QrV3Service::class);
    }
}
