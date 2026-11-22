<?php

namespace App\Services\Backups;

use App\Models\Backup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

class GithubBackupExecutor implements BackupExecutor
{
    private const ACTIVE_STATUSES = ['queued', 'in_progress', 'waiting', 'pending', 'requested'];

    private const CONCLUSIONS = ['success', 'failure', 'cancelled', 'timed_out', 'action_required',
        'neutral', 'skipped', 'stale', 'startup_failure'];

    public function ready(): bool
    {
        $config = config('backups.github');

        return config('backups.executor') === 'github'
            && preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', $config['repository'])
            && $config['token'] !== '' && $config['ref'] === 'backup-execution';
    }

    public function dispatch(string $backupId): string
    {
        $config = config('backups.github');
        try {
            $response = Http::withToken($config['token'])->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->post('https://api.github.com/repos/'.$config['repository'].'/actions/workflows/'
                    .rawurlencode($config['workflow']).'/dispatches', [
                        'ref' => $config['ref'], 'inputs' => ['backup_id' => $backupId],
                    ]);

            if (in_array($response->status(), [200, 202, 204], true)) {
                return 'ACCEPTED';
            }

            return $response->clientError() ? 'REJECTED' : 'UNKNOWN';
        } catch (Throwable) {
            // Acceptance is ambiguous. Keep the slot until callback or verified reconciliation.
            // Never log an HTTP exception: it may contain authentication or response bodies.
            return 'UNKNOWN';
        }
    }

    public function assertRunningExecution(Backup $backup, string $externalId): void
    {
        if (! preg_match('/\A[0-9]{1,30}\.[0-9]{1,10}\z/', $externalId)) {
            throw new BackupFailure('EXECUTOR_IDENTITY_MISMATCH');
        }
        try {
            if (! $this->ready()) {
                throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
            }
            $config = config('backups.github');
            $runId = explode('.', $externalId)[0];
            $response = Http::withToken($config['token'])->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'Cache-Control' => 'no-cache'])
                ->connectTimeout(2)->timeout(5)->withoutRedirecting()
                ->get('https://api.github.com/repos/'.$config['repository'].'/actions/runs/'.$runId);
            $run = $response->json();
            if (! $response->successful() || ! is_array($run)
                || ! isset($run['id'], $run['status'], $run['run_attempt'], $run['workflow_id'],
                    $run['display_title'], $run['head_branch'], $run['path'], $run['event'],
                    $run['repository']['full_name'], $run['created_at'])) {
                throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
            }
            try {
                $confirmed = $this->validatedExecutionId($backup, $run);
            } catch (BackupFailure) {
                throw new BackupFailure('EXECUTOR_IDENTITY_MISMATCH');
            }
            if ($confirmed !== $externalId || $run['status'] === 'completed') {
                throw new BackupFailure('EXECUTOR_IDENTITY_MISMATCH');
            }
            if ($run['status'] !== 'in_progress' || ($run['conclusion'] ?? null) !== null) {
                throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
            }
        } catch (BackupFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
        }
    }

    public function completedExecution(Backup $backup): string
    {
        try {
            $config = config('backups.github');
            if (! $this->ready() || ! preg_match('/\A[A-Za-z0-9_.-]+\.ya?ml\z/', $config['workflow'])) {
                throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
            }
            $base = 'https://api.github.com/repos/'.$config['repository'];
            $http = Http::withToken($config['token'])->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'Cache-Control' => 'no-cache'])
                ->connectTimeout(2)->timeout(5)->withoutRedirecting();
            $response = $http->get($base.'/actions/workflows/'.rawurlencode($config['workflow']).'/runs', [
                'branch' => $config['ref'], 'event' => 'workflow_dispatch', 'per_page' => 100,
                'created' => '>='.$backup->requested_at->subMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
            $data = $response->json();
            // One bounded page only. Missing/truncated history is not proof of completion.
            if (! $response->successful() || ! is_array($data)
                || ! is_int($data['total_count'] ?? null) || ! is_array($data['workflow_runs'] ?? null)
                || $data['total_count'] !== count($data['workflow_runs'])
                || str_contains($response->header('Link') ?? '', 'rel="next"')) {
                throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
            }
            $matches = [];
            foreach ($data['workflow_runs'] as $run) {
                if (! is_array($run)) {
                    throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
                }
                $this->requireCompleted($run);
                if (($run['display_title'] ?? null) === 'EIDA backup '.$backup->id) {
                    $matches[] = $run;
                }
            }
            if (count($matches) !== 1) {
                throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
            }
            $listed = $matches[0];
            $this->validatedExecutionId($backup, $listed);
            // Query the run again: the list may precede a queued/in-progress rerun.
            $response = $http->get($base.'/actions/runs/'.$listed['id']);
            $current = $response->json();
            if (! $response->successful() || ! is_array($current)) {
                throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
            }
            $this->requireCompleted($current);
            $externalId = $this->validatedExecutionId($backup, $current);
            if ((string) $current['id'] !== (string) $listed['id']
                || (string) $current['workflow_id'] !== (string) $listed['workflow_id']) {
                throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
            }
            if ($backup->external_run_id !== null && $backup->external_run_id !== $externalId) {
                throw new BackupFailure('EXECUTOR_IDENTITY_MISMATCH');
            }

            return $externalId;
        } catch (BackupFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            // Never expose provider bodies, credentials or exception messages.
            throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
        }
    }

    private function requireCompleted(array $run): void
    {
        if (in_array($run['status'] ?? null, self::ACTIVE_STATUSES, true)) {
            throw new BackupFailure('EXECUTOR_ACTIVE');
        }
        if (($run['status'] ?? null) !== 'completed'
            || ! in_array($run['conclusion'] ?? null, self::CONCLUSIONS, true)) {
            throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
        }
    }

    private function validatedExecutionId(Backup $backup, array $run): string
    {
        $config = config('backups.github');
        if (! preg_match('/\A[0-9]{1,30}\z/', (string) ($run['id'] ?? ''))
            || ! preg_match('/\A[0-9]{1,10}\z/', (string) ($run['run_attempt'] ?? ''))
            || (int) $run['run_attempt'] < 1 || ! is_int($run['workflow_id'] ?? null)
            || ($run['display_title'] ?? null) !== 'EIDA backup '.$backup->id
            || ($run['head_branch'] ?? null) !== $config['ref']
            || ($run['event'] ?? null) !== 'workflow_dispatch'
            || explode('@', $run['path'] ?? '')[0] !== '.github/workflows/'.$config['workflow']
            || strcasecmp($run['repository']['full_name'] ?? '', $config['repository']) !== 0
            || ! is_string($run['created_at'] ?? null)
            || CarbonImmutable::parse($run['created_at'])->lessThan($backup->requested_at->subMinutes(5))) {
            throw new BackupFailure('EXECUTOR_STATE_UNVERIFIED');
        }

        return $run['id'].'.'.$run['run_attempt'];
    }
}
