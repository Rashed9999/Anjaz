<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProvisionsAccountsFromMuwathaq;
use Tests\TestCase;

/**
 * Account creation is owned by Muwathaq. Amial's admin hub may review
 * operational data, but it must never become a second account-creation path.
 */
class AdminCreatedAccountReviewTest extends TestCase
{
    use RefreshDatabase;
    use ProvisionsAccountsFromMuwathaq;

    private function admin(): User
    {
        $admin = User::factory()->create(['type' => ADMIN_TYPE]);
        app(PlatformRoleService::class)->assign($admin, PlatformRoleService::ADMIN);

        return $admin->refresh();
    }

    /** @test */
    public function legacy_admin_creation_endpoints_are_gone_for_every_account_kind(): void
    {
        foreach (['customers', 'merchants', 'agents'] as $kind) {
            $phone = '9677718'.random_int(100000, 999999);

            $this->actingAs($this->admin(), 'user')
                ->postJson('/admin/amial/hub/'.$kind.'/users', [
                    'phone' => $phone,
                    'email' => 'legacy-'.bin2hex(random_bytes(6)).'@example.test',
                ])
                ->assertStatus(410)
                ->assertJsonPath('code', 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ');

            $this->assertDatabaseMissing('users', ['phone' => $phone]);
        }
    }

    /** @test */
    public function an_approved_muwathaq_merchant_is_operational_and_visible_to_amial_reviewers(): void
    {
        $merchant = $this->provisionMerchantFromMuwathaq('retail', '967771809001');

        $this->assertSame(MERCHANT_TYPE, (int) $merchant->type);
        $this->assertSame(1, (int) $merchant->is_active);
        $this->assertSame(1, (int) $merchant->is_kyc_verified);
        $this->assertDatabaseHas('e_money', ['user_id' => $merchant->id]);
        $this->assertSame(
            'verified',
            MerchantProfile::query()->where('user_id', $merchant->id)->value('verification_status'),
        );

        $ids = collect(
            $this->actingAs($this->admin(), 'user')
                ->getJson('/admin/amial/hub/verification/list.json?filter=verified')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($merchant->id, $ids);
    }
}
