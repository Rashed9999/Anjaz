<?php

namespace App\Observers;

use App\Models\WithdrawalRequest;
use App\Services\CustomerTurnoverService;

/** السحب النقدي يحجز أصل مبلغ السحب فقط؛ الرسم لا يستهلك حد KYC. */
class WithdrawalTurnoverObserver
{
    private function key(WithdrawalRequest $request): string
    {
        return 'cash-out:' . (string) $request->op_code;
    }

    public function created(WithdrawalRequest $request): void
    {
        if ($request->status !== 'pending') return;

        app(CustomerTurnoverService::class)->reserve(
            user: (int) $request->customer_user_id,
            amount: (string) $request->amount,
            direction: 'out',
            sourceKey: $this->key($request),
            transactionType: 'cash_out',
            occurredAt: $request->created_at ?? now(),
        );
    }

    public function updated(WithdrawalRequest $request): void
    {
        if (!$request->wasChanged('status')) return;

        $turnover = app(CustomerTurnoverService::class);
        if ($request->status === 'completed') {
            $turnover->finalize($this->key($request));
        } elseif (in_array($request->status, ['cancelled', 'expired'], true)) {
            $turnover->release($this->key($request), 'cash-out cancelled/expired');
        }
    }
}
