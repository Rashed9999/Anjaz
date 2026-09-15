<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\RequestMoney;
use App\Models\Transaction;
use App\Models\TransactionLimit;
use App\Models\User;
use App\Services\FeeDiscountPolicy;
use App\Services\FeeService;
use App\Services\KycTierService;
use App\Traits\TransactionTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * AMIAL-PROGRESSIVE-MONEY-001
 *
 * بوابة المال للعميل بعد الانتقال إلى Progressive KYC. لا تغيّر معنى
 * `is_kyc_verified` ولا تتظاهر أن Tier 1 هو KYC كامل. الأهلية تأتي من
 * KycTierService: هاتف + إقامة موثقة + الميزة + حدود المستوى.
 *
 * التنفيذ المالي نفسه يبقى في TransactionTrait ومحرك الرسوم الموحد؛ أي لا
 * يوجد دفتر أو حساب رصيد موازٍ لمسارات 6cash القديمة.
 */
class ProgressiveCustomerMoneyController extends Controller
{
    use TransactionTrait;

    public function __construct(
        private readonly KycTierService $kyc,
        private readonly FeeService $fees,
        private readonly FeeDiscountPolicy $discounts,
    ) {}

    public function sendMoney(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pin' => ['required', 'digits:4'],
            'phone' => ['required', 'string'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        if (!Helpers::get_business_settings('send_money_status')) {
            return response()->json(['message' => translate('خدمة تحويل المال غير مفعّلة حالياً')], 403);
        }

        $sender = $request->user();
        $receiverPhone = Helpers::filter_phone((string) $request->phone);
        $receiver = User::query()->whereIn('phone', \App\Support\Phone::variants($receiverPhone))->first();

        if (!$receiver) {
            return response()->json(['message' => translate('المستلِم غير موجود — تحقّق من الرقم')], 404);
        }
        if ((int) $receiver->type !== 2) {
            return response()->json(['message' => translate('المستلِم يجب أن يكون عميلاً')], 422);
        }
        if ((int) $receiver->id === (int) $sender->id) {
            return response()->json(['message' => translate('لا يمكن إجراء المعاملة على حسابك نفسه')], 422);
        }
        if (!Helpers::pin_check($sender->id, (string) $request->pin)) {
            return response()->json(['message' => translate('رمز PIN غير صحيح')], 403);
        }

        $amount = (string) $request->amount;
        try {
            $this->kyc->assertTransactionAllowed($sender, $amount, 'send_money');
            $this->kyc->assertCanReceive($receiver, $amount);
        } catch (RuntimeException $e) {
            return $this->policyDenied($e);
        }

        if ($error = $this->legacyLimitError($sender, $amount, 'send_money', 'customer_send_money_limit')) {
            return $error;
        }

        $breakdown = $this->fees->calculate('SEND_MONEY', $amount, ['applies_to' => 'customer']);
        $discounted = $this->discounts->applyFavouriteNumber(
            $sender,
            (string) $receiver->phone,
            $breakdown['fee'],
            'SEND_MONEY',
        );

        $transactionId = $this->customer_send_money_transaction(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: $amount,
            charge: $discounted['fee'],
            feeMeta: [
                'scheme_id' => $breakdown['scheme_id'],
                'scheme_version' => $breakdown['scheme_version'],
                'discount' => $discounted['discount'],
                'discount_reason' => $discounted['reason'],
                'kyc_tier' => $this->kyc->effectiveTier($sender),
            ],
        );

        if ($transactionId === null) {
            return response()->json(['message' => translate('فشلت العملية')], 422);
        }

        $this->recordLegacyLimit($sender, $amount, 'send_money', 'customer_send_money_limit');

        return response()->json([
            'message' => 'success',
            'transaction_id' => $transactionId,
            'transaction_no' => Transaction::where('transaction_id', $transactionId)->value('transaction_no'),
            'kyc_tier' => $this->kyc->effectiveTier($sender),
        ]);
    }

    public function cashOut(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pin' => ['required', 'digits:4'],
            'phone' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        if (!Helpers::get_business_settings('cash_out_status')) {
            return response()->json(['message' => translate('خدمة السحب النقدي غير مفعّلة حالياً')], 403);
        }

        $sender = $request->user();
        $receiverPhone = Helpers::filter_phone((string) $request->phone);
        $agent = User::query()->whereIn('phone', \App\Support\Phone::variants($receiverPhone))->first();

        if (!$agent) {
            return response()->json(['message' => translate('الوكيل غير موجود — تحقّق من الرقم')], 404);
        }
        if ((int) $agent->type !== 1) {
            return response()->json(['message' => translate('المستلِم يجب أن يكون وكيلاً')], 422);
        }
        // أهلية الوكيل المؤسسية لم تتحول إلى Progressive Customer KYC.
        if ((int) ($agent->is_kyc_verified ?? 0) !== 1) {
            return response()->json(['message' => translate('الوكيل غير معتمد لاستلام سحب نقدي')], 403);
        }
        if ((int) $agent->id === (int) $sender->id) {
            return response()->json(['message' => translate('لا يمكن إجراء المعاملة على حسابك نفسه')], 422);
        }
        if (!Helpers::pin_check($sender->id, (string) $request->pin)) {
            return response()->json(['message' => translate('رمز PIN غير صحيح')], 403);
        }

        $amount = (string) $request->amount;
        try {
            $this->kyc->assertTransactionAllowed($sender, $amount, 'cash_out');
        } catch (RuntimeException $e) {
            return $this->policyDenied($e);
        }

        if ($error = $this->legacyLimitError($sender, $amount, 'cash_out', 'customer_cash_out_limit')) {
            return $error;
        }

        $breakdown = $this->fees->calculate('CASH_OUT', $amount, ['applies_to' => 'customer']);
        $discounted = $this->discounts->applyFavouriteNumber(
            $sender,
            (string) $agent->phone,
            $breakdown['fee'],
            'CASH_OUT',
        );

        $transactionId = $this->customer_cash_out_transaction(
            from_user_id: $sender->id,
            to_user_id: $agent->id,
            amount: $amount,
            charge: $discounted['fee'],
            agentCommissionOverride: $breakdown['agent_commission'] ?? null,
            feeMeta: [
                'scheme_id' => $breakdown['scheme_id'],
                'scheme_version' => $breakdown['scheme_version'],
                'discount' => $discounted['discount'],
                'discount_reason' => $discounted['reason'],
                'kyc_tier' => $this->kyc->effectiveTier($sender),
            ],
        );

        if ($transactionId === null) {
            return response()->json(['message' => translate('فشلت العملية')], 422);
        }

        $this->recordLegacyLimit($sender, $amount, 'cash_out', 'customer_cash_out_limit');

        return response()->json([
            'message' => 'success',
            'transaction_id' => $transactionId,
            'transaction_no' => Transaction::where('transaction_id', $transactionId)->value('transaction_no'),
            'kyc_tier' => $this->kyc->effectiveTier($sender),
        ]);
    }

    public function requestMoney(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        if (!Helpers::get_business_settings('send_money_request_status')) {
            return response()->json(['message' => translate('خدمة طلب المال غير مفعّلة حالياً')], 403);
        }

        $requester = $request->user();
        $payerPhone = Helpers::filter_phone((string) $request->phone);
        $payer = User::query()->whereIn('phone', \App\Support\Phone::variants($payerPhone))->first();

        if (!$payer || (int) $payer->type !== 2) {
            return response()->json(['message' => translate('العميل المطلوب منه الدفع غير موجود')], 404);
        }
        if ((int) $payer->id === (int) $requester->id) {
            return response()->json(['message' => translate('لا يمكنك طلب المال من حسابك نفسه')], 422);
        }

        $amount = (string) $request->amount;
        try {
            // إنشاء الطلب لا يخصم مالاً، لذلك نفحص الميزة فقط عند الدافع،
            // ونفحص قدرة صاحب الطلب على استقبال المبلغ لو وافق الدافع.
            $this->kyc->assertFeatureAllowed($payer, 'send_money');
            $this->kyc->assertCanReceive($requester, $amount);
        } catch (RuntimeException $e) {
            return $this->policyDenied($e);
        }

        $moneyRequest = new RequestMoney();
        $moneyRequest->from_user_id = $requester->id;
        $moneyRequest->to_user_id = $payer->id;
        $moneyRequest->type = 'pending';
        $moneyRequest->amount = $amount;
        $moneyRequest->note = $request->note;
        $moneyRequest->save();

        Helpers::send_transaction_notification($moneyRequest->from_user_id, $amount, 'request_money', 'send_request_money');
        Helpers::send_transaction_notification($moneyRequest->to_user_id, $amount, 'request_money');

        return response()->json([
            'message' => 'success',
            'request_id' => $moneyRequest->id,
        ]);
    }

    public function requestMoneyStatus(Request $request, string $slug): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pin' => ['required', 'digits:4'],
            'id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }
        $decision = strtolower($slug);
        if (!in_array($decision, ['deny', 'approve'], true)) {
            return response()->json(['message' => translate('طلب غير صالح')], 422);
        }

        $payer = $request->user();
        $moneyRequest = RequestMoney::query()->find($request->id);
        if (!$moneyRequest) {
            return response()->json(['message' => translate('الطلب غير موجود')], 404);
        }
        if ((int) $moneyRequest->to_user_id !== (int) $payer->id) {
            return response()->json(['message' => translate('طلب غير مصرّح به')], 403);
        }
        if ((string) $moneyRequest->type !== 'pending') {
            return response()->json(['message' => translate('تم اتخاذ قرار على هذا الطلب مسبقاً')], 409);
        }
        if (!Helpers::pin_check($payer->id, (string) $request->pin)) {
            return response()->json(['message' => translate('رمز PIN غير صحيح')], 403);
        }

        if ($decision === 'deny') {
            $moneyRequest->type = 'denied';
            $moneyRequest->note = $request->note;
            $moneyRequest->save();
            Helpers::send_transaction_notification($moneyRequest->from_user_id, $moneyRequest->amount, 'denied_money');
            return response()->json(['message' => 'success']);
        }

        if (!Helpers::get_business_settings('send_money_status')) {
            return response()->json(['message' => translate('خدمة تحويل المال غير مفعّلة حالياً')], 403);
        }

        $requester = User::query()->find($moneyRequest->from_user_id);
        if (!$requester) {
            return response()->json(['message' => translate('صاحب الطلب غير موجود')], 404);
        }

        $amount = (string) $moneyRequest->amount;
        try {
            $this->kyc->assertTransactionAllowed($payer, $amount, 'send_money');
            $this->kyc->assertCanReceive($requester, $amount);
        } catch (RuntimeException $e) {
            return $this->policyDenied($e);
        }

        if ($error = $this->legacyLimitError($payer, $amount, 'send_money', 'customer_send_money_limit')) {
            return $error;
        }

        $breakdown = $this->fees->calculate('SEND_MONEY', $amount, ['applies_to' => 'customer']);
        $discounted = $this->discounts->applyFavouriteNumber(
            $payer,
            (string) $requester->phone,
            $breakdown['fee'],
            'SEND_MONEY',
        );

        $transactionId = $this->customer_request_money_transaction(
            $moneyRequest->to_user_id,
            $moneyRequest->from_user_id,
            $amount,
            $discounted['fee'],
        );
        if ($transactionId === null) {
            return response()->json(['message' => translate('رصيدك غير كافٍ')], 422);
        }

        $moneyRequest->type = 'approved';
        $moneyRequest->note = $request->note;
        $moneyRequest->save();
        $this->recordLegacyLimit($payer, $amount, 'send_money', 'customer_send_money_limit');

        return response()->json([
            'message' => 'success',
            'transaction_id' => $transactionId,
            'transaction_no' => Transaction::where('transaction_id', $transactionId)->value('transaction_no'),
        ]);
    }

