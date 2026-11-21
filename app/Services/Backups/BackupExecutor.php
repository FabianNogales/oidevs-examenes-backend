<?php

namespace App\Services\Backups;

interface BackupExecutor
{
    public function ready(): bool;

    /** Return ACCEPTED, UNKNOWN (ambiguous transport), or REJECTED. Never retry dispatch. */
    public function dispatch(string $backupId): string;
}
