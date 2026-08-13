<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\FeeService;
use App\Traits\TransactionTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-MERCHANT-PAY-001 — دفع العميل للتاجر عبر QR أو POS.
 *
 *   POST /api/v1/amial/merchant/quote — معاينة (التاجر يستلم كم بعد الرسم)
 *   POST /api/v1/amial/merchant/pay   — تنفيذ الدفع
 *
 * المال يذهب لحساب التاجر الرئيسي؛ التاجر يتحمّل الرسم؛ يُنسب لموظف POS إن وُجد.
 */
class MerchantPaymentController extends Controller
{
    use TransactionTrait;

    public function __construct(
        private readonly FeeService $fees,
    ) {}

    /** POST /api/v1/amial/merchant/quote — لا يحرّك مالاً */
    public function quote(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'channel' => 'sometimes|in:qr,pos',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $channel = $request->input('channel', 'qr');
        $code = $channel === 'pos' ? 'MERCHANT_POS' : 'MERCHANT_QR';
        $b = $this->fees->calculate($code, (string)$request->input('amount'), ['applies_to' => 'merchant']);

        return $this->ok([
            'amount' => $b['amount'],
            'fee' => $b['fee'],
            'merchant_receives' => $b['net_credit'],
            'channel' => $channel,
        ], 'QUOTE_OK', 'Quote');
    }

    /** POST /api/v1/amial/merchant/pay */
    public function pay(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'merchant_user_id' => 'required_without:merchant_phone|integer',
            'merchant_phone' => 'required_without:merchant_user_id|string',
            'amount' => 'required|numeric|min:0.01',
            'channel' => 'sometimes|in:qr,pos',
            'pos_user_id' => 'sometimes|nullable|integer',
            'note' => 'sometimes|nullable|string|max:255',
            'idempotency_key' => 'sometimes|nullable|string|max:100',
            'sale_ulid' => 'sometimes|nullable|string|max:40',
            'pin' => 'required|string|min:4|max:4',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $customer = $request->user();

        // AMIAL-MERCHANT-PAY-002 — **ورمزُ المعاملات هنا كما هو في التحويل.**
        //
        // قِيس قبل الإضافة: `customer/send-money` يشترط PIN، و**دفعُ التاجر
        // لم يكن يشترطه**. أي أنّ دفعَ مالٍ في متجرٍ كان محميّاً أقلَّ من
        // إرساله لصديق — ومن أخذ هاتفاً مفتوحاً يدفع به بلا حاجز.
        //
        // **وموضعُ الفحص هنا لا في `merchant_payment_transaction`:**
        // تلك الدالّة يناديها `FuelStationService` و`SplitBillService`
        // بمساراتٍ لها حواجزُها، فاشتراطُ PIN داخلها يكسرهما. (تحليلُ
        // الأثر قبل التعديل — لا بعده.)
        if (!\App\CentralLogics\Helpers::pin_check($customer->id, (string) $request->input('pin'))) {
            return $this->error('PIN_INVALID', 'رمز الحماية غير صحيح', 403);
        }
        $channel = $request->input('channel', 'qr');

        // حلّ التاجر
        $merchant = $request->filled('merchant_user_id')
            ? User::find($request->input('merchant_user_id'))
            : User::whereIn('phone', \App\Support\Phone::variants((string) $request->input('merchant_phone')))->first();

        if (!$merchant) {
            return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
        }
        if ($merchant->id === $customer->id) {
            return $this->error('SELF_PAYMENT', 'لا يمكن الدفع لنفسك', 422);
        }

        $profile = MerchantProfile::where('user_id', $merchant->id)->first();
        if (!$profile) {
            return $this->error('NOT_A_MERCHANT', 'الحساب ليس تاجراً', 422);
        }
        if ($profile->verification_status === 'verification_suspended') {
            return $this->error('MERCHANT_SUSPENDED', 'توثيق التاجر موقوف', 422);
        }

        // تحقق موظف POS (إن مُرّر)
        $posUserId = $request->input('pos_user_id');
        if ($posUserId !== null) {
            $pos = PosUser::where('id', $posUserId)
                ->where('merchant_user_id', $merchant->id)
                ->where('is_active', true)
                ->first();
            if (!$pos) {
                return $this->error('POS_USER_INVALID', 'موظف POS غير صالح لهذا التاجر', 422);
            }
        }

        try {
            $txId = $this->merchant_payment_transaction(
                customer_user_id: $customer->id,
                merchant_user_id: $merchant->id,
                amount: (string)$request->input('amount'),
                channel: $channel,
                pos_user_id: $posUserId,
                note: $request->input('note'),
                idempotencyKey: $request->input('idempotency_key'),
            );
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_PAYMENT', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('MERCHANT_PAY_FAILED', $e->getMessage(), 422);
        }

        // معاينة الأرقام للعرض/الإيصال
        $code = $channel === 'pos' ? 'MERCHANT_POS' : 'MERCHANT_QR';
        $b = $this->fees->calculate($code, (string)$request->input('amount'), ['applies_to' => 'merchant']);

        // AMIAL-CASHIER-001 — ربط بيع الكاشير المعلّق (إن مُرّر sale_ulid)
        $linkedSale = null;
        if ($request->filled('sale_ulid')) {
            $linkedSale = app(\App\Services\CashierService::class)
                ->linkPayment($request->input('sale_ulid'), (string)$txId, $merchant->id);
            if ($linkedSale) {
                app(\App\Services\ReceiptService::class)->attachBusinessReference(
                    (string) $txId,
                    'merchant_sale',
                    (int) $linkedSale->id,
                    [
                        'merchant_vertical' => $profile->business_type ?? 'quick_sale',
                        'sale_ulid' => $linkedSale->sale_ulid,
                    ],
                );
            }
        }

        return $this->ok([
            'transaction_id' => $txId,
            'amount' => $b['amount'],
            'fee' => $b['fee'],
            'merchant_receives' => $b['net_credit'],
            'channel' => $channel,
            'sale_linked' => $linkedSale !== null,
            'merchant' => [
                'name' => $merchant->f_name ?? $merchant->name ?? null,
                'verified' => $profile->verification_status === 'verified',
            ],
        ], 'MERCHANT_PAY_OK', 'تم الدفع بنجاح');
    }

    // ---- ردود منظّمة (نفس نمط متحكمات أميال) ----

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
