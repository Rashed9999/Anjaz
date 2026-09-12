<?php

namespace App\Services;

use App\Models\FuelSale;
use App\Models\MerchantSale;
use App\Models\PaymentRequest;
use App\Models\PharmacySale;
use App\Models\SplitBillParticipant;
use App\Models\WholesaleCollection;
use App\Models\WholesaleInvoice;
use App\Models\User;
use RuntimeException;

/**
 * AMIAL-MERCHANT-PAYMENT-REFERENCE-001
 *
 * مرجع دفع QR ليس نصاً يرسله التطبيق ليعلن أن البيع دُفع. هذه الخدمة هي
 * البوابة الواحدة التي تثبت أن المرجع:
 *   - طلب QR مكتمل إلى محفظة المنشأة نفسها؛
 *   - يطابق المبلغ المطلوب؛
 *   - لم يُستهلك في فاتورة أو تحصيل آخر.
 *
 * يجب استدعاؤها داخل معاملة البيع. القفل على PaymentRequest يجعل محاولة
 * استعمال المرجع نفسه في طلبين متوازيين تمرّ واحدة فقط ثم ترى الثانية أثرها.
 */
class MerchantPaymentReferenceService
{
    public function assertPaidForMerchant(
        User $merchant,
        ?string $paidTransactionId,
        string $expectedAmount,
    ): PaymentRequest {
        $transactionId = trim((string) $paidTransactionId);
        if ($transactionId === '') {
            throw new RuntimeException('أنشئ رمز QR وانتظر تأكيد دفع العميل أولاً');
        }

        $request = PaymentRequest::where('paid_transaction_id', $transactionId)
            ->where('requester_user_id', $merchant->id)
            ->where('status', 'paid')
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            throw new RuntimeException('مرجع أميال باي غير صالح لمحفظة المنشأة أو لم يكتمل الدفع');
        }

        $amount = MoneyService::normalize($expectedAmount);
        if (MoneyService::compare((string) $request->amount, $amount) !== 0) {
            throw new RuntimeException('مبلغ رمز الدفع لا يطابق مبلغ البيع');
        }

        if ($this->isAlreadyConsumed($transactionId)) {
            throw new RuntimeException('تم استخدام رمز الدفع هذا في عملية سابقة');
        }

        return $request;
    }

    private function isAlreadyConsumed(string $transactionId): bool
    {
        return MerchantSale::where('paid_transaction_id', $transactionId)->exists()
            || FuelSale::where('paid_transaction_id', $transactionId)->exists()
            || PharmacySale::where('paid_transaction_id', $transactionId)->exists()
            || WholesaleInvoice::where('paid_transaction_id', $transactionId)->exists()
            || WholesaleCollection::where('paid_transaction_id', $transactionId)->exists()
            || SplitBillParticipant::where('paid_transaction_id', $transactionId)->exists();
    }
}
