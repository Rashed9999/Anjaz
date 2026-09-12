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
    public function allocate(
        CustomerCreditAccount $account,
        string $amount,
        ?string $saleMovementUlid = null,
    ): array
    {
        $open = $this->openEntries($account);

        $allocations = [];
        foreach ($this->consume($open, $amount, $saleMovementUlid) as $portion) {
            $allocations[] = [
                'sale_movement_ulid' => $portion['movement_ulid'],
                'reference_type' => $portion['reference_type'],
                'reference_id' => $portion['reference_id'],
                'amount' => $portion['amount'],
                'fully_settled' => $portion['fully_settled'],
            ];
        }

        return $allocations;
    }

    /**
     * الفواتير الآجلة المفتوحة كما هي الآن، لا كما كانت عند إنشائها.
     *
     * القيد هو دفتر الحقيقة الوحيد. نعيد لعب القيود لنحسب المتبقي لكل
     * فاتورة؛ فلا ننشئ جدول "فواتير مؤجلة" موازياً قد يختلف عن الرصيد.
     * السدادات القديمة التي لم تحدد فاتورة تبقى FIFO، والسداد الجديد الذي
     * يحدده العميل يحمل ULID الفاتورة في مرجعه ويخصم منها تحديداً.
     *
     * @return array<int,array<string,mixed>>
     */
    public function openInvoices(CustomerCreditAccount $account): array
    {
        return array_values(array_filter($this->openEntries($account),
            fn (array $entry) => MoneyService::isPositive($entry['remaining'])));
    }

    /** @return array<int,array<string,mixed>> */
    private function openEntries(CustomerCreditAccount $account): array
    {
        $open = [];
        $movements = CustomerCreditMovement::where('account_id', $account->id)
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            $value = (string) $movement->amount;
            if (str_starts_with($value, '-')) {
                // السداد الذي اختار العميل له فاتورة لا يُحوّل بصمت إلى
                // أقدم فاتورة. القيود القديمة تبقى FIFO للتوافق التاريخي.
                $target = $movement->reference_type === 'credit_sale_payment'
                    ? (string) $movement->reference_id : null;
                $this->consume($open, ltrim($value, '-'), $target ?: null);
                continue;
            }

            if ($movement->type !== 'sale') {
                continue;
            }

            $open[] = [
                'movement_id' => $movement->id,
                'movement_ulid' => $movement->movement_ulid,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'reference_number' => $movement->reference_number,
                'due_date' => $movement->due_date?->toDateString(),
                'note' => $movement->note,
                'issued_at' => $movement->created_at?->toIso8601String(),
                'original_amount' => MoneyService::normalize($value),
                'remaining' => MoneyService::normalize($value),
            ];
        }

        return $open;
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
    private function consume(array &$open, string $amount, ?string $saleMovementUlid = null): array
    {
        $remaining = MoneyService::normalize($amount);
        $portions = [];

        // اختار العميل فاتورة بعينها: لا يُعاد ترتيب دينه من وراء ظهره.
        if ($saleMovementUlid !== null) {
            foreach ($open as $index => &$entry) {
                if (($entry['movement_ulid'] ?? null) !== $saleMovementUlid) {
                    continue;
                }
                if (MoneyService::compare($remaining, $entry['remaining']) > 0) {
                    throw new RuntimeException('مبلغ السداد أكبر من المتبقي في الفاتورة المختارة');
                }
                $entry['remaining'] = MoneyService::sub($entry['remaining'], $remaining);
                $portions[] = [
                    'movement_ulid' => $entry['movement_ulid'],
                    'reference_type' => $entry['reference_type'],
                    'reference_id' => $entry['reference_id'],
                    'amount' => $remaining,
                    'fully_settled' => MoneyService::compare($entry['remaining'], '0') <= 0,
                ];
                if (!MoneyService::isPositive($entry['remaining'])) {
                    unset($open[$index]);
                }
                unset($entry);
                return $portions;
            }
            unset($entry);
            throw new RuntimeException('الفاتورة المختارة غير مستحقة أو لا تخص هذا الحساب');
        }

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
                'movement_ulid' => $entry['movement_ulid'],
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
