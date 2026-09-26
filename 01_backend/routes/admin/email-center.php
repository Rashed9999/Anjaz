<?php

use App\Http\Controllers\Admin\EmailVerificationCenterController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-EMAIL-ADMIN-001
 *
 * مستقل عن routes/admin/amial.php عمداً: مركز البريد له صلاحيتان دقيقتان
 * بدل إعادة استعمال settings.update. AppServiceProvider يحمّل هذا الملف
 * داخل route cache أيضاً عبر loadRoutesFrom().
 */
Route::middleware(['web', 'admin', 'amial.force-pin-change'])
    ->prefix('admin/amial/email-center')
    ->name('admin.amial.email-center.')
    ->group(function (): void {
        Route::get('/', [EmailVerificationCenterController::class, 'index'])
            ->middleware('platform:platform.email.view')
            ->name('index');

        Route::get('/export', [EmailVerificationCenterController::class, 'export'])
            ->middleware('platform:platform.email.view')
            ->name('export');

        Route::post('/challenges/{challengeId}/revoke', [EmailVerificationCenterController::class, 'revoke'])
            ->where('challengeId', '[0-9A-HJKMNP-TV-Z]{26}')
            ->middleware('platform:platform.email.manage')
            ->name('challenges.revoke');
    });
