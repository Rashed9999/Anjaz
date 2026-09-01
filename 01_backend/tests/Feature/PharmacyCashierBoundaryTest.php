<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * الصيدلية ليست نسخةً من كاشير التجزئة: بيعها يحتفظ بالتشغيلة، الصلاحية
 * والوصفة. هذا الاختبار يمنع إعادة فتح API الكاشير العام لها عرضاً.
 */
class PharmacyCashierBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function pharmacy_merchant_cannot_access_the_generic_cashier_api(): void
    {
        $merchant = User::factory()->create([
            'type' => 3,
            'role' => A::ROLE_MERCHANT,
        ]);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_PHARMACY,
            'business_name' => 'صيدلية الاختبار',
            'verification_status' => 'verified',
        ]);

        Passport::actingAs($merchant);

        $this->getJson('/api/v1/amial/merchant/cashier/products')
            ->assertForbidden()
            ->assertJsonPath('code', 'PHARMACY_CASHIER_ONLY');
    }
}
