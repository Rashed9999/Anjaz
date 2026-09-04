<?php

namespace App\Http\Middleware;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\CashierShiftService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-SHIFT-GATE-001 — **لا شبّاكَ بلا ورديّة. ولا استثناءَ للمالك.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **بنصّ صاحب المشروع:** «لا يتمّ فتح الكاشير **حتّى لو كان مالك المتجر**
 * إلّا بفتح ورديّة».
 *
 * **وقِيس قبل البناء:** `CashierService` فيه **صفرُ ذكرٍ للورديّة**. أي
 * أنّ الدرجَ كان مفتوحاً بلا أحد: تُقبَض النقودُ ولا يُعرف من قبضها، ولا
 * شيءَ يُطابَق آخرَ اليوم لأنّه لا يوجد «آخرُ يومٍ» أصلاً.
 *
 * ─────────────────────────────────────────────────────────────────────
 * **① ولماذا وسيطٌ لا سطرٌ في الخدمة؟**
 *
 * لأنّ الخدمةَ تُنادى من خمسةَ عشرَ موضعاً — منها اختباراتٌ تفحص المخزونَ
 * والولاءَ والدفتر، ولا شأنَ لها بالورديّة. **والحدُّ يخصّ البابَ الذي
 * يدخل منه إنسان**، وهو المسار. فالوسيطُ على مسارات البيع وحدَها،
 * **وحارسٌ يعدّها** فلا يُضاف مسارُ بيعٍ جديدٌ يفلت منه (وهو ما يمنع
 * تكرارَ «مبنيٌّ ولا يُوصَل إليه» مقلوباً: بابٌ بلا حارس).
 *
 * **② والمالكُ يمرّ من الحدّ نفسِه.**
 *
 * `CashierShiftService::current` يفرّق بالفاعل: ورديّةُ المالك
 * `pos_user_id = null`، وورديّةُ كلّ كاشيرٍ برقمه. فالمالكُ يفتح
 * ورديّتَه كغيره — **وهذا هو المطلبُ لا استثناءٌ منه**: بلا ذلك لا
 * تُنسَب بيعتُه إلى أحد، ويصير الفرقُ في الدرج بلا صاحب.
 *
 * **③ والرفضُ ٤٠٩ لا ٤٠٣.**
 *
 * ٤٠٣ تعني «ليس لك»، وهذا ليس منعَ صلاحيّة: هو **شرطُ حالةٍ** يُصلحه
 * المستعملُ بنفسه في ثانية. فيخرج ٤٠٩ ومعه `can_open` و`open_endpoint`
 * — **فالشاشةُ تفتح النافذةَ الصحيحة بدل أن تعرض عطلاً**.
 *
 * **④ ولا يُقفَل الشبّاكُ على من لا يملك الميزة.**
 *
 * `F_SHIFT_CLOSE` كانت **باقةَ الأعمال فأعلى** (٤٠٢). ولو بقيت كذلك مع
 * هذا الحدّ لصار **كلُّ تاجرٍ مجّانيٍّ عاجزاً عن البيع إطلاقاً** — أي
 * بيعُ القدرةِ على التشغيل. فصارت الورديّةُ **أساسيّةً لا تُباع** (وهو
 * حدُّ `core()` نفسُه في سجلّ القدرات: ما يمنع أرقاماً كاذبةً لا يُباع)،
 * ويبقى المدفوعُ في التقارير العميقة.
 */
class EnsureOpenShift
{
    public function __construct(private readonly CashierShiftService $shifts)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $ctx = $this->resolve($request);

        // **ومن ليس تاجراً ولا موظّفَه يمرّ من هنا بلا حكم** — حدُّ
        // الهويّة ليس شغلَ هذا الوسيط، وله حرّاسُه. وإصدارُ حكمٍ هنا
        // يُنتج رسالةً خاطئة: «افتح ورديّة» لمن لا متجرَ له.
        if ($ctx === null) {
            return $next($request);
        }

        [$merchant, $posUserId] = $ctx;

        // ④ الحدُّ يُطفأ من اللوحة بقرارٍ مكتوب، لا بتعليق سطر.
        $required = (bool) (MerchantProfile::where('user_id', $merchant->id)
            ->value('require_shift_to_sell') ?? true);

        if (! $required) {
            return $next($request);
        }

        if ($this->shifts->current($merchant, $posUserId) !== null) {
            return $next($request);
        }

        return new JsonResponse([
            'success' => false,
            'code' => 'SHIFT_REQUIRED',
            'message' => 'افتح ورديّتك أوّلاً — لا يُقبض نقدٌ بلا ورديّة '
                . 'تحمل اسمَك ويُجرَد درجُها عند الإقفال.',
            'errors' => (object) [],
            'meta' => [
                'can_open' => true,
                'open_endpoint' => '/api/v1/amial/cashier/shift/open',
                'is_owner' => $posUserId === null,
            ],
        ], 409);
    }

    /** @return array{0:User,1:?int}|null */
    private function resolve(Request $request): ?array
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $pos = PosUser::where('user_id', $user->id)->where('is_active', true)->first();

        if ($pos) {
            $merchant = User::find($pos->merchant_user_id);

            return $merchant ? [$merchant, $pos->id] : null;
        }

        if (! MerchantProfile::where('user_id', $user->id)->exists()) {
            return null;
        }

        return [$user, null];
    }
}
