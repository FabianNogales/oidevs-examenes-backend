<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\Http;
use Throwable;

class GithubBackupExecutor implements BackupExecutor
{
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
            // The remote side may already have accepted it. Keep QUEUED until callback/deadline.
            // Never log an HTTP exception: it may contain authentication or response bodies.
            return 'UNKNOWN';
        }
    }
}
