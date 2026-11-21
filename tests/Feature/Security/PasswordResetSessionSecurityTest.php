<?php

namespace Tests\Feature\Security;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\MobileDevice;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\MobileTokenPermissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\IsolatedMobileDatabase;
use Tests\TestCase;

class PasswordResetSessionSecurityTest extends TestCase
{
    use IsolatedMobileDatabase;

    private const OLD_PASSWORD = 'OldPassword!SEC02';

    private const NEW_PASSWORD = 'NewPassword!SEC02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
        config(['eida.institutional_email_domains' => ['umss.edu.bo'],
            'sanctum.stateful' => ['spa.test'], 'session.driver' => 'file',
            'session.encrypt' => false, 'session.secure' => false]);
        Session::setDefaultDriver('file');
    }

    public function test_direct_reset_rotates_both_session_secrets_and_revokes_only_mobile_credentials(): void
    {
        $user = $this->account();
        [$device, $bearer] = $this->mobileCredential($user);
        $generic = $user->createToken('existing-authorized-client');
        $before = $user->getAttributes();
        DB::table('users')->where('id', $user->id)->update(['profile_photo' => 'existing-photo-reference']);

        app(ResetUserPassword::class)->reset($user, $this->passwordInput());

        $this->assertNotSame($before['active_session_id'], $user->active_session_id);
        $this->assertSame(40, strlen($user->active_session_id));
        $this->assertNotSame($before['remember_token'], $user->remember_token);
        $this->assertSame(60, strlen($user->remember_token));
        $this->assertFalse($user->must_change_password);
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $user->password));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertSame('existing-photo-reference', $user->profile_photo);
        $this->assertSame($user->active_session_id, $user->fresh()->active_session_id);
        $this->assertSame($user->getAuthPassword(), $user->fresh()->getAuthPassword());
        $provider = Auth::guard('web')->getProvider();
        $this->assertNull($provider->retrieveByToken($user->id, $before['remember_token']));
        $this->assertSame($user->id, $provider->retrieveByToken($user->id, $user->remember_token)?->id);
        $this->assertNull(PersonalAccessToken::findToken($bearer));
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->assertNotNull($generic->accessToken->fresh());
    }

    public function test_reset_route_invalidates_the_previous_cookie_and_allows_a_new_login_for_multiple_roles(): void
    {
        $user = $this->account();
        foreach (['ADMINISTRADOR', 'DOCENTE'] as $name) {
            $role = Role::firstOrCreate(['name' => $name], ['status' => 'ACTIVE']);
            $user->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        }
        $roles = $user->roles()->pluck('roles.id')->sort()->values()->all();
        $this->useSessionCookie(Str::random(40))->postJson('/login', [
            'identifier' => $user->email, 'password' => self::OLD_PASSWORD,
        ])->assertOk();
        $oldSession = $user->refresh()->active_session_id;
        $oldRemember = $user->remember_token;
        $token = Password::broker()->createToken($user);

        // Fortify's recovery route remains guest-only. Complete recovery from
        // a new browser session, then verify the old cookie cannot read data.
        $this->useSessionCookie(Str::random(40))->postJson('/reset-password', ['email' => $user->email, 'token' => $token] + $this->passwordInput())
            ->assertOk()->assertDontSee(self::NEW_PASSWORD)->assertDontSee($token);
        $user->refresh();
        $this->assertNotSame($oldSession, $user->active_session_id);
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertSame($roles, $user->roles()->pluck('roles.id')->sort()->values()->all());
        $this->useSessionCookie($oldSession)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonMissingPath('data');

        $this->useSessionCookie(Str::random(40))->postJson('/login', [
            'identifier' => $user->email, 'password' => self::OLD_PASSWORD,
        ])->assertUnprocessable();
        $this->assertGuest('web');
        $this->useSessionCookie(Str::random(40))->postJson('/login', [
            'identifier' => $user->email, 'password' => self::NEW_PASSWORD,
        ])->assertOk();
        $currentSession = $user->refresh()->active_session_id;
        $this->useSessionCookie($currentSession)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_guest_reset_does_not_authenticate_the_recovery_browser_and_cannot_reuse_its_token(): void
    {
        $user = $this->account();
        $token = Password::broker()->createToken($user);
        $body = ['email' => $user->email, 'token' => $token] + $this->passwordInput();
        $this->useSessionCookie(Str::random(40))->postJson('/reset-password', $body)->assertOk();
        $this->assertGuest('web');
        $after = $user->fresh()->getAttributes();
        $this->useSessionCookie(Str::random(40))->postJson('/reset-password', $body)->assertUnprocessable();
        $this->assertSame($after, $user->fresh()->getAttributes());
        $this->assertFalse(Password::broker()->tokenExists($user->fresh(), $token));
    }

    public function test_invalid_reset_token_preserves_password_session_remember_and_mobile_credentials(): void
    {
        $user = $this->account();
        [$device, $bearer] = $this->mobileCredential($user);
        $before = $user->getAttributes();
        $this->useSessionCookie(Str::random(40))->postJson('/reset-password',
            ['email' => $user->email, 'token' => 'invalid-reset-token'] + $this->passwordInput())->assertUnprocessable();
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertNotNull(PersonalAccessToken::findToken($bearer));
        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_failed_revocation_audit_rolls_back_password_both_markers_and_mobile_revocation(): void
    {
        $user = $this->account();
        [$device, $bearer] = $this->mobileCredential($user);
        $before = $user->getAttributes();
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('audit unavailable'));
        try {
            app(ResetUserPassword::class)->reset($user, $this->passwordInput());
            $this->fail('Reset must roll back when the revocation audit fails.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame($before, $user->getAttributes());
        $this->assertNotNull(PersonalAccessToken::findToken($bearer));
        $this->assertNull($device->fresh()->revoked_at);
    }

    private function account(): User
    {
        $user = User::factory()->create(['email' => 'sec02.reset@umss.edu.bo', 'password' => self::OLD_PASSWORD,
            'status' => 'ACTIVE', 'must_change_password' => true]);
        $user->forceFill(['active_session_id' => Str::random(40), 'remember_token' => Str::random(60)])->save();

        return $user->refresh();
    }

    private function mobileCredential(User $user): array
    {
        $device = MobileDevice::create(['user_id' => $user->id, 'installation_id' => (string) Str::uuid(),
            'name' => 'SEC-02 synthetic installation', 'platform' => 'android', 'last_login_at' => now()]);
        $issued = $user->createToken(MobileTokenPermissions::PREFIX.$device->id, MobileTokenPermissions::ABILITIES, now()->addHours(4));
        $issued->accessToken->forceFill(['mobile_device_id' => $device->id])->save();

        return [$device, $issued->plainTextToken];
    }

    private function passwordInput(): array
    {
        return ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
    }

    private function useSessionCookie(string $sessionId): self
    {
        if ($this->app['session']->isStarted()) {
            $this->app['session']->save();
        }
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->app['session']->setId($sessionId);
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this->withCredentials()->withHeader('Origin', 'http://spa.test')
            ->withCookie(config('session.cookie'), $sessionId);
    }
}
