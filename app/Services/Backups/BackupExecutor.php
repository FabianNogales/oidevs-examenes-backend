<?php

namespace App\Services\Backups;

use App\Models\Backup;

interface BackupExecutor
{
    public function ready(): bool;

    /** Return ACCEPTED, UNKNOWN (ambiguous transport), or REJECTED. Never retry dispatch. */
    public function dispatch(string $backupId): string;

    /** Verify the latest remote attempt is completed; otherwise throw BackupFailure. */
    public function completedExecution(Backup $backup): string;
}
