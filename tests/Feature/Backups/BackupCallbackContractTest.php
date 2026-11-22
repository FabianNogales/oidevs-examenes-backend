<?php

namespace Tests\Feature\Backups;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Role;
use App\Models\User;
use App\Services\Backups\BackupExecutor;
use App\Services\Backups\BackupFailure;
use App\Services\Backups\BackupService;
use App\Services\Backups\BackupStorage;
use App\Services\Backups\GithubBackupExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        Http::fake([]);
        config(['backups.enabled' => true, 'backups.callback_secret' => self::SECRET]);
        $executor = Mockery::mock(BackupExecutor::class);
        $executor->shouldReceive('completedExecution')->andThrow(new BackupFailure('EXECUTOR_STATE_UNVERIFIED'));
        $executor->shouldReceive('assertRunningExecution')->andReturnNull()->byDefault();
        $executor->shouldNotReceive('dispatch');
        $storage = Mockery::mock(BackupStorage::class);
        $storage->shouldReceive('ready')->andReturn(true);
        $storage->shouldNotReceive('verify');
        $this->app->instance(BackupExecutor::class, $executor);
        $this->app->instance(BackupStorage::class, $storage);
    }

    public static function dispatchCallbackTiming(): array
    {
        return ['during dispatch' => [true, 204], 'after dispatch' => [false, 204],
            'during ambiguous response' => [true, 503], 'during rejected response' => [true, 403]];
    }

    #[DataProvider('dispatchCallbackTiming')]
    public function test_admin_request_dispatch_and_first_real_python_callback(bool $duringDispatch, int $dispatchStatus): void
    {
        $this->assertDispatchedClaim($duringDispatch, [], 200, $dispatchStatus);
    }

    public static function foreignDispatchedExecutions(): array
    {
        return [
            ['display_title', 'EIDA backup bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
            ['head_branch', 'main'],
            ['path', '.github/workflows/another.yml'],
            ['event', 'push'],
            ['run_attempt', 2],
            ['id', 45069670271],
        ];
    }

    #[DataProvider('foreignDispatchedExecutions')]
    public function test_first_claim_cannot_use_another_run_branch_workflow_or_attempt(string $field, mixed $value): void
    {
        $this->assertDispatchedClaim(false, [$field => $value], 409);
    }

    public function test_pending_metadata_is_retryable_without_releasing_the_slot(): void
    {
        $backup = $this->assertDispatchedClaim(false, ['status' => 'queued'], 503);
        $this->assertSame(1, $backup->active_slot);
        $audit = AuditLog::where('action', 'BACKUP_TRANSITION_REJECTED')->firstOrFail();
        $this->assertSame('EXECUTION_UNVERIFIED', $audit->new_values['condition']);
    }

    public static function postgresTimezones(): array
    {
        return ['application offset' => ['America/La_Paz', 'UTC'],
            'database offset with UTC application' => ['UTC', 'Asia/Dubai']];
    }

    #[DataProvider('postgresTimezones')]
    public function test_first_dispatched_claim_preserves_instants_across_postgres_timezone_round_trip(string $appTimezone, string $databaseTimezone): void
    {
        $previousTimezone = date_default_timezone_get();
        config(['app.timezone' => $appTimezone]);
        date_default_timezone_set($appTimezone);
        $this->travelTo(CarbonImmutable::parse('2026-11-22T21:01:05Z'));
        $this->emulatePostgresTimestampRoundTrip($databaseTimezone);
        try {
            $backup = $this->assertDispatchedClaim(false);
            $this->assertSame('2026-11-22T21:01:05+00:00', $backup->requested_at->utc()->toIso8601String());
            $this->assertSame('2026-11-22T22:01:05+00:00', $backup->deadline_at->utc()->toIso8601String());
        } finally {
            $this->travelBack();
            date_default_timezone_set($previousTimezone);
        }
    }

    public function test_legacy_offset_free_binding_reproduces_incident_dates_and_409_with_utc_application(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22T21:01:05Z'));
        // Replay the old offset-free Eloquent binding at the INSERT boundary.
        Backup::creating(function (Backup $backup) {
            $attributes = $backup->getAttributes();
            foreach (['requested_at', 'deadline_at'] as $field) {
                $attributes[$field] = CarbonImmutable::parse($attributes[$field])->format('Y-m-d H:i:s');
            }
            $backup->setRawAttributes($attributes);
        });
        $this->emulatePostgresTimestampRoundTrip('Asia/Dubai');
        try {
            $backup = $this->assertDispatchedClaim(false, [], 409, 204, false);
            $this->assertSame('2026-11-22T17:01:05+00:00', $backup->requested_at->utc()->toIso8601String());
            $this->assertSame('2026-11-22T18:01:05+00:00', $backup->deadline_at->utc()->toIso8601String());
            $audit = AuditLog::where('action', 'BACKUP_TRANSITION_REJECTED')->firstOrFail();
            $this->assertSame('DEADLINE_EXPIRED', $audit->new_values['condition']);
        } finally {
            $this->travelBack();
        }
    }

    private function emulatePostgresTimestampRoundTrip(string $databaseTimezone): void
    {
        // PostgreSQL interprets offset-free timestamptz input in its session timezone.
        // Only test fixture storage is emulated; no PostgreSQL connection is opened.
        Backup::created(function (Backup $backup) use ($databaseTimezone) {
            $values = [];
            foreach (['requested_at', 'deadline_at'] as $field) {
                $values[$field] = CarbonImmutable::parse($backup->getAttributes()[$field], $databaseTimezone)
                    ->utc()->format('Y-m-d H:i:sP');
            }
            DB::table('backups')->where('id', $backup->id)->update($values);
        });
    }

    public function test_dispatched_execution_completes_the_full_available_transition_without_losing_the_slot_early(): void
    {
        $storage = Mockery::mock(BackupStorage::class);
        $storage->shouldReceive('ready')->andReturn(true);
        $storage->shouldReceive('verify')->once()->andReturnNull();
        $this->app->instance(BackupStorage::class, $storage);
        $backup = $this->assertDispatchedClaim(false);
        $this->postPythonCallback($backup, ['status' => 'UPLOADING', 'external_run_id' => '45069670270.1'])
            ->assertOk();
        $this->assertSame('UPLOADING', $backup->refresh()->status);
        $this->assertSame(1, $backup->active_slot);
        $this->postPythonCallback($backup, ['status' => 'AVAILABLE', 'external_run_id' => '45069670270.1',
            'evidence' => ['storage_provider' => 'google_drive', 'file_id' => 'fixture-dump',
                'manifest_id' => 'fixture-manifest', 'parent_id' => 'fixture-parent',
                'bytes' => 123, 'sha256' => str_repeat('a', 64), 'storage_version' => '1',
                'integrity_method' => 'READBACK_SHA256', 'postgres_version' => '17.7']])->assertOk();
        $this->assertSame('AVAILABLE', $backup->refresh()->status);
        $this->assertNull($backup->active_slot);
        $this->assertNotNull($backup->verified_at);
    }

    public function test_dispatched_claim_is_idempotent_only_with_the_same_nonce_and_body(): void
    {
        $backup = $this->assertDispatchedClaim(false);
        $startedAt = $backup->started_at;
        $nonce = DB::table('backup_callback_receipts')->where('backup_id', $backup->id)->value('nonce');
        $this->postPythonCallback($backup, ['external_run_id' => '45069670270.1', 'nonce' => $nonce])->assertOk();
        $this->assertTrue($startedAt->equalTo($backup->refresh()->started_at));
        $this->postPythonCallback($backup, ['external_run_id' => '45069670270.1'])->assertConflict();
        $this->assertSame('RUNNING', $backup->refresh()->status);
        Http::assertSentCount(2); // Dispatch plus the first claim's metadata GET.
    }

    public function test_failed_dispatched_backup_is_terminal_and_concurrency_stays_protected(): void
    {
        $backup = $this->assertDispatchedClaim(false);
        $this->postJson('/api/v1/admin/backups')->assertConflict()->assertJsonPath('code', 'BACKUP_IN_PROGRESS');
        $this->postPythonCallback($backup, ['status' => 'FAILED', 'external_run_id' => '45069670270.1',
            'evidence' => ['error_code' => 'EXECUTOR_FAILED']])->assertOk();
        $this->postPythonCallback($backup, ['external_run_id' => '45069670270.1'])->assertConflict();
        $this->assertSame('FAILED', $backup->refresh()->status);
        $this->assertNull($backup->active_slot);
    }

    private function assertDispatchedClaim(bool $duringDispatch, array $metadataChanges = [], int $expectedStatus = 200, int $dispatchStatus = 204, bool $verifyMetadata = true): Backup
    {
        config(['backups.github.repository' => 'example/backend', 'backups.github.token' => 'fixture-token',
            'backups.github.ref' => 'backup-execution', 'backups.github.workflow' => 'database-backup.yml']);
        $this->app->instance(BackupExecutor::class, new GithubBackupExecutor);
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $role->forceFill(['is_custom' => false])->save();
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->actingAs($user, 'web');
        $baselineTransactionLevel = DB::transactionLevel();
        $observed = [];
        $callbackResponse = null;
        $runId = '45069670270.1';
        $runMetadata = [];
        Http::fake(function ($request) use (&$observed, &$callbackResponse, &$runMetadata, $runId, $duringDispatch, $metadataChanges, $dispatchStatus) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/dispatches')) {
                $id = $request->data()['inputs']['backup_id'];
                $backup = Backup::findOrFail($id);
                $observed = ['id' => $id, 'status' => $backup->status, 'slot' => $backup->active_slot,
                    'run' => $backup->external_run_id, 'transaction_level' => DB::transactionLevel(),
                    'ref' => $request->data()['ref'], 'url' => $request->url()];
                $runMetadata = array_replace([
                    'id' => 45069670270, 'run_attempt' => 1, 'workflow_id' => 426278918,
                    'display_title' => 'EIDA backup '.$id, 'head_branch' => $request->data()['ref'],
                    'path' => '.github/workflows/database-backup.yml', 'event' => 'workflow_dispatch',
                    'repository' => ['full_name' => 'example/backend'],
                    'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'status' => 'in_progress', 'conclusion' => null,
                ], $metadataChanges);
                if ($duringDispatch) {
                    $callbackResponse = $this->postPythonCallback($backup, ['external_run_id' => $runId]);
                }

                return Http::response('', $dispatchStatus);
            }
            if (str_contains($request->url(), '/actions/runs/')) {
                return Http::response($runMetadata);
            }

            return Http::response(['total_count' => 1, 'workflow_runs' => [$runMetadata]]);
        });
        $response = $this->postJson('/api/v1/admin/backups')->assertStatus(202);
        $this->assertSame('QUEUED', $observed['status']);
        $this->assertSame(1, $observed['slot']);
        $this->assertNull($observed['run']);
        $this->assertSame($baselineTransactionLevel, $observed['transaction_level']);
        $this->assertSame('backup-execution', $observed['ref']);
        $this->assertSame('https://api.github.com/repos/example/backend/actions/workflows/database-backup.yml/dispatches',
            $observed['url']);
        $this->assertSame($observed['id'], $response->json('data.id'));
        if (! $duringDispatch) {
            $this->assertSame('QUEUED', $response->json('data.state'));
            $callbackResponse = $this->postPythonCallback(Backup::findOrFail($observed['id']),
                ['external_run_id' => $runId]);
        }
        if ($verifyMetadata) {
            Http::assertSent(fn ($sent) => $sent->method() === 'GET'
                && str_contains($sent->url(), '/actions/runs/45069670270'));
            $remote = Http::recorded(fn ($sent) => $sent->method() === 'GET'
                && str_contains($sent->url(), '/actions/runs/45069670270'))->first();
            $this->assertSame($runMetadata, $remote[1]->json());
        } else {
            Http::assertNotSent(fn ($sent) => str_contains($sent->url(), '/actions/runs/'));
        }
        $callbackResponse->assertStatus($expectedStatus);
        $backup = Backup::findOrFail($observed['id']);
        if ($expectedStatus !== 200) {
            $callbackResponse->assertJsonPath('code', $expectedStatus === 503
                ? 'EXECUTOR_STATE_UNVERIFIED' : 'INVALID_BACKUP_TRANSITION');
            $this->assertSame('QUEUED', $backup->status);
            $this->assertSame(1, $backup->active_slot);
            $this->assertNull($backup->external_run_id);

            return $backup;
        }
        $callbackResponse->assertExactJson(['acknowledged' => true]);
        $this->assertSame('RUNNING', $backup->status);
        $this->assertSame($runId, $backup->external_run_id);
        $this->assertSame(1, $backup->active_slot);

        return $backup;
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

    public function test_first_failed_callback_also_requires_the_correct_execution_identity(): void
    {
        $backup = $this->queuedBackup();
        $executor = $this->app->make(BackupExecutor::class);
        $executor->shouldReceive('assertRunningExecution')->once()
            ->andThrow(new BackupFailure('EXECUTOR_IDENTITY_MISMATCH'));
        $this->postPythonCallback($backup, ['status' => 'FAILED',
            'evidence' => ['error_code' => 'EXECUTOR_FAILED']])->assertConflict();
        $this->assertSame('QUEUED', $backup->refresh()->status);
        $this->assertSame(1, $backup->active_slot);
    }

    public function test_verified_first_failed_callback_releases_slot_without_allowing_reactivation(): void
    {
        $backup = $this->queuedBackup();
        $executor = $this->app->make(BackupExecutor::class);
        $executor->shouldReceive('assertRunningExecution')->once()->andReturnNull();
        $this->postPythonCallback($backup, ['status' => 'FAILED',
            'evidence' => ['error_code' => 'EXECUTOR_FAILED']])->assertOk();
        $this->assertSame('FAILED', $backup->refresh()->status);
        $this->assertNull($backup->active_slot);
        $this->postPythonCallback($backup)->assertConflict();
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
