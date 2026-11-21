<?php

namespace App\Services\Backups;

use RuntimeException;

class BackupFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct('El respaldo no pudo verificarse.');
    }
}
