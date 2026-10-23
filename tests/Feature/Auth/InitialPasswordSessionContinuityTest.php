<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\Career;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Auth\InitialPasswordService;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InitialPasswordSessionContinuityTest extends TestCase
{
    use RefreshDatabase;

    private const INITIAL_PASSWORD = '12345678';
    private const NEW_PASSWORD = 'ChangedPassword1';

    private PDO $isolatedPdo;
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatedPdo = $this->app['db']->connection()->getPdo();
    }

    public static function roles(): array
    {
        return [
            'teacher' => [RoleName::DOCENTE],
            'student' => [RoleName::ESTUDIANTE],
        ];
    }

    public static function continuityScenarios(): array
    {
        $cases = [];
        foreach (self::roles() as $name => [$role]) {
            $cases[$name.' with prior API request'] = [$role, true];
            $cases[$name.' without prior API request'] = [$role, false];
        }

        return $cases;
    }

    public static function invalidPasswords(): array
    {
        $cases = [];
        foreach (self::roles() as $name => [$role]) {
            $cases[$name.' incorrect current password'] = [$role, 'Incorrect1', self::NEW_PASSWORD, 'current_password'];
            $cases[$name.' invalid new password'] = [$role, self::INITIAL_PASSWORD, 'short', 'password'];
        }

        return $cases;
    }

    #[DataProvider('continuityScenarios')]
    public function test_password_change_preserves_cookie_session_without_reauthentication(RoleName $role, bool $priorApi): void
    {
        [$user, $endpoint] = $this->createAccount($role);
        $sessionId = $this->login($user);
        $oldHash = $user->refresh()->getAuthPassword();
        $this->assertTrue($user->must_change_password);

        if ($priorApi) {
            $this->request('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);
            $this->request('GET', $endpoint)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
            $this->assertSame($this->expectedSessionHash($oldHash), $this->sessionData($sessionId)['password_hash_web']);
        } else {
            $this->assertArrayNotHasKey('password_hash_web', $this->sessionData($sessionId));
        }

        $this->request('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->changePassword()->assertOk();

        $this->assertSame($sessionId, $this->app['session']->getId());
        $this->assertSame($sessionId, $user->refresh()->active_session_id);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse(Hash::check(self::INITIAL_PASSWORD, $user->password));
        $this->assertSame(
            $this->expectedSessionHash($user->getAuthPassword()),
            $this->sessionData($sessionId)['password_hash_web'] ?? null,
        );

        $this->request('GET', '/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $user->id)->assertJsonPath('data.must_change_password', false);
        $this->request('GET', $endpoint)->assertOk();
        $this->assertSame($sessionId, $this->app['session']->getId());
        $this->assertSame($sessionId, $user->refresh()->active_session_id);
        $this->assertSame(1, DB::table('audit_logs')->where('user_id', $user->id)->where('action', 'LOGIN')->count());

        // New browser login: old credentials stay invalid; new credentials work.
        $this->cookies = [];
        $this->request('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->request('POST', '/login', ['identifier' => $user->email, 'password' => self::INITIAL_PASSWORD])
            ->assertUnprocessable();
        $newSessionId = $this->login($user, self::NEW_PASSWORD);
        $this->assertNotSame($sessionId, $newSessionId);
        $this->request('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', false);
        $this->request('GET', $endpoint)->assertOk();
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_change_has_no_partial_effects(
        RoleName $role, string $currentPassword, string $newPassword, string $errorField,
    ): void {
        [$user, $endpoint] = $this->createAccount($role);
        $sessionId = $this->login($user);
        $this->request('GET', '/api/v1/me')->assertOk();
        $before = $this->sessionData($sessionId);
        $hashBefore = $user->refresh()->password;

        $this->changePassword($currentPassword, $newPassword)->assertUnprocessable()->assertJsonValidationErrors($errorField);

        $this->assertSame($hashBefore, $user->refresh()->password);
        $this->assertTrue($user->must_change_password);
        $this->assertSame($sessionId, $user->active_session_id);
        $this->assertSame($before, $this->sessionData($sessionId));
        $this->request('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->request('GET', $endpoint)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    #[DataProvider('roles')]
    public function test_replaced_cookie_cannot_change_password_or_recover_current_session(RoleName $role): void
    {
        [$user] = $this->createAccount($role);
        $sessionA = $this->login($user);
        $this->request('GET', '/api/v1/me')->assertOk();
        $cookiesA = $this->cookies;
        $hashBefore = $user->refresh()->password;
        $this->cookies = [];
        $sessionB = $this->login($user);
        $cookiesB = $this->cookies;
        $sessionBBefore = $this->sessionData($sessionB);
        $this->assertNotSame($sessionA, $sessionB);

        $this->cookies = $cookiesA;
        $this->changePassword()->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
        $this->assertSame($hashBefore, $user->refresh()->password);
        $this->assertTrue($user->must_change_password);
        $this->assertSame($sessionB, $user->active_session_id);
        $this->assertSame($sessionBBefore, $this->sessionData($sessionB));
        $this->request('GET', '/api/v1/me')->assertUnauthorized();

        $this->cookies = $cookiesB;
        $this->request('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->assertSame($sessionB, $user->refresh()->active_session_id);
    }

    #[DataProvider('roles')]
    public function test_inactive_account_cookie_cannot_change_password(RoleName $role): void
    {
        [$user] = $this->createAccount($role);
        $sessionId = $this->login($user);
        $hashBefore = $user->refresh()->password;
        $user->forceFill(['status' => 'INACTIVE'])->save();

        $this->changePassword()->assertUnauthorized()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertSame($hashBefore, $user->refresh()->password);
        $this->assertTrue($user->must_change_password);
        $this->assertSame($sessionId, $user->active_session_id);
        $this->request('GET', '/api/v1/me')->assertUnauthorized();
    }

    #[DataProvider('roles')]
    public function test_external_password_change_still_invalidates_old_cookie(RoleName $role): void
    {
        [$user, $endpoint] = $this->createAccount($role);
        $sessionId = $this->login($user);
        $this->request('GET', '/api/v1/me')->assertOk();
        $oldSessionHash = $this->sessionData($sessionId)['password_hash_web'];
        $user->forceFill(['password' => Hash::make('ExternalPassword1')])->save();
        $this->assertNotSame($this->expectedSessionHash($user->password), $oldSessionHash);

        $this->request('GET', '/api/v1/me')->assertUnauthorized()->assertJsonMissingPath('code');
        $this->assertArrayNotHasKey('password_hash_web', $this->sessionData($sessionId));
        $this->assertArrayNotHasKey($this->app['auth']->guard('web')->getName(), $this->sessionData($sessionId));
        $this->request('GET', $endpoint)->assertUnauthorized();
        $this->assertSame($sessionId, $user->refresh()->active_session_id);
        $this->assertTrue(Hash::check('ExternalPassword1', $user->password));
    }

    #[DataProvider('roles')]
    public function test_logout_after_password_change_clears_session_and_blocks_cookie_reuse(RoleName $role): void
    {
        [$user, $endpoint] = $this->createAccount($role);
        $sessionId = $this->login($user);
        $this->request('GET', '/api/v1/me')->assertOk();
        $this->changePassword()->assertOk();
        $this->request('GET', '/api/v1/me')->assertOk();
        $authenticatedCookies = $this->cookies;

        $this->request('POST', '/api/v1/auth/logout')->assertOk();
        $this->assertNull($user->refresh()->active_session_id);
        $this->assertNotSame($sessionId, $this->app['session']->getId());
        $this->request('GET', '/api/v1/me')->assertUnauthorized();
        $this->cookies = $authenticatedCookies;
        $this->request('GET', '/api/v1/me')->assertUnauthorized();
        $this->request('GET', $endpoint)->assertUnauthorized();
    }

    private function createAccount(RoleName $roleName): array
    {
        $user = User::factory()->create([
            'email' => 'continuity.'.strtolower($roleName->value).'@umss.edu.bo',
            'password' => 'Temporary1', 'status' => 'ACTIVE', 'must_change_password' => false,
        ]);
        app(InitialPasswordService::class)->initialize($user, self::INITIAL_PASSWORD);
        $role = Role::create(['name' => $roleName->value, 'description' => 'Session regression', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);

        if ($roleName === RoleName::DOCENTE) {
            Teacher::create(['user_id' => $user->id, 'institutional_code' => 'DOC-CONTINUITY',
                'identity_number' => self::INITIAL_PASSWORD, 'first_names' => 'Docente', 'last_names' => 'Sesiones', 'status' => 'ACTIVE']);

            return [$user, '/api/v1/teacher/dashboard/subjects'];
        }

        $career = Career::create(['code' => 'CONTINUITY', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        Student::create(['user_id' => $user->id, 'career_id' => $career->id, 'sis_code' => '202600001',
            'identity_number' => self::INITIAL_PASSWORD, 'first_names' => 'Estudiante', 'last_names' => 'Sesiones', 'status' => 'ACTIVE']);

        return [$user, '/api/v1/students/exams'];
    }

    private function login(User $user, string $password = self::INITIAL_PASSWORD): string
    {
        $this->request('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->request('POST', '/login', ['identifier' => $user->email, 'password' => $password])->assertOk();
        $sessionId = $this->app['session']->getId();
        $this->assertSame($sessionId, $user->refresh()->active_session_id);

        return $sessionId;
    }

    private function changePassword(string $current = self::INITIAL_PASSWORD, string $new = self::NEW_PASSWORD): TestResponse
    {
        return $this->request('PUT', '/user/password', [
            'current_password' => $current, 'password' => $new, 'password_confirmation' => $new,
        ]);
    }

    private function request(string $method, string $uri, array $payload = []): TestResponse
    {
        // Persist only the database PDO and the response cookies between fresh application instances.
        $this->refreshApplication();
        $this->app['db']->connection()->setPdo($this->isolatedPdo)->setReadPdo($this->isolatedPdo);
        config(['eida.institutional_email_domains' => ['umss.edu.bo'],
            'session.driver' => 'file', 'sanctum.stateful' => ['127.0.0.1:5173']]);

        $response = $this->call($method, $uri, [], $this->cookies, [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://127.0.0.1:5173',
        ], $payload ? json_encode($payload) : null);

        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        $cookieName = config('session.cookie');
        $this->assertArrayHasKey($cookieName, $this->cookies);
        $decryptedId = CookieValuePrefix::remove($this->app['encrypter']->decrypt($this->cookies[$cookieName], false));
        $this->assertSame($this->app['session']->getId(), $decryptedId, 'Client must apply the latest server session cookie.');

        return $response;
    }

    private function sessionData(string $sessionId): array
    {
        $contents = $this->app['session']->getHandler()->read($sessionId);

        return $contents === '' ? [] : unserialize($contents, ['allowed_classes' => false]);
    }

    private function expectedSessionHash(string $passwordHash): string
    {
        $guard = $this->app['auth']->guard('web');

        return method_exists($guard, 'hashPasswordForCookie') ? $guard->hashPasswordForCookie($passwordHash) : $passwordHash;
    }
}

