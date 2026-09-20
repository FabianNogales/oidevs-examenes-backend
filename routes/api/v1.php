<?php

use App\Enums\RoleName;
use App\Http\Controllers\Api\V1\Admin\TeacherController;
use App\Http\Controllers\Api\V1\Admin\StudentImportController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Enrollments\StudentEnrollmentController;
use App\Http\Controllers\Api\V1\Exams\ExamSchedulingController;
use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use App\Http\Controllers\Api\V1\Students\StudentProfileController;
use App\Http\Controllers\Api\V1\Students\StudentQrController;
use App\Http\Controllers\Api\V1\TeacherDashboard\TeacherDashboardController;
use Illuminate\Support\Facades\Route;

// Health Check
Route::get('health', HealthCheckController::class)->name('health');

// Autenticación genérica
Route::prefix('auth')->middleware(['auth:sanctum', 'session.current'])->group(function () {
    Route::post('/logout', [AuthController::class, 'revokeSessionToken']);
});

// HU02 Auth: devuelve usuario, roles y estado de primer acceso
Route::middleware(['auth:sanctum', 'session.current'])->group(function () {
    Route::get('me', CurrentUserController::class)->name('me');
});

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
        Route::post(
        '/students/import/preview',
        [StudentImportController::class, 'preview']
    );

    Route::post(
        '/students/import/confirm',
        [StudentImportController::class, 'confirm']
    );
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
        Route::get('/enrollments', [StudentEnrollmentController::class, 'index']);
        Route::post('/enrollments/manual', [StudentEnrollmentController::class, 'storeManual']);
        Route::post('/enrollments/bulk', [StudentEnrollmentController::class, 'storeBulk']);
        Route::post('/exams', [ExamSchedulingController::class, 'store']);
    });
