<?php

use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Students\StudentQrController;

Route::post('/students/{student}/qr', [StudentQrController::class, 'generate']);
Route::get('health', HealthCheckController::class)->name('health');
