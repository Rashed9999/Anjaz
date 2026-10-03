<?php

namespace App\Observers;

use App\Models\Donation;
use App\Services\CustomerTurnoverService;

/** التبرع النهائي يستهلك أصل التبرع، والاسترداد يحرر استخدامه. */
class DonationTurnoverObserver
{
    private function key(Donation $donation): string
    {
        return 'donation:' . (string) $donation->donation_ulid;
    }

    public function created(Donation $donation): void
    {
        if (!in_array($donation->status, ['completed', 'settled'], true)) return;

        app(CustomerTurnoverService::class)->recordPosted(
            user: (int) $donation->donor_user_id,
            amount: (string) $donation->amount,
            direction: 'out',
            sourceKey: $this->key($donation),
            transactionType: 'donation',
            occurredAt: $donation->donated_at ?? $donation->created_at ?? now(),
        );
    }

    public function updated(Donation $donation): void
    {
        if (!$donation->wasChanged('status')) return;

        if ($donation->status === 'refunded') {
            app(CustomerTurnoverService::class)->release(
                $this->key($donation),
                'donation refunded',
            );
        }
    }
}
