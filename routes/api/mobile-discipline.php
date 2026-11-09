<?php

use App\Http\Controllers\Api\V1\Incidents\MobileIncidentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'session.current', 'password.changed', 'throttle:60,1'])
    ->where(['exam_id' => '[1-9][0-9]*'])->group(function () {
        Route::post('exams/{exam_id}/offline/incidents', [MobileIncidentController::class, 'sync']);
        Route::get('exams/{exam_id}/discipline-context', [MobileIncidentController::class, 'context']);
    });
