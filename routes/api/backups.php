<?php

use App\Http\Controllers\Api\V1\Admin\BackupController;
use App\Http\Controllers\Api\V1\Admin\BackupReadinessController;
use App\Http\Controllers\Api\V1\BackupCallbackController;
use App\Http\Middleware\AuthenticateBackupExecutor;
use App\Http\Middleware\EnsureActiveAdministrator;
use App\Http\Middleware\EnsureBackupAdministrator;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Temporary public, configuration-only diagnostic. Remove after the investigation.
Route::get('backups/readiness', BackupReadinessController::class)
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware('throttle:10,1,backups-readiness:');

Route::prefix('admin/backups')->middleware(['auth:sanctum', 'session.current', 'password.changed',
    EnsureActiveAdministrator::class, EnsureBackupAdministrator::class, 'throttle:60,1,backups-read:'])->group(function () {
        Route::get('/', [BackupController::class, 'index']);
        Route::get('/diagnostics/readiness', BackupReadinessController::class);
        Route::post('/', [BackupController::class, 'store'])->middleware('throttle:3,60,backups-create:');
        Route::get('/{backup}', [BackupController::class, 'show'])->whereUuid('backup');
        Route::post('/{backup}/download-ticket', [BackupController::class, 'download'])->whereUuid('backup')->middleware('throttle:20,1,backups-download:');
        Route::post('/{backup}/recover', [BackupController::class, 'recover'])->whereUuid('backup')->middleware('throttle:6,1,backups-recover:');
    });

Route::post('backup-executor/callback', BackupCallbackController::class)
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware(['throttle:60,1,backups-callback:', AuthenticateBackupExecutor::class]);
