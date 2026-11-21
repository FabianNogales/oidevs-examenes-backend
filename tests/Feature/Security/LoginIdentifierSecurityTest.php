<?php

namespace Tests\Feature\Security;

use App\Actions\Fortify\ValidateLoginIdentifier;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedMobileDatabase;
use Tests\Support\OfflineExamFixture;
use Tests\TestCase;

class LoginIdentifierSecurityTest extends TestCase
{
    use IsolatedMobileDatabase, OfflineExamFixture;

    private const PASSWORD = 'ExistingPassword1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
        $this->createOfflineFixture();
        $this->fixture['user']->forceFill(['email' => 'sec02.teacher@umss.edu.bo', 'password' => self::PASSWORD])->save();
        $this->fixture['teacher']->forceFill(['institutional_code' => 'Doc-Mixto-SEC02'])->save();
        config(['eida.institutional_email_domains' => ['umss.edu.bo'], 'sanctum.stateful' => ['spa.test']]);
    }

    private function login(string $identifier, string $password, bool $mobile = false)
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->invalidate();
        $this->flushHeaders();
        $body = compact('identifier', 'password');
        if ($mobile) {
            $body += ['installation_id' => (string) Str::uuid(), 'device_name' => 'SEC02 isolated login'];
        }

        return $this->postJson($mobile ? '/api/v1/auth/mobile/login' : '/login', $body);
    }

    public function test_missing_email_sis_and_teacher_code_match_wrong_password_errors_on_web_and_mobile(): void
    {
        foreach ([false, true] as $mobile) {
            foreach (['missing@umss.edu.bo', '999999999', 'Doc-No-Existe', '12345', $this->fixture['user']->email] as $identifier) {
                $this->login($identifier, 'IncorrectPassword1', $mobile)->assertUnprocessable()
                    ->assertJsonPath('errors.identifier', [trans('auth.failed')]);
            }
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_teacher_code_validation_never_queries_existence(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });
        foreach (['Doc-Mixto-SEC02', 'Doc-No-Existe', '12345', '202600001'] as $identifier) {
            $request = Request::create('/login', 'POST', ['identifier' => $identifier, 'password' => self::PASSWORD]);
            $this->assertTrue(app(ValidateLoginIdentifier::class)->handle($request, fn () => true));
        }
        $this->assertSame([], $queries);
    }

    public function test_teacher_email_and_exact_institutional_code_work_on_web_and_mobile(): void
    {
        foreach ([false, true] as $mobile) {
            foreach ([' SEC02.TEACHER@umss.edu.bo ', 'Doc-Mixto-SEC02'] as $identifier) {
                $response = $this->login($identifier, self::PASSWORD, $mobile)->assertOk();
                if ($mobile) {
                    $response->assertJsonPath('data.user.id', $this->fixture['user']->id);
                } else {
                    $this->assertAuthenticatedAs($this->fixture['user'], 'web');
                }
            }
        }
    }

    public function test_student_sis_and_personnel_email_still_authenticate(): void
    {
        $student = Student::findOrFail($this->fixture['studentId']);
        $student->user->forceFill(['password' => self::PASSWORD, 'must_change_password' => false])->save();
        $this->login($student->sis_code, self::PASSWORD)->assertOk();
        $this->assertAuthenticatedAs($student->user, 'web');

        $staff = User::factory()->create(['email' => 'sec02.staff@umss.edu.bo', 'password' => self::PASSWORD,
            'status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'Personal SEC02'], ['status' => 'ACTIVE']);
        $role->forceFill(['is_custom' => true, 'custom_name_key' => 'PERSONAL SEC02'])->save();
        $staff->roles()->attach($role->id, ['status' => 'ACTIVE']);
        $this->login($staff->email, self::PASSWORD)->assertOk();
        $this->assertAuthenticatedAs($staff, 'web');
    }

    public function test_short_numeric_teacher_code_and_legacy_initial_password_remain_usable(): void
    {
        $this->fixture['teacher']->forceFill(['institutional_code' => '12345'])->save();
        $this->fixture['user']->forceFill(['password' => '1234567', 'must_change_password' => true])->save();
        $this->login('12345', '1234567')->assertOk();
        $this->login('12345', '1234567', true)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_inactive_account_restriction_is_preserved_without_authenticating_or_issuing_tokens(): void
    {
        $this->fixture['user']->forceFill(['status' => 'INACTIVE'])->save();
        foreach ([false, true] as $mobile) {
            $this->login($this->fixture['user']->email, self::PASSWORD, $mobile)->assertUnprocessable()
                ->assertJsonPath('errors.identifier', ['La cuenta se encuentra inactiva.']);
            $this->assertGuest('web');
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
