<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\PosUser;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MerchantStaffPerformanceTruthTest extends TestCase
{
    use RefreshDatabase;

    private function sale(
        User $merchant,
        ?int $posUserId,
        string $amount,
        string $status,
    ): void {
        MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'pos_user_id' => $posUserId,
            'total_amount' => $amount,
            'payment_method' => $status === 'credit_unpaid' ? 'credit' : 'cash',
            'status' => $status,
            'items' => [],
            'zone_code' => 'SOUTH',
        ]);
    }

    /** @test */
    public function employee_performance_excludes_qr_sales_that_have_not_been_paid(): void
    {
        $merchant = User::factory()->create([
            'type' => 3,
            'role' => A::ROLE_MERCHANT,
            'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        $staffUser = User::factory()->create(['type' => 4, 'role' => 'pos']);
        $pos = PosUser::create([
            'user_id' => $staffUser->id,
            'merchant_user_id' => $merchant->id,
            'pos_number' => 'PERF-01',
            'display_name' => 'كاشير الأداء',
            'is_active' => true,
        ]);

        $this->sale($merchant, $pos->id, '100', 'completed');
        $this->sale($merchant, $pos->id, '200', 'credit_unpaid');
        $this->sale($merchant, $pos->id, '900', 'pending_payment');
        $this->sale($merchant, null, '50', 'completed');

        Passport::actingAs($merchant);

        $res = $this->getJson('/api/v1/amial/merchant/staff/performance?days=7');

        $res->assertOk();
        $meta = (array) $res->json('meta');
        $row = collect($meta['staff'] ?? [])->firstWhere('id', $pos->id);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['sales_count']);
        $this->assertEquals('300', $row['sales_total']);
        $this->assertEquals('50', $meta['unattributed_total']);
        $this->assertEquals('350', $meta['grand_total']);
    }
}
