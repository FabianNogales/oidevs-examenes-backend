<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\InitialPasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FirstAccessTest extends TestCase
{
    use RefreshDatabase;

    private const INITIAL_PASSWORD = '12345678';

    private const NEW_PASSWORD = 'NewPassword1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eida.institutional_email_domains' => ['umss.edu.bo'],
        ]);

        Route::middleware(['auth:sanctum', 'password.changed'])
            ->get('/test/password-ready', fn () => response()->json(['success' => true]))
            ->name('test.password-ready');
    }

    public function test_must_change_password_is_cast_to_boolean(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
        ]);

        $this->assertIsBool($user->refresh()->must_change_password);
        $this->assertTrue($user->must_change_password);
    }

    public function test_user_default_must_change_password_is_false(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_initial_password_service_stores_hash_and_requires_password_change(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPassword1',
            'must_change_password' => false,
        ]);

        app(InitialPasswordService::class)->initialize($user, self::INITIAL_PASSWORD);

        $user->refresh();

        $this->assertNotSame(self::INITIAL_PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::INITIAL_PASSWORD, $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public function test_active_user_with_must_change_password_can_login(): void
    {
        $user = $this->createFirstAccessUser(email: 'first.login@umss.edu.bo');

        $this->postJson('/login', [
            'identifier' => 'first.login@umss.edu.bo',
            'password' => self::INITIAL_PASSWORD,
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_current_user_after_login_returns_must_change_password_true(): void
    {
        $user = $this->createFirstAccessUser(email: 'first.me@umss.edu.bo');

        $this->postJson('/login', [
            'identifier' => 'first.me@umss.edu.bo',
            'password' => self::INITIAL_PASSWORD,
        ])->assertOk();

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_first_access_successful_login_updates_last_login_at(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-09-09 11:45:00'));
        $user = $this->createFirstAccessUser(email: 'first.last.login@umss.edu.bo');

        try {
            $this->postJson('/login', [
                'identifier' => 'first.last.login@umss.edu.bo',
                'password' => self::INITIAL_PASSWORD,
            ])->assertOk();

            $this->assertTrue($user->refresh()->last_login_at->equalTo($now));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_first_access_user_can_change_password(): void
    {
        $user = $this->createFirstAccessUser(email: 'change.password@umss.edu.bo');

        $this->actingAs($user)
            ->putJson('/user/password', [
                'current_password' => self::INITIAL_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
    }

    public function test_successful_password_change_clears_must_change_password(): void
    {
        $user = $this->createFirstAccessUser(email: 'clear.flag@umss.edu.bo');

        $this->actingAs($user)
            ->putJson('/user/password', [
                'current_password' => self::INITIAL_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_new_password_allows_future_authentication_and_initial_password_stops_working(): void
    {
        $user = $this->createFirstAccessUser(email: 'future.auth@umss.edu.bo');

        $this->actingAs($user)
            ->putJson('/user/password', [
                'current_password' => self::INITIAL_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->postJson('/logout')->assertNoContent();

        $this->postJson('/login', [
            'identifier' => 'future.auth@umss.edu.bo',
            'password' => self::INITIAL_PASSWORD,
        ])->assertUnprocessable();

        $this->assertGuest();

        $this->postJson('/login', [
            'identifier' => 'future.auth@umss.edu.bo',
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_current_password_does_not_change_password_or_flag(): void
    {
        $user = $this->createFirstAccessUser(email: 'wrong.current@umss.edu.bo');
        $originalHash = $user->password;

        $this->actingAs($user)
            ->putJson('/user/password', [
                'current_password' => 'WrongPass1',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertUnprocessable();

        $user->refresh();

        $this->assertSame($originalHash, $user->password);
        $this->assertTrue($user->must_change_password);
    }

    public function test_invalid_new_password_does_not_change_password_or_flag(): void
    {
        $user = $this->createFirstAccessUser(email: 'invalid.new@umss.edu.bo');
        $originalHash = $user->password;

        $this->actingAs($user)
            ->putJson('/user/password', [
                'current_password' => self::INITIAL_PASSWORD,
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertUnprocessable();

        $user->refresh();

        $this->assertSame($originalHash, $user->password);
        $this->assertTrue($user->must_change_password);
    }

    public function test_regular_user_can_continue_changing_password_normally(): void
    {
        $user = User::factory()->create([
            'email' => 'regular@umss.edu.bo',
            'password' => 'RegularPass1',
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)
            ->putJson('/user/password', [
                'current_password' => 'RegularPass1',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->assertFalse($user->refresh()->must_change_password);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
    }

    public function test_current_user_response_does_not_expose_sensitive_password_data(): void
    {
        $user = $this->createFirstAccessUser(email: 'sensitive.me@umss.edu.bo');

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertDontSee($user->password)
            ->assertDontSee(self::INITIAL_PASSWORD);
    }

    public function test_password_change_required_middleware_blocks_first_access_user(): void
    {
        $user = $this->createFirstAccessUser(email: 'blocked.middleware@umss.edu.bo');

        $this->actingAs($user)
            ->getJson('/test/password-ready')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_password_change_required_middleware_allows_user_after_password_change(): void
    {
        $user = User::factory()->create([
            'email' => 'allowed.middleware@umss.edu.bo',
            'password' => self::NEW_PASSWORD,
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)
            ->getJson('/test/password-ready')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function createFirstAccessUser(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
        ]);

        return app(InitialPasswordService::class)->initialize($user, self::INITIAL_PASSWORD);
    }
}
