<?php

namespace App\Services;

use App\Models\CustomerCreditAccount;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-CUSTOMER-CREDIT-SETTLE-001 — سداد العميل لدَينه الآجل من محفظته.
 *
 * العميل يسدّد كامل الدَّين أو جزءاً منه لدى تاجر مسجّل عبر أميال باي:
 * يتحرّك المال من محفظة العميل إلى محفظة التاجر (قيود مزدوجة)، ثم يُخفَّض
 * رصيد الآجل في دفتر الائتمان، ويُشعَر التاجر بالسداد.
 */
class CustomerCreditSettleService
{
    use TransactionTrait;

    public function __construct(
        private readonly CustomerCreditService $credit,
    ) {}

    /**
     * @return array{new_balance:string, paid:string, transaction_no:?string}
     */
    public function settle(User $customer, CustomerCreditAccount $account, string $amount): array
    {
        if ($account->customer_user_id !== $customer->id) {
            throw new InvalidArgumentException('هذا الحساب لا يخصّك');
        }

        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ السداد يجب أن يكون موجباً');
        }

        $balance = (string) $account->current_balance;
        if (MoneyService::gt($amount, $balance)) {
            throw new InvalidArgumentException('المبلغ أكبر من الدَّين المستحقّ');
        }

        $merchantId = $account->merchant_user_id;

        return DB::transaction(function () use ($customer, $account, $merchantId, $amount) {
            // 1) حرّك المال: خصم من العميل، إضافة للتاجر (يرمي عند نقص الرصيد)
            $this->guard()->lockWalletsOrdered([$customer->id, $merchantId]);
            $this->guard()->debit($customer->id, $amount, "credit_settle:{$account->id}");
            $this->guard()->credit($merchantId, $amount, "credit_settle:{$account->id}");

            // 2) سجّل حركة السداد في دفتر الائتمان (تُخفّض الرصيد وتُشعر التاجر)
            $movement = $this->credit->recordPayment(
                account: $account,
                amount: $amount,
                note: 'سداد عبر أميال باي',
                createdBy: $customer->id,
                referenceType: 'wallet_settle',
                referenceId: (string) $customer->id,
            );

            if (!$movement) {
                throw new RuntimeException('تعذّر تسجيل السداد');
            }

            return [
                'new_balance' => (string) $account->fresh()->current_balance,
                'paid' => $amount,
                'transaction_no' => null, // حركة محفظة مباشرة (بلا رقم عملية دفتر العام)
            ];
        });
    }
}
