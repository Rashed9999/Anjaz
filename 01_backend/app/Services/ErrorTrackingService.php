<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OBSERVABILITY-001 — **تتبّعُ أخطاءٍ لا يخرج من الخادم.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن:** ثلاثةُ أعطالٍ في يومٍ واحدٍ وصلت عبر صاحب المشروع لا عبر
 * جهاز. ولم يكن في المنصّة تتبّعُ أخطاءٍ من جهة الخادم إطلاقاً.
 *
 * **ولمَ جدولٌ لا Sentry:** الخادمُ في اليمن، وربطُ الرصد بخدمةٍ خارجيّة
 * يجعله يسقط مع أوّل انقطاع — وهو الوقتُ الذي يُحتاج فيه. والبياناتُ
 * ماليّةٌ فلا تخرج من الخادم أصلاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والبصمةُ من الموضع لا من الرسالة.** رسالةٌ فيها معرّفُ عمليّةٍ تنتج
 * بصمةً جديدةً لكلّ وقوع، فيصير الجدولُ سجلّاً يُغرق ما فيه. فالبصمةُ من
 * الصنف والملفّ والسطر: **عطلٌ وقع ألفَ مرّةٍ سطرٌ واحدٌ بعدّاد.**
 *
 * **ولا يُسجَّل ما ليس عطلاً:** التحقّقُ من مدخلاتٍ (422) ورفضُ صلاحيّة
 * (403) و«غير موجود» (404) سلوكٌ صحيح. وتسجيلُها يُغرق ما يستحقّ النظر.
 */
class ErrorTrackingService
{
    /** حالاتٌ ليست أعطالاً — سلوكٌ صحيحٌ يُردّ به على طلبٍ خاطئ. */
    private const NOT_A_DEFECT = [400, 401, 402, 403, 404, 405, 409, 419, 422, 429];

    public function record(\Throwable $e, ?Request $request = null, ?int $status = null): void
    {
        if ($status !== null && in_array($status, self::NOT_A_DEFECT, true)) {
            return;
        }

        $fingerprint = hash('sha256', implode('|', [
            $e::class,
            $e->getFile(),
            (string) $e->getLine(),
        ]));

        $now = now();

        try {
            $existing = DB::table('system_errors')
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($existing) {
                DB::table('system_errors')
                    ->where('id', $existing->id)
                    ->update([
                        'occurrences' => DB::raw('occurrences + 1'),
                        'last_seen_at' => $now,
                        'message' => mb_substr($e->getMessage(), 0, 2000),
                        // **خطأٌ عاد بعد إغلاقه يُفتح ثانيةً.** وإلّا بقي
                        // «محلولاً» وهو يقع كلَّ دقيقة.
                        'status_flag' => $existing->status_flag === 'resolved'
                            ? 'open' : $existing->status_flag,
                        'updated_at' => $now,
                    ]);

                return;
            }

            DB::table('system_errors')->insert([
                'fingerprint' => $fingerprint,
                'exception' => mb_substr($e::class, 0, 191),
                'message' => mb_substr($e->getMessage(), 0, 2000),
                'file' => mb_substr((string) $e->getFile(), 0, 512),
                'line' => $e->getLine(),
                'method' => $request?->method(),
                'path' => $request ? mb_substr($request->path(), 0, 512) : null,
                'status' => $status,
                'user_id' => $request?->user()?->id,
                'actor_type' => $request?->user()?->type !== null
                    ? (string) $request->user()->type : null,
                'occurrences' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'status_flag' => 'open',
                'trace_head' => $this->traceHead($e),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $ignored) {
            // **المُسجِّلُ لا يُسقط الطلب.** خطأٌ في تسجيل خطأٍ يُبتلع —
            // وإلّا صار الرصدُ نفسُه سببَ عطل. (والقاعدةُ ذاتُها التي
            // جعلت `after_commit` مفعّلاً: أداةُ المراقبة لا تُغيّر النتيجة.)
        }
    }

    /** أوّلُ خمسة أسطرٍ من الأثر — الكاملُ فيه مساراتٌ وقد يحمل أسراراً. */
    private function traceHead(\Throwable $e): string
    {
        $lines = array_slice(explode("\n", $e->getTraceAsString()), 0, 5);

        return mb_substr(implode("\n", $lines), 0, 2000);
    }
}
