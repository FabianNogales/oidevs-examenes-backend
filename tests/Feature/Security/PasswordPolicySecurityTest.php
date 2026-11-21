<?php

namespace Tests\Feature\Security;

use App\Services\Auth\PasswordPolicy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\Support\IsolatedMobileDatabase;
use Tests\Support\OfflineExamFixture;
use Tests\TestCase;

class PasswordPolicySecurityTest extends TestCase
{
    use IsolatedMobileDatabase, OfflineExamFixture;

    private const INITIAL = 'InitialPass1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
        $this->createOfflineFixture();
        $this->fixture['user']->forceFill(['email' => 'sec02.password@umss.edu.bo', 'password' => self::INITIAL])->save();
        config(['eida.institutional_email_domains' => ['umss.edu.bo'], 'sanctum.stateful' => ['spa.test']]);
    }

    private function longPassword(): string
    {
        return str_pad('esta es una frase de acceso larga y segura ', PasswordPolicy::MAX_LENGTH, 'x');
    }

    private function input(string $password): array
    {
        return ['password' => $password, 'password_confirmation' => $password];
    }

    private function login(string $password, bool $mobile = false)
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->invalidate();
        $this->flushHeaders();
        $data = ['identifier' => $this->fixture['user']->email, 'password' => $password];
        if ($mobile) {
            $data += ['installation_id' => (string) Str::uuid(), 'device_name' => 'SEC02 password test'];
        }

        return $this->postJson($mobile ? '/api/v1/auth/mobile/login' : '/login', $data);
    }

    public function test_passphrases_at_the_maximum_need_no_external_compromised_password_service(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $this->assertTrue(Validator::make($this->input($this->longPassword()), ['password' => PasswordPolicy::newPasswordRules()])->passes());
        Http::assertNothingSent();
    }

    public function test_first_mandatory_change_accepts_long_password_and_both_login_consumers_can_use_it(): void
    {
        $actor = $this->fixture['user'];
        $actor->forceFill(['must_change_password' => true])->save();
        $this->actingAs($actor, 'web')->putJson('/user/password',
            ['current_password' => self::INITIAL] + $this->input($this->longPassword()))->assertOk();
        $this->assertFalse($actor->fresh()->must_change_password);
        $this->assertTrue(Hash::check($this->longPassword(), $actor->fresh()->password));
        $this->login($this->longPassword())->assertOk();
        $this->login($this->longPassword(), true)->assertOk();
    }

    public function test_reset_accepts_long_password_and_both_login_consumers_can_use_it(): void
    {
        $actor = $this->fixture['user'];
        $token = Password::broker()->createToken($actor);
        $this->postJson('/reset-password', ['email' => $actor->email, 'token' => $token] + $this->input($this->longPassword()))->assertOk();
        $this->assertFalse($actor->fresh()->must_change_password);
        $this->login($this->longPassword())->assertOk();
        $this->login($this->longPassword(), true)->assertOk();
    }

    public function test_too_long_password_is_rejected_consistently_for_setting_and_both_logins(): void
    {
        $tooLong = str_repeat('a', PasswordPolicy::MAX_LENGTH + 1);
        $this->assertTrue(Validator::make($this->input($tooLong), ['password' => PasswordPolicy::newPasswordRules()])->fails());
        foreach ([false, true] as $mobile) {
            $this->login($tooLong, $mobile)->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->actingAs($this->fixture['user'], 'web')->putJson('/user/password',
            ['current_password' => self::INITIAL] + $this->input($tooLong))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check(self::INITIAL, $this->fixture['user']->fresh()->password));
    }

    public function test_short_new_password_is_rejected_while_existing_credentials_are_not_rewritten(): void
    {
        $actor = $this->fixture['user'];
        $originalHash = $actor->password;
        $this->actingAs($actor, 'web')->putJson('/user/password',
            ['current_password' => self::INITIAL] + $this->input('short'))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($originalHash, $actor->fresh()->password);
        $this->assertTrue(Validator::make(['password' => '1234567'], ['password' => PasswordPolicy::loginRules()])->passes());
    }

    public function test_bcrypt_byte_limit_and_null_bytes_cannot_silently_truncate_new_passwords(): void
    {
        config(['hashing.driver' => 'bcrypt']);
        $this->assertTrue(Validator::make($this->input(str_repeat('é', 36)), ['password' => PasswordPolicy::newPasswordRules()])->passes());
        foreach ([str_repeat('é', 40), "twelve chars\0suffix"] as $password) {
            $this->assertTrue(Validator::make($this->input($password), ['password' => PasswordPolicy::newPasswordRules()])->fails());
        }
    }
}
