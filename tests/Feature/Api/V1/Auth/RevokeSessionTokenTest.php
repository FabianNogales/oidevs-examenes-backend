<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Enums\RoleName;
use App\Models\Career;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Sanctum\TransientToken;
use Tests\TestCase;

class RevokeSessionTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eida.institutional_email_domains' => ['umss.edu.bo'],
            'sanctum.stateful' => ['127.0.0.1:5173'],
            'session.driver' => 'file',
        ]);

        Session::setDefaultDriver('file');
    }

    public function testUserCanRevokeSessionTokenSuccessfully(): void
    {
        $user = User::factory()->create(['status' => 'ACTIVE']);
        $user->forceFill(['active_session_id' => 'existing-spa-session'])->save();
        $token = $user->createToken('api-client');

        $response = $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Session revoked successfully']);

        // Verificamos que el token haya sido eliminado de la base de datos
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame('existing-spa-session', $user->refresh()->active_session_id);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_revoking_personal_access_token_preserves_other_tokens(): void
    {
        $user = User::factory()->create(['status' => 'ACTIVE']);
        $token = $user->createToken('current-client');
        $otherToken = $user->createToken('other-client');

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }

    public function test_student_can_logout_with_spa_session_and_transient_token(): void
    {
        $user = $this->createStudentUser();
        $sessionId = $this->loginFromNewSession($user);

        $this->useSessionCookie($sessionId)
            ->getJson('/api/v1/me')
            ->assertOk();

        // Sanctum usa un token real de sesion que no tiene el metodo delete().
        $this->assertInstanceOf(TransientToken::class, $this->app['auth']->guard('sanctum')->user()->currentAccessToken());
        $csrfToken = $this->app['session']->token();
        $this->app['session']->put('logout-test', 'private-session-data');

        $this->useSessionCookie($sessionId)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Session revoked successfully']);

        $this->assertGuest('web');
        $this->assertNull($user->refresh()->active_session_id);
        $this->assertNotSame($sessionId, $this->app['session']->getId());
        $this->assertNotEmpty($this->app['session']->token());
        $this->assertNotSame($csrfToken, $this->app['session']->token());
        $this->assertNull($this->app['session']->get('logout-test'));

        $loggedOutSessionId = $this->app['session']->getId();
        $this->useSessionCookie($loggedOutSessionId)->getJson('/api/v1/me')->assertUnauthorized();
        $this->useSessionCookie($sessionId)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_replaced_spa_session_cannot_logout_the_current_session(): void
    {
        $user = $this->createStudentUser();
        $sessionA = $this->loginFromNewSession($user);
        $sessionB = $this->loginFromNewSession($user);

        $this->useSessionCookie($sessionA)
            ->postJson('/api/v1/auth/logout')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_REPLACED');

        $this->assertSame($sessionB, $user->refresh()->active_session_id);
        $this->useSessionCookie($sessionB)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_spa_logout_requires_valid_csrf_token(): void
    {
        $user = $this->createStudentUser();
        $sessionId = $this->loginFromNewSession($user);
        $csrfToken = $this->app['session']->token();

        // Activa la verificacion CSRF que Laravel omite en el entorno testing.
        $this->app->instance('env', 'production');

        $this->useSessionCookie($sessionId)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(419);

        $this->assertSame($sessionId, $user->refresh()->active_session_id);

        $this->useSessionCookie($sessionId)
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertNull($user->refresh()->active_session_id);
    }

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    private function createStudentUser(): User
    {
        $user = User::factory()->create([
            'email' => 'logout.student@umss.edu.bo',
            'password' => 'Password1',
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);
        $role = Role::query()->create([
            'name' => RoleName::ESTUDIANTE->value,
            'description' => 'Estudiante',
            'status' => 'ACTIVE',
        ]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $career = Career::query()->create([
            'code' => 'SIS-LOGOUT',
            'name' => 'Sistemas',
            'status' => 'ACTIVE',
        ]);
        Student::query()->create([
            'user_id' => $user->id,
            'career_id' => $career->id,
            'sis_code' => '202300123',
            'identity_number' => '12345678',
            'first_names' => 'Test',
            'last_names' => 'Student',
            'status' => 'ACTIVE',
        ]);

        return $user;
    }

    private function loginFromNewSession(User $user): string
    {
        $this->useSessionCookie(Str::random(40));

        $this->postJson('/login', [
            'identifier' => $user->email,
            'password' => 'Password1',
        ])->assertOk();

        return $user->refresh()->active_session_id;
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

        return $this
            ->withCredentials()
            ->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $sessionId);
    }
}
