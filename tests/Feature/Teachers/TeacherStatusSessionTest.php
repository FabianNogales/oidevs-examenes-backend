<?php

namespace Tests\Feature\Teachers;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Teachers\TeacherService;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TeacherStatusSessionTest extends TestCase
{
    use RefreshDatabase;

    private Connection $isolatedConnection;
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedConnection = DB::connection();
    }

    public static function passwordStates(): array
    {
        return ['established password' => [false], 'initial password' => [true]];
    }

    #[DataProvider('passwordStates')]
    public function test_deactivation_revokes_only_target_sessions_and_tokens_preserving_credentials(bool $initial): void
    {
        $teacher = $this->teacher($initial);
        $user = $teacher->user;
        $user->forceFill(['active_session_id' => 'previous-session'])->save();
        $password = $user->password;
        $user->createToken('target-test-token');
        $other = User::factory()->create(['active_session_id' => 'other-session']);
        $other->createToken('other-test-token');

        app(TeacherService::class)->changeStatus($teacher, UserStatus::INACTIVE);
        $user->refresh();

        $this->assertSame('INACTIVE', $user->status);
        $this->assertNotNull($user->active_session_id);
        $this->assertNotSame('previous-session', $user->active_session_id);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->assertSame('other-session', $other->refresh()->active_session_id);
        $this->assertSame($password, $user->password);
        $this->assertSame($initial, $user->must_change_password);

        $revokedSession = $user->active_session_id;
        app(TeacherService::class)->changeStatus($teacher, UserStatus::ACTIVE);
        $this->assertSame($revokedSession, $user->refresh()->active_session_id);
        $this->assertSame($password, $user->password);
        $this->assertSame($initial, $user->must_change_password);
    }

    public function test_setting_active_does_not_revoke_an_authorized_multirole_account(): void
    {
        $teacher = $this->teacher();
        $user = $teacher->user;
        $role = Role::create(['name' => RoleName::ESTUDIANTE->value, 'status' => 'ACTIVE']);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $roles = DB::table('role_user')->where('user_id', $user->id)->get()->toJson();
        $user->forceFill(['active_session_id' => 'authorized-session'])->save();
        $user->createToken('authorized-test-token');

        app(TeacherService::class)->changeStatus($teacher, UserStatus::ACTIVE);

        $this->assertSame('authorized-session', $user->refresh()->active_session_id);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame($roles, DB::table('role_user')->where('user_id', $user->id)->get()->toJson());
    }

    public function test_repeated_deactivation_reasserts_revocation(): void
    {
        $teacher = $this->teacher();
        $service = app(TeacherService::class);
        $service->changeStatus($teacher, UserStatus::INACTIVE);
        $previous = $teacher->user->active_session_id;
        $teacher->user->createToken('late-test-token');

        $service->changeStatus($teacher, UserStatus::INACTIVE);

        $this->assertNotNull($teacher->user->active_session_id);
        $this->assertNotSame($previous, $teacher->user->active_session_id);
        $this->assertSame(0, $teacher->user->tokens()->count());
    }

    public function test_audit_failure_rolls_back_status_session_revocation_and_token_deletion(): void
    {
        $teacher = $this->teacher();
        $user = $teacher->user;
        $user->forceFill(['active_session_id' => 'authorized-session'])->save();
        $user->createToken('rollback-test-token');
        $failAudit = true;
        AuditLog::creating(function (AuditLog $audit) use (&$failAudit) {
            if ($failAudit && $audit->action === 'TEACHER_DEACTIVATED') {
                throw new RuntimeException('Simulated audit failure');
            }
        });

        try {
            app(TeacherService::class)->changeStatus($teacher, UserStatus::INACTIVE);
            $this->fail('The simulated audit error must reject the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        } finally {
            $failAudit = false;
        }

        $this->assertSame(UserStatus::ACTIVE, $teacher->refresh()->status);
        $this->assertSame('ACTIVE', $user->refresh()->status);
        $this->assertSame('authorized-session', $user->active_session_id);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'TEACHER_DEACTIVATED']);
    }

    public function test_old_http_cookie_cannot_revive_after_reactivation_but_new_login_keeps_credentials(): void
    {
        $teacher = $this->teacher();
        $this->request('POST', '/login', ['identifier' => $teacher->user->email, 'password' => 'Password1'])->assertOk();
        $oldCookies = $this->cookies;
        $oldSession = $teacher->user->refresh()->active_session_id;
        $this->request('GET', '/api/v1/me')->assertOk();
        $this->request('GET', '/api/v1/teacher/dashboard/subjects')->assertOk();

        app(TeacherService::class)->changeStatus($teacher, UserStatus::INACTIVE);
        $this->cookies = [];
        $this->request('POST', '/login', ['identifier' => $teacher->user->email, 'password' => 'Password1'])->assertUnprocessable();
        app(TeacherService::class)->changeStatus($teacher, UserStatus::ACTIVE);

        // Reuse the untouched cookie issued before deactivation, not the logged-out replacement.
        $this->cookies = $oldCookies;
        $this->request('GET', '/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
        $this->cookies = [];
        $this->request('POST', '/login', ['identifier' => $teacher->user->email, 'password' => 'Password1'])->assertOk();
        $this->request('GET', '/api/v1/teacher/dashboard/subjects')->assertOk();
        $user = $teacher->user->refresh();
        $this->assertNotSame($oldSession, $user->active_session_id);
        $this->assertTrue(Hash::check('Password1', $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_current_cookie_is_rejected_while_account_is_inactive(): void
    {
        $teacher = $this->teacher();
        $this->request('POST', '/login', ['identifier' => $teacher->user->email, 'password' => 'Password1'])->assertOk();
        app(TeacherService::class)->changeStatus($teacher, UserStatus::INACTIVE);

        $this->request('GET', '/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    private function teacher(bool $initial = false): Teacher
    {
        $teacher = Teacher::factory()->create();
        $teacher->user->forceFill(['email' => 'status.session@umss.edu.bo', 'status' => 'ACTIVE',
            'must_change_password' => $initial, 'password' => 'Password1'])->save();
        $role = Role::firstOrCreate(['name' => RoleName::DOCENTE->value], ['status' => 'ACTIVE']);
        $teacher->user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);

        return $teacher;
    }

    private function request(string $method, string $uri, array $payload = []): TestResponse
    {
        // Fresh applications and encrypted HTTP cookies. Keep the isolated connection so
        // RefreshDatabase's outer transaction and service savepoints remain consistent.
        $this->refreshApplication();
        DB::extend('sqlite', fn () => $this->isolatedConnection);
        config(['eida.institutional_email_domains' => ['umss.edu.bo'], 'session.driver' => 'file',
            'sanctum.stateful' => ['127.0.0.1:5173']]);
        $response = $this->call($method, $uri, [], $this->cookies, [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:5173',
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
        $id = CookieValuePrefix::remove($this->app['encrypter']->decrypt($this->cookies[$cookieName], false));
        $this->assertSame($this->app['session']->getId(), $id);

        return $response;
    }
}
