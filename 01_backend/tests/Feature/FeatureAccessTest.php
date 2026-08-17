<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRITICAL-001 — اختبارات Foundation الطبقات.
 */
class FeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    private FeatureAccessService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(FeatureAccessService::class);
    }

    /** @test */
    public function regular_user_sees_only_basic_features(): void
    {
        $user = User::factory()->create([
            'role' => A::ROLE_USER,
            'verification_level' => A::VERIFICATION_BASIC,
        ]);

        $access = $this->svc->accessFor($user);

        $this->assertSame(A::ROLE_USER, $access['role']);
        $this->assertNull($access['business_type']);
        $this->assertSame(A::PLAN_FREE, $access['subscription_plan']);

        $features = $access['features'];
        $this->assertContains(A::F_WALLET, $features);
        $this->assertContains(A::F_FAMILY_FUND, $features);
        $this->assertContains(A::F_SAFE_PAY, $features);

        // لا يرى ميزات التجّار
        $this->assertNotContains(A::F_CASHIER, $features);
        $this->assertNotContains(A::F_FUEL_POS, $features);
        $this->assertNotContains(A::F_PHARMACY_POS, $features);
        $this->assertNotContains(A::F_INVENTORY, $features);
    }

    /** @test */
    public function agent_sees_cash_in_out_but_not_merchant_features(): void
    {
        $user = User::factory()->create(['role' => A::ROLE_AGENT]);

        $access = $this->svc->accessFor($user);

        $features = $access['features'];
        $this->assertContains(A::F_CASH_IN, $features);
        $this->assertContains(A::F_CASH_OUT, $features);
        $this->assertContains(A::F_AGENT_COMMISSIONS, $features);

        // لا يرى ميزات تاجر
        $this->assertNotContains(A::F_CASHIER, $features);
        $this->assertNotContains(A::F_FAMILY_FUND, $features);
    }

    /** @test */
    public function fish_seller_quick_sale_free_sees_minimal_features(): void
    {
        $user = User::factory()->create(['role' => A::ROLE_MERCHANT]);
        MerchantProfile::create([
            'user_id' => $user->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_QUICK_SALE,
            'subscription_plan' => A::PLAN_FREE,
        ]);

        $access = $this->svc->accessFor($user);

        $this->assertSame(A::BIZ_QUICK_SALE, $access['business_type']);
        $this->assertSame(A::PLAN_FREE, $access['subscription_plan']);

        $features = $access['features'];
        // يرى ميزات QUICK_SALE
        $this->assertContains(A::F_QUICK_SALE, $features);
        $this->assertContains(A::F_DEBTS, $features);
        $this->assertContains(A::F_DAILY_REPORTS, $features);

        // لا يرى ميزات Retail/Fuel/Pharmacy
        $this->assertNotContains(A::F_CASHIER, $features);
        $this->assertNotContains(A::F_FUEL_POS, $features);
        $this->assertNotContains(A::F_PHARMACY_POS, $features);

        // لا يرى ميزات الخطط المرتفعة
        $this->assertNotContains(A::F_INVENTORY, $features);
        $this->assertNotContains(A::F_BARCODE, $features);
        $this->assertNotContains(A::F_EMPLOYEES, $features);

        // الحدود: 20 منتج، 0 موظفين
        $this->assertSame(0, $access['limits']['max_products']); // FREE: لا منتجات (تُفتح من STARTER)
        $this->assertSame(0, $access['limits']['max_employees']);
    }

    /** @test */
    public function retail_business_starter_plan_adds_barcode_and_inventory(): void
    {
        $user = User::factory()->create(['role' => A::ROLE_MERCHANT]);
        MerchantProfile::create([
            'user_id' => $user->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_STARTER,
        ]);

        $access = $this->svc->accessFor($user);

        $features = $access['features'];
        // Retail base
        $this->assertContains(A::F_CASHIER, $features);
        $this->assertContains(A::F_PRODUCTS, $features);
        // ══════════════════════════════════════════════════════════════
        // **و«العملاء» ليست في «البداية»** — قرارُ صاحب المشروع صراحةً:
        //
        //   «customers ليست مجانية … إدارةُ العملاء الكاملة تبدأ من
        //    Business.  Retail وWholesale: products تبدأ من Starter،
        //    وcustomers تبدأ من Business. لا يحصل عليهما التاجر مجانًا
        //    بسبب business_type.»
        //
        // وكان هذا الفحصُ يطلب عكسَه، فبقي ساقطاً منذ نُفّذ القرار.
        // **والاختبارُ هو المتخلّف لا الشيفرة** — فيُقلَب ويُثبَّت المنع.
        $this->assertNotContains(A::F_CUSTOMERS, $features,
            '«العملاء» مُنحت على «البداية» — وهي تبدأ من «الأعمال»');
        // STARTER يُفعّل
        $this->assertContains(A::F_INVENTORY, $features);
        $this->assertContains(A::F_BARCODE, $features);

        // لكن لا يرى ميزات Business/Pro
        $this->assertNotContains(A::F_EMPLOYEES, $features);
        $this->assertNotContains(A::F_BRANCHES, $features);
        $this->assertNotContains(A::F_RBAC, $features);

        $this->assertSame(100, $access['limits']['max_products']);
    }

    /** @test */
    public function fuel_enterprise_sees_everything_relevant(): void
    {
        $user = User::factory()->create(['role' => A::ROLE_MERCHANT]);
        MerchantProfile::create([
            'user_id' => $user->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_FUEL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
        ]);

        $access = $this->svc->accessFor($user);

        $features = $access['features'];
        // Fuel base
        $this->assertContains(A::F_FUEL_POS, $features);
        $this->assertContains(A::F_FUEL_PUMPS, $features);
        $this->assertContains(A::F_FUEL_COMPANIES, $features);
        $this->assertContains(A::F_FUEL_SHIFTS, $features);
        // ENTERPRISE يُفعّل
        $this->assertContains(A::F_EMPLOYEES, $features);
        $this->assertContains(A::F_BRANCHES, $features);
        $this->assertContains(A::F_RBAC, $features);
        $this->assertContains(A::F_API_ACCESS, $features);
        $this->assertContains(A::F_FUEL_CARDS, $features);
        $this->assertContains(A::F_FUEL_VARIANCE, $features);

        // لا حدود
        $this->assertSame(-1, $access['limits']['max_products']);
        $this->assertSame(-1, $access['limits']['max_branches']);
    }

    /** @test */
    public function pharmacy_business_plan_unlocks_employees(): void
    {
        $user = User::factory()->create(['role' => A::ROLE_MERCHANT]);
        MerchantProfile::create([
            'user_id' => $user->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_PHARMACY,
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        $access = $this->svc->accessFor($user);

        $features = $access['features'];
        $this->assertContains(A::F_PHARMACY_POS, $features);
        $this->assertContains(A::F_PHARMACY_BATCHES, $features);
        $this->assertContains(A::F_PHARMACY_ALERTS, $features);
        $this->assertContains(A::F_EMPLOYEES, $features);
        $this->assertContains(A::F_ADVANCED_REPORTS, $features);

        // PRESCRIPTIONS feature تأتي مع MERCHANT_PRO فقط
        $this->assertNotContains(A::F_PHARMACY_PRESCRIPTIONS, $features);
    }

    /** @test */
    public function merchant_without_business_type_sees_only_common(): void
    {
        $user = User::factory()->create(['role' => A::ROLE_MERCHANT]);
        MerchantProfile::create([
            'user_id' => $user->id,
            'verification_status' => 'pending_review',
            'business_type' => null, // لم يختر بعد
        ]);

        $access = $this->svc->accessFor($user);

        $this->assertNull($access['business_type']);
        $features = $access['features'];
        $this->assertContains(A::F_WALLET, $features);
        $this->assertContains(A::F_MERCHANT_VERIFICATION, $features);

        // لا يرى أيّ ميزة POS
        $this->assertNotContains(A::F_CASHIER, $features);
        $this->assertNotContains(A::F_FUEL_POS, $features);
        $this->assertNotContains(A::F_PHARMACY_POS, $features);
    }

    /** @test */
    public function admin_can_update_merchant_plan(): void
    {
        $user = User::factory()->create(['role' => A::ROLE_MERCHANT]);
        $profile = MerchantProfile::create([
            'user_id' => $user->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_FREE,
        ]);

        $updated = $this->svc->updateMerchantPlan(
            $profile, A::PLAN_BUSINESS,
            new \DateTimeImmutable('+30 days'),
            'دفع بـ 35 ريال سعودي عبر التحويل البنكي',
        );

        $this->assertSame(A::PLAN_BUSINESS, $updated->subscription_plan);
        $this->assertNotNull($updated->subscription_expires_at);
        $this->assertStringContainsString('ريال سعودي', $updated->subscription_notes);
    }

    /** @test */
    public function invalid_plan_is_rejected(): void
    {
        $user = User::factory()->create(['role' => A::ROLE_MERCHANT]);
        $profile = MerchantProfile::create([
            'user_id' => $user->id,
            'verification_status' => 'verified',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->updateMerchantPlan($profile, 'platinum_plus_ultra'); // غير صحيح
    }

    /** @test */
    public function extra_features_are_merged(): void
    {
        $user = User::factory()->create(['role' => A::ROLE_MERCHANT]);
        $profile = MerchantProfile::create([
            'user_id' => $user->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_QUICK_SALE,
            'subscription_plan' => A::PLAN_FREE,
        ]);

        // الأدمن يُفعّل ميزة Inventory يدوياً لهذا التاجر (خارج الخطّة)
        $this->svc->addExtraFeature($profile, A::F_INVENTORY);

        $access = $this->svc->accessFor($user);
        $this->assertContains(A::F_INVENTORY, $access['features']);

        // إزالة
        $this->svc->removeExtraFeature($profile, A::F_INVENTORY);
        $access2 = $this->svc->accessFor($user->fresh());
        $this->assertNotContains(A::F_INVENTORY, $access2['features']);
    }

    /** @test */
    public function has_feature_returns_boolean(): void
    {
        $user = User::factory()->create(['role' => A::ROLE_USER]);

        $this->assertTrue($this->svc->hasFeature($user, A::F_WALLET));
        $this->assertTrue($this->svc->hasFeature($user, A::F_SAFE_PAY));
        $this->assertFalse($this->svc->hasFeature($user, A::F_FUEL_POS));
        $this->assertFalse($this->svc->hasFeature($user, A::F_CASHIER));
    }
}
