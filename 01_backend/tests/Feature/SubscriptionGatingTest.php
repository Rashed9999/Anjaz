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
 * AMIAL-SUB-GATING-001 — تشغيل الاشتراكات والميزات:
 * تغيير الأدمن لخطّة التاجر يفتح/يغلق الميزات في /me/access فوراً،
 * والاشتراك المنتهي يعود تلقائياً لميزات المجاني.
 */
class SubscriptionGatingTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create([
            'type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH', 'phone' => '967771800001',
        ]);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'business_type' => A::BIZ_FUEL,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_FREE,
        ]);
    }

    private function featuresFromApi(): array
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        return $this->getJson('/api/v1/amial/me/access')
            ->assertOk()
            ->json('meta.access.features');
    }

    /** @test محطة وقود على المجاني: أساسيات الوقود متاحة، الميزات المدفوعة مقفلة. */
    public function free_fuel_merchant_has_base_features_only(): void
    {
        $f = $this->featuresFromApi();

        // أساسيات محطة الوقود (من نوع النشاط — متاحة دائماً)
        $this->assertContains(A::F_FUEL_POS, $f);
        $this->assertContains(A::F_FUEL_PUMPS, $f);
        $this->assertContains(A::F_FUEL_SHIFTS, $f);

        // ميزات مدفوعة — يجب أن تكون مقفلة على المجاني
        $this->assertNotContains(A::F_FUEL_CARDS, $f);
        $this->assertNotContains(A::F_FUEL_VARIANCE, $f);
        $this->assertNotContains(A::F_PRODUCTS, $f);
        $this->assertNotContains(A::F_EMPLOYEES, $f);
        $this->assertNotContains(A::F_BRANCHES, $f);
    }

    /** @test الأدمن يرقّي الخطة → الميزات تُفتح فوراً في /me/access. */
    public function admin_upgrade_unlocks_features_immediately(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);

        // ترقية عبر نفس خدمة الاشتراكات التي تستخدمها لوحة الأدمن
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);

        $f = $this->featuresFromApi();

        // الآن الميزات المدفوعة مفتوحة
        $this->assertContains(A::F_FUEL_CARDS, $f);
        $this->assertContains(A::F_FUEL_VARIANCE, $f);
        $this->assertContains(A::F_PRODUCTS, $f);
        $this->assertContains(A::F_INVENTORY, $f);
        $this->assertContains(A::F_EMPLOYEES, $f);
        // وأساسيات الوقود ما زالت متاحة
        $this->assertContains(A::F_FUEL_POS, $f);
    }

    /** @test الترقية إلى Merchant Pro تفتح الفروع (فوق Business). */
    public function merchant_pro_unlocks_branches(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);

        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);
        $this->assertNotContains(A::F_BRANCHES, $this->featuresFromApi());

        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_MERCHANT_PRO, $admin);
        $this->assertContains(A::F_BRANCHES, $this->featuresFromApi());
    }

    /** @test اشتراك مدفوع منتهٍ يعود تلقائياً لميزات المجاني. */
    public function expired_paid_plan_falls_back_to_free_features(): void
    {
        MerchantProfile::where('user_id', $this->merchant->id)->update([
            'subscription_plan' => A::PLAN_BUSINESS,
            'subscription_expires_at' => now()->subDay(), // منتهٍ أمس
        ]);

        $f = $this->featuresFromApi();
        // انتهى → يعود مجانياً → الميزات المدفوعة مقفلة
        $this->assertNotContains(A::F_PRODUCTS, $f);
        $this->assertNotContains(A::F_FUEL_CARDS, $f);
        // أساسيات الوقود تبقى
        $this->assertContains(A::F_FUEL_POS, $f);
    }

    /** @test plan_info في /me/access يعكس الخطة الفعلية بعد الترقية. */
    public function plan_info_reflects_current_plan(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_MERCHANT_PRO, $admin);

        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/me/access')
            ->assertOk()
            ->assertJsonPath('meta.plan_info.code', A::PLAN_MERCHANT_PRO);
    }
}
