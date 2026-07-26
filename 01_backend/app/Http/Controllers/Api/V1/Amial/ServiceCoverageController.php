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
                    'notice' => 'لم نتمكّن من تحديد محافظتك. حدّث عنوانك لعرض الوكلاء والتجار القريبين.',
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
            ],
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
