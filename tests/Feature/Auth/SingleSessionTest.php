<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\InitialPasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class SingleSessionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password1';
    private const NEW_PASSWORD = 'NewPassword1';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'eida.institutional_email_domains' => ['umss.edu.bo'],
            'session.driver' => 'file',
        ]);
        Session::setDefaultDriver('file');
    }

    public function test_successful_login_stores_active_session_id_matching_generated_session(): void
    {
        $user = $this->createUser('single.login@umss.edu.bo');
        $sessionId = $this->loginFromNewSession($user);
        $this->assertNotNull($sessionId);
        $this->assertSame($sessionId, $user->refresh()->active_session_id);
    }

    public function test_failed_login_does_not_modify_active_session_id(): void
    {
        $user = $this->createUser('failed.session@umss.edu.bo');
        $user->forceFill(['active_session_id' => 'existing-session'])->save();

        $this->postJson('/login', [
            'identifier' => 'failed.session@umss.edu.bo',
            'password' => 'WrongPass1',
        ])->assertUnprocessable();

        $this->assertSame('existing-session', $user->refresh()->active_session_id);
    }

    public function test_inactive_user_login_does_not_modify_active_session_id(): void
    {
        $user = $this->createUser('inactive.session@umss.edu.bo', UserStatus::INACTIVE->value);
        $user->forceFill(['active_session_id' => 'existing-session'])->save();

        $this->postJson('/login', [
            'identifier' => 'inactive.session@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->assertSame('existing-session', $user->refresh()->active_session_id);
    }

    public function test_current_session_can_access_current_user_and_does_not_expose_active_session_id(): void
    {
        $user = $this->createUser('current.session@umss.edu.bo');
        $sessionId = $this->loginFromNewSession($user);

        $this->useSessionCookie($sessionId)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.active_session_id');
    }

    public function test_new_login_replaces_previous_session(): void
    {
        $user = $this->createUser('replace.session@umss.edu.bo');

        $sessionA = $this->loginFromNewSession($user);
        $sessionB = $this->loginFromNewSession($user);

        $this->assertNotSame($sessionA, $sessionB);
        $this->assertSame($sessionB, $user->refresh()->active_session_id);

        $this->useSessionCookie($sessionB)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->useSessionCookie($sessionA)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_REPLACED');

        $this->useSessionCookie($sessionA)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonMissingPath('code');
    }

    public function test_replaced_session_cannot_change_password_or_clear_current_session(): void
    {
        $user = $this->createUser('replaced.password@umss.edu.bo');

        $sessionA = $this->loginFromNewSession($user);
        $sessionB = $this->loginFromNewSession($user);

        $this->useSessionCookie($sessionA)
            ->putJson('/user/password', [
                'current_password' => self::PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_REPLACED');

        $this->assertSame($sessionB, $user->refresh()->active_session_id);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_current_logout_clears_active_session_id_and_blocks_future_access(): void
    {
        $user = $this->createUser('logout.current@umss.edu.bo');
        $sessionId = $this->loginFromNewSession($user);

        $this->useSessionCookie($sessionId)
            ->postJson('/logout')
            ->assertNoContent();

        $this->assertNull($user->refresh()->active_session_id);

        $this->useSessionCookie($sessionId)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_replaced_logout_does_not_clear_new_active_session_id(): void
    {
        $user = $this->createUser('logout.replaced@umss.edu.bo');

        $sessionA = $this->loginFromNewSession($user);
        $sessionB = $this->loginFromNewSession($user);

        $this->useSessionCookie($sessionA)
            ->postJson('/logout')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_REPLACED');

        $this->assertSame($sessionB, $user->refresh()->active_session_id);
    }

    public function test_first_access_current_session_can_change_password_without_breaking_session(): void
    {
        $user = $this->createUser('first.current.session@umss.edu.bo');
        app(InitialPasswordService::class)->initialize($user, '12345678');

        $sessionId = $this->loginFromNewSession($user, '12345678');

        $this->useSessionCookie($sessionId)
            ->putJson('/user/password', [
                'current_password' => '12345678',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->assertSame($sessionId, $user->refresh()->active_session_id);
        $this->assertFalse($user->must_change_password);
    }

    public function test_session_lifetime_is_five_minutes(): void
    {
        $this->assertSame(5, (int) config('session.lifetime'));
    }

    public function test_expired_session_returns_normal_unauthenticated_response(): void
    {
        $user = $this->createUser('expired.session@umss.edu.bo');
        $sessionId = $this->loginFromNewSession($user);

        session()->getHandler()->destroy($sessionId);

        $this->useSessionCookie($sessionId, persistCurrentSession: false)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonMissingPath('code');
    }

    private function createUser(string $email, string $status = UserStatus::ACTIVE->value): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => self::PASSWORD,
            'status' => $status,
            'must_change_password' => false,
        ]);
    }

    private function loginFromNewSession(User $user, string $password = self::PASSWORD): string
    {
        $this->useSessionCookie(Str::random(40));

        $this->postJson('/login', [
            'identifier' => $user->email,
            'password' => $password,
        ])->assertOk();

        return $user->refresh()->active_session_id;
    }

    private function useSessionCookie(string $sessionId, bool $persistCurrentSession = true): self
    {
        if ($persistCurrentSession && $this->app['session']->isStarted()) {
            $this->app['session']->save();
        }

        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->app['session']->setId($sessionId);
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this
            ->withCredentials()
            ->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $sessionId);
    }
}