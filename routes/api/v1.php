<?php

use App\Enums\RoleName;
use App\Http\Controllers\Api\V1\Admin\TeacherController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use App\Http\Controllers\Api\V1\Students\StudentProfileController;
use App\Http\Controllers\Api\V1\Students\StudentQrController;
use Illuminate\Support\Facades\Route;

// Verificación de estado de la API
Route::get('health', HealthCheckController::class)->name('health');

// HU02 Auth: devuelve usuario, roles y estado de primer acceso de la sesion actual.
Route::middleware(['auth:sanctum', 'session.current'])->get('me', CurrentUserController::class)->name('me');

// Rutas de Estudiantes (HU-10 Perfil y HU-11 Exámenes/QR)
Route::middleware([
    'auth:sanctum',
    'session.current',
    'password.changed',
    'role:'.RoleName::ESTUDIANTE->value,
])->group(function () {
    // Rutas de Perfil del Estudiante (HU-10)
    Route::get('/students/profile', [StudentProfileController::class, 'show']);
    Route::post('/students/profile/photo', [StudentProfileController::class, 'updatePhoto']);

    // Rutas de Exámenes y QR del Estudiante (HU-11)
    Route::get('/students/exams', [StudentQrController::class, 'index']);
    Route::get('/students/exams/{exam_id}/qr', [StudentQrController::class, 'show']);
});

// Rutas de Administración
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