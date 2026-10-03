<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\Pharmacy;
use App\Models\PharmacyBatch;
use App\Models\PharmacyProduct;
use App\Models\User;
use App\Services\Merchant\MerchantSectorDashboardService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantSectorDashboardV2Test extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function pharmacy_dashboard_exposes_real_stock_and_expiry_alerts(): void
    {
        $owner = User::factory()->create([
            'role' => A::ROLE_MERCHANT,
            'type' => MERCHANT_TYPE,
            'is_active' => 1,
        ]);

        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => A::BIZ_PHARMACY,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $pharmacy = Pharmacy::create([
            'merchant_user_id' => $owner->id,
            'pharmacy_name' => 'صيدلية V2',
            'is_active' => true,
        ]);

        $low = PharmacyProduct::create([
            'pharmacy_id' => $pharmacy->id,
            'trade_name' => 'دواء منخفض',
            'sale_price' => '500',
            'current_stock' => '2',
            'low_stock_threshold' => 5,
            'is_active' => true,
        ]);

        $expired = PharmacyProduct::create([
            'pharmacy_id' => $pharmacy->id,
            'trade_name' => 'دواء منتهي',
            'sale_price' => '700',
            'current_stock' => '3',
            'low_stock_threshold' => 1,
            'is_active' => true,
        ]);

        PharmacyBatch::create([
            'batch_ulid' => (string) IlluminateSupportStr::ulid(),
            'product_id' => $low->id,
            'batch_number' => 'NEAR-001',
            'expiry_date' => now()->addDays(12)->toDateString(),
            'received_date' => now()->subMonth()->toDateString(),
            'quantity_received' => '2',
            'quantity_remaining' => '2',
            'status' => 'active',
        ]);

        PharmacyBatch::create([
            'batch_ulid' => (string) IlluminateSupportStr::ulid(),
            'product_id' => $expired->id,
            'batch_number' => 'EXP-001',
            'expiry_date' => now()->subDay()->toDateString(),
            'received_date' => now()->subMonths(2)->toDateString(),
            'quantity_received' => '3',
            'quantity_remaining' => '3',
            'status' => 'active',
        ]);

        $data = app(MerchantSectorDashboardService::class)->build($owner, 14);

        $this->assertSame('pharmacy', $data['kind']);
        $cards = collect($data['cards'])->keyBy('code');
        $this->assertSame(1, $cards['low_stock']['value']);
        $this->assertSame(0, $cards['out_of_stock']['value']);
        $this->assertSame(1, $cards['near_expiry']['value']);
        $this->assertSame(1, $cards['expired_batches']['value']);
        $this->assertCount(2, $data['lists']['expiring_batches']);
    }
}
