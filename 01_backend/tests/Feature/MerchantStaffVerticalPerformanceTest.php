<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PharmacySale;
use App\Models\PosUser;
use App\Models\User;
use App\Services\PharmacyService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantStaffVerticalPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function pharmacy_owner_staff_performance_reads_pharmacy_sales_not_merchant_sales(): void
    {
        $merchant = User::factory()->create([
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_PHARMACY,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $staffA = User::factory()->create(['type' => 4, 'role' => 'pos', 'is_active' => 1]);
        $staffB = User::factory()->create(['type' => 4, 'role' => 'pos', 'is_active' => 1]);
        $posA = PosUser::create([
            'user_id' => $staffA->id, 'merchant_user_id' => $merchant->id,
            'pos_number' => 'PH-A', 'display_name' => 'صيدلي أ', 'is_active' => true,
        ]);
        $posB = PosUser::create([
            'user_id' => $staffB->id, 'merchant_user_id' => $merchant->id,
            'pos_number' => 'PH-B', 'display_name' => 'صيدلي ب', 'is_active' => true,
        ]);

        $pharmacy = app(PharmacyService::class)->getOrCreatePharmacy($merchant, ['name' => 'صيدلية الأداء']);

        foreach ([[$posA, $staffA, '700'], [$posB, $staffB, '300']] as [$pos, $user, $amount]) {
            PharmacySale::create([
                'sale_ulid' => (string) Str::ulid(),
                'merchant_user_id' => $merchant->id,
                'pos_user_id' => $pos->id,
                'created_by_user_id' => $user->id,
                'pharmacy_id' => $pharmacy->id,
                'subtotal' => $amount,
                'discount_amount' => '0',
                'total_amount' => $amount,
                'payment_method' => 'cash',
                'status' => 'completed',
                'zone_code' => 'SOUTH',
            ]);
        }

        $this->actingAs($merchant, 'merchant_web')
            ->getJson('/merchant/data/staff-performance?days=7')
            ->assertOk()
            ->assertJsonPath('meta.source', 'pharmacy_sales')
            ->assertJsonPath('meta.grand_total', '1000.0000')
            ->assertJsonPath('meta.unattributed_total', '0.0000')
            ->assertJsonPath('meta.staff.0.sales_total', '700.0000')
            ->assertJsonPath('meta.staff.1.sales_total', '300.0000');
    }
}
