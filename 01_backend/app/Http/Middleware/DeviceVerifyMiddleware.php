<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * AMIAL-OTP-ENV-001 — **حارسٌ كان معطَّلاً في الإنتاج ولا يعلم أحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان الشرط `env('APP_MODE') == LIVE`. و`entrypoint.prod.sh` يشغّل
 * `config:cache`، ولارافيل بعدها لا يحمّل `.env` — فـ`env()` تُرجع
 * `null`، و`null == 'live'` كاذبة. **فالحارسُ لم يُنفَّذ مرّةً على
 * الخادم الحيّ**، وكان يُنفَّذ في التطوير وحدَه.
 *
 * أي أنّه كان **معكوساً**: يشتدّ حيث لا يلزم، ويغيب حيث يلزم.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا لم يُفعَّل مع إصلاح القراءة:**
 *
 * تصحيحُ القراءة إلى `config('app.mode')` كان سيُشعله فجأةً على الإنتاج،
 * فيردّ ٤٠١ على **كلّ عميلٍ لا يرسل `User-Agent: Dart/…`** — ومنه بناءُ
 * الويب وأيّ تكاملٍ خارجيّ. وقياسُ ذلك ظهر فوراً: ٣٦ اختباراً سقط.
 *
 * **وتشغيلُ حارسٍ نائمٍ منذ شهورٍ قرارُ تشغيلٍ لا إصلاحُ خطأ مطبعيّ.**
 * فبقي على حاله الفعليّ اليوم (معطَّل)، وصار **مفتاحاً صريحاً** يُقرأ من
 * الإعداد بدل أن يكون أثراً جانبيّاً لمتغيّرٍ يكذب.
 *
 * **وقيمتُه الأمنيّة محدودةٌ أصلاً**: `User-Agent` ترويسةٌ يكتبها العميل،
 * ونسخُها سطرٌ واحد. فهي تصدّ فضولاً لا مهاجماً. والحمايةُ الحقيقيّة في
 * `deviceVerify` الآخر — بصمةُ الجهاز المسجَّلة — لا في اسم المكتبة.
 *
 * لتشغيله: `AMIAL_REQUIRE_DART_CLIENT=true`.
 */
class DeviceVerifyMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('amial.security.require_dart_client', false)) {
            return $next($request);
        }

        // `explode` على `null` خطأٌ مميتٌ في PHP 8.1+ — وطلبٌ بلا ترويسة
        // ليس حالةً نظريّة.
        $device = explode('/', (string) $request->header('User-Agent'));

        if (($device[0] ?? '') !== 'Dart') {
            abort(response()->json([
                'errors' => [['code' => 'auth-001', 'message' => 'Unauthorized.']],
            ], 401));
        }

        return $next($request);
    }
}
