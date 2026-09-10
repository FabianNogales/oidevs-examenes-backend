<?php

use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use App\Http\Controllers\Api\V1\Students\StudentQrController;
use Illuminate\Support\Facades\Route;

// Obtiene o genera automáticamente el QR firmado para la materia elegida
Route::get('/students/{student}/subjects/{subject}/qr', [StudentQrController::class, 'show']);

// Verificación de estado de la API
Route::get('health', HealthCheckController::class)->name('health');