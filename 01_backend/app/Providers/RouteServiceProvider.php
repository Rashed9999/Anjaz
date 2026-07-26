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
 *   - راوتات أميال تُسجَّل في bootstrap/app.php (then:) — لا تكرار هنا.
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

            // AMIAL-AUDIT-ORPHAN-002: أُزيل تسجيل routes/merchant.php —
            // لوحة التاجر الويبيّة من قالب 6cash. قوالبها كلّها محذوفة، فكل
            // صفحاتها ترمي 500، ولا شيء خارجها يشير إليها (مراجعها الوحيدة
            // متحكّماتها نفسها). تاجر أميال يعمل من التطبيق عبر
            // /api/v1/amial/merchant/* — وهي حيّة ومختبَرة.
            //
            // الملفّ يبقى في المستودع لا يُحمَّل: حذفه يُفقد سياق ما كان،
            // وتسجيله يُبقي سطح خطأ بلا وظيفة.

            // AMIAL-CLEANUP: أُزيل تسجيل routes/install.php (معالج تثبيت 6cash)

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}
