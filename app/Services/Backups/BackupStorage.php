<?php

namespace App\Services\Backups;

use App\Models\Backup;

interface BackupStorage
{
    public function ready(): bool;

    /** Verify private metadata and the bounded manifest; never proxy the dump. */
    public function verify(Backup $backup, array $evidence): void;
}
