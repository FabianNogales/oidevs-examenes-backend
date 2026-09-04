<?php

use App\Http\Controllers\Api\V1\Health\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthCheckController::class)->name('health');
