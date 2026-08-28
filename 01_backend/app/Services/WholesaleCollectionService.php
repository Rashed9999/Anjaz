<?php

namespace App\Services;

use App\CentralLogics\Helpers;

use App\Models\User;
use App\Models\PaymentRequest;
use App\Models\WholesaleCollection;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-WHOLESALE-001 — تسجيل التحصيلات الجزئية على الفواتير.
 *
 * المنطق:
 *   1. تحصيل جزئي أو كامل من فاتورة بعينها.
 *   2. يحدّث paid_amount + balance_due + status للفاتورة.
 *   3. يقلّل current_balance للعميل.
 *   4. كل شيء داخل DB transaction مع lockForUpdate.
 */
class WholesaleCollectionService
{
    /**
     * تسجيل تحصيل على فاتورة.
     *
     * @param array $data {
     *   amount: float,
     *   payment_method: cash|bank_transfer|amial_pay|check,
     *   collection_date?: string (YYYY-MM-DD),
     *   reference_number?: string,
     *   paid_transaction_id?: string,
     *   notes?: string,
     * }
     */
    public function recordCollection(
        User $receiver,
        WholesaleInvoice $invoice,
        array $data,
    ): WholesaleCollection {
        if (!isset($data['amount']) || (float)$data['amount'] <= 0) {
            throw new InvalidArgumentException('المبلغ يجب أن يكون موجباً');
        }
        if (!isset($data['payment_method'])
            || !in_array($data['payment_method'], WholesaleCollection::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException('طريقة دفع غير صحيحة');
        }
        $paidTransactionId = trim((string) ($data['paid_transaction_id'] ?? ''));
        if (($data['payment_method'] ?? null) === 'amial_pay' && $paidTransactionId === '') {
            throw new InvalidArgumentException('تحصيل أميال باي يحتاج مرجع دفع فعلي');
        }

        return DB::transaction(function () use ($receiver, $invoice, $data, $paidTransactionId) {
            // اقفل الفاتورة + العميل
            $inv = WholesaleInvoice::lockForUpdate()->find($invoice->id);
            $customer = WholesaleCustomer::lockForUpdate()->find($inv->customer_id);

            if ($inv->status === 'voided') {
                throw new RuntimeException('الفاتورة مُبطَلة');
            }
            if ($inv->status === 'paid') {
                throw new RuntimeException('الفاتورة مدفوعة بالكامل');
            }

            $amount = MoneyService::normalize((string)$data['amount']);

            // لا يتجاوز balance_due
            if (MoneyService::compare($amount, (string)$inv->balance_due) > 0) {
                throw new InvalidArgumentException(
                    "المبلغ يتجاوز المتبقّي (" . Helpers::money($inv->balance_due) . " ر.ي)"
                );
            }

            // لا يكفي أن يقول الموظف إن التحصيل «أميال». لا يُقبل إلا طلب
            // QR مكتمل إلى محفظة مالك التاجر وبالمبلغ نفسه، ولا يعاد استعماله
            // كتحصيل ثانٍ أو كفاتورة بيع أولى.
            if ($data['payment_method'] === 'amial_pay') {
                $paymentRequest = PaymentRequest::where('paid_transaction_id', $paidTransactionId)
                    ->where('requester_user_id', $receiver->id)
                    ->where('status', 'paid')
                    ->lockForUpdate()
                    ->first();
                if (!$paymentRequest) {
                    throw new RuntimeException('مرجع أميال باي غير صالح لهذه المحفظة أو لم يكتمل الدفع');
                }
                if (MoneyService::compare((string) $paymentRequest->amount, $amount) !== 0) {
                    throw new RuntimeException('مبلغ تحصيل أميال باي لا يطابق مبلغ التسوية');
                }
                if (WholesaleInvoice::where('paid_transaction_id', $paidTransactionId)->exists()
                    || WholesaleCollection::where('paid_transaction_id', $paidTransactionId)->exists()) {
                    throw new RuntimeException('تم ربط حركة أميال باي هذه بتحصيل أو فاتورة مسبقاً');
                }
            }

            // أنشئ التحصيل
            // $receiver هو مالك المحفظة/المنشأة. أمّا الفاعل في الأثر فهو
            // الموظف أو المالك الذي نفّذ التحصيل، ويُحقن من المتحكم بعد
            // المصادقة ولا يأتي من التطبيق كحقل يمكن تزويره.
            $receivedByUserId = (int) ($data['received_by_user_id'] ?? $receiver->id);
            $collection = WholesaleCollection::create([
                'collection_ulid' => (string) Str::ulid(),
                'invoice_id' => $inv->id,
                'customer_id' => $customer->id,
                'business_id' => $inv->business_id,
                'received_by_user_id' => $receivedByUserId,
                'collection_date' => $data['collection_date'] ?? now()->toDateString(),
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'paid_transaction_id' => $data['payment_method'] === 'amial_pay' ? $paidTransactionId : null,
                'notes' => $data['notes'] ?? null,
            ]);

            // حدّث الفاتورة
            $newPaid = MoneyService::add((string)$inv->paid_amount, $amount);
            $newBalance = MoneyService::sub((string)$inv->balance_due, $amount);
            $newStatus = MoneyService::compare($newBalance, '0') <= 0
                ? 'paid'
                : 'partial_paid';

            $inv->update([
                'paid_amount' => $newPaid,
                'balance_due' => $newBalance,
                'status' => $newStatus,
            ]);

            // حدّث رصيد العميل
            $customer->update([
                'current_balance' => MoneyService::sub((string)$customer->current_balance, $amount),
            ]);

            if (!empty($collection->paid_transaction_id)) {
                app(ReceiptService::class)->attachBusinessReference(
                    (string) $collection->paid_transaction_id,
                    'wholesale_invoice',
                    (int) $inv->id,
                    [
                        'merchant_vertical' => 'wholesale',
                        'invoice_number' => $inv->invoice_number,
                        'collection_id' => $collection->id,
                    ],
                );
            }

            return $collection->fresh(['invoice', 'customer']);
        });
    }

    /** إبطال تحصيل (إن دُفع بالخطأ). */
    public function voidCollection(WholesaleCollection $collection, string $reason): WholesaleCollection
    {
        return DB::transaction(function () use ($collection, $reason) {
            $inv = WholesaleInvoice::lockForUpdate()->find($collection->invoice_id);
            $customer = WholesaleCustomer::lockForUpdate()->find($collection->customer_id);

            // أعد المبلغ للفاتورة
            $newPaid = MoneyService::sub((string)$inv->paid_amount, (string)$collection->amount);
            $newBalance = MoneyService::add((string)$inv->balance_due, (string)$collection->amount);
            $newStatus = MoneyService::compare($newPaid, '0') <= 0
                ? 'issued'
                : 'partial_paid';

            $inv->update([
                'paid_amount' => $newPaid,
                'balance_due' => $newBalance,
                'status' => $newStatus,
            ]);

            // أعد لرصيد العميل
            $customer->update([
                'current_balance' => MoneyService::add(
                    (string)$customer->current_balance, (string)$collection->amount,
                ),
            ]);

            // حذف التحصيل
            $collection->delete();
            return $collection;
        });
    }
}
