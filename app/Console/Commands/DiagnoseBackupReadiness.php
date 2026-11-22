<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupReadinessDiagnostic;
use Illuminate\Console\Command;

class DiagnoseBackupReadiness extends Command
{
    protected $signature = 'backups:diagnose';

    protected $description = 'Report backup readiness predicates without exposing configuration values or credentials';

    public function handle(BackupReadinessDiagnostic $diagnostic): int
    {
        $report = $diagnostic->report();
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        // A completed diagnosis is not a health check or permission to run a backup.
        return $report['summary_enabled'] === null ? self::FAILURE : self::SUCCESS;
    }
}
