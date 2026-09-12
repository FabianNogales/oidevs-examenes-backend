<?php

use App\Enums\RoleName;
use App\Http\Controllers\Api\V1\Admin\TeacherController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthCheckController::class)->name('health');

Route::middleware(['auth:sanctum', 'session.current'])->get('me', CurrentUserController::class)->name('me');
Route::middleware([
    'auth:sanctum',
    'session.current',
    'password.changed',
    'role:'.RoleName::ADMINISTRADOR->value,
])->prefix('admin')->group(function () {

    Route::get('/test', function () {
        return response()->json([
            'success' => true,
            'message' => 'Acceso administrativo autorizado.',
        ]);
    });

    Route::get('teachers', [TeacherController::class, 'index'])
        ->name('teachers.index');
    Route::post('teachers', [TeacherController::class, 'store'])
        ->name('teachers.store');
    Route::get('teachers/{teacher}', [TeacherController::class, 'show'])
        ->name('teachers.show');
    Route::put('teachers/{teacher}', [TeacherController::class, 'update'])
        ->name('teachers.update');
    Route::patch('teachers/{teacher}/status', [TeacherController::class, 'updateStatus'])
        ->name('teachers.status');
});
