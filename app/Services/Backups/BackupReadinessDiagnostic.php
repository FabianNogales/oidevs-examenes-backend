<?php

namespace App\Services\Backups;

use App\Providers\BackupServiceProvider;
use Illuminate\Support\Env;
use Throwable;

/** Configuration inspection only: never call provider APIs or reconcile backups. */
class BackupReadinessDiagnostic
{
    public function report(): array
    {
        // Keep these expressions identical to the production predicates.
        $predicates = [
            'module_enabled' => ['EIDA_BACKUPS_ENABLED', 'backups.enabled', false,
                fn () => (bool) config('backups.enabled')],
            'callback_secret_minimum' => ['EIDA_BACKUPS_CALLBACK_SECRET', 'backups.callback_secret', '',
                fn () => strlen(config('backups.callback_secret')) >= 32],
            'github_executor_selected' => ['EIDA_BACKUPS_EXECUTOR', 'backups.executor', 'github',
                fn () => config('backups.executor') === 'github'],
            'github_repository_format' => ['EIDA_BACKUPS_GITHUB_REPOSITORY', 'backups.github.repository', '',
                fn () => (bool) preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', config('backups.github.repository'))],
            'github_token_not_empty' => ['EIDA_BACKUPS_GITHUB_TOKEN', 'backups.github.token', '',
                fn () => config('backups.github.token') !== ''],
            'github_execution_ref' => ['EIDA_BACKUPS_GITHUB_REF', 'backups.github.ref', 'backup-execution',
                fn () => config('backups.github.ref') === 'backup-execution'],
            'drive_client_id_not_empty' => ['EIDA_BACKUPS_DRIVE_CLIENT_ID', 'backups.drive.client_id', '',
                fn () => config('backups.drive.client_id') !== ''],
            'drive_client_secret_not_empty' => ['EIDA_BACKUPS_DRIVE_CLIENT_SECRET', 'backups.drive.client_secret', '',
                fn () => config('backups.drive.client_secret') !== ''],
            'drive_refresh_token_not_empty' => ['EIDA_BACKUPS_DRIVE_REFRESH_TOKEN', 'backups.drive.refresh_token', '',
                fn () => config('backups.drive.refresh_token') !== ''],
            'drive_folder_id_format' => ['EIDA_BACKUPS_DRIVE_FOLDER_ID', 'backups.drive.folder_id', '',
                fn () => (bool) preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', config('backups.drive.folder_id'))],
        ];
        $checks = [];
        $failed = [];
        $errors = [];
        foreach ($predicates as $name => [$variable, $path, $default, $predicate]) {
            $passed = $this->evaluate($predicate);
            $matchesEnvironment = $this->evaluate(function () use ($variable, $path, $default) {
                $currentEnvironment = Env::get($variable, $default);
                if ($path === 'backups.enabled') {
                    $currentEnvironment = (bool) $currentEnvironment;
                }

                return config($path) === $currentEnvironment;
            });
            $checks[$name] = ['variable' => $variable, 'passed' => $passed,
                'configuration_matches_env' => $matchesEnvironment];
            if ($passed === false) {
                $failed[] = $name;
            } elseif ($passed === null) {
                $errors[] = $name;
            }
        }

        return [
            'diagnostic_version' => 1,
            'configuration_only' => true,
            'provider_connectivity_checked' => false,
            'summary_enabled' => $this->evaluate(fn () => app(BackupService::class)->enabled()),
            'failed_conditions' => $failed,
            'conditions_with_evaluation_error' => $errors,
            'checks' => $checks,
            'executor_ready' => $this->evaluate(fn () => app(BackupExecutor::class)->ready()),
            'storage_ready' => $this->evaluate(fn () => app(BackupStorage::class)->ready()),
            'expected_bindings' => [
                'github_executor' => $this->evaluate(fn () => app(BackupExecutor::class) instanceof GithubBackupExecutor),
                'drive_storage' => $this->evaluate(fn () => app(BackupStorage::class) instanceof DriveBackupStorage),
                'provider_loaded' => (bool) (app()->getLoadedProviders()[BackupServiceProvider::class] ?? false),
            ],
            'configuration_cached' => app()->configurationIsCached(),
            'application_environment_is_production' => app()->environment('production'),
            'vercel_environment_is_production' => Env::get('VERCEL_ENV') === 'production',
            'download_readiness_used_for_summary' => false,
        ];
    }

    /** Explicit HTTP allowlist: no environment names, values, lengths or exceptions. */
    public function administratorReport(): array
    {
        $report = $this->report();
        $indicators = [
            'module_enabled' => $report['checks']['module_enabled']['passed'] === true,
            'callback_secret_valid' => $report['checks']['callback_secret_minimum']['passed'] === true,
            'github_executor_ready' => $report['executor_ready'] === true,
            'drive_storage_ready' => $report['storage_ready'] === true,
            'overall_ready' => $report['summary_enabled'] === true,
        ];
        $failed = $report['failed_conditions'];
        foreach ($report['conditions_with_evaluation_error'] as $condition) {
            $failed[] = $condition.'_evaluation_error';
        }
        foreach (['github_executor_ready', 'drive_storage_ready', 'overall_ready'] as $condition) {
            if (! $indicators[$condition]) {
                $failed[] = $condition;
            }
        }

        return [...$indicators, 'failed_conditions' => array_values(array_unique($failed))];
    }

    private function evaluate(callable $predicate): ?bool
    {
        try {
            return (bool) $predicate();
        } catch (Throwable) {
            return null;
        }
    }
}
