<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\MerchantInvoiceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantInvoiceNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $vertical): User
    {
        $merchant = User::factory()->create(['type' => MERCHANT_TYPE]);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => $vertical,
            'verification_status' => 'verified',
        ]);

        return $merchant;
    }

    /** @test */
    public function invoice_numbers_are_ordered_per_merchant_sector_and_year(): void
    {
        $merchant = $this->merchant('retail');
        $numbers = app(MerchantInvoiceNumberService::class);

        $first = $numbers->nextForMerchant($merchant);
        $second = $numbers->nextForMerchant($merchant);

        $prefix = 'AMY-RT-' . now()->format('Y') . '-';
        $this->assertSame($prefix . '000001', $first);
        $this->assertSame($prefix . '000002', $second);
    }

    /** @test */
    public function every_merchant_sector_has_a_stable_public_invoice_series(): void
    {
        $numbers = app(MerchantInvoiceNumberService::class);

        foreach ([
            'quick_sale' => 'QS',
            'retail' => 'RT',
            'fuel' => 'FU',
            'pharmacy' => 'PH',
            'restaurant' => 'RS',
            'wholesale' => 'WH',
        ] as $vertical => $series) {
            $invoice = $numbers->nextForMerchant($this->merchant($vertical));

            $this->assertSame(
                sprintf('AMY-%s-%s-000001', $series, now()->format('Y')),
                $invoice,
            );
        }
    }
}
