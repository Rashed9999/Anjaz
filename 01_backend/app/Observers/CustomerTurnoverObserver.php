<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PROGRESSIVE-KYC-TURNOVER-002
 *
 * يسجل أصل حركة العميل الفردي فقط. الرسوم تبقى في Transaction/Ledger
 * للمحاسبة لكنها لا تستهلك حد KYC.
 *
 * الحدث يُنشأ في نفس DB transaction التي أنشأت صف Transaction؛ لذلك إذا
 * رُدّت العملية المالية يُرد هذا السجل معها ولا يبقى استهلاك وهمي.
 */
class CustomerTurnoverObserver
{
    public function created(Transaction $transaction): void
    {
        if (!Schema::hasTable('customer_turnover_usage')) {
            return;
        }

        $userId = (int) ($transaction->user_id ?? 0);
        if ($userId < 1) {
            return;
        }

        $type = User::query()->whereKey($userId)->value('type');
        if ((int) $type !== 2) {
            return; // هذا السلم يخص العميل الفردي فقط.
        }

        $amount = (string) ($transaction->amount ?? '0');
        if (bccomp($amount, '0', 4) <= 0) {
            return;
        }

        $debit = (string) ($transaction->debit ?? '0');
        $credit = (string) ($transaction->credit ?? '0');
        $direction = bccomp($debit, '0', 4) > 0 ? 'out'
            : (bccomp($credit, '0', 4) > 0 ? 'in' : null);

        if ($direction === null) {
            return;
        }

        DB::table('customer_turnover_usage')->insertOrIgnore([
            'user_id' => $userId,
            'transaction_row_id' => (int) $transaction->id,
            'transaction_id' => $transaction->transaction_id,
            'transaction_type' => $transaction->transaction_type,
            'direction' => $direction,
            'principal_amount' => $amount,
            'occurred_at' => $transaction->created_at ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
