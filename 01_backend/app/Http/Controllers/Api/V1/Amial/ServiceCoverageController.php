<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\YemenGovernorates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-COVERAGE-001 — تغطية الخدمة في محافظة المستخدم.
 *
 * **لماذا تغطية فعلية لا علَم سياسة:**
 * العميل الذي انتقل شمالاً لا يُحجَب في أميال باي — عملياته داخل الدفتر
 * (تحويل، استقبال) لا يعبر فيها نقد فتبقى عاملة. ما يتوقّف عنده فعلاً هو
 * السحب والدفع، وسببه بسيط: **لا وكيل ولا تاجر هناك**.
 *
 * الفرق جوهري في التجربة: «ممنوع في منطقتك» تُشعره بأنه مطرود، بينما
 * «لا يوجد وكلاء قريبون» حقيقة يفهمها ويتصرّف بناءً عليها. وهي كذلك تصحّح
 * نفسها تلقائياً: يوم تعتمد وكيلاً هناك تتغيّر الرسالة بلا إصدار جديد.
 */
class ServiceCoverageController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $code = YemenGovernorates::codeFromName((string) ($user->residence_governorate ?? ''));

        // يسمح بالاستعلام عن محافظة أخرى (قبل السفر مثلاً)
        if ($request->filled('governorate')) {
            $code = YemenGovernorates::codeFromName((string) $request->input('governorate')) ?? $code;
        }

        if ($code === null) {
            return response()->json([
                'success' => true,
                'data' => [
                    'governorate' => null,
                    'agents' => 0,
                    'merchants' => 0,
                    'can_receive' => true,
                    'can_transfer' => true,
                    'can_cash_out' => false,
                    'can_pay_merchant' => false,
                    // AMIAL-COVERAGE-002: الصيغة السابقة «لم نتمكّن من
                    // تحديد محافظتك» توهم بمحاولةٍ فشلت، ولا محاولة تقع:
                    // الحقل يُقرأ من الملفّ الشخصي ولا يُسأل عنه أحد. ثم
                    // تنصح بـ«حدّث عنوانك» ولا سبيل في التطبيق إلى ذلك —
                    // نصيحةٌ إلى طريق مسدود.
                    // AMIAL-COVERAGE-003: التطبيق يطلب إذن الموقع ويحدّدها
                    // بنفسه. هذه اللافتة لا تظهر إلا لمن رفض الإذن أو تعذّر
                    // تحديد موقعه — فالصيغة تخاطبه هو لا تُحمّله عملاً كان
                    // على التطبيق أن يقوم به.
                    'notice' => 'تعذّر تحديد محافظتك من موقعك. اخترها يدوياً '
                        . 'لعرض الوكلاء والتجّار القريبين منك.',
                    'needs_governorate' => true,
                ],
            ]);
        }

        $agents = $this->countActive(AGENT_TYPE, $code);
        $merchants = $this->countActive(MERCHANT_TYPE, $code);
        $name = YemenGovernorates::name($code);

        return response()->json([
            'success' => true,
            'data' => [
                'governorate' => $name,
                'governorate_code' => $code,
                'agents' => $agents,
                'merchants' => $merchants,
                // الاستقبال والتحويل داخل الدفتر — لا يتوقّفان بالجغرافيا.
                'can_receive' => true,
                'can_transfer' => true,
                'can_cash_out' => $agents > 0,
                'can_pay_merchant' => $merchants > 0,
                'notice' => $this->notice($name, $agents, $merchants),
                'notice_short' => $this->noticeShort($name, $agents, $merchants),
                'needs_governorate' => false,
            ],
        ]);
    }

    /**
     * POST /api/v1/amial/me/governorate — يحدّد محافظة الإقامة.
     *
     * AMIAL-COVERAGE-002 — كانت الرسالة تنصح بتحديث العنوان ولا سبيل إليه
     * في التطبيق: نصيحةٌ إلى طريق مسدود. هذا يفتح الطريق.
     *
     * **التمييز المقصود بين التعيين والتغيير:**
     *   - حساب لم تُحدَّد محافظته قطّ: يُعيَّن مباشرةً. لا شيء يُنقَض، والحقل
     *     يخدم العرض (وكلاء وتجّار قريبون) لا الصلاحيات.
     *   - حساب محدَّدة محافظته: لا تُبدَّل بطلب من التطبيق. العنوان بيانات
     *     KYC قُورنت بالهوية ووثيقة العنوان عند المراجعة، وتبديلها بضغطة
     *     يُفرغ تلك المقارنة من معناها. يُسجَّل الطلب للمراجعة ويُقال ذلك
     *     للعميل صراحةً بدل تجاهل صامت.
     *
     * ولا يمسّ هذا zone_code في الحالتين — الصلاحيات المالية تُشتقّ منه لا
     * من هذا الحقل، فلا سبيل لتوسيعها من هنا.
     */
    public function setGovernorate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'governorate_code' => 'required|string|in:' . implode(',', YemenGovernorates::codes()),
        ]);

        $user = $request->user();
        $code = $validated['governorate_code'];
        $current = (string) ($user->residence_governorate ?? '');

        $audit = app(\App\Services\AuditService::class);

        if ($current !== '' && YemenGovernorates::codeFromName($current) !== $code) {
            $audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $user->id,
                'subject_type' => 'user',
                'subject_id' => (string) $user->id,
                'action' => 'RESIDENCE_CHANGE_REQUESTED',
                'decision_code' => 'PENDING_REVIEW',
                'severity' => 'notice',
                'context' => ['from' => $current, 'to' => $code],
            ]);

            return response()->json([
                'success' => false,
                'code' => 'REVIEW_REQUIRED',
                'message' => 'محافظتك مسجّلة مسبقاً ضمن بيانات التوثيق. '
                    . 'سجّلنا طلب التغيير وسيراجعه الدعم — تواصل معهم لتسريعه.',
            ], 422);
        }

        $user->residence_governorate = $code;
        $user->save();

        $audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'RESIDENCE_SET',
            'decision_code' => 'OK',
            'severity' => 'info',
            'context' => ['governorate' => $code],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديد محافظة ' . YemenGovernorates::name($code) . '.',
            'data' => ['governorate_code' => $code, 'governorate' => YemenGovernorates::name($code)],
        ]);
    }

    private function countActive(int $type, string $governorateCode): int
    {
        return User::where('type', $type)
            ->where('is_active', 1)
            ->where('is_kyc_verified', 1)
            ->where('residence_governorate', $governorateCode)
            ->count();
    }

    /**
     * AMIAL-COVERAGE-004 — **سطرٌ واحد، والتفصيلُ عند الطلب.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كانت اللافتة تعرض النصَّ الكامل — ثلاثةَ أسطرٍ تأكل ثلثَ الشاشة
     * الرئيسيّة، **في كلّ فتحة، إلى الأبد**. والخبرُ ثابت: محافظةٌ بلا
     * وكلاء اليوم هي بلا وكلاء غداً.
     *
     * فصار المعروضُ سطراً واحداً، والنصُّ الكامل يظهر بلمسة. **ومصدرُ
     * النصّين واحدٌ هنا** — لا نسخةٌ في الخادم وأخرى في التطبيق تفترقان.
     */
    private function noticeShort(?string $name, int $agents, int $merchants): string
    {
        if ($agents > 0 && $merchants > 0) {
            return "{$agents} وكيل و{$merchants} تاجر في {$name}";
        }

        if ($agents > 0) {
            return "{$agents} وكيل في {$name} · لا تجار بعد";
        }

        if ($merchants > 0) {
            return "{$merchants} تاجر في {$name} · لا وكلاء بعد";
        }

        return "لا وكلاء ولا تجار في {$name} بعد";
    }

    private function notice(?string $name, int $agents, int $merchants): string
    {
        if ($agents > 0 && $merchants > 0) {
            return "يوجد {$agents} وكيل و{$merchants} تاجر في محافظة {$name}.";
        }

        if ($agents > 0) {
            return "يوجد {$agents} وكيل في محافظة {$name} للإيداع والسحب. "
                . 'لا يوجد تجار يقبلون الدفع بأميال باي هنا بعد.';
        }

        if ($merchants > 0) {
            return "يوجد {$merchants} تاجر في محافظة {$name}. "
                . 'لا يوجد وكلاء للسحب النقدي هنا بعد.';
        }

        return "لا يوجد وكلاء أو تجار لأميال باي في محافظة {$name} حالياً. "
            . 'يمكنك استقبال الأموال وتحويلها لمستخدمي أميال باي في أي وقت، '
            . 'أمّا السحب النقدي والدفع فيتطلّبان وكيلاً أو تاجراً معتمداً.';
    }
}
