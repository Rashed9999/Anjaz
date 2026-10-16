<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\MerchantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * يمنع عودة عيب «دفع QR صحيح لكن لوحة التاجر صفر».
 */
class MerchantDailyStatsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function paid_payment_request_is_included_in_the_merchant_wallet_daily_stats(): void
    {
        $merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        $payer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => 'pharmacy',
            'verification_status' => 'verified',
        ]);

        $ledger = app(LedgerService::class);
        $merchantWallet = $ledger->getOrCreateUserWallet($merchant->id);
        $payerWallet = $ledger->getOrCreateUserWallet($payer->id);
        $ledger->post(
            sourceType: 'payment_request',
            sourceId: 'paid-qr-test',
            description: 'دفع QR مؤكد',
            lines: [
                ['account' => $payerWallet->account_code, 'direction' => 'debit', 'amount' => '750'],
                ['account' => $merchantWallet->account_code, 'direction' => 'credit', 'amount' => '750'],
            ],
            createdByUserId: $payer->id,
            idempotencyKey: 'merchant-daily-stats-paid-qr',
            allowNegative: true,
        );

        $stats = app(MerchantService::class)->getDailyStats($merchant);

        $this->assertSame('750.0000', $stats['today_sales']);
        $this->assertSame('750.0000', $stats['today_net']);
        $this->assertSame('750.0000', $stats['current_balance']);
    }
}
