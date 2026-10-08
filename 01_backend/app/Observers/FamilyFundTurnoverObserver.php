<?php

namespace App\Observers;

use App\Models\FamilyFundTransaction;
use App\Services\CustomerTurnoverService;

/**
 * مساهمة العضو = حركة صادرة. صرف الصندوق للمستفيد = حركة واردة للمستفيد.
 * المقترح pending لا يستهلك حد المستفيد قبل أن يصبح completed.
 */
class FamilyFundTurnoverObserver
{
    public function created(FamilyFundTransaction $tx): void
    {
        $this->syncCompleted($tx);
    }

    public function updated(FamilyFundTransaction $tx): void
    {
        if ($tx->wasChanged('status')) {
            $this->syncCompleted($tx);
        }
    }

    private function syncCompleted(FamilyFundTransaction $tx): void
    {
        if ($tx->status !== 'completed') return;

        $turnover = app(CustomerTurnoverService::class);
        if ($tx->tx_type === 'contribute') {
            $turnover->recordPosted(
                user: (int) $tx->user_id,
                amount: (string) $tx->amount,
                direction: 'out',
                sourceKey: 'family-fund:contribute:' . (string) $tx->tx_ulid,
                transactionType: 'family_fund_contribute',
                occurredAt: $tx->created_at ?? now(),
            );
        }

        if (!empty($tx->beneficiary_user_id)) {
            $turnover->recordPosted(
                user: (int) $tx->beneficiary_user_id,
                amount: (string) $tx->amount,
                direction: 'in',
                sourceKey: 'family-fund:disburse:' . (string) $tx->tx_ulid,
                transactionType: 'family_fund_disbursement',
                occurredAt: $tx->created_at ?? now(),
            );
        }
    }
}
