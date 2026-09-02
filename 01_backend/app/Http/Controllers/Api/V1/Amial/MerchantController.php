<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\PosUser;
use App\Services\MerchantService;
use App\Services\MerchantFinancialTruthReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-MERCHANT-001 (v1.7) — merchant endpoints
 */
class MerchantController extends AmialApiController // AMIAL-FIX-007
{
    public function __construct(
        private readonly MerchantService $service,
        private readonly MerchantFinancialTruthReportService $financialTruth,
    ) {}

    /** POST /api/v1/amial/merchant/refund */
    public function refund(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'original_transaction_id' => 'required|string|max:64',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        // لو الطالب POS user، مرر pos_user_id
        $posUserId = $this->resolvePosUserId($request);

        try {
            $result = $this->service->processRefund(
                merchant: $this->resolveMerchantPos($request),
                originalTransactionId: $request->input('original_transaction_id'),
                refundAmount: (string) $request->input('amount'),
                reason: $request->input('reason'),
                posUserId: $posUserId,
            );
        } catch (\RuntimeException $e) {
            return $this->error('REFUND_FAILED', $e->getMessage(), 422);
        }

        $status = $result['status'] === 'pending_approval' ? 202 : 201;
        return $this->ok($result,
            $result['status'] === 'pending_approval' ? 'PENDING_APPROVAL' : 'REFUNDED',
            $result['message'] ?? 'تم الاسترجاع بنجاح', $status);
    }

    /** GET /api/v1/amial/merchant/ledger */
    public function ledger(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $data = $this->service->getLedger($this->resolveMerchantPos($request), $page);
        return $this->ok($data);
    }

    /** GET /api/v1/amial/merchant/daily-stats */
    // ══════════════════════════════════════════════════════════════════
    // **استُعيدت هذه الطبقةُ بعد أن حُذفت في دفعةٍ لاحقة.**
    //
    // وقِيس بالحارس: `PosBalanceScopeGuardTest` أخرج «رصيدُ المتجر وصل
    // إلى الكاشير: 0.0000» — والردُّ ٢٠٠ وصيغتُه صحيحة، والرقمُ ليس له.
    // ولا خطأَ في أيّ سجلّ.
    // ══════════════════════════════════════════════════════════════════
    /**
     * GET /api/v1/amial/merchant/daily-stats
     *
     * ══════════════════════════════════════════════════════════════════
     * AMIAL-POS-SCOPE-001 — **رصيدُ المتجر لا يخرج إلى الكاشير.**
     *
     * كان هذا المسارُ يردّ `current_balance` — **محفظةَ صاحب المتجر
     * كاملةً** — لموظّف نقطة البيع، لأنّ `resolveMerchantPos` تحوّل
     * الموظّفَ إلى صاحبه فتُقرأ محفظةُ الأخير.
     *
     * **وأختُه تحرس نفسَها**: `financialReport` أسفلَه يردّ موظّفَ نقطة
     * البيع صراحةً بـ«التقرير المالي الكامل متاح لمالك المتجر فقط».
     * فالحمايةُ كانت موجودةً في واحدٍ وغائبةً عن الآخر، **والآخرُ هو
     * الذي تقرؤه لوحةُ التاجر في كلّ فتحة**.
     *
     * **وما يبقى للموظّف مقصود**: مبيعاتُ اليوم والمرتجعاتُ وعددُ
     * العمليّات — يحتاجها ليُقفل ورديّتَه. **والرصيدُ ليس منها.**
     * (القاعدة الثامنة: الهويّة تحدّد النطاق.)
     * ══════════════════════════════════════════════════════════════════
     */
    public function dailyStats(Request $request): JsonResponse
    {
        $stats = $this->service->getDailyStats($this->resolveMerchantPos($request));

        if ($this->resolvePosUserId($request) !== null) {
            // **لا يُصفَّر بل يُحذف** — وصفرٌ يُقرأ «متجرٌ فارغ»، وهو كذب.
            // (القاعدة السابعة: «غير معروف» ليس صفراً.)
            unset($stats['current_balance']);
            $stats['balance_scope'] = 'owner_only';
        }

        return $this->ok($stats);
    }

    /** تقرير تشغيل موحّد: البيع والتحصيل والمحفظة ليست الرقم نفسه. */
    public function financialReport(Request $request): JsonResponse
    {
        // تقرير اليوم المجاني بديل التقرير القديم الذي كان متاحاً للكاشير؛
        // لا نرفع صلاحية القراءة إلى «المالك فقط» ثم نربط زر الموظف بمسار
        // يرده 403. بياناته تُقرأ دائماً داخل منشأة صاحب الـPOS نفسها عبر
        // resolveMerchantPos، وأفعال التحويل/السحب تبقى محروسة في مساراتها.
        $v = Validator::make($request->query(), ['from' => 'sometimes|date', 'to' => 'sometimes|date']);
        if ($v->fails()) return $this->validationError($v);
        try {
            $report = $this->financialTruth->report(
                $this->resolveMerchantPos($request), $request->query('from'), $request->query('to'),
            );

            // ══════════════════════════════════════════════════════════
            // AMIAL-POS-SCOPE-002 — **نطاقٌ لا حجب، ولا ٤٠٣ على زرٍّ قائم.**
            //
            // حجّتان صحيحتان تعارضتا: الحارسُ يقول «التقريرُ الماليُّ
            // الكاملُ للمالك»، والتعليقُ أعلاه يقول «لا تربط زرَّ الموظّف
            // بمسارٍ يردّه ٤٠٣». وكلاهما محقّ.
            //
            // **والجوابُ ثالثٌ**: يبقى البابُ مفتوحاً ويُقصّ ما ليس له —
            // كما في `dailyStats` أعلاه بالحرف. فالتقريرُ يحمل `wallet`
            // و`receivables`، وهما مالُ المالك لا حاجةَ للكاشير بهما
            // ليُقفل ورديّتَه. والمبيعاتُ والتحصيلاتُ تبقى له.
            //
            // **ويُحذَف ولا يُصفَّر** — صفرٌ يُقرأ «لا ذممَ على أحد»
            // وهو كذب. (القاعدتان السابعة والثامنة.)
            // ══════════════════════════════════════════════════════════
            if ($this->resolvePosUserId($request) !== null) {
                unset($report['wallet'], $report['receivables']);
                $report['scope'] = 'owner_only_fields_removed';
            }

            return $this->ok(['report' => $report]);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_PERIOD', $e->getMessage(), 422);
        }
    }

    // ============================================================
    private function resolveMerchantPos(Request $request)
    {
        // لو POS user، التاجر الرئيسي هو merchant_user_id
        $user = $request->user();
        $posUser = PosUser::where('user_id', $user->id)->first();
        if ($posUser) {
            return \App\Models\User::find($posUser->merchant_user_id);
        }
        return $user;
    }

    private function resolvePosUserId(Request $request): ?int
    {
        $posUser = PosUser::where('user_id', $request->user()->id)->first();
        return $posUser?->id;
    }
}
