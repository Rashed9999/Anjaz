<?php

namespace App\Http\Middleware;

use App\Models\Merchant\PosDevice;
use App\Models\Merchant\PosDeviceSession;
use App\Models\PosUser;
use App\Services\OpsAlertService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-POS-DEVICES-003 — **المقعدُ يُفحص في كلّ طلب، لا عند التسجيل وحدَه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الفرقُ بين حدِّ تسجيلٍ وحدِّ استعمال:**
 *
 * `max_pos_devices` مفروضٌ عند التسجيل — وهذا صحيحٌ ولازم (فرضُه عند كلّ
 * دخولٍ يُقفل المتجرَ على موظّفٍ يبدّل ورديّتَه). **لكنّه وحدَه لا يحدّ
 * شيئاً**:
 *
 *   يُسجَّل جهازٌ واحدٌ فيشغل المقعدَ الوحيد
 *     ⇒ يُنسخ رمزُه إلى عشرة أجهزة
 *       ⇒ تعمل كلُّها، ولا يمرّ أيٌّ منها ببابِ التسجيل أصلاً
 *
 * فالالتفافُ **لا يُكسر الحارسَ بل يتجنّبه**. ولا يظهر في أيّ اختبارٍ
 * يسأل «هل يُرفض الجهازُ الثاني عند التسجيل؟» — لأنّه لا يُسجَّل ثانٍ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ فحوصٍ هنا، ولكلٍّ سببُه:**
 *
 * ① **الرمزُ المربوطُ يموت بموت مقعده.** إلغاءُ الجهاز من شاشة الإدارة
 *    كان — لولا هذا — يُخلي المقعدَ **ويترك الجلسةَ القائمةَ تعمل** حتّى
 *    ينتهي الرمزُ من نفسِه. أي أنّ «ألغيتُ الجهازَ المسروق» جملةٌ كاذبة.
 *
 * ② **والترويسةُ تُقارَن ولا يُوثَق بها.** الربطُ في الخادم (‏`PosDeviceSession`)
 *    فحذفُ الترويسة لا يُحرّر الرمز؛ وحضورُها بقيمةٍ أخرى يُكشف تناقضاً.
 *    (القيدُ السادس: لا يُؤخذ معرِّفُ الجهاز من فلاتر دليلاً وحيداً.)
 *
 * ③ **وموظّفُ نقطة بيعٍ بلا ربطٍ إطلاقاً** — رمزٌ صدر قبل الإنفاذ، أو
 *    دخولٌ بلا جهاز. وهذا وحدَه في **الوضع الصامت** مؤقّتاً، وسببُه
 *    وشرطُ خروجه مكتوبان في `config/amial.php`.
 */
class EnsurePosDevice
{
    /** ترويسةُ هويّة الجهاز — والقيمةُ معرِّفُ تثبيتٍ لا سرّ. */
    public const HEADER = 'x-pos-device';

    public function __construct(private OpsAlertService $ops) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        // المصادقةُ شأنُ `auth:api` — وهذه بوّابةُ مقعدٍ لا بوّابةُ هويّة.
        if ($user === null) {
            return $next($request);
        }

        $tokenId = $this->tokenId($request);
        $session = $tokenId === null ? null : PosDeviceSession::forToken($tokenId);

        if ($session !== null) {
            return $this->withBoundSeat($request, $next, $session);
        }

        // ══════════════════════════════════════════════════════════════
        // لا ربط. **والتاجرُ نفسُه لا يحتاج مقعداً** — المقعدُ للأجهزة
        // العاملة على نقطة البيع، ومالكُ الحساب يدير من أيّ متصفّح.
        if (! $this->isPosActor($user->id)) {
            return $next($request);
        }

