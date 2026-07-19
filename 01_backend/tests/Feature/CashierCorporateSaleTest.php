<?php

namespace Tests\Feature;

use App\Models\CorporateAccount;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CorporateAccountService;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-CORPORATE-ACCOUNTS-001 — بيع الكاشير على حساب شركة:
 * يُسجَّل البيع ويُحمَّل على دَين الشركة ضمن الحدّ، محميّ بالباقة المؤسسية.
 */
class CashierCorporateSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private CorporateAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create([
            'type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_FREE,
        ]);
    }

    private function enterpriseAndAccount(string $limit = '50000'): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_ENTERPRISE, $admin);
        $this->account = app(CorporateAccountService::class)->createAccount(
            $this->merchant->fresh(), ['company_name' => 'شركة الاختبار', 'credit_limit' => $limit],
        );
    }

    /** @test بيع على حساب شركة يُسجَّل ويرفع دَين الشركة. */
    public function corporate_sale_charges_the_company(): void
    {
        $this->enterpriseAndAccount();
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $this->postJson('/api/v1/amial/merchant/cashier/sales', [
            'total' => '12000',
            'payment_method' => 'corporate',
            'corporate_account_id' => $this->account->id,
            'items' => [['name' => 'بضاعة', 'qty' => 1, 'price' => '12000']],
        ])->assertOk()->assertJsonPath('meta.sale.payment_method', 'corporate');

        $this->assertSame('12000.0000', (string) $this->account->fresh()->current_balance);
    }

    /** @test تجاوز حدّ الائتمان يرفض البيع ولا يُسجّله. */
    public function over_limit_corporate_sale_is_rejected(): void
    {
        $this->enterpriseAndAccount('10000');
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $this->postJson('/api/v1/amial/merchant/cashier/sales', [
            'total' => '15000',
            'payment_method' => 'corporate',
            'corporate_account_id' => $this->account->id,
        ])->assertStatus(422);

        $this->assertSame('0.0000', (string) $this->account->fresh()->current_balance);
        $this->assertDatabaseMissing('merchant_sales', [
            'merchant_user_id' => $this->merchant->id, 'payment_method' => 'corporate',
        ]);
    }

    /** @test الباقة الأدنى من المؤسسية لا تستطيع البيع على حساب شركة. */
    public function non_enterprise_cannot_corporate_sale(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->postJson('/api/v1/amial/merchant/cashier/sales', [
            'total' => '1000', 'payment_method' => 'corporate', 'corporate_account_id' => 1,
        ])->assertStatus(402);
    }
}
