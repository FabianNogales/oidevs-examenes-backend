<?php

use App\Http\Controllers\Api\V1\Eligibility\AcademicRecordController;
use App\Http\Controllers\Api\V1\Eligibility\EligibilityAutomationController;
use App\Http\Controllers\Api\V1\Eligibility\EligibilityEvaluationController;
use App\Http\Controllers\Api\V1\Eligibility\EligibilityPolicyController;
use App\Http\Middleware\EnsureActiveAdministrator;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/eligibility-policies')->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureActiveAdministrator::class])->group(function () {
    Route::get('/', [EligibilityPolicyController::class, 'index']);
    Route::post('/', [EligibilityPolicyController::class, 'store']);
    Route::get('/{policy}', [EligibilityPolicyController::class, 'show'])->whereNumber('policy');
    Route::patch('/{policy}', [EligibilityPolicyController::class, 'update'])->whereNumber('policy');
    Route::post('/{policy}/activate', [EligibilityPolicyController::class, 'activate'])->whereNumber('policy');
    Route::post('/{policy}/versions', [EligibilityPolicyController::class, 'store'])->whereNumber('policy');
});

Route::middleware(['auth:sanctum', 'session.current', 'password.changed', 'teacher.role'])->group(function () {
    Route::prefix('exams/{exam}/eligibility-automation')->whereNumber('exam')->group(function () {
        Route::get('/options', [EligibilityAutomationController::class, 'options']);
        Route::get('/configuration', [EligibilityAutomationController::class, 'show']);
        Route::put('/configuration', [EligibilityAutomationController::class, 'update']);
        Route::post('/evaluations/preview', [EligibilityEvaluationController::class, 'preview']);
        Route::post('/evaluations/{preview}/confirm', [EligibilityEvaluationController::class, 'confirm'])->whereUuid('preview');
        Route::get('/evaluations', [EligibilityEvaluationController::class, 'index']);
        Route::get('/evaluations/{evaluation}', [EligibilityEvaluationController::class, 'show'])->whereNumber('evaluation');
    });
    Route::prefix('academic-offerings/{offering}/academic-records')->whereNumber('offering')->group(function () {
        Route::get('/import-schema', [AcademicRecordController::class, 'schema']);
        Route::post('/imports/preview', [AcademicRecordController::class, 'preview']);
        Route::post('/imports/{preview}/confirm', [AcademicRecordController::class, 'confirm'])->whereUuid('preview');
        Route::get('/history', [AcademicRecordController::class, 'history']);
        Route::get('/', [AcademicRecordController::class, 'index']);
        Route::patch('/{record}', [AcademicRecordController::class, 'update'])->whereNumber('record');
    });
});
