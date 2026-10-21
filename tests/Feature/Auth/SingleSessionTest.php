<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\InitialPasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function teacherDashboardEndpoints(): array
    {
        return [
            'subjects' => ['/api/v1/teacher/dashboard/subjects'],
            'upcoming exams' => ['/api/v1/teacher/dashboard/upcoming-exams'],
        ];
    }

    public static function inactiveTeacherAuthorizations(): array
    {
        $cases = [];
        foreach (self::teacherDashboardEndpoints() as $name => [$endpoint]) {
            $cases[$name.' inactive role'] = [$endpoint, 'roles'];
            $cases[$name.' inactive assignment'] = [$endpoint, 'role_user'];
        }

        return $cases;
    }

    #[DataProvider('teacherDashboardEndpoints')]
    public function test_current_teacher_cookie_can_read_dashboard_and_records_audit(string $endpoint): void
    {
        $teacher = $this->createDashboardTeacher();
        $sessionId = $this->loginFromNewSession($teacher);

        $this->useSessionCookie($sessionId)->getJson($endpoint)
            ->assertOk()->assertJsonPath('data', []);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $teacher->id,
            'action' => 'READ',
            'entity_type' => 'TeacherDashboard',
        ]);
        $this->assertSame($sessionId, $teacher->refresh()->active_session_id);
    }

    #[DataProvider('teacherDashboardEndpoints')]
    public function test_guest_cannot_read_teacher_dashboard(string $endpoint): void
    {
        $this->getJson($endpoint)->assertUnauthorized()
            ->assertJsonPath('message', 'La sesión no es válida o ha expirado.');
    }

    #[DataProvider('teacherDashboardEndpoints')]
    public function test_inactive_teacher_cookie_cannot_read_dashboard(string $endpoint): void
    {
        $teacher = $this->createDashboardTeacher();
        $sessionId = $this->loginFromNewSession($teacher);
        $teacher->forceFill(['status' => UserStatus::INACTIVE->value])->save();

        $this->useSessionCookie($sessionId)->getJson($endpoint)
            ->assertUnauthorized()->assertJsonPath('code', 'ACCOUNT_INACTIVE')
            ->assertJsonMissingPath('data');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'READ', 'entity_type' => 'TeacherDashboard']);
    }

    #[DataProvider('teacherDashboardEndpoints')]
    public function test_first_access_teacher_cookie_must_change_password_before_dashboard(string $endpoint): void
    {
        $teacher = $this->createDashboardTeacher();
        app(InitialPasswordService::class)->initialize($teacher, '12345678');
        $sessionId = $this->loginFromNewSession($teacher, '12345678');

        $this->useSessionCookie($sessionId)->getJson($endpoint)
            ->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED')
            ->assertJsonMissingPath('data');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'READ', 'entity_type' => 'TeacherDashboard']);

        $this->useSessionCookie($sessionId)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.must_change_password', true);
        $this->useSessionCookie($sessionId)->putJson('/user/password', [
            'current_password' => '12345678',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertFalse($teacher->refresh()->must_change_password);
        $this->assertSame($sessionId, $teacher->active_session_id);

        // Reautenticar con la nueva contraseña sin alterar el ciclo de sesión existente.
        $newSessionId = $this->loginFromNewSession($teacher, self::NEW_PASSWORD);
        $this->useSessionCookie($newSessionId)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.must_change_password', false);
        $this->useSessionCookie($newSessionId)->getJson($endpoint)->assertOk();
    }

    #[DataProvider('teacherDashboardEndpoints')]
    public function test_replaced_teacher_cookie_cannot_read_dashboard_but_current_cookie_can(string $endpoint): void
    {
        $teacher = $this->createDashboardTeacher();
        $sessionA = $this->loginFromNewSession($teacher);
        $sessionB = $this->loginFromNewSession($teacher);
        $this->assertNotSame($sessionA, $sessionB);

        $this->useSessionCookie($sessionA)->getJson($endpoint)
            ->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED')
            ->assertJsonMissingPath('data');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'READ', 'entity_type' => 'TeacherDashboard']);
        $this->assertSame($sessionB, $teacher->refresh()->active_session_id);

        $this->useSessionCookie($sessionB)->getJson($endpoint)->assertOk();
        $this->useSessionCookie($sessionB)->getJson('/api/v1/me')->assertOk();
        $this->assertSame($sessionB, $teacher->refresh()->active_session_id);
    }

    #[DataProvider('teacherDashboardEndpoints')]
    public function test_expired_teacher_cookie_cannot_read_dashboard(string $endpoint): void
    {
        $teacher = $this->createDashboardTeacher();
        $sessionId = $this->loginFromNewSession($teacher);
        session()->getHandler()->destroy($sessionId);

        $this->useSessionCookie($sessionId, persistCurrentSession: false)->getJson($endpoint)
            ->assertUnauthorized()->assertJsonMissingPath('code')->assertJsonMissingPath('data');
    }

    #[DataProvider('teacherDashboardEndpoints')]
    public function test_current_cookie_without_teacher_role_cannot_read_dashboard(string $endpoint): void
    {
        $user = $this->createUser('non.teacher@umss.edu.bo');
        $sessionId = $this->loginFromNewSession($user);

        $this->useSessionCookie($sessionId)->getJson($endpoint)->assertForbidden()
            ->assertJsonPath('message', 'Forbidden - Insufficient permissions')
            ->assertJsonMissingPath('data');
    }

    #[DataProvider('inactiveTeacherAuthorizations')]
    public function test_inactive_teacher_authorization_cannot_read_dashboard(string $endpoint, string $table): void
    {
        $teacher = $this->createDashboardTeacher();
        $sessionId = $this->loginFromNewSession($teacher);
        DB::table($table)->update(['status' => 'INACTIVE']);

        $this->useSessionCookie($sessionId)->getJson($endpoint)->assertForbidden()
            ->assertJsonPath('message', 'Forbidden - Insufficient permissions')
            ->assertJsonMissingPath('data');
    }

    private function createDashboardTeacher(): User
    {
        $user = $this->createUser('dashboard.session@umss.edu.bo');
        $roleId = DB::table('roles')->insertGetId([
            'name' => RoleName::DOCENTE->value,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->roles()->attach($roleId, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        DB::table('teachers')->insert([
            'user_id' => $user->id,
            'institutional_code' => 'DOC-SESSION-001',
            'identity_number' => '70000001',
            'first_names' => 'Docente',
            'last_names' => 'Sesiones',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
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
