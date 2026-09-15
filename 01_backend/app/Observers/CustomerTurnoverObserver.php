<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\CustomerTurnoverService;

/**
 * AMIAL-PROGRESSIVE-KYC-TURNOVER-003
 *
 * كل Transaction ناجحة لعميل فرد تسجل أصل المبلغ فقط. التنفيذ يمر من
 * CustomerTurnoverService ليحصل على idempotency والحارس الذري نفسه الذي
 * تستخدمه الخدمات المتخصصة التي لا تكتب Transaction عامة.
 */
class CustomerTurnoverObserver
{
    public function created(Transaction $transaction): void
    {
        $amount = (string) ($transaction->amount ?? '0');
        if (bccomp($amount, '0', 4) <= 0) return;

        $debit = (string) ($transaction->debit ?? '0');
        $credit = (string) ($transaction->credit ?? '0');
        $direction = bccomp($debit, '0', 4) > 0 ? 'out'
            : (bccomp($credit, '0', 4) > 0 ? 'in' : null);
        if ($direction === null) return;

        app(CustomerTurnoverService::class)->recordPosted(
            user: (int) $transaction->user_id,
            amount: $amount,
            direction: $direction,
            sourceKey: 'transaction:' . (int) $transaction->id,
            transactionRowId: (int) $transaction->id,
            transactionId: (string) $transaction->transaction_id,
            transactionType: (string) $transaction->transaction_type,
            occurredAt: $transaction->created_at ?? now(),
        );
    }
}