        if (! $this->enforced()) {
            // **الوضعُ الصامتُ يُقاس ولا يُنسى** — فبلا عدٍّ لا يُعرف متى
            // يُغلق، فيبقى «مؤقّتاً» إلى الأبد.
            $this->ops->note(
                'pos_device_unbound_session',
                'جلسةُ نقطة بيعٍ بلا مقعدٍ مربوط',
                sprintf('user=%d · path=%s — رمزٌ صدر قبل الإنفاذ أو دخولٌ بلا جهاز',
                    $user->id, $request->path()),
            );

            return $next($request);
        }

        return $this->deny('POS_DEVICE_REQUIRED',
            'هذا الحسابُ يعمل على نقطة بيع، ولا جهازَ مسجَّلٌ لهذه الجلسة. '
            . 'سجّل الجهازَ من حساب التاجر ثمّ أعد الدخول.', 403);
    }

    /** الفحصان الأوّلان — على جلسةٍ لها مقعدٌ مربوط. */
    private function withBoundSeat(Request $request, Closure $next, PosDeviceSession $session): mixed
    {
        $device = $session->device;

        // ① الملغى لا يُكمل — **والمختومُ كذلك.**
        //
        //    والختمُ يُفحص هنا صراحةً: من رُبط مرّةً يبقى محكوماً بربطه،
        //    فجلسةٌ مختومةٌ **منعٌ** لا «رمزٌ حرّ». (وقد كانت تُقرأ حرّاً،
        //    فكان الإلغاءُ يُطلق الرمزَ بدل أن يوقفه — انظر `forToken`.)
        if ($session->ended_at !== null
            || $device === null || $device->revoked_at !== null || ! $device->is_active) {
            $session->forceFill(['ended_at' => now()])->save();

            return $this->deny('POS_DEVICE_REVOKED',
                'أُلغي هذا الجهاز. راجع صاحبَ الحساب لتسجيله من جديد.', 401);
        }

        // ② والترويسةُ إن حضرت طُوبقت.
        $presented = trim((string) $request->header(self::HEADER, ''));

        if ($presented !== '') {
            [$claimed] = PosDevice::locate((int) $session->merchant_user_id, $presented);

            if ($claimed === null || $claimed->id !== $device->id) {
                return $this->deny('POS_DEVICE_MISMATCH',
                    'الرمزُ صدر لجهازٍ آخر.', 403);
            }
        }

        $this->touch($session, $device);

        return $next($request);
    }

    /**
     * أثرُ الاستعمال — **بتباعدِ دقيقةٍ لا في كلّ طلب.**
     *
     * كلُّ طلبٍ محميٍّ يمرّ من هنا، وكتابةٌ في كلٍّ منها تحوّل بوّابةً
     * خفيفةً إلى حِملٍ على القاعدة. (‏وهو نفسُ ما فعله `CheckDeviceId`.)
     */
    private function touch(PosDeviceSession $session, PosDevice $device): void
    {
        $last = $session->last_seen_at;

        if ($last !== null && $last->diffInSeconds(now()) < 60) {
            return;
        }

        PosDeviceSession::where('id', $session->id)->update(['last_seen_at' => now()]);
        PosDevice::where('id', $device->id)->update(['last_seen_at' => now()]);
    }

    /** معرّفُ رمز Passport الجاري — و`null` إن لم يكن الطلبُ برمز. */
    private function tokenId(Request $request): ?string
    {
        try {
            $token = $request->user()?->token();

            return $token?->id === null ? null : (string) $token->id;
        } catch (\Throwable) {
            // حرسٌ لاختباراتٍ تُصادِق بـ`actingAs` بلا Passport.
            return null;
        }
    }

    private function isPosActor(int $userId): bool
    {
        return PosUser::where('user_id', $userId)->where('is_active', true)->exists();
    }

    /**
     * **شرطُ الخروج من الوضع الصامت مكتوبٌ لا متروك.**
     *
     * انظر `config/amial.php` → `pos_devices.enforce_session_binding`.
     */
    private function enforced(): bool
    {
        return (bool) config('amial.pos_devices.enforce_session_binding', false);
    }

    private function deny(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ], $status);
    }
}
