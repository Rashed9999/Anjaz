<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-419-LOOP-001 — **صفحةٌ تحمل رمزاً لا تُخزَّن.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل المقيس — من شاشة صاحب المشروع:**
 *
 * فتح `amialpay.com/admin/...` على هاتفه فخرجت «انتهت صلاحية هذه الصفحة»
 * (٤١٩)، وتكرّرت مع كلّ ضغطةٍ على «إعادة المحاولة».
 *
 * وصفحةُ ٤١٩ نفسُها كتبت الجواب ولم يُطبَّق على من يُصدر الرمز:
 *
 *   «`no-cache` ليست `no-store`: الأولى تعني «تحقّق قبل الاستعمال»،
 *    والمتصفّح **يحتفظ بالنسخة ويستعملها حين يتعذّر التحقّق**.»
 *
 * فقِيست ترويسةُ `/admin/auth/login` فإذا هي `no-cache, private`. وسرعةُ
 * الاتّصال في اللقطة **٤٫١ ك.ب/ث**: التحقّقُ يتعذّر، فيُقدَّم النسخُ
 * المخزَّن — ومعه `_token` من جلسةٍ ماتت. فيُرفَض الإرسالُ بـ٤١٩.
 *
 * **والحلقةُ كانت تُغلَق بالزرّ:** «إعادة المحاولة» كان `location.reload()`
 * — وإعادةُ تحميل ردِّ ٤١٩ تُعيد إرسال الطلب نفسِه برمزه الفاسد. فلا
 * مخرجَ إلّا بمسح بيانات المتصفّح، وهو ما لا يعرفه المستعمل.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحدُّ ليس مسارَ الدخول وحده.** كلُّ صفحةٍ تحمل رمزَ CSRF تُصبح فاسدةً
 * إن خُزِّنت — فيُقاس المحتوى لا العنوان: إن كان في الردّ `csrf-token` أو
 * `_token` فهو لا يُخزَّن. وهذا يشمل ما يُكتب غداً بلا تذكُّر.
 *
 * ولا يُطبَّق على غير HTML ولا على الأصول: صورةٌ أو CSS لا رمزَ فيها،
 * ومنعُ تخزينها يُبطئ كلَّ صفحةٍ على اتّصالٍ ضعيف — وهو عكسُ المقصود.
 */
class NoStoreCsrfPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->carriesToken($response)) {
            return $response;
        }

        $response->headers->set('Cache-Control',
            'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    /** أفي الردّ رمزُ CSRF؟ يُقاس المحتوى لا العنوان. */
    private function carriesToken(Response $response): bool
    {
        $type = (string) $response->headers->get('Content-Type');

        if (! str_contains($type, 'text/html')) {
            return false;
        }

        // ردودُ التدفّق والتنزيل لا يُقرأ محتواها هنا.
        if (! method_exists($response, 'getContent')) {
            return false;
        }

        $body = (string) $response->getContent();

        return str_contains($body, 'name="csrf-token"')
            || str_contains($body, 'name="_token"');
    }
}
