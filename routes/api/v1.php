<?php

use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use App\Http\Controllers\Api\V1\Students\StudentQrController;
use App\Http\Controllers\Api\V1\Students\StudentProfileController;
use Illuminate\Support\Facades\Route;

// Verificación de estado de la API
Route::get('health', HealthCheckController::class)->name('health');

Route::middleware('auth:sanctum')->group(function () {
    // Rutas de Perfil del Estudiante
    Route::get('/students/profile', [StudentProfileController::class, 'show']);
    Route::post('/students/profile/photo', [StudentProfileController::class, 'updatePhoto']);

    // Rutas de Exámenes y QR del Estudiante (HU-11)
    Route::get('/students/exams', [StudentQrController::class, 'index']);
    Route::get('/students/exams/{exam_id}/qr', [StudentQrController::class, 'show']);
});