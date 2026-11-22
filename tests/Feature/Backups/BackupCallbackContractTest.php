<?php

namespace Tests\Feature\Backups;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Services\Backups\BackupExecutor;
use App\Services\Backups\BackupFailure;
use App\Services\Backups\BackupService;
use App\Services\Backups\BackupStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupCallbackContractTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'fixture-tttttttttttttttttttttttttttttttttttttttttttttttt';

    public function createApplication()
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')
            || $app['config']->get('session.driver') !== 'array'
            || $app['config']->get('cache.default') !== 'array') {
            throw new \RuntimeException('Callback contract tests require isolated SQLite and array session/cache.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        config(['backups.enabled' => true, 'backups.callback_secret' => self::SECRET]);
        $executor = Mockery::mock(BackupExecutor::class);
        $executor->shouldReceive('completedExecution')->andThrow(new BackupFailure('EXECUTOR_STATE_UNVERIFIED'));
        $executor->shouldNotReceive('dispatch');
        $storage = Mockery::mock(BackupStorage::class);
        $storage->shouldNotReceive('verify');
        $this->app->instance(BackupExecutor::class, $executor);
        $this->app->instance(BackupStorage::class, $storage);
    }

    public function test_real_python_signature_claims_queued_backup_and_failed_callback_releases_slot(): void
    {
        $backup = $this->queuedBackup();
        $this->postPythonCallback($backup)->assertOk()->assertExactJson(['acknowledged' => true]);
        $this->assertSame('RUNNING', $backup->refresh()->status);
        $this->assertSame('123.1', $backup->external_run_id);
        $this->assertSame(1, $backup->active_slot);
        $this->assertNotNull($backup->started_at);

        $this->postPythonCallback($backup, ['status' => 'FAILED',
            'evidence' => ['error_code' => 'EXECUTOR_FAILED']])->assertOk();
        $this->assertSame('FAILED', $backup->refresh()->status);
        $this->assertNull($backup->active_slot);
        $this->assertNotNull($backup->completed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'BACKUP_FAILED']);
        Http::assertNothingSent();
    }

    public function test_python_and_php_use_the_same_utf8_key_bytes(): void
    {
        $secret = 'fixture-'.str_repeat('á', 32);
        config(['backups.callback_secret' => $secret]);
        $backup = $this->queuedBackup();
        $this->postPythonCallback($backup, ['secret' => $secret])->assertOk();
        $this->assertSame('RUNNING', $backup->refresh()->status);
    }

    public static function invalidAuthentication(): array
    {
        return [
            [['secret' => 'fixture-wrong-sensitive-marker-'.str_repeat('w', 48)], 'SIGNATURE_MISMATCH'],
            [['tamper' => true], 'SIGNATURE_MISMATCH'],
            [['old_timestamp' => true], 'TIMESTAMP_EXPIRED'],
            [['nonce' => 'invalid-nonce'], 'NONCE_INVALID'],
        ];
    }

    #[DataProvider('invalidAuthentication')]
    public function test_invalid_authentication_is_rejected_and_only_safe_condition_is_audited(array $changes, string $condition): void
    {
        $backup = $this->queuedBackup();
        $response = $this->postPythonCallback($backup, $changes)->assertUnauthorized()
            ->assertJsonPath('code', 'EXECUTOR_UNAUTHENTICATED');
        $this->assertSame('QUEUED', $backup->refresh()->status);
        $this->assertSame(1, $backup->active_slot);
        $audit = AuditLog::where('action', 'BACKUP_CALLBACK_REJECTED')->firstOrFail();
        $this->assertSame($condition, $audit->new_values['condition']);
        foreach ([$response->getContent(), json_encode($audit->new_values)] as $output) {
            $this->assertStringNotContainsString('sensitive-marker', $output);
            $this->assertStringNotContainsString(self::SECRET, $output);
        }
        Http::assertNothingSent();
    }

    public static function invalidIdentifiers(): array
    {
        return [
            ['backup_id', 'invalid-uuid', 422],
            ['backup_id', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 404],
            ['external_run_id', '123', 422],
            ['external_run_id', '123.x', 422],
            ['external_run_id', '123.1.2', 422],
        ];
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_signed_but_invalid_or_unknown_identifiers_are_rejected(string $field, string $value, int $status): void
    {
        $backup = $this->queuedBackup();
        $this->postPythonCallback($backup, [$field => $value])->assertStatus($status);
        $this->assertSame('QUEUED', $backup->refresh()->status);
        $this->assertSame(1, $backup->active_slot);
        Http::assertNothingSent();
    }

    public function test_a_different_run_attempt_cannot_change_the_binding(): void
    {
        $backup = $this->queuedBackup();
        $this->postPythonCallback($backup)->assertOk();
        $this->postPythonCallback($backup, ['status' => 'UPLOADING', 'external_run_id' => '123.2'])
            ->assertConflict()->assertJsonPath('code', 'INVALID_BACKUP_TRANSITION');
        $this->assertSame('RUNNING', $backup->refresh()->status);
        $this->assertSame('123.1', $backup->external_run_id);
    }

    public function test_duplicate_claim_with_a_new_nonce_is_rejected_without_restarting(): void
    {
        $backup = $this->queuedBackup();
        $this->postPythonCallback($backup)->assertOk();
        $this->postPythonCallback($backup)->assertConflict()->assertJsonPath('code', 'INVALID_BACKUP_TRANSITION');
        $this->assertSame('RUNNING', $backup->refresh()->status);
    }

    public function test_expired_backup_cannot_be_claimed_even_with_a_valid_python_signature(): void
    {
        $backup = $this->queuedBackup();
        $backup->forceFill(['deadline_at' => now()->subMinute()])->save();
        $this->postPythonCallback($backup)->assertConflict()->assertJsonPath('code', 'INVALID_BACKUP_TRANSITION');
        $this->assertSame('QUEUED', $backup->refresh()->status);
        Http::assertNothingSent();
    }

    private function queuedBackup(): Backup
    {
        $id = (string) Str::uuid();

        return Backup::create(['id' => $id, 'requester_name' => 'Contract fixture', 'type' => 'MANUAL_FULL',
            'status' => 'QUEUED', 'filename' => 'eida-'.$id.'.dump', 'storage_provider' => 'google_drive',
            'requested_at' => now(), 'deadline_at' => now()->addHour(), 'active_slot' => 1]);
    }

    private function postPythonCallback(Backup $backup, array $changes = [])
    {
        $parameters = array_replace(['backup_id' => $backup->id, 'external_run_id' => '123.1',
            'status' => 'RUNNING', 'secret' => self::SECRET, 'timestamp' => now()->timestamp,
            'nonce' => (string) Str::uuid()], $changes);
        if ($parameters['old_timestamp'] ?? false) {
            $parameters['timestamp'] -= 360;
        }
        $python = getenv('EIDA_TEST_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
        $source = getenv('EIDA_TEST_BACKUP_EXECUTOR_DIR') ?: base_path('scripts/backups');
        $process = new Process([$python, '-B', base_path('tests/Support/Backups/emit_python_callback.py'), $source],
            base_path(), ['PYTHONDONTWRITEBYTECODE' => '1']);
        $process->setInput(json_encode($parameters, JSON_THROW_ON_ERROR));
        $process->setTimeout(20);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Offline Python callback fixture could not be generated.');
        $fixture = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $body = base64_decode($fixture['body_base64'], true).(($parameters['tamper'] ?? false) ? ' ' : '');
        $headers = $fixture['headers'];

        return $this->call('POST', BackupService::CALLBACK_PATH, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BACKUP_TIMESTAMP' => $headers['x-backup-timestamp'],
            'HTTP_X_BACKUP_NONCE' => $headers['x-backup-nonce'],
            'HTTP_X_BACKUP_SIGNATURE' => $headers['x-backup-signature'],
        ], $body);
    }
}
