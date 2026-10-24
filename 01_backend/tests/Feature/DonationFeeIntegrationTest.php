<?php

namespace Tests\Feature;

use App\Models\FeeScheme;
use App\Models\User;
use App\Services\DonationsService;
use App\Services\FeeService;
use App\Support\Fees\FeeOperationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-UNIFIED-FEES-001 — التبرع لا يملك سعراً موازياً خارج محرك الرسوم.
 */
class DonationFeeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_donation_is_a_live_centrally_priced_operation(): void
    {
        $operation = FeeOperationRegistry::find('DONATION');

        $this->assertNotNull($operation);
        $this->assertTrue($operation->isLive());
        $this->assertSame(['customer'], $operation->actors);
        $this->assertSame(['receiver', 'sender'], $operation->bearers);

        $scheme = FeeScheme::create([
            'code' => 'DONATION',
            'label' => 'رسم تبرع اختباري',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'percent',
            'percent_rate' => '2.5000',
            'fixed_amount' => '0.0000',
            'min_fee' => null,
            'max_fee' => null,
            'agent_commission_percent' => '0.0000',
            'agent_commission_fixed' => '0.0000',
            'bearer' => 'receiver',
            'version' => 1,
            'is_active' => true,
            'effective_from' => now(),
        ]);

        $quote = app(FeeService::class)->calculate('DONATION', '200.0000', [
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
        ]);

        $this->assertSame('5.0000', $quote['fee']);
        $this->assertSame($scheme->id, $quote['scheme_id']);
        $this->assertSame(1, $quote['scheme_version']);

        $service = app(DonationsService::class);
        $method = (new \ReflectionClass($service))->getMethod('quoteDonationFee');
        $method->setAccessible(true);

        $donor = new User(['zone_code' => 'SOUTH']);
        $resolved = $method->invoke($service, $donor, '200.0000');

        $this->assertSame('fee_scheme', $resolved['source']);
        $this->assertSame('5.0000', $resolved['fee']);
        $this->assertSame($scheme->id, $resolved['scheme_id']);
        $this->assertSame(1, $resolved['scheme_version']);
    }

    public function test_legacy_donation_price_is_used_only_before_a_zone_scheme_exists(): void
    {
        config()->set('amial.donations.fee_percent', '1.5');

        $service = app(DonationsService::class);
        $method = (new \ReflectionClass($service))->getMethod('quoteDonationFee');
        $method->setAccessible(true);

        $resolved = $method->invoke($service, new User(['zone_code' => 'SOUTH']), '200.0000');

        $this->assertSame('legacy_config', $resolved['source']);
        $this->assertSame('3.0000', $resolved['fee']);
        $this->assertNull($resolved['scheme_id']);
    }
}
