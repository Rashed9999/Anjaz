<?php

namespace App\Observers;

use App\Models\PendingTransfer;
use App\Models\Transaction;
use App\Models\WithdrawalRequest;
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
        // هذان المساران يحجزان الأصل أولاً ثم يثبتانه عند التسوية. إن عُدّت
        // صفوف Transaction هنا أيضاً صار أصل مبلغ واحد حركتين في الحد.
        if (PendingTransfer::query()
            ->where(function ($query) use ($transaction): void {
                $query->where('release_transaction_id', (string) $transaction->transaction_id);

                if (!empty($transaction->ref_trans_id)) {
                    $query->orWhere('release_transaction_id', (string) $transaction->ref_trans_id);
                }
            })
            ->exists()) {
            return;
        }
        if (WithdrawalRequest::query()
            ->where('transaction_id', (string) $transaction->transaction_id)
            ->exists()) {
            return;
        }

        $debit = (string) ($transaction->debit ?? '0');
        $credit = (string) ($transaction->credit ?? '0');
        $direction = bccomp($debit, '0', 4) > 0 ? 'out'
            : (bccomp($credit, '0', 4) > 0 ? 'in' : null);
        if ($direction === null) return;

        // لا نقرأ Transaction.amount هنا: في بعض المسارات التاريخية يسجل
        // ما خُصم من المحفظة (الأصل + الرسم)، بينما debit/credit هما أصل
        // الحركة في القيود الثنائية. الرسوم والعمولات لا تدخل حد KYC.
        $amount = $direction === 'out' ? $debit : $credit;
        if (bccomp($amount, '0', 4) <= 0) return;

        // الحافز/المكافأة ليس أصل مبلغ دفعه أو استلمه العميل في معاملة؛
        // إدخاله في الحد يجعل العرض يقول «الأصل فقط» ثم يحاسب شيئاً آخر.
        if (in_array((string) $transaction->transaction_type, [
            'add_money_bonus',
            'agent_commission',
            'admin_charge',
        ], true)) {
            return;
        }

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
