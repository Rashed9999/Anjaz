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

/*
| AMIAL-PROD-READINESS-001 — مسبارُ النشر.
|
| الاسمُ يبقى `/railway-health` **عمداً**: هو المضبوطُ في لوحة Coolify
| (‏`DEPLOY_COOLIFY.md`)، وتغييرُه يتطلّب خطوةً يدويّةً قد تُنسى — فيبقى
| النشرُ بلا فحصٍ حقيقيّ حتّى تُذكَر. **فيُصلَح المسارُ القائم بدل انتظار
| أن يُوجَّه إلى غيره.** وكان سطراً في nginx يردّ 200 قبل PHP.
*/
Route::get('/railway-health', [HealthCheckController::class, 'deployProbe'])
    ->name('health.deploy');
