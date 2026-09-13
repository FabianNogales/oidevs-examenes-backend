<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Admin\TeacherController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\TeacherDashboard\TeacherDashboardController;
use App\Http\Controllers\Api\V1\Enrollments\StudentEnrollmentController;
use App\Http\Controllers\Api\V1\Exams\ExamSchedulingController;

// Health Check
Route::get('health', HealthCheckController::class)->name('health');

// Autenticación genérica
Route::prefix('auth')->middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'revokeSessionToken']);
});

// HU02 Auth: Desbloqueo de la ruta /me quitando password.changed
Route::middleware(['auth:sanctum', 'session.current'])->group(function () {
    Route::get('me', CurrentUserController::class)->name('me');
});

// HU 01: Acceso Administrativo protegido estrictamente
Route::middleware(['auth:sanctum', 'session.current', 'password.changed', 'verify.admin'])
    ->prefix('admin')
    ->group(function () {
        
        Route::get('/test', function () {
            return response()->json([
                'success' => true,
                'message' => 'Acceso administrativo autorizado.',
            ]);
        });

        Route::get('teachers', [TeacherController::class, 'index'])->name('teachers.index');
        Route::post('teachers', [TeacherController::class, 'store'])->name('teachers.store');
        Route::get('teachers/{teacher}', [TeacherController::class, 'show'])->name('teachers.show');
        Route::put('teachers/{teacher}', [TeacherController::class, 'update'])->name('teachers.update');
        Route::patch('teachers/{teacher}/status', [TeacherController::class, 'updateStatus'])->name('teachers.status');
    });

// HU 06: Seguridad de rutas del panel docente
Route::prefix('teacher/dashboard')
    ->middleware(['auth:sanctum', 'throttle:60,1', 'teacher.role', 'block.mutations', 'audit.logger'])
    ->group(function () {
        Route::get('/subjects', [TeacherDashboardController::class, 'getAssignedSubjects']);
        Route::get('/upcoming-exams', [TeacherDashboardController::class, 'getUpcomingExams']);
    });

// HU 07 y HU 08: Bloquear a usuarios sin permisos (RBAC) para inscripciones y exámenes
Route::prefix('course-offerings/{courseOffering}')
    ->middleware(['auth:sanctum', 'teacher.role'])
    ->group(function () {
        Route::post('/enrollments/manual', [StudentEnrollmentController::class, 'storeManual']);
        Route::post('/enrollments/bulk', [StudentEnrollmentController::class, 'storeBulk']);
        Route::post('/exams', [ExamSchedulingController::class, 'store']);
    });