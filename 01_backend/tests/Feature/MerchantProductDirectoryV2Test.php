<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MerchantProduct;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantProductDirectoryV2Test extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create([
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        return $owner;
    }

    /** @test */
    public function product_directory_is_server_paginated_and_reports_stock_summary(): void
    {
        $owner = $this->owner();

        for ($i = 1; $i <= 31; $i++) {
            MerchantProduct::create([
                'merchant_user_id' => $owner->id,
                'name' => sprintf('منتج %02d', $i),
                'price' => '500',
                'cost_price' => '300',
                'quantity' => $i === 1 ? '0' : ($i === 2 ? '2' : '20'),
                'reorder_level' => '5',
                'track_stock' => true,
                'is_active' => $i !== 31,
                'sku' => sprintf('SKU-%02d', $i),
            ]);
        }

        $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/products-v2?status=all')
            ->assertOk()
            ->assertJsonPath('meta.source', 'merchant_products')
            ->assertJsonPath('meta.summary.total', 31)
            ->assertJsonPath('meta.summary.active', 30)
            ->assertJsonPath('meta.summary.inactive', 1)
            ->assertJsonPath('meta.summary.low_stock', 2)
            ->assertJsonPath('meta.summary.out_of_stock', 1)
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonCount(25, 'meta.rows');

        $this->getJson('/merchant/data/products-v2?status=active&low_stock_only=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonCount(2, 'meta.rows');

        $this->getJson('/merchant/data/products-v2?status=all&search=SKU-31')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.rows.0.is_active', false);
    }
}
