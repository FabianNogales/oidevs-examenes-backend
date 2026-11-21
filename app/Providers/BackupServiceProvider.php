<?php

namespace App\Providers;

use App\Services\Backups\BackupExecutor;
use App\Services\Backups\BackupStorage;
use App\Services\Backups\DriveBackupStorage;
use App\Services\Backups\GithubBackupExecutor;
use Illuminate\Support\ServiceProvider;

class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BackupExecutor::class, GithubBackupExecutor::class);
        $this->app->bind(BackupStorage::class, DriveBackupStorage::class);
    }
}