    private function policyDenied(RuntimeException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'PROGRESSIVE_KYC_POLICY_DENIED',
        ], 403);
    }

    private function legacyLimitError(User $user, string $amount, string $type, string $settingKey): ?JsonResponse
    {
        $limit = Helpers::get_business_settings($settingKey);
        if (!is_array($limit) || (int) ($limit['status'] ?? 0) !== 1) {
            return null;
        }
        $check = Helpers::check_customer_transaction_limit($user, $amount, $type, $limit);
        if (!($check['status'] ?? false)) {
            return response()->json(['message' => translate($check['message'] ?? 'تجاوزت حد العملية')], 400);
        }
        return null;
    }

    private function recordLegacyLimit(User $user, string $amount, string $type, string $settingKey): void
    {
        $limit = Helpers::get_business_settings($settingKey);
        if (!is_array($limit) || (int) ($limit['status'] ?? 0) !== 1) {
            return;
        }

        $row = TransactionLimit::firstOrNew(['user_id' => $user->id, 'type' => $type]);
        $row->todays_count = (int) ($row->todays_count ?? 0) + 1;
        $row->todays_amount = (float) ($row->todays_amount ?? 0) + (float) $amount;
        $row->this_months_count = (int) ($row->this_months_count ?? 0) + 1;
        $row->this_months_amount = (float) ($row->this_months_amount ?? 0) + (float) $amount;
        $row->save();
    }
}
