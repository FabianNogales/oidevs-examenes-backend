<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eida.institutional_email_domains' => ['umss.edu.bo'],
        ]);
    }

    public function test_user_can_login_with_institutional_email(): void
    {
        $user = $this->createUser(email: 'student@umss.edu.bo');

        $this->postJson('/login', [
            'identifier' => 'student@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_accepts_legacy_email_field_for_current_frontend_compatibility(): void
    {
        $user = $this->createUser(email: 'legacy@umss.edu.bo');

        $this->postJson('/login', [
            'email' => 'legacy@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_institutional_email_with_wrong_password_is_rejected(): void
    {
        $this->createUser(email: 'wrong.password@umss.edu.bo');

        $this->postJson('/login', [
            'identifier' => 'wrong.password@umss.edu.bo',
            'password' => 'WrongPass1',
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_external_email_domain_is_rejected(): void
    {
        $this->postJson('/login', [
            'identifier' => 'external@gmail.com',
            'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_unknown_institutional_email_is_rejected(): void
    {
        $this->postJson('/login', [
            'identifier' => 'missing@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_user_can_login_with_sis_code(): void
    {
        [$user] = $this->createStudentUser(sisCode: '202300123');

        $this->postJson('/login', [
            'identifier' => '202300123',
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_sis_code_is_rejected(): void
    {
        $this->postJson('/login', [
            'identifier' => '202300999',
            'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_sis_code_with_less_than_nine_digits_is_rejected(): void
    {
        $this->postJson('/login', [
            'identifier' => '12345678',
            'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_sis_code_with_non_numeric_characters_is_rejected(): void
    {
        $this->postJson('/login', [
            'identifier' => '20230012A',
            'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_valid_sis_code_with_wrong_password_is_rejected(): void
    {
        $this->createStudentUser(sisCode: '202300124');

        $this->postJson('/login', [
            'identifier' => '202300124',
            'password' => 'WrongPass1',
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_identifier_is_required(): void
    {
        $this->postJson('/login', [
            'identifier' => '',
            'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_password_is_required(): void
    {
        $this->postJson('/login', [
            'identifier' => 'student@umss.edu.bo',
            'password' => '',
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_identifier_must_not_exceed_one_hundred_characters(): void
    {
        $this->postJson('/login', [
            'identifier' => str_repeat('1', 101),
            'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_password_must_have_at_least_eight_characters(): void
    {
        $this->postJson('/login', [
            'identifier' => 'student@umss.edu.bo',
            'password' => 'short7',
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_password_must_not_exceed_twenty_characters(): void
    {
        $this->postJson('/login', [
            'identifier' => 'student@umss.edu.bo',
            'password' => str_repeat('a', 21),
        ])->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_active_user_can_login(): void
    {
        $user = $this->createUser(email: 'active@umss.edu.bo', status: UserStatus::ACTIVE->value);

        $this->postJson('/login', [
            'identifier' => 'active@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->createUser(email: 'inactive@umss.edu.bo', status: UserStatus::INACTIVE->value);

        $this->postJson('/login', [
            'identifier' => 'inactive@umss.edu.bo',
            'password' => self::PASSWORD,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identifier');
    }

    public function test_inactive_user_does_not_remain_authenticated(): void
    {
        $this->createUser(email: 'inactive.session@umss.edu.bo', status: UserStatus::INACTIVE->value);

        $this->postJson('/login', [
            'identifier' => 'inactive.session@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_successful_login_updates_last_login_at(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-09-09 10:30:00'));
        $user = $this->createUser(email: 'last.login@umss.edu.bo');

        try {
            $this->postJson('/login', [
                'identifier' => 'last.login@umss.edu.bo',
                'password' => self::PASSWORD,
            ])->assertOk();

            $this->assertTrue($user->refresh()->last_login_at->equalTo($now));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_successful_login_records_login_audit_data(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-09-09 12:15:00'));
        $user = $this->createUser(email: 'audit.login@umss.edu.bo');

        try {
            $this->withServerVariables([
                'REMOTE_ADDR' => '192.0.2.10',
                'HTTP_USER_AGENT' => 'EIDA Test Browser',
            ])->postJson('/login', [
                'identifier' => 'audit.login@umss.edu.bo',
                'password' => self::PASSWORD,
            ])->assertOk();

            $auditLog = AuditLog::query()->where('action', 'LOGIN')->firstOrFail();

            $this->assertSame($user->id, $auditLog->user_id);
            $this->assertSame(User::class, $auditLog->entity_type);
            $this->assertSame($user->id, $auditLog->entity_id);
            $this->assertSame('192.0.2.10', $auditLog->ip_address);
            $this->assertSame('EIDA Test Browser', $auditLog->user_agent);
            $this->assertTrue($auditLog->created_at->equalTo($now));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failed_login_does_not_update_last_login_at(): void
    {
        $user = $this->createUser(email: 'failed.login@umss.edu.bo');

        $this->postJson('/login', [
            'identifier' => 'failed.login@umss.edu.bo',
            'password' => 'WrongPass1',
        ])->assertUnprocessable();

        $this->assertNull($user->refresh()->last_login_at);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'LOGIN',
            'user_id' => $user->id,
        ]);
    }

    public function test_inactive_account_login_does_not_update_last_login_at(): void
    {
        $user = $this->createUser(
            email: 'inactive.last.login@umss.edu.bo',
            status: UserStatus::INACTIVE->value,
        );

        $this->postJson('/login', [
            'identifier' => 'inactive.last.login@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->assertNull($user->refresh()->last_login_at);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'LOGIN',
            'user_id' => $user->id,
        ]);
    }

    public function test_current_user_endpoint_works_after_login(): void
    {
        $user = $this->createUser(email: 'me.after.login@umss.edu.bo');

        $this->postJson('/login', [
            'identifier' => 'me.after.login@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertOk();
        $this->useSessionCookie($user->refresh()->active_session_id);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'me.after.login@umss.edu.bo');
    }

    public function test_roles_still_appear_on_current_user_after_login(): void
    {
        $user = $this->createUser(email: 'roles.after.login@umss.edu.bo');
        $role = Role::query()->create([
            'name' => RoleName::ADMINISTRADOR->value,
            'description' => 'Administrador del sistema.',
            'status' => UserStatus::ACTIVE->value,
        ]);
        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);

        $this->postJson('/login', [
            'identifier' => 'roles.after.login@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertOk();
        $this->useSessionCookie($user->refresh()->active_session_id);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.roles', [
                RoleName::ADMINISTRADOR->value,
            ]);
    }

    public function test_logout_invalidates_authentication(): void
    {
        $user = $this->createUser(email: 'logout@umss.edu.bo');

        $this->postJson('/login', [
            'identifier' => 'logout@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertOk();
        $this->useSessionCookie($user->refresh()->active_session_id);

        $this->postJson('/logout')->assertNoContent();

        $this->assertGuest();
    }

    public function test_current_user_endpoint_returns_unauthorized_after_logout(): void
    {
        $user = $this->createUser(email: 'me.after.logout@umss.edu.bo');

        $this->postJson('/login', [
            'identifier' => 'me.after.logout@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertOk();
        $this->useSessionCookie($user->refresh()->active_session_id);

        $this->postJson('/logout')->assertNoContent();

        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_login_response_does_not_expose_password_or_hashes(): void
    {
        $user = $this->createUser(email: 'secure.response@umss.edu.bo');

        $response = $this->postJson('/login', [
            'identifier' => 'secure.response@umss.edu.bo',
            'password' => self::PASSWORD,
        ])->assertOk();

        $response->assertDontSee(self::PASSWORD);
        $response->assertDontSee($user->password);
    }

    public function test_login_flow_does_not_return_sensitive_tokens(): void
    {
        $this->createUser(email: 'no.tokens@umss.edu.bo');

        $this->postJson('/login', [
            'identifier' => 'no.tokens@umss.edu.bo',
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('access_token')
            ->assertJsonMissingPath('plain_text_token')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.access_token');
    }

    private function createUser(string $email, string $status = UserStatus::ACTIVE->value): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => self::PASSWORD,
            'status' => $status,
        ]);
    }

    private function useSessionCookie(string $sessionId): self
    {
        return $this
            ->withCredentials()
            ->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $sessionId);
    }

    /**
     * @return array{0: User, 1: Student}
     */
    private function createStudentUser(string $sisCode): array
    {
        $user = $this->createUser(email: $sisCode.'@umss.edu.bo');
        $careerId = DB::table('careers')->insertGetId([
            'code' => 'INF-'.$sisCode,
            'name' => 'Informatica',
            'status' => UserStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = Student::query()->create([
            'user_id' => $user->id,
            'sis_code' => $sisCode,
            'identity_number' => 'CI-'.$sisCode,
            'first_names' => 'Test',
            'last_names' => 'Student',
            'career_id' => $careerId,
            'status' => UserStatus::ACTIVE->value,
        ]);

        return [$user, $student];
    }
}
