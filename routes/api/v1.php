<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\TeacherDashboard\TeacherDashboardController;

Route::get('health', HealthCheckController::class)->name('health');

Route::prefix('auth')->middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'revokeSessionToken']);
});

//Limitación de Peticiones (Rate Limiting)
Route::prefix('teacher/dashboard')
    ->middleware(['auth:sanctum', 'throttle:60,1'])
    ->group(function () {
        Route::get('/subjects', [TeacherDashboardController::class, 'getAssignedSubjects']);
        Route::get('/upcoming-exams', [TeacherDashboardController::class, 'getUpcomingExams']);
    });

//seguridad de rutas del panel
Route::prefix('teacher/dashboard')
    ->middleware(['auth:sanctum', 'throttle:60,1', 'teacher.role', 'block.mutations', 'audit.logger'])
    ->group(function () {
        Route::get('/subjects', [TeacherDashboardController::class, 'getAssignedSubjects']);
        Route::get('/upcoming-exams', [TeacherDashboardController::class, 'getUpcomingExams']);
    });