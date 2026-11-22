<?php

namespace Tests\Feature\Backups;

use App\Models\Role;
use App\Models\User;
use App\Services\Backups\BackupExecutor;
use App\Services\Backups\BackupReadinessDiagnostic;
use App\Services\Backups\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackupReadinessDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/backups/diagnostics/readiness';

    // Fail before RefreshDatabase can touch a non-isolated database.
    public function createApplication()
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')
            || $app['config']->get('session.driver') !== 'array'
            || $app['config']->get('cache.default') !== 'array') {
            throw new \RuntimeException('Backup diagnostic tests require in-memory SQLite and array session/cache.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        config([
            'sanctum.stateful' => ['localhost'],
            'backups.diagnostics.enabled' => true,
            'backups.enabled' => true,
            'backups.callback_secret' => str_repeat('callback-sensitive-marker-', 2),
            'backups.executor' => 'github',
            'backups.github.repository' => 'private-owner/private-repository',
            'backups.github.token' => 'github-sensitive-marker',
            'backups.github.ref' => 'backup-execution',
            'backups.drive.client_id' => 'client-id-sensitive-marker',
            'backups.drive.client_secret' => 'client-secret-sensitive-marker',
            'backups.drive.refresh_token' => 'refresh-token-sensitive-marker',
            'backups.drive.folder_id' => 'folder-sensitive-marker',
        ]);
    }

    public function test_guest_cannot_access_diagnostic(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized()
            ->assertJsonMissingPath('overall_ready')->assertJsonMissingPath('failed_conditions');
        Http::assertNothingSent();
    }

    public static function forbiddenRoles(): array
    {
        return [['DOCENTE', false], ['OPERADOR', true], ['ADMINISTRADOR', true]];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_other_roles_and_custom_administrator_are_denied(string $name, bool $custom): void
    {
        $this->authenticateAdministrator($name, $custom);
        $this->getJson(self::ENDPOINT)->assertForbidden()->assertJsonMissingPath('overall_ready');
        Http::assertNothingSent();
    }

    public function test_password_change_is_required(): void
    {
        $user = $this->authenticateAdministrator();
        $user->forceFill(['must_change_password' => true])->save();
        $this->getJson(self::ENDPOINT)->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED')->assertJsonMissingPath('overall_ready');
    }

    public function test_inactive_account_is_denied(): void
    {
        $user = $this->authenticateAdministrator();
        $user->forceFill(['status' => 'INACTIVE'])->save();
        $this->getJson(self::ENDPOINT)->assertUnauthorized()
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE')->assertJsonMissingPath('overall_ready');
    }

    public function test_replaced_web_session_is_denied(): void
    {
        $user = $this->authenticateAdministrator();
        $user->forceFill(['active_session_id' => 'another-current-session'])->save();
        $this->getJson(self::ENDPOINT)->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_REPLACED')->assertJsonMissingPath('overall_ready');
    }

    public function test_inactive_role_assignment_is_denied(): void
    {
        $user = $this->authenticateAdministrator();
        $user->roles()->updateExistingPivot($user->roles()->first()->id, ['status' => 'INACTIVE']);
        $this->getJson(self::ENDPOINT)->assertForbidden()->assertJsonMissingPath('overall_ready');
    }

    public function test_inactive_administrator_role_is_denied(): void
    {
        $user = $this->authenticateAdministrator();
        $user->roles()->first()->forceFill(['status' => 'INACTIVE'])->save();
        $this->getJson(self::ENDPOINT)->assertForbidden()->assertJsonMissingPath('overall_ready');
    }

    public function test_disabled_diagnostic_switch_closes_endpoint(): void
    {
        $this->authenticateAdministrator();
        config(['backups.diagnostics.enabled' => false]);
        $diagnostic = Mockery::mock(BackupReadinessDiagnostic::class);
        $diagnostic->shouldNotReceive('administratorReport');
        $this->app->instance(BackupReadinessDiagnostic::class, $diagnostic);

        $response = $this->getJson(self::ENDPOINT)->assertNotFound()
            ->assertExactJson(['failed_conditions' => ['diagnostics_disabled']]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public function test_administrator_gets_only_boolean_indicators_without_side_effects(): void
    {
        $this->authenticateAdministrator();
        $response = $this->getJson(self::ENDPOINT)->assertOk()->assertExactJson([
            'module_enabled' => true,
            'callback_secret_valid' => true,
            'github_executor_ready' => true,
            'drive_storage_ready' => true,
            'overall_ready' => true,
            'failed_conditions' => [],
        ]);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['callback-sensitive-marker-', 'private-owner/private-repository',
            'github-sensitive-marker', 'client-id-sensitive-marker', 'client-secret-sensitive-marker',
            'refresh-token-sensitive-marker', 'folder-sensitive-marker', 'EIDA_BACKUPS_'] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
        $this->assertDatabaseCount('backups', 0);
        $this->assertDatabaseCount('backup_callback_receipts', 0);
        Http::assertNothingSent();
    }

    public static function failingConfiguration(): array
    {
        return [
            ['backups.enabled', false, 'module_enabled'],
            ['backups.callback_secret', 'short-secret', 'callback_secret_minimum'],
            ['backups.executor', 'other', 'github_executor_selected'],
            ['backups.github.repository', 'https://github.com/owner/repo', 'github_repository_format'],
            ['backups.github.token', '', 'github_token_not_empty'],
            ['backups.github.ref', 'main', 'github_execution_ref'],
            ['backups.drive.client_id', '', 'drive_client_id_not_empty'],
            ['backups.drive.client_secret', '', 'drive_client_secret_not_empty'],
            ['backups.drive.refresh_token', '', 'drive_refresh_token_not_empty'],
            ['backups.drive.folder_id', 'https://drive.google.com/drive/folders/private-id', 'drive_folder_id_format'],
        ];
    }

    #[DataProvider('failingConfiguration')]
    public function test_each_readiness_condition_is_reported_without_enabling_backups(string $path, mixed $value, string $condition): void
    {
        $this->authenticateAdministrator();
        config([$path => $value]);
        $response = $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('overall_ready', false);
        $this->assertContains($condition, $response->json('failed_conditions'));
        $this->assertFalse(app(BackupService::class)->enabled());
        $this->assertDatabaseCount('backups', 0);
        Http::assertNothingSent();
    }

    public function test_multiple_failures_are_reported_despite_short_circuiting_in_enabled(): void
    {
        $this->authenticateAdministrator();
        config(['backups.enabled' => false, 'backups.callback_secret' => 'short',
            'backups.github.token' => '', 'backups.drive.folder_id' => 'invalid/id']);
        $response = $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('module_enabled', false)
            ->assertJsonPath('callback_secret_valid', false)->assertJsonPath('github_executor_ready', false)
            ->assertJsonPath('drive_storage_ready', false)->assertJsonPath('overall_ready', false);
        foreach (['module_enabled', 'callback_secret_minimum', 'github_token_not_empty', 'drive_folder_id_format'] as $condition) {
            $this->assertContains($condition, $response->json('failed_conditions'));
        }
        Http::assertNothingSent();
    }

    public function test_evaluation_exception_never_exposes_its_message_or_trace(): void
    {
        $this->authenticateAdministrator();
        $executor = Mockery::mock(BackupExecutor::class);
        $executor->shouldReceive('ready')->andThrow(new \RuntimeException('exception-sensitive-marker'));
        $executor->shouldNotReceive('dispatch');
        $this->app->instance(BackupExecutor::class, $executor);
        $response = $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('github_executor_ready', false)->assertJsonPath('overall_ready', false);
        $this->assertStringNotContainsString('exception-sensitive-marker', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
        $this->assertContains('github_executor_ready', $response->json('failed_conditions'));
        Http::assertNothingSent();
    }

    public function test_console_command_uses_the_shared_diagnostic_report(): void
    {
        $diagnostic = Mockery::mock(BackupReadinessDiagnostic::class);
        $diagnostic->shouldReceive('report')->once()->andReturn(['summary_enabled' => false]);
        $this->app->instance(BackupReadinessDiagnostic::class, $diagnostic);
        $this->artisan('backups:diagnose')->expectsOutputToContain('"summary_enabled": false')->assertExitCode(0);
        Http::assertNothingSent();
    }

    private function authenticateAdministrator(string $name = 'ADMINISTRADOR', bool $custom = false): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => $name], ['status' => 'ACTIVE']);
        $role->forceFill(['is_custom' => $custom])->save();
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->withSession(['backup_diagnostic_test' => true]);
        $sessionId = $this->app['session']->getId();
        $user->forceFill(['active_session_id' => $sessionId])->save();
        $this->actingAs($user, 'web')->withCredentials()->withHeader('Origin', 'http://localhost')
            ->withCookie(config('session.cookie'), $sessionId);

        return $user;
    }
}
