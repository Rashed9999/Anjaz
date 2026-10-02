<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\PosUser;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantSalesDirectoryV2Test extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function owner_sales_directory_filters_on_the_server_and_preserves_sector_detail_reference(): void
    {
        $owner = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $owner->id, 'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE, 'verification_status' => 'verified',
        ]);
        Merchant::create([
            'user_id' => $owner->id, 'merchant_number' => 'SALES-V2-001',
            'store_name' => 'متجر مبيعات V2',
        ]);

        $staffUser = User::factory()->create(['type' => 4, 'role' => 'pos', 'is_active' => 1]);
        $pos = PosUser::create([
            'user_id' => $staffUser->id, 'merchant_user_id' => $owner->id,
            'pos_number' => 'SALE-01', 'display_name' => 'كاشير سجل', 'is_active' => true,
        ]);

        $cash = MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(),
            'invoice_number' => 'INV-V2-CASH',
            'merchant_user_id' => $owner->id,
            'pos_user_id' => $pos->id,
            'customer_name' => 'عميل البحث',
            'total_amount' => '2500',
            'payment_method' => 'cash',
            'status' => 'completed',
            'items' => [],
            'zone_code' => 'SOUTH',
        ]);

        MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(),
            'invoice_number' => 'INV-V2-WALLET',
            'merchant_user_id' => $owner->id,
            'pos_user_id' => $pos->id,
            'total_amount' => '4000',
            'payment_method' => 'amial_pay',
            'status' => 'completed',
            'items' => [],
            'zone_code' => 'SOUTH',
        ]);

        MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $owner->id,
            'pos_user_id' => $pos->id,
            'total_amount' => '9000',
            'payment_method' => 'cash',
            'status' => 'pending_payment',
            'items' => [],
            'zone_code' => 'SOUTH',
        ]);

        $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/sales-v2?payment_method=cash&employee_id='.$pos->id.'&search=عميل')
            ->assertOk()
            ->assertJsonPath('meta.source', 'merchant_sales')
            ->assertJsonPath('meta.summary.count', 1)
            ->assertJsonPath('meta.summary.total', '2500.0000')
            ->assertJsonPath('meta.rows.0.reference', $cash->sale_ulid)
            ->assertJsonPath('meta.rows.0.detail_id', $cash->sale_ulid)
            ->assertJsonPath('meta.rows.0.employee_name', 'كاشير سجل')
            ->assertJsonPath('meta.rows.0.customer_name', 'عميل البحث')
            ->assertJsonPath('meta.pagination.total', 1);
    }
}
