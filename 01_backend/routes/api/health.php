<?php

use App\Http\Controllers\Api\HealthCheckController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AMIAL-HEALTH-001 (v1.0-C) — Health check routes
|--------------------------------------------------------------------------
|
| تُسجَّل في bootstrap/app.php بـ:
|   then: function () {
|       Route::middleware('api')->group(base_path('routes/api/health.php'));
|   }
*/

Route::get('/health/liveness', [HealthCheckController::class, 'liveness'])
    ->name('health.liveness');

Route::get('/health/readiness', [HealthCheckController::class, 'readiness'])
    ->name('health.readiness');
