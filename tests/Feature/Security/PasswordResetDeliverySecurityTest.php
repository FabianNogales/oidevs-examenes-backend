<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Auth\PasswordResetDeliveryPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\IsolatedMobileDatabase;
use Tests\TestCase;

class PasswordResetDeliverySecurityTest extends TestCase
{
    use IsolatedMobileDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshDatabase();
        config(['mail.driver' => null, 'mail.mailers.smtp.url' => null]);
        Notification::fake();
    }

    private function requestResetLink(string $email)
    {
        // Production/local simulation enables real CSRF middleware. Supply a
        // matching session token rather than disabling that protection.
        $token = Str::random(40);

        return $this->withSession(['_token' => $token])->postJson('/forgot-password', ['email' => $email, '_token' => $token]);
    }

    public function test_production_logging_or_array_mailer_fails_explicitly_before_creating_tokens(): void
    {
        $this->app->instance('env', 'production');
        foreach (['log', 'array', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->requestResetLink('user@umss.edu.bo')->assertStatus(503)
                ->assertJsonPath('code', 'PASSWORD_RECOVERY_UNAVAILABLE');
        }
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_production_smtp_can_use_existing_reset_notification_flow_without_exposing_token(): void
    {
        $this->app->instance('env', 'production');
        config(['mail.default' => 'smtp']);
        $actor = User::factory()->create(['email' => 'delivery@umss.edu.bo', 'status' => 'ACTIVE']);
        $response = $this->requestResetLink($actor->email)->assertOk();
        $token = null;
        Notification::assertSentTo($actor, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token);
        $response->assertDontSee($token)->assertDontSee($actor->password);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_local_logging_remains_an_explicit_development_option(): void
    {
        $this->app->instance('env', 'local');
        config(['mail.default' => 'log']);
        $actor = User::factory()->create(['email' => 'local@umss.edu.bo', 'status' => 'ACTIVE']);
        $this->requestResetLink($actor->email)->assertOk();
        Notification::assertSentTo($actor, ResetPassword::class);
    }

    public function test_fallback_mailers_must_not_include_a_logging_or_discarding_transport(): void
    {
        $policy = app(PasswordResetDeliveryPolicy::class);
        config(['mail.mailers.failover.mailers' => ['smtp', 'log']]);
        $this->assertFalse($policy->canDeliverWithoutLogging('failover'));
        config(['mail.mailers.failover.mailers' => ['smtp'],
            'mail.mailers.rotation' => ['transport' => 'roundrobin', 'mailers' => ['failover', 'array']]]);
        $this->assertTrue($policy->canDeliverWithoutLogging('failover'));
        $this->assertFalse($policy->canDeliverWithoutLogging('rotation'));
        config(['mail.mailers.failover.mailers' => ['failover']]);
        $this->assertFalse($policy->canDeliverWithoutLogging('failover'));
        $this->assertFalse($policy->canDeliverWithoutLogging('nonexistent'));
    }

    public function test_mail_url_and_legacy_driver_cannot_hide_a_logging_transport(): void
    {
        $policy = app(PasswordResetDeliveryPolicy::class);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'log://localhost']);
        $this->assertFalse($policy->canDeliverWithoutLogging());
        config(['mail.mailers.smtp.url' => null, 'mail.driver' => 'log']);
        $this->assertFalse($policy->canDeliverWithoutLogging());
    }
}
