<?php

use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthCheckController::class)->name('health');

// HU02 Auth: devuelve usuario, roles y estado de primer acceso de la sesion actual.
Route::middleware(['auth:sanctum', 'session.current'])->get('me', CurrentUserController::class)->name('me');
