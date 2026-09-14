<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * MERGED (6cash base + Amial Pay):
 *   - راوتات 6cash الأساسية (api/v1, admin, merchant, install, web).
 *   - راوتات أميال تُسجَّل هنا دون فتح أبواب متوازية لنفس القرار.
 */
class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    protected $namespace = 'App\\Http\\Controllers';

    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api/v1')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api/v1/api.php'));

            Route::prefix('admin')
                ->middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/admin.php'));

            // AMIAL-KYC-FORENSIC-002 — مركز التتبّع له ملف routes مستقل
            // لكنه يعيش تحت نفس بوابة موظفي المنصّة وبنفس حارس تغيير PIN.
            Route::prefix('admin/amial')
                ->middleware(['web', 'admin', 'amial.force-pin-change'])
                ->name('admin.amial.')
                ->group(base_path('routes/admin/kyc-privacy.php'));

            // AMIAL-KYC-BIOMETRIC-WEBHOOK-001 — المزود الخارجي لا يملك
            // auth:api. لا نضع عليه limiter العملاء العام (90/IP) لأن callback
            // قد يصل بدفعات؛ له throttle مستقل في ملفه، والثقة الفعلية من
            // التوقيع التشفيري الذي يتحقق منه Driver قبل قراءة الحدث.
            Route::prefix('api/v1/amial')
                ->group(base_path('routes/api/kyc-biometric-webhook.php'));

            // AMIAL-KYC-PRIVACY-API-001 — اختيار صاحب الحساب لمسار الخصوصية.
            // نفس حراس سطح amial المصادق: الرمز يعود لصاحب الحساب نفسه،
            // ولا يستطيع هذا الباب منح اعتماد أو كتابة نتيجة بيومترية.
            Route::prefix('api/v1/amial')
                ->middleware(['api', 'auth:api', 'trackLastActiveAt', 'amial.pos-device'])
                ->group(base_path('routes/api/kyc-privacy.php'));

            // AMIAL-AUDIT-ORPHAN-002: أُزيل تسجيل routes/merchant.php —
            // لوحة التاجر الويبيّة من قالب 6cash. قوالبها كلّها محذوفة، فكل
            // صفحاتها ترمي 500، ولا شيء خارجها يشير إليها (مراجعها الوحيدة
            // متحكّماتها نفسها). تاجر أميال يعمل من التطبيق عبر
            // /api/v1/amial/merchant/* — وهي حيّة ومختبَرة.

            // AMIAL-CLEANUP: أُزيل تسجيل routes/install.php (معالج تثبيت 6cash)

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * AMIAL-RATELIMIT-CGNAT-001 — حدٌّ بالـIP يقتل تجربةً في اليمن.
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            $user = optional($request->user())->id;

            if ($user) {
                return Limit::perMinute(120)->by('u:' . $user);
            }

            $device = preg_replace('/[^A-Za-z0-9_\-]/', '',
                (string) $request->header('device-id', ''));
            $device = $device === '' ? 'nodev' : substr($device, 0, 48);

            return Limit::perMinute(90)->by('ip:' . $request->ip() . '|d:' . $device);
        });
    }
}
