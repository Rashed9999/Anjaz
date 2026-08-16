<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-PROD-READINESS-005 — **خيطٌ واحدٌ يربط الحكايةَ كلَّها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس في تدقيق الجاهزيّة:**
 *
 *   $ curl -sD- .../api/v1/amial/ping | grep -i request
 *   (لا شيء)
 *
 *   $ grep -rn 'X-Request-Id' app/Http/Middleware/
 *   (لا وسيطَ يولّد معرّفاً ولا يمرّره)
 *
 * فكلُّ حلقةٍ مرصودةٌ وحدَها ولا خيطَ يربطها: تاجرٌ يقول «فشل التحويل
 * الساعةَ الثالثة»، ولا سبيلَ لربط قوله بسطرٍ في السجلّ إلّا بالوقت
 * والتخمين. والوقتُ يكذب: عشراتُ الطلبات في الثانية نفسِها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةُ مواضعَ يظهر فيها المعرّفُ نفسُه:**
 *
 *   ① ترويسةُ الردّ `X-Request-Id` — فيراه العميلُ ويرسله في الشكوى.
 *   ② سياقُ كلّ سطرٍ في السجلّ — عبر `Log::shareContext`.
 *   ③ عمودٌ في `system_errors` — فمركزُ الأعطال يعرضه ويُبحث به.
 *
 * **ويُقبَل الواردُ إن جاء** — فالتطبيقُ قد يرسله، وسلسلةُ الاستدعاء عبر
 * خدماتٍ متعدّدةٍ تبقى موصولة. **ويُنظَّف قبل القبول**: قيمةٌ من الخارج
 * تدخل السجلَّ والترويسة، فمحرفُ سطرٍ فيها يزرع سطراً كاذباً في السجلّ
 * (‏log injection). فيُقتصر على الحروف الآمنة وستّةٍ وثلاثين محرفاً.
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->sanitize((string) $request->header(self::HEADER, ''));

        if ($id === '') {
            $id = (string) Str::ulid();
        }

        // يُوضَع في الطلب — تقرؤه الخدماتُ والمهامُّ المرحَّلة.
        $request->attributes->set('request_id', $id);
        $request->headers->set(self::HEADER, $id);

        // **كلُّ سطرٍ يُكتب بعد هذا يحمله** — بلا تعديل موضعِ استدعاء.
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);

        // **ويُعاد إلى العميل** — فمن يشتكي يحمل رقمَ حكايته.
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /**
     * حروفٌ آمنةٌ فقط، وطولٌ محدود.
     *
     * القيمةُ تدخل ترويسةَ ردٍّ وسطرَ سجلّ. ومحرفُ سطرٍ أو `\r` فيها يزرع
     * سطراً كاذباً يُقرأ سجلّاً حقيقيّاً — وهو ما يُبحث فيه عند التحقيق.
     */
    private function sanitize(string $raw): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9\-_]/', '', $raw) ?? '', 0, 36);
    }
}
