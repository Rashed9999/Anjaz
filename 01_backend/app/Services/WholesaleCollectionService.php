<?php

namespace App\Services;

use App\CentralLogics\Helpers;

use App\Models\User;
use App\Models\CustomerCreditAccount;
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
    public function __construct(
        private readonly MerchantPaymentReferenceService $paymentReference,
    ) {}

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
                $this->paymentReference->assertPaidForMerchant(
                    $receiver, $paidTransactionId, $amount,
                );
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

            // تحصيل موظف الجملة (نقداً أو عبر QR) يجب أن يخفض أيضاً الدفتر
            // الموحد الذي يراه العميل، وإلا ظهرت له فاتورة مدفوعة كأنها دين.
            $this->recordUnifiedCreditPaymentIfLinked(
                $receiver, $customer, $amount, $collection, $receivedByUserId,
            );

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

            // دفتر الآجل append-only؛ لا نحذف سداداً ظهر للعميل، بل نسجل
            // إعادة الدين بسبب إبطال التحصيل مع مرجع واضح في الكشف.
            $this->restoreUnifiedCreditIfLinked($inv, $customer, $collection, $reason);

            // حذف التحصيل
            $collection->delete();
            return $collection;
        });
    }

    private function recordUnifiedCreditPaymentIfLinked(
        User $merchant,
        WholesaleCustomer $customer,
        string $amount,
        WholesaleCollection $collection,
        ?int $receivedByUserId,
    ): void {
        $account = $this->findUnifiedAccount($merchant->id, (string) $customer->phone);
        if (!$account) {
            return;
        }

        app(CustomerCreditService::class)->recordPayment(
            account: $account,
            amount: $amount,
            note: 'تحصيل فاتورة جملة ' . ($collection->invoice?->invoice_number ?? ''),
            createdBy: $receivedByUserId ?? $merchant->id,
            referenceType: 'wholesale_collection',
            referenceId: $collection->collection_ulid,
        );
    }

    private function restoreUnifiedCreditIfLinked(
        WholesaleInvoice $invoice,
        WholesaleCustomer $customer,
        WholesaleCollection $collection,
        string $reason,
    ): void {
        $merchantId = (int) \App\Models\WholesaleBusiness::where('id', $invoice->business_id)
            ->value('merchant_user_id');
        $account = $this->findUnifiedAccount($merchantId, (string) $customer->phone);
        if (!$account) {
            return;
        }

        app(CustomerCreditService::class)->recordSale(
            account: $account,
            amount: (string) $collection->amount,
            note: 'إبطال تحصيل جملة: ' . $reason,
            referenceType: 'wholesale_collection_void',
            referenceId: $collection->collection_ulid,
            referenceNumber: $invoice->invoice_number,
        );
    }

    private function findUnifiedAccount(int $merchantId, string $phone): ?CustomerCreditAccount
    {
        if (trim($phone) === '') {
            return null;
        }

        return CustomerCreditAccount::where('merchant_user_id', $merchantId)
            ->whereIn('customer_phone', \App\Support\Phone::variants($phone))
            ->first();
    }
}
