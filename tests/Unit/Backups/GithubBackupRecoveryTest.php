<?php

namespace Tests\Unit\Backups;

use App\Models\Backup;
use App\Services\Backups\BackupFailure;
use App\Services\Backups\GithubBackupExecutor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GithubBackupRecoveryTest extends TestCase
{
    private Backup $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['backups.executor' => 'github', 'backups.github.repository' => 'example/backend',
            'backups.github.token' => 'test-only-marker', 'backups.github.ref' => 'backup-execution']);
        $this->backup = new Backup(['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'requested_at' => now()->subHour(), 'status' => 'QUEUED', 'active_slot' => 1]);
    }

    public function test_orphaned_queued_backup_is_found_by_exact_uuid_title_and_rechecked(): void
    {
        Http::fakeSequence()->push($this->listing([$this->workflowRun()]))->push($this->workflowRun());
        $this->assertSame('123.1', (new GithubBackupExecutor)->completedExecution($this->backup));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/actions/workflows/database-backup.yml/runs?')
            && $request['branch'] === 'backup-execution' && $request['event'] === 'workflow_dispatch'
            && $request['per_page'] === 100 && str_starts_with($request['created'], '>='));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.github.com/repos/example/backend/actions/runs/123');
    }

    public static function activeStatuses(): array
    {
        return [['queued'], ['in_progress'], ['waiting'], ['pending'], ['requested']];
    }

    #[DataProvider('activeStatuses')]
    public function test_nonterminal_workflow_execution_blocks_recovery(string $status): void
    {
        Http::fakeSequence()->push($this->listing([$this->workflowRun(['status' => $status, 'conclusion' => null])]));
        $this->assertRejected('EXECUTOR_ACTIVE');
        Http::assertSentCount(1);
    }

    public function test_active_duplicate_or_other_backup_workflow_also_blocks_recovery(): void
    {
        Http::fakeSequence()->push($this->listing([$this->workflowRun(), $this->workflowRun([
            'id' => 124, 'display_title' => 'EIDA backup another-uuid', 'status' => 'in_progress', 'conclusion' => null,
        ])]));
        $this->assertRejected('EXECUTOR_ACTIVE');
    }

    public function test_fresh_run_detail_detects_a_rerun_started_after_listing(): void
    {
        Http::fakeSequence()->push($this->listing([$this->workflowRun()]))
            ->push($this->workflowRun(['run_attempt' => 2, 'status' => 'in_progress', 'conclusion' => null]));
        $this->assertRejected('EXECUTOR_ACTIVE');
    }

    public function test_a_bound_execution_cannot_be_replaced_by_a_different_attempt(): void
    {
        $this->backup->external_run_id = '123.1';
        Http::fakeSequence()->push($this->listing([$this->workflowRun(['run_attempt' => 2])]))
            ->push($this->workflowRun(['run_attempt' => 2]));
        $this->assertRejected('EXECUTOR_IDENTITY_MISMATCH');
    }

    public static function incorrectIdentity(): array
    {
        return [
            ['display_title', 'EIDA backup another-uuid'],
            ['head_branch', 'main'],
            ['event', 'push'],
            ['path', '.github/workflows/another.yml'],
            ['repository', ['full_name' => 'another/repository']],
            ['created_at', '2000-01-01T00:00:00Z'],
            ['workflow_id', 999],
            ['id', 999],
        ];
    }

    #[DataProvider('incorrectIdentity')]
    public function test_completed_run_must_have_the_expected_identity(string $field, mixed $value): void
    {
        Http::fakeSequence()->push($this->listing([$this->workflowRun()]))->push($this->workflowRun([$field => $value]));
        $this->assertRejected('EXECUTOR_STATE_UNVERIFIED');
    }

    public static function unavailableResponses(): array
    {
        return [[401], [403], [404], [429], [500]];
    }

    #[DataProvider('unavailableResponses')]
    public function test_api_failure_does_not_prove_execution_is_finished(int $status): void
    {
        Http::fakeSequence()->push(['message' => 'sensitive-provider-marker'], $status);
        $this->assertRejected('EXECUTOR_STATE_UNVERIFIED');
    }

    public function test_connection_failure_is_safe_and_not_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('sensitive-network-marker'));
        $this->assertRejected('EXECUTOR_STATE_UNVERIFIED');
    }

    public function test_missing_run_is_not_assumed_to_have_finished(): void
    {
        Http::fakeSequence()->push($this->listing([]));
        $this->assertRejected('EXECUTOR_STATE_UNVERIFIED');
    }

    public function test_incomplete_history_does_not_hide_another_active_run(): void
    {
        Http::fakeSequence()->push(['total_count' => 101, 'workflow_runs' => [$this->workflowRun()]]);
        $this->assertRejected('EXECUTOR_STATE_UNVERIFIED');
    }

    public function test_duplicate_matching_runs_are_rejected_as_ambiguous(): void
    {
        Http::fakeSequence()->push($this->listing([$this->workflowRun(), $this->workflowRun(['id' => 124])]));
        $this->assertRejected('EXECUTOR_STATE_UNVERIFIED');
    }

    public function test_completed_status_without_a_conclusion_is_not_confirmation(): void
    {
        Http::fakeSequence()->push($this->listing([$this->workflowRun(['conclusion' => null])]));
        $this->assertRejected('EXECUTOR_STATE_UNVERIFIED');
    }

    private function workflowRun(array $overrides = []): array
    {
        return array_replace([
            'id' => 123, 'run_attempt' => 1, 'workflow_id' => 456,
            'display_title' => 'EIDA backup '.$this->backup->id,
            'head_branch' => 'backup-execution', 'event' => 'workflow_dispatch',
            'path' => '.github/workflows/database-backup.yml@backup-execution',
            'repository' => ['full_name' => 'example/backend'],
            'created_at' => now()->subMinutes(30)->utc()->format('Y-m-d\TH:i:s\Z'),
            'status' => 'completed', 'conclusion' => 'failure',
        ], $overrides);
    }

    private function listing(array $runs): array
    {
        return ['total_count' => count($runs), 'workflow_runs' => $runs];
    }

    private function assertRejected(string $code): void
    {
        try {
            (new GithubBackupExecutor)->completedExecution($this->backup);
            $this->fail('Recovery must reject unconfirmed execution state.');
        } catch (BackupFailure $failure) {
            $this->assertSame($code, $failure->safeCode);
            $this->assertStringNotContainsString('sensitive', $failure->getMessage());
        }
    }
}
