<?php

namespace App\Services;

use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditMovement;
use App\Models\MerchantSale;
use App\Models\User;
use App\Models\WholesaleCollection;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-CREDIT-SOURCES-001
 *
 * حساب العميل هو الدفتر الموحّد، لكن فاتورة الجملة تحتاج أن ترى سدادها
 * أيضاً. يوزّع السداد الوارد من تطبيق العميل FIFO على حركات البيع، ثم
 * يعكس فقط المصادر التي لها حالة تفصيلية مستقلة.
 */
class CreditSourceSettlementService
{
    /**
     * يعيد توزيع مبلغ جديد على أقدم بنود الدين المفتوحة قبل نشر حركة السداد.
     * لا يكتب شيئاً هنا؛ لتبقى العملية الذرية تحت معاملة السداد الأم.
     *
     * @return array<int, array{reference_type:?string,reference_id:?string,amount:string,fully_settled:bool}>
     */
    public function allocate(CustomerCreditAccount $account, string $amount): array
    {
        $open = [];
        $movements = CustomerCreditMovement::where('account_id', $account->id)
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            $value = (string) $movement->amount;
            if (str_starts_with($value, '-')) {
                $this->consume($open, ltrim($value, '-'));
                continue;
            }

            $open[] = [
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'remaining' => MoneyService::normalize($value),
            ];
        }

        $allocations = [];
        foreach ($this->consume($open, $amount) as $portion) {
            $allocations[] = [
                'reference_type' => $portion['reference_type'],
                'reference_id' => $portion['reference_id'],
                'amount' => $portion['amount'],
                'fully_settled' => $portion['fully_settled'],
            ];
        }

        return $allocations;
    }

    /** يطبّق الأثر على مصادر التفاصيل بعد أن نجح قيد السداد والمحفظة. */
    public function apply(array $allocations, CustomerCreditMovement $payment, User $customer): void
    {
        foreach ($allocations as $allocation) {
            if (($allocation['reference_type'] ?? null) === 'wholesale_invoice') {
                $this->settleWholesaleInvoice($allocation, $payment, $customer);
            }

            if (($allocation['reference_type'] ?? null) === 'merchant_sale'
                && ($allocation['fully_settled'] ?? false)) {
                MerchantSale::where('sale_ulid', $allocation['reference_id'])
                    ->where('payment_method', 'credit')
                    ->where('status', 'credit_unpaid')
                    ->update(['status' => 'credit_paid', 'settled_at' => now()]);
            }
        }
    }

    private function settleWholesaleInvoice(array $allocation, CustomerCreditMovement $payment, User $customer): void
    {
        $invoice = WholesaleInvoice::where('invoice_ulid', $allocation['reference_id'])
            ->lockForUpdate()
            ->first();
        if (!$invoice || $invoice->status === 'voided') {
            return;
        }

        $amount = $allocation['amount'];
        if (MoneyService::compare($amount, (string) $invoice->balance_due) > 0) {
            throw new RuntimeException('تعارض في رصيد فاتورة الجملة أثناء السداد');
        }

        $wholesaleCustomer = WholesaleCustomer::lockForUpdate()->find($invoice->customer_id);
        if (!$wholesaleCustomer) {
            throw new RuntimeException('عميل الجملة غير موجود أثناء السداد');
        }

        $newPaid = MoneyService::add((string) $invoice->paid_amount, $amount);
        $newBalance = MoneyService::sub((string) $invoice->balance_due, $amount);
        $invoice->update([
            'paid_amount' => $newPaid,
            'balance_due' => $newBalance,
            'status' => MoneyService::compare($newBalance, '0') <= 0 ? 'paid' : 'partial_paid',
        ]);
        $wholesaleCustomer->update([
            'current_balance' => MoneyService::sub((string) $wholesaleCustomer->current_balance, $amount),
        ]);

        WholesaleCollection::create([
            'collection_ulid' => (string) Str::ulid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $wholesaleCustomer->id,
            'business_id' => $invoice->business_id,
            'received_by_user_id' => $customer->id,
            'collection_date' => now()->toDateString(),
            'amount' => $amount,
            'payment_method' => 'customer_wallet',
            'reference_number' => 'credit-settle:' . $payment->movement_ulid,
            'notes' => 'سداد العميل من تطبيق أميال باي',
        ]);
    }

    /**
     * يستهلك مبلغاً من قائمة بنود مفتوحة FIFO ويعيد حصص الاستهلاك.
     *
     * @param array<int, array{reference_type:?string,reference_id:?string,remaining:string}> $open
     * @return array<int, array{reference_type:?string,reference_id:?string,amount:string,fully_settled:bool}>
     */
    private function consume(array &$open, string $amount): array
    {
        $remaining = MoneyService::normalize($amount);
        $portions = [];

        foreach ($open as $index => &$entry) {
            if (!MoneyService::isPositive($remaining)) {
                break;
            }
            if (!MoneyService::isPositive($entry['remaining'])) {
                continue;
            }

            $portion = MoneyService::compare($entry['remaining'], $remaining) <= 0
                ? $entry['remaining'] : $remaining;
            $entry['remaining'] = MoneyService::sub($entry['remaining'], $portion);
            $remaining = MoneyService::sub($remaining, $portion);
            $portions[] = [
                'reference_type' => $entry['reference_type'],
                'reference_id' => $entry['reference_id'],
                'amount' => $portion,
                'fully_settled' => MoneyService::compare($entry['remaining'], '0') <= 0,
            ];
            if (!MoneyService::isPositive($entry['remaining'])) {
                unset($open[$index]);
            }
        }
        unset($entry);

        return $portions;
    }
}
