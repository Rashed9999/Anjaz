<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-HTTPS-001 — وضعيّةُ التشفير: تتبع الواقع ولا تُضبط بيد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل الذي يمنعه هذا الملفّ وقع في هذا المشروع بصورةٍ أخرى.**
 *
 * `SESSION_SECURE_COOKIE` مفتاحٌ يدويّ، وله وضعان خاطئان لا واحد:
 *
 *   • يُضبط `true` **قبل** أن يعمل HTTPS ⇒ المتصفّح لا يرسل الكوكي على
 *     HTTP إطلاقاً ⇒ كلّ إرسالٍ يردّ ٤١٩ ⇒ **يُقفل النظام على الجميع**.
 *     وهو نفس ما وقع بساعة الخادم: صفحةٌ تُفتح ٢٠٠ ثمّ ٤١٩ بيضاء.
 *
 *   • يبقى `false` **بعد** أن يعمل HTTPS ⇒ الكوكي تُرسَل على HTTP أيضاً
 *     ⇒ من يعترض الشبكة يسرق جلسة صرّافٍ من طلبٍ واحدٍ غير مشفَّر.
 *     وذلك يمرّ صامتاً: كلّ شيء يعمل، والحماية غائبة.
 *
 * فيُنزع المفتاح من اليد: **الكوكي تصير آمنةً إن كان الطلب آمناً، وإلّا
 * فلا**. فلا خطوةَ تُنسى ولا خطوةَ تُقفل الباب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا يصحّ `isSecure()` هنا؟** لأنّ `TrustProxies` مضبوطٌ قبله في
 * السلسلة ويقرأ `X-Forwarded-Proto`. فالتطبيق خلف الوسيط يرى المخطّط
 * الذي رآه المتصفّح لا الذي وصل إلى PHP.
 */
class HttpsPosture
{
    public function handle(Request $request, Closure $next): Response
    {
        $secure = $request->isSecure();

        // تُكتب قبل بناء أيّ كوكي في هذا الطلب: `StartSession` يقرأ
        // الإعداد عند إنشاء الكوكي، لا عند إقلاع التطبيق.
        config([
            'session.secure' => $secure,
            // `SameSite=None` تتطلّب `Secure` — فلا تُفرض على HTTP.
            'session.same_site' => 'lax',
        ]);

        $response = $next($request);

        if ($secure) {
            // **HSTS لا يُرسَل إلّا على اتّصالٍ مشفَّر.**
            //
            // وإرسالُها على HTTP ليس بلا أثر: المتصفّح قد يُثبّتها لسنة،
            // فإن انقطع HTTPS يوماً صار الموقع غير قابلٍ للفتح إطلاقاً —
            // ولا سبيل للتراجع من جهة الخادم.
            //
            // وبلا `preload` عمداً: القائمة المُسبقة تُدخل النطاق في
            // متصفّحاتٍ حول العالم، والخروجُ منها يستغرق شهوراً.
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
                false,
            );
        }

        return $response;
    }

    /**
     * وصفُ وضعيّة التشفير — تُعرَض في الإعدادات.
     *
     * **ولا تُقاس بمتغيّر بيئةٍ بل بالطلب نفسه.** فمن يضبط `APP_URL`
     * إلى `https://…` ويقرأ منه يستنتج أنّ التشفير يعمل، ولو كان الخادم
     * يردّ على HTTP وحده.
     *
     * @return array<string, mixed>
     */
    public static function describe(Request $request): array
    {
        $secure = $request->isSecure();

        return [
            'secure' => $secure,
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
            // شهادةُ Let's Encrypt لا تُصدَر لعنوان IP — تحتاج اسم نطاق.
            // وهذه أوّل عقبةٍ عمليّة، ولا يذكرها أحدٌ حتى تُجرَّب وتفشل.
            'host_is_ip' => (bool) filter_var($request->getHost(), FILTER_VALIDATE_IP),
            'cookie_secure' => (bool) config('session.secure'),
            'headline' => $secure
                ? '🔒 الاتّصال مشفَّر — كلمات السرّ محميّة'
                : '🔓 الاتّصال غير مشفَّر — كلمات سرّ موظّفيك تمرّ نصّاً صريحاً',
        ];
    }
}
