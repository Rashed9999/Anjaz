<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-PORTAL-HOSTS-001 — من طرق البابَ الخطأ يُنقل إلى بابه.
 *
 * **ولماذا تحويلٌ لا منع؟**
 *
 * لأنّ الروابط القديمة لا تموت: مفضّلةُ متصفّح، ورسالةٌ في واتساب، وسطرٌ
 * في دليلٍ مطبوع. ومن يفتح `amialpay.com/admin` بعد الفصل يجب أن يصل
 * لوحتَه، لا أن يرى ٤٠٤ فيظنّ النظام سقط.
 *
 * **ولا يُحوَّل POST.** تحويلُ طلبِ إرسالٍ يُفقد جسمَه، فيصل الخادمَ نموذجٌ
 * فارغ: «الحقل مطلوب» على حقلٍ مُلئ فعلاً — وهو أسوأ من الرفض لأنّه يكذب
 * على من يقرؤه. فيُردّ ٤٢١ بمضيفه الصحيح صراحةً.
 */
class PortalHostRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = PortalHost::expectedFor($request->path());

        if ($expected === null || mb_strtolower($request->getHost()) === $expected) {
            return $next($request);
        }

        $target = $request->getScheme() . '://' . $expected . '/' . ltrim($request->getRequestUri(), '/');

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return response()->json([
                'success' => false,
                'code'    => 'WRONG_PORTAL_HOST',
                'message' => "هذه العمليّة تُرسَل إلى {$expected}",
                'errors'  => (object) [],
                'meta'    => ['expected_host' => $expected],
            ], 421);
        }

        return redirect()->away($target, 302);
    }
}
