<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * حارس قطاع الصيدلية: لا يكفي إخفاء الشاشة في Flutter؛ API نفسه يجب أن
 * يرفض تاجر قطاع آخر وكذلك جهاز POS التابع له.
 */
class PharmacyVerticalBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_non_pharmacy_merchant_and_its_pos_cannot_open_pharmacy_api(): void
    {
        $retailMerchant = User::factory()->create([
            'type' => 3,
            'role' => A::ROLE_MERCHANT,
        ]);
        MerchantProfile::create([
            'user_id' => $retailMerchant->id,
            'business_type' => A::BIZ_RETAIL,
            'business_name' => 'متجر تجزئة الاختبار',
            'verification_status' => 'verified',
        ]);

        $posAccount = User::factory()->create(['type' => 3, 'role' => 'merchant_staff']);
        PosUser::create([
            'user_id' => $posAccount->id,
            'merchant_user_id' => $retailMerchant->id,
            'pos_number' => 'RETAIL-POS-01',
            'display_name' => 'كاشير التجزئة',
            'is_active' => true,
        ]);

        Passport::actingAs($retailMerchant);
        $this->getJson('/api/v1/amial/merchant/pharmacy')
            ->assertForbidden()
            ->assertJsonPath('code', 'PHARMACY_ONLY');

        Passport::actingAs($posAccount);
        $this->getJson('/api/v1/amial/merchant/pharmacy')
            ->assertForbidden()
            ->assertJsonPath('code', 'PHARMACY_ONLY');
    }
}
