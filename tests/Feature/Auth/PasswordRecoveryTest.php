<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'OldPass1';

    private const NEW_PASSWORD = 'NewPass1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eida.institutional_email_domains' => ['umss.edu.bo'],
        ]);
    }

    public function test_forgot_password_sends_reset_notification_for_existing_user(): void
    {
        Notification::fake();
        $user = $this->createUser('forgot@umss.edu.bo');

        $this->postJson('/forgot-password', [
            'email' => 'forgot@umss.edu.bo',
        ])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_response_does_not_expose_password_or_hash(): void
    {
        Notification::fake();
        $user = $this->createUser('forgot.secure@umss.edu.bo');

        $this->postJson('/forgot-password', [
            'email' => 'forgot.secure@umss.edu.bo',
        ])
            ->assertOk()
            ->assertDontSee(self::OLD_PASSWORD)
            ->assertDontSee($user->password);
    }

    public function test_valid_reset_token_sets_new_hashed_password(): void
    {
        Notification::fake();
        $user = $this->createUser('reset.valid@umss.edu.bo');
        $token = $this->requestResetToken($user);

        $this->postJson('/reset-password', [
            'email' => 'reset.valid@umss.edu.bo',
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $user->refresh();

        $this->assertNotSame(self::NEW_PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
    }

    public function test_invalid_reset_token_is_rejected_and_does_not_change_password_or_flag(): void
    {
        $user = $this->createUser('reset.invalid@umss.edu.bo', mustChangePassword: true);
        $originalHash = $user->password;

        $this->postJson('/reset-password', [
            'email' => 'reset.invalid@umss.edu.bo',
            'token' => 'invalid-token',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnprocessable();

        $user->refresh();

        $this->assertSame($originalHash, $user->password);
        $this->assertTrue($user->must_change_password);
    }

    public function test_successful_reset_allows_new_password_and_rejects_old_password(): void
    {
        Notification::fake();
        $user = $this->createUser('reset.auth@umss.edu.bo');
        $token = $this->requestResetToken($user);

        $this->postJson('/reset-password', [
            'email' => 'reset.auth@umss.edu.bo',
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/login', [
            'identifier' => 'reset.auth@umss.edu.bo',
            'password' => self::OLD_PASSWORD,
        ])->assertUnprocessable();

        $this->assertGuest();

        $this->postJson('/login', [
            'identifier' => 'reset.auth@umss.edu.bo',
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    public function test_successful_reset_clears_must_change_password(): void
    {
        Notification::fake();
        $user = $this->createUser('reset.first.access@umss.edu.bo', mustChangePassword: true);
        $token = $this->requestResetToken($user);

        $this->postJson('/reset-password', [
            'email' => 'reset.first.access@umss.edu.bo',
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_password_boundaries_are_enforced_for_reset(): void
    {
        $sevenCharacterUser = $this->createUser('reset.boundary.7@umss.edu.bo');

        $this->postJson('/reset-password', [
            'email' => $sevenCharacterUser->email,
            'token' => Password::broker()->createToken($sevenCharacterUser),
            'password' => 'Passw1!',
            'password_confirmation' => 'Passw1!',
        ])->assertUnprocessable();

        $eightCharacterUser = $this->createUser('reset.boundary.8@umss.edu.bo');

        $this->postJson('/reset-password', [
            'email' => $eightCharacterUser->email,
            'token' => Password::broker()->createToken($eightCharacterUser),
            'password' => 'Passw1!A',
            'password_confirmation' => 'Passw1!A',
        ])->assertOk();

        $twentyCharacterUser = $this->createUser('reset.boundary.20@umss.edu.bo');

        $this->postJson('/reset-password', [
            'email' => $twentyCharacterUser->email,
            'token' => Password::broker()->createToken($twentyCharacterUser),
            'password' => '12345678901234567890',
            'password_confirmation' => '12345678901234567890',
        ])->assertOk();

        $twentyOneCharacterUser = $this->createUser('reset.boundary.21@umss.edu.bo');

        $this->postJson('/reset-password', [
            'email' => $twentyOneCharacterUser->email,
            'token' => Password::broker()->createToken($twentyOneCharacterUser),
            'password' => '123456789012345678901',
            'password_confirmation' => '123456789012345678901',
        ])->assertUnprocessable();
    }

    private function createUser(string $email, bool $mustChangePassword = false): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => self::OLD_PASSWORD,
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => $mustChangePassword,
        ]);
    }

    private function requestResetToken(User $user): string
    {
        $token = null;

        $this->postJson('/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertNotNull($token);

        return $token;
    }
}
