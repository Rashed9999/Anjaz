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

    /**
     * @test
     *
     * AMIAL-VERTICAL-SCOPE-001 — **والترقيةُ لا تُحوِّل المحطّةَ بقالة.**
     *
     * كان هذا الملفُّ يشترط أنّ ترقيةَ **محطّة وقود** تفتح لها
     * `products` و`inventory` — أي كتالوجَ رفوفٍ وجردَ مخزون. وذاك ما
     * سأل عنه صاحبُ المشروع: «لماذا تاجرُ وقودٍ لديه أصنافٌ ومخزون؟».
     *
     * فالمشتري يدفع ٣٥ ر.س ويأخذ سبعَ قدراتٍ **لا شاشةَ تفتحها له**:
     * `CashierPosScreen` تردّ حسابَ الوقود إلى `FuelSaleScreen`.
     *
     * **والباقةُ تفتح العمقَ في نطاق النشاط** — لا تُخرجه منه.
     */
    public function upgrading_a_fuel_station_never_opens_the_dry_goods_catalogue(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);

        foreach ([A::PLAN_BUSINESS, A::PLAN_ENTERPRISE] as $plan) {
            app(SubscriptionService::class)->changePlan($this->merchant, $plan, $admin);

            $f = $this->featuresFromApi();

            foreach ([
                A::F_PRODUCTS, A::F_INVENTORY, A::F_BARCODE,
                A::F_INVENTORY_AUDIT, A::F_LOW_STOCK_ALERTS,
                A::F_SUPPLIERS, A::F_PURCHASES,
            ] as $code) {
                $this->assertNotContains($code, $f,
                    "**فُتحت «{$code}» لمحطّة وقودٍ بترقيتها إلى «{$plan}»** — "
                    . 'وهي كتالوجُ رفوفٍ لا يبيع منه شيءٌ في المحطّة. '
                    . 'والتدقيق: «محطة وقود — لا يجوز أن يرى… كاشير منتجات عام».');
            }
        }
    }

    /** @test الأدمن يرقّي الخطة → الميزات تُفتح فوراً في /me/access. */
    public function admin_upgrade_unlocks_features_immediately(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);

        // ترقية عبر نفس خدمة الاشتراكات التي تستخدمها لوحة الأدمن
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);

        $f = $this->featuresFromApi();

        // ══════════════════════════════════════════════════════════════
        // الآن الميزاتُ المدفوعةُ مفتوحة — **وفي نطاق نشاطها**.
        //
        // AMIAL-VERTICAL-SCOPE-001: كان هنا `F_PRODUCTS` و`F_INVENTORY`،
        // والحسابُ **محطّةُ وقود**. فكان الملفُّ يشترط أن تفتح الترقيةُ
        // كتالوجَ رفوفٍ لمن يبيع لتراً من مضخّة — وهو ما سأل عنه صاحبُ
        // المشروع. فاستُبدلا بعمق **قطاعه** وبالمشترَك الحقيقيّ، وأُفرد
        // للمنع اختبارٌ صريحٌ أدناه لئلّا يُقرأ الحذفُ تخفيفاً.
        // ══════════════════════════════════════════════════════════════
        $this->assertContains(A::F_FUEL_CARDS, $f);
        $this->assertContains(A::F_FUEL_VARIANCE, $f);
        $this->assertContains(A::F_FUEL_COMPANIES, $f);
        $this->assertContains(A::F_EMPLOYEES, $f);
        $this->assertContains(A::F_PROFIT_REPORTS, $f);
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
