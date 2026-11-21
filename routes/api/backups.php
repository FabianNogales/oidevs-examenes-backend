<?php

use App\Http\Controllers\Api\V1\Admin\BackupController;
use App\Http\Controllers\Api\V1\BackupCallbackController;
use App\Http\Middleware\AuthenticateBackupExecutor;
use App\Http\Middleware\EnsureActiveAdministrator;
use App\Http\Middleware\EnsureBackupAdministrator;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::prefix('admin/backups')->middleware(['auth:sanctum', 'session.current', 'password.changed',
    EnsureActiveAdministrator::class, EnsureBackupAdministrator::class, 'throttle:60,1,backups-read:'])->group(function () {
        Route::get('/', [BackupController::class, 'index']);
        Route::post('/', [BackupController::class, 'store'])->middleware('throttle:3,60,backups-create:');
        Route::get('/{backup}', [BackupController::class, 'show'])->whereUuid('backup');
        Route::post('/{backup}/download-ticket', [BackupController::class, 'download'])->whereUuid('backup')->middleware('throttle:20,1,backups-download:');
    });

Route::post('backup-executor/callback', BackupCallbackController::class)
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware(['throttle:60,1,backups-callback:', AuthenticateBackupExecutor::class]);
