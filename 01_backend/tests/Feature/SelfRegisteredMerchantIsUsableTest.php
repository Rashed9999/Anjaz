<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A merchant cannot self-create in Amial Pay. It must open a Muwathaq case
 * and be provisioned only after Muwathaq's final signed decision.
 */
class SelfRegisteredMerchantIsUsableTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function legacy_merchant_registration_creates_nothing_in_amial_pay(): void
    {
        $phone = '777444999';

        $this->postJson('/api/v1/customer/auth/register', [
            'account_type' => 'merchant',
            'f_name' => 'تاجر',
            'l_name' => 'اختبار',
            'phone' => $phone,
            'email' => 'merchant-' . $phone . '@example.test',
            'password' => 'not-used-by-amial',
            'store_name' => 'متجر يجب ألا ينشأ هنا',
            'business_type' => 'retail',
        ])->assertStatus(410)
          ->assertJsonPath('code', 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ');

        $this->assertDatabaseMissing('users', ['phone' => $phone]);
        $this->assertSame(0, MerchantProfile::count());
    }
}
