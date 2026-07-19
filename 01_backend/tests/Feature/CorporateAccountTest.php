<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-CORPORATE-ACCOUNTS-001 — حسابات الشركات B2B: محميّة بالباقة المؤسسية،
 * تفرض حدّ الائتمان، الشراء يزيد الدَّين والسداد يقلّه، مع عزل بين التجّار.
 */
class CorporateAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create([
            'type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'business_type' => A::BIZ_FUEL,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_FREE,
        ]);
    }

    private function upgradeToEnterprise(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_ENTERPRISE, $admin);
    }

    /** @test الباقة الأدنى لا تصل لحسابات الشركات → 402. */
    public function non_enterprise_is_locked(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/corporate/accounts')->assertStatus(402);
    }

    /** @test دورة كاملة: إنشاء → شراء ضمن الحدّ → تجاوز مرفوض → سداد. */
    public function full_lifecycle_charge_limit_and_settle(): void
    {
        $this->upgradeToEnterprise();
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        // إنشاء حساب بحدّ 100000
        $create = $this->postJson('/api/v1/amial/merchant/corporate/accounts', [
            'company_name' => 'شركة النقل الوطنية',
            'contact_person' => 'أحمد',
            'credit_limit' => '100000',
        ])->assertStatus(201);
        $accId = $create->json('meta.account.id');
        $this->assertStringStartsWith('CORP-', $create->json('meta.account.account_code'));

        // إضافة عضو بحدّ عملية 30000
        $this->postJson("/api/v1/amial/merchant/corporate/accounts/{$accId}/members", [
            'member_name' => 'سيارة 1', 'identifier' => 'CAR-01', 'per_txn_limit' => '30000',
        ])->assertStatus(201);

        // شراء 60000 ضمن الحدّ
        $this->postJson("/api/v1/amial/merchant/corporate/accounts/{$accId}/charge", [
            'amount' => '60000',
        ])->assertStatus(201)
          ->assertJsonPath('meta.balance_after', '60000.0000')
          ->assertJsonPath('meta.available', '40000.0000');

        // شراء 50000 يتجاوز الحدّ (المتاح 40000) → مرفوض
        $this->postJson("/api/v1/amial/merchant/corporate/accounts/{$accId}/charge", [
            'amount' => '50000',
        ])->assertStatus(422);

        // سداد 40000 → المستحقّ 20000
        $this->postJson("/api/v1/amial/merchant/corporate/accounts/{$accId}/settle", [
            'amount' => '40000',
        ])->assertStatus(201)
          ->assertJsonPath('meta.balance_after', '20000.0000');

        // الكشف يعرض 3 حركات
        $this->getJson("/api/v1/amial/merchant/corporate/accounts/{$accId}/statement")
            ->assertOk()
            ->assertJsonPath('meta.account.current_balance', '20000.0000')
            ->assertJsonCount(2, 'meta.movements'); // شراء ناجح + سداد (المرفوض لم يُسجَّل)
    }

    /** @test حدّ العضو للعملية يُفرض. */
    public function member_per_txn_limit_is_enforced(): void
    {
        $this->upgradeToEnterprise();
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $accId = $this->postJson('/api/v1/amial/merchant/corporate/accounts', [
            'company_name' => 'ش', 'credit_limit' => '100000',
        ])->json('meta.account.id');
        $memberId = $this->postJson("/api/v1/amial/merchant/corporate/accounts/{$accId}/members", [
            'member_name' => 'سائق', 'per_txn_limit' => '5000',
        ])->json('meta.id');

        $this->postJson("/api/v1/amial/merchant/corporate/accounts/{$accId}/charge", [
            'amount' => '9000', 'member_id' => $memberId,
        ])->assertStatus(422);
    }

    /** @test تاجر لا يصل لحساب تاجر آخر. */
    public function merchant_cannot_access_others_account(): void
    {
        $this->upgradeToEnterprise();

        $other = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $other->id, 'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_ENTERPRISE, 'subscription_expires_at' => now()->addDays(30)]);
        $otherAcc = app(\App\Services\CorporateAccountService::class)
            ->createAccount($other, ['company_name' => 'ملك آخر', 'credit_limit' => '1000']);

        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson("/api/v1/amial/merchant/corporate/accounts/{$otherAcc->id}")->assertStatus(404);
    }
}
