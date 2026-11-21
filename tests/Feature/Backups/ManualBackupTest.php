<?php

namespace Tests\Feature\Backups;

use App\Models\Backup;
use App\Models\Role;
use App\Models\User;
use App\Services\Backups\BackupExecutor;
use App\Services\Backups\BackupFailure;
use App\Services\Backups\BackupService;
use App\Services\Backups\BackupStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualBackupTest extends TestCase
{
    use RefreshDatabase;

    private $executor;

    private $storage;

    // Fail before RefreshDatabase can touch an inherited/non-isolated configuration.
    public function createApplication()
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')
            || $app['config']->get('session.driver') !== 'array'
            || $app['config']->get('cache.default') !== 'array') {
            throw new \RuntimeException('Backup tests require in-memory SQLite and array session/cache.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['backups.enabled' => true, 'backups.callback_secret' => str_repeat('t', 48)]);
        config(['backups.download.enabled' => true, 'backups.download.worker_url' => 'https://download.example.invalid/download',
            'backups.download.signing_secret' => str_repeat('s', 48), 'backups.download.key_id' => 'test-key',
            'backups.download.issuer' => 'test-issuer']);
        $this->executor = Mockery::mock(BackupExecutor::class);
        $this->executor->shouldReceive('ready')->andReturn(true)->byDefault();
        $this->executor->shouldReceive('dispatch')->andReturn('ACCEPTED')->byDefault();
        $this->storage = Mockery::mock(BackupStorage::class);
        $this->storage->shouldReceive('ready')->andReturn(true)->byDefault();
        $this->storage->shouldReceive('verify')->andReturnNull()->byDefault();
        $this->app->instance(BackupExecutor::class, $this->executor);
        $this->app->instance(BackupStorage::class, $this->storage);
    }

    public static function adminEndpoints(): array
    {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        return [['GET', ''], ['POST', ''], ['GET', '/'.$id], ['POST', '/'.$id.'/download-ticket']];
    }

    #[DataProvider('adminEndpoints')]
    public function test_guests_are_rejected(string $method, string $suffix): void
    {
        $this->json($method, '/api/v1/admin/backups'.$suffix)->assertUnauthorized();
    }

    #[DataProvider('adminEndpoints')]
    public function test_custom_roles_and_teachers_cannot_use_any_endpoint(string $method, string $suffix): void
    {
        $this->account('OPERADOR');
        $this->json($method, '/api/v1/admin/backups'.$suffix)->assertForbidden();
        $this->account('DOCENTE');
        $this->json($method, '/api/v1/admin/backups'.$suffix)->assertForbidden();
    }

    public function test_inactive_and_password_pending_accounts_cannot_request(): void
    {
        $user = $this->account();
        $user->forceFill(['must_change_password' => true])->save();
        $this->postJson('/api/v1/admin/backups')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->forceFill(['must_change_password' => false, 'status' => 'INACTIVE'])->save();
        $this->postJson('/api/v1/admin/backups')->assertUnauthorized()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertDatabaseCount('backups', 0);
    }

    public function test_request_is_queued_audited_and_does_not_wait_for_dump(): void
    {
        $user = $this->account();
        $this->executor->shouldReceive('dispatch')->once()->with(Mockery::on(fn ($id) => Str::isUuid($id)))->andReturn('ACCEPTED');
        $response = $this->postJson('/api/v1/admin/backups')->assertStatus(202)->assertJsonPath('data.state', 'QUEUED');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('backups', ['id' => $id, 'requested_by' => $user->id, 'active_slot' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'BACKUP_REQUESTED', 'user_id' => $user->id]);
        $response->assertJsonMissingPath('data.storage_file_id')->assertJsonMissingPath('data.manifest_file_id')
            ->assertJsonMissingPath('data.ticket');
    }

    public function test_duplicate_and_simultaneous_requests_have_only_one_active_slot(): void
    {
        $this->account();
        $this->postJson('/api/v1/admin/backups')->assertStatus(202);
        $this->postJson('/api/v1/admin/backups')->assertConflict()->assertJsonPath('code', 'BACKUP_IN_PROGRESS');
        $this->account(); // Global database slot applies to other administrators too.
        $this->postJson('/api/v1/admin/backups')->assertConflict();
        $this->assertDatabaseCount('backups', 1);
    }

    public function test_disabled_configuration_is_inactive_and_does_not_dispatch(): void
    {
        $this->account();
        config(['backups.enabled' => false]);
        $this->executor->shouldNotReceive('dispatch');
        $this->postJson('/api/v1/admin/backups')->assertStatus(503)->assertJsonPath('code', 'BACKUPS_DISABLED');
        $this->assertDatabaseCount('backups', 0);
    }

    public function test_definitive_dispatch_failure_is_failed_but_ambiguous_transport_stays_queued(): void
    {
        $this->account();
        $this->executor->shouldReceive('dispatch')->once()->andReturn('REJECTED');
        $this->postJson('/api/v1/admin/backups')->assertStatus(503)->assertJsonPath('data.state', 'FAILED');
        $this->assertDatabaseHas('backups', ['status' => 'FAILED', 'active_slot' => null, 'error_code' => 'DISPATCH_FAILED']);
        $this->executor->shouldReceive('dispatch')->once()->andReturn('UNKNOWN');
        $this->postJson('/api/v1/admin/backups')->assertStatus(202)->assertJsonPath('data.state', 'QUEUED')
            ->assertJsonPath('data.error_code', 'DISPATCH_UNCONFIRMED');
    }

    public function test_request_limit_is_persisted_even_without_shared_cache(): void
    {
        $user = $this->account();
        for ($i = 0; $i < 3; $i++) {
            $this->backup(['requested_by' => $user->id]);
        }
        $this->postJson('/api/v1/admin/backups')->assertStatus(429);
    }

    public function test_list_is_paginated_and_summary_counts_all_pages(): void
    {
        $this->account();
        for ($i = 0; $i < 12; $i++) {
            $this->backup();
        }
        $this->getJson('/api/v1/admin/backups?per_page=10&page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 12)->assertJsonPath('summary.total', 12);
        $this->getJson('/api/v1/admin/backups?per_page=101')->assertUnprocessable();
    }

    public function test_unsigned_changed_or_expired_callback_is_rejected(): void
    {
        $backup = $this->backup(['status' => 'QUEUED', 'active_slot' => 1]);
        $payload = ['backup_id' => $backup->id, 'status' => 'RUNNING', 'external_run_id' => '123.1'];
        $this->postJson(BackupService::CALLBACK_PATH, $payload)->assertUnauthorized();
        $this->sendBackupCallbackRequest($payload, timestamp: now()->subMinutes(6)->timestamp)->assertUnauthorized();
        $this->sendBackupCallbackRequest($payload, secret: 'wrong-secret')->assertUnauthorized();
        $this->assertSame('QUEUED', $backup->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'BACKUP_CALLBACK_REJECTED']);
    }

    public function test_callbacks_are_ordered_idempotent_and_bind_the_external_run(): void
    {
        $backup = $this->backup(['status' => 'QUEUED', 'active_slot' => 1]);
        $payload = ['backup_id' => $backup->id, 'status' => 'RUNNING', 'external_run_id' => '123.1'];
        $nonce = (string) Str::uuid();
        $this->sendBackupCallbackRequest($payload, $nonce)->assertOk();
        $this->sendBackupCallbackRequest($payload, $nonce)->assertOk();
        $this->assertDatabaseCount('backup_callback_receipts', 1);
        $this->sendBackupCallbackRequest([...$payload, 'status' => 'UPLOADING'], $nonce)->assertConflict();
        $this->sendBackupCallbackRequest([...$payload, 'external_run_id' => '456.1', 'status' => 'UPLOADING'])->assertConflict();
        $this->sendBackupCallbackRequest([...$payload, 'status' => 'UPLOADING'])->assertOk();
        $this->sendBackupCallbackRequest($payload)->assertConflict();
        $this->sendBackupCallbackRequest([...$payload, 'backup_id' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_available_requires_storage_verification_and_is_terminal(): void
    {
        $backup = $this->backup(['status' => 'UPLOADING', 'active_slot' => 1, 'external_run_id' => '123.1']);
        $this->storage->shouldReceive('verify')->once()->andReturnNull();
        $this->sendBackupCallbackRequest($this->evidence($backup))->assertOk();
        $this->assertSame('AVAILABLE', $backup->refresh()->status);
        $this->assertNotNull($backup->verified_at);
        $this->assertNull($backup->active_slot);
        $this->sendBackupCallbackRequest(['backup_id' => $backup->id, 'status' => 'FAILED', 'external_run_id' => '123.1', 'error_code' => 'EXECUTOR_FAILED'])->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['action' => 'BACKUP_AVAILABLE']);
    }

    public function test_storage_outage_can_retry_but_corruption_is_never_available(): void
    {
        $backup = $this->backup(['status' => 'UPLOADING', 'active_slot' => 1, 'external_run_id' => '123.1']);
        $nonce = (string) Str::uuid();
        $this->storage->shouldReceive('verify')->once()->andThrow(new BackupFailure('STORAGE_UNAVAILABLE'));
        $this->sendBackupCallbackRequest($this->evidence($backup), $nonce)->assertStatus(503);
        $this->assertSame('UPLOADING', $backup->refresh()->status);
        $this->assertDatabaseCount('backup_callback_receipts', 0);
        $this->storage->shouldReceive('verify')->once()->andThrow(new BackupFailure('INTEGRITY_MISMATCH'));
        $this->sendBackupCallbackRequest($this->evidence($backup), $nonce)->assertUnprocessable();
        $this->sendBackupCallbackRequest($this->evidence($backup), $nonce)->assertUnprocessable();
        $this->assertDatabaseHas('backups', ['id' => $backup->id, 'status' => 'FAILED', 'error_code' => 'INTEGRITY_MISMATCH']);
    }

    public function test_abandoned_execution_expires_and_late_callbacks_cannot_revive_it(): void
    {
        $this->account();
        $backup = $this->backup(['status' => 'RUNNING', 'active_slot' => 1,
            'external_run_id' => '123.1', 'deadline_at' => now()->subMinute()]);
        $this->getJson('/api/v1/admin/backups/'.$backup->id)->assertOk()->assertJsonPath('data.state', 'FAILED')
            ->assertJsonPath('data.error_code', 'EXECUTION_EXPIRED');
        $this->sendBackupCallbackRequest(['backup_id' => $backup->id, 'status' => 'UPLOADING', 'external_run_id' => '123.1'])->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['action' => 'BACKUP_ABANDONED']);
    }

    public function test_download_is_authorized_reverified_audited_and_never_proxies_dump(): void
    {
        $this->account();
        $backup = $this->backup(['status' => 'AVAILABLE', 'bytes' => 123, 'sha256' => str_repeat('a', 64),
            'storage_file_id' => 'drive-dump-id', 'manifest_file_id' => 'drive-manifest-id',
            'storage_parent_id' => 'private-folder', 'storage_version' => '1',
            'integrity_method' => 'READBACK_SHA256', 'external_run_id' => '123.1', 'postgres_version' => '17.7']);
        $this->storage->shouldReceive('verify')->once()->andReturnNull();
        $response = $this->postJson('/api/v1/admin/backups/'.$backup->id.'/download-ticket')->assertOk()
            ->assertJsonPath('data.method', 'POST')->assertJsonPath('data.url', 'https://download.example.invalid/download')
            ->assertJsonMissingPath('data.file_id')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(3, explode('.', $response->json('data.ticket')));
        $this->assertDatabaseHas('audit_logs', ['action' => 'BACKUP_DOWNLOAD_TICKET_ISSUED']);
        $queued = $this->backup(['status' => 'QUEUED']);
        $this->postJson('/api/v1/admin/backups/'.$queued->id.'/download-ticket')->assertConflict();
        $this->storage->shouldReceive('verify')->once()->andThrow(new BackupFailure('STORAGE_UNAVAILABLE'));
        $this->postJson('/api/v1/admin/backups/'.$backup->id.'/download-ticket')->assertStatus(503);
        $this->assertDatabaseHas('audit_logs', ['action' => 'BACKUP_DOWNLOAD_FAILED']);
    }

    private function account(string $roleName = 'ADMINISTRADOR'): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => $roleName], ['status' => 'ACTIVE']);
        if ($roleName === 'OPERADOR') {
            $role->forceFill(['is_custom' => true])->save();
        }
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $user->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($user);

        return $user;
    }

    private function backup(array $overrides = []): Backup
    {
        $id = (string) Str::uuid();

        return Backup::create($overrides + [
            'id' => $id, 'requester_name' => 'Administrador de prueba', 'type' => 'MANUAL_FULL',
            'status' => 'FAILED', 'filename' => 'eida-'.$id.'.dump',
            'storage_provider' => 'google_drive',
            'requested_at' => now(), 'deadline_at' => now()->addHour(),
        ]);
    }

    private function evidence(Backup $backup): array
    {
        return ['backup_id' => $backup->id, 'status' => 'AVAILABLE', 'external_run_id' => '123.1',
            'storage_provider' => 'google_drive', 'file_id' => 'drive-dump-id', 'manifest_id' => 'drive-manifest-id',
            'parent_id' => 'private-folder', 'bytes' => 123, 'sha256' => str_repeat('a', 64),
            'storage_version' => '1', 'integrity_method' => 'READBACK_SHA256', 'postgres_version' => '17.7'];
    }

    private function sendBackupCallbackRequest(array $payload, ?string $nonce = null, ?int $timestamp = null, ?string $secret = null)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $nonce ??= (string) Str::uuid();
        $timestamp ??= now()->timestamp;
        $canonical = $timestamp."\n".$nonce."\nPOST\n".BackupService::CALLBACK_PATH."\n".$body;

        return $this->call('POST', BackupService::CALLBACK_PATH, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BACKUP_TIMESTAMP' => (string) $timestamp, 'HTTP_X_BACKUP_NONCE' => $nonce,
            'HTTP_X_BACKUP_SIGNATURE' => hash_hmac('sha256', $canonical, $secret ?? config('backups.callback_secret')),
        ], $body);
    }
}
