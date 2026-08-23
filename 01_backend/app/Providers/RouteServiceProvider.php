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

    /**
     * AMIAL-RATELIMIT-CGNAT-001 — **حدٌّ بالـIP يقتل تجربةً في اليمن.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ما كان، وقد قِيس بجولةٍ حقيقيّةٍ عبر HTTP لا بقراءة:**
     *
     *     360 طلباً · 12 متوازية  →  59 نجحت · **301 ردَّت 429**
     *     والسبب: `Limit::perMinute(60)->by(user ?: ip)`
     *
     * **والمصادَقُ سليم** — يُحدُّ بحسابه، وستّون طلباً في الدقيقة كافيةٌ
     * لمحفظة.
     *
     * **وغيرُ المصادق يُحدُّ بالـIP، وهذا هو القاتل:** مشغّلو الهاتف في
     * اليمن يستعملون CGNAT — آلافُ المشتركين خلف عناوينَ معدودة. فألفا
     * مستخدمٍ على ثلاثة عناوين ⇒ ~٦٦٠ لكلٍّ ⇒ **طلبٌ واحدٌ كلَّ إحدى
     * عشرةَ دقيقةً للمستعمل**.
     *
     * ويراه العميلُ «التطبيقُ لا يعمل»، **ولا خطأَ في أيّ سجلٍّ عندنا** —
     * الحدُّ يعمل كما كُتب.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولا يُرفع الحدُّ وحدَه — يُفرَّق المفتاح.**
     *
     * رفعُه إلى ٣٠٠ يترك ٦٦٠ مستعملاً يتقاسمونها، ويُضعف الحمايةَ في آن.
     * فالمفتاحُ يصير **العنوانَ ومعه الجهاز**: التطبيقُ يرسل `device-id`
     * (تقرؤه `CheckDeviceId` سلفاً)، فيُفرَّق ألفُ جهازٍ خلف عنوانٍ واحد.
     *
     * **والحمايةُ الحقيقيّةُ ليست هنا أصلاً.** الأبوابُ الحسّاسةُ لها
     * حدودُها المبنيّةُ لها: `amial.rate-limit:auth_login,10,1` على
     * الدخول، وقفلُ الحساب المتصاعد خلفه. **وهذا حدٌّ عامٌّ خلفيّ**،
     * وظيفتُه منعُ الإغراق لا حراسةُ باب.
     *
     * **ومن لا يرسل جهازاً يبقى على العنوان وحدَه** — فلا يُفتح الباب
     * بترويسةٍ يخترعها مهاجم: أسوأُ ما يبلغه أن يُعامَل كجهازٍ واحدٍ
     * إضافيّ، وحدُّه هو نفسُه.
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            $user = optional($request->user())->id;

            if ($user) {
                // مصادَقٌ: الحدُّ على حسابه — والحسابُ لا يتقاسمه أحد.
                return Limit::perMinute(120)->by('u:' . $user);
            }

            // **وغيرُ المصادق: العنوانُ والجهازُ معاً.** والجهازُ يُقصَّر
            // ويُنظَّف — ترويسةٌ طويلةٌ أو غريبةٌ لا تُوسّع المفتاح بلا حدّ.
            $device = preg_replace('/[^A-Za-z0-9_\-]/', '',
                (string) $request->header('device-id', ''));

            $device = $device === '' ? 'nodev' : substr($device, 0, 48);

            return Limit::perMinute(90)->by('ip:' . $request->ip() . '|d:' . $device);
        });
    }
}
