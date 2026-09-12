<?php
use App\Enums\RoleName;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthCheckController::class)->name('health');

Route::middleware(['auth:sanctum', 'session.current'])->get('me', CurrentUserController::class)->name('me');
Route::middleware([
    'auth:sanctum',
    'session.current',
    'password.changed',
    'role:' . RoleName::ADMINISTRADOR->value,
])->prefix('admin')->group(function () {

    Route::get('/test', function () {
        return response()->json([
            'success' => true,
            'message' => 'Acceso administrativo autorizado.',
        ]);
    });
});