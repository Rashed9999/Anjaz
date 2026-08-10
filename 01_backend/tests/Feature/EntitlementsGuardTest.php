<?php

namespace Tests\Feature;

use App\Models\Access\MerchantCapabilityOverride;
use App\Models\Access\PlanCapability;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Access\EntitlementService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-ENTITLEMENTS-001 — حرّاسُ سجلّ القدرات.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأهمُّها الأخير**: مسارُ تاجرٍ يُضاف غداً بلا قدرةٍ تحرسه **يُسقط
 * الفحص**. فبلا هذا الحارس يعود التسرّبُ بعد شهرٍ وأحدٌ لا يلاحظ —
 * وهو بالضبط ما وقع مع الـ٤٢ نقطةً التي بنيناها بلا فحص باقة.
 */
class EntitlementsGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $plan, ?string $biz = A::BIZ_RETAIL): User
    {
        // **`role` صريحاً**: المصنعُ يثبّت `'customer'`، وخطّافُ `saving`
        // في `User` لا يُصحّحه لأنّه ليس `ROLE_USER` — فيبقى التاجرُ
        // عميلاً في عين محرّك الصلاحيات. (وفي الإنتاج يُترك فارغاً فيُشتقّ.)
        $u = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $u->id,
            'verification_status' => 'verified',
            'business_type' => $biz,
            'subscription_plan' => $plan,
        ]);

        return $u;
    }

    private function svc(): EntitlementService
    {
        app(EntitlementService::class)->forget();

        return app(EntitlementService::class);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الحالاتُ الأربع — ولا تُخلط
    // ══════════════════════════════════════════════════════════════════

    public function test_a_free_merchant_is_locked_by_plan_with_the_price_to_unlock(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $r = $this->svc()->state($m, 'retail.transfers');

        $this->assertSame(EntitlementService::LOCKED_BY_PLAN, $r['state']);
        $this->assertSame('plan', $r['unlock']['kind'],
            'قفلُ الباقة يُقال «صلاحية» — فيبحث التاجر عن دورٍ يمنحه لنفسه ولن يجد');
        $this->assertGreaterThan(0, $r['unlock']['price_monthly'],
            'رسالةٌ بلا سعرٍ ولا اسمِ باقة ليست طريقَ خروج');
        $this->assertSame('تاجر محترف', $r['unlock']['plan_name']);
    }

    public function test_a_pro_merchant_gets_the_same_capability(): void
    {
        $m = $this->merchant(A::PLAN_MERCHANT_PRO);

        $this->assertSame(EntitlementService::AVAILABLE,
            $this->svc()->state($m, 'retail.transfers')['state']);
    }

    public function test_an_employee_missing_the_role_is_locked_by_role_not_by_plan(): void
    {
        $owner = $this->merchant(A::PLAN_ENTERPRISE);

        $perm = app(\App\Services\Merchant\MerchantPermissionService::class);
        $roles = $perm->seedRetailRoles($owner);
        $cashierRole = collect($roles)->firstWhere('code', 'cashier');

        // **`role = 'pos'`**: بها وحدها يرث الموظّفُ صنفَ تاجره وباقتَه.
        // وبدونها يُقرأ عميلاً على المجّاني، فيُقال له «رقِّ باقتك» —
        // وهو العطلُ نفسُه الذي يحرسه هذا الاختبار.
        $employee = User::factory()->create(['role' => 'pos']);
        $perm->assign($owner, $employee, $cashierRole);
        PosUser::create([
            'merchant_user_id' => $owner->id, 'user_id' => $employee->id,
            'display_name' => 'كاشير', 'pos_number' => 'POS1', 'is_active' => true,
        ]);

        $r = $this->svc()->state($employee, 'retail.transfers');

        // **الباقةُ مؤسسيّةٌ — فالنقصُ في الدور لا في الاشتراك.**
        $this->assertSame(EntitlementService::LOCKED_BY_ROLE, $r['state'],
            'قيل للموظّف «رقِّ باقتك» والباقةُ أعلى ما في المنصّة — '
            . 'فيذهب صاحب المتجر يدفع بلا سبب');
        $this->assertSame('role', $r['unlock']['kind']);
        $this->assertStringContainsString('مالك', $r['unlock']['ask']);
    }

    public function test_the_owner_is_never_locked_by_role(): void
    {
        $m = $this->merchant(A::PLAN_ENTERPRISE);
        app(\App\Services\Merchant\MerchantPermissionService::class)->seedRetailRoles($m);

        $this->assertSame(EntitlementService::AVAILABLE,
            $this->svc()->state($m, 'retail.transfers')['state'],
            'المالكُ حُدّ بدورٍ — وحدودُه حدودُ الباقة لا حدودُ دوره');
    }

    public function test_a_capability_of_another_business_type_is_not_offered(): void
    {
        $pharmacy = $this->merchant(A::PLAN_ENTERPRISE, A::BIZ_PHARMACY);

        $this->assertSame(EntitlementService::NOT_APPLICABLE,
            $this->svc()->state($pharmacy, A::F_FUEL_PUMPS)['state']);

        $codes = collect($this->svc()->manifestFor($pharmacy)['capabilities'])
            ->pluck('capability.code')->all();

        $this->assertNotContains(A::F_FUEL_PUMPS, $codes,
            'عُرضت مضخّاتُ الوقود على صيدليّة — عرضٌ لما لا يُشترى');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الأساسيّة لا تُباع
    // ══════════════════════════════════════════════════════════════════

    public function test_core_capabilities_are_available_on_the_free_plan(): void
    {
        $m = $this->merchant(A::PLAN_FREE);
        $svc = $this->svc();

        foreach (CapabilityRegistry::all() as $cap) {
            if (! $cap->isCore()) {
                continue;
            }
            $this->assertSame(EntitlementService::AVAILABLE,
                $svc->state($m, $cap->code)['state'],
                "«{$cap->name()}» أساسيّةٌ وأُقفلت على المجّاني — "
                . 'وبيعُها بيعُ أرقامٍ خاطئةٍ لمن دفع أقلّ');
        }
    }

    public function test_the_admin_matrix_refuses_to_close_a_core_capability(): void
    {
        // **الحارسُ في الخدمة لا في الشاشة**: إخفاءُ الزرّ ليس منعاً.
        PlanCapability::create([
            'plan' => A::PLAN_FREE,
            'capability_code' => 'core.sale_lines',
            'is_enabled' => false,
            'reason' => 'محاولةُ إقفالٍ مباشرةً في القاعدة',
        ]);

        $m = $this->merchant(A::PLAN_FREE);

        $this->assertSame(EntitlementService::AVAILABLE,
            $this->svc()->state($m, 'core.sale_lines')['state'],
            'صفٌّ في القاعدة أقفل قدرةً أساسيّة — والمحرّك يجب أن يتجاوزه');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ قرارُ اللوحة يعلو على افتراضِ الشيفرة
    // ══════════════════════════════════════════════════════════════════

    public function test_the_admin_can_open_a_capability_in_a_cheaper_plan(): void
    {
        $m = $this->merchant(A::PLAN_STARTER);

        $this->assertSame(EntitlementService::LOCKED_BY_PLAN,
            $this->svc()->state($m, 'retail.transfers')['state']);

        PlanCapability::create([
            'plan' => A::PLAN_STARTER, 'capability_code' => 'retail.transfers',
            'is_enabled' => true, 'reason' => 'تجربة تسعير رمضان',
        ]);

        $this->assertSame(EntitlementService::AVAILABLE,
            $this->svc()->state($m, 'retail.transfers')['state'],
            'قرارُ اللوحة لا يعلو على افتراض الشيفرة — فالتسعير غير قابل للتجربة');
    }

    public function test_the_admin_can_close_a_capability_the_code_opens(): void
    {
        $m = $this->merchant(A::PLAN_ENTERPRISE);

        PlanCapability::create([
            'plan' => A::PLAN_ENTERPRISE, 'capability_code' => 'retail.waste',
            'is_enabled' => false, 'reason' => 'سُحبت مؤقتاً لعطل',
        ]);

        $this->assertSame(EntitlementService::LOCKED_BY_PLAN,
            $this->svc()->state($m, 'retail.waste')['state']);
    }

    public function test_a_merchant_grant_beats_the_plan(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        MerchantCapabilityOverride::create([
            'merchant_user_id' => $m->id,
            'capability_code' => 'retail.transfers',
            'effect' => MerchantCapabilityOverride::GRANT,
            'expires_at' => now()->addDays(14),
            'reason' => 'تجربة أسبوعين',
        ]);

        $this->assertSame(EntitlementService::AVAILABLE,
            $this->svc()->state($m, 'retail.transfers')['state']);
    }

    public function test_an_expired_grant_stops_granting(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        MerchantCapabilityOverride::create([
            'merchant_user_id' => $m->id,
            'capability_code' => 'retail.transfers',
            'effect' => MerchantCapabilityOverride::GRANT,
            'expires_at' => now()->subDay(),
            'reason' => 'تجربة انتهت',
        ]);

        $this->assertSame(EntitlementService::LOCKED_BY_PLAN,
            $this->svc()->state($m, 'retail.transfers')['state'],
            'منحةٌ منتهيةٌ ما زالت تفتح — فتصير باقةً دائمةً مجّانيّة');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ الحدّ — بالرقمين
    // ══════════════════════════════════════════════════════════════════

    public function test_reaching_the_product_limit_says_the_two_numbers(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        // **الطبقتان تعملان معاً**: الإدارةُ تفتح «الأصناف» على المجّاني،
        // **وحدُّ الباقة يبقى صفراً**. فالفتحُ ليس رفعاً للحدّ — ومن خلطهما
        // فتح للمجّاني أصنافاً بلا سقف.
        PlanCapability::create([
            'plan' => A::PLAN_FREE, 'capability_code' => A::F_PRODUCTS,
            'is_enabled' => true, 'reason' => 'تجربة: أصناف على المجّاني',
        ]);

        \App\Models\MerchantProduct::create([
            'merchant_user_id' => $m->id, 'name' => 'صنف',
            'price' => '10', 'cost_price' => '5', 'quantity' => '1', 'is_active' => true,
        ]);

        $r = $this->svc()->state($m->fresh(), A::F_PRODUCTS);

        $this->assertSame(EntitlementService::LIMIT_REACHED, $r['state']);
        $this->assertSame(1, $r['usage']['used']);
        $this->assertSame(0, $r['usage']['max']);
        $this->assertNotNull($r['unlock'],
            'بلغ الحدَّ ولا يُقال أيّ باقةٍ ترفعه — منعٌ بلا طريق خروج');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ البوّابة — ورقمان مختلفان لبابين
    // ══════════════════════════════════════════════════════════════════

    public function test_the_gate_returns_402_for_a_plan_lock_not_403(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $res = $this->actingAs($m, 'api')
            ->getJson('/api/v1/amial/merchant/retail/transfers');

        $res->assertStatus(402);
        $res->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED');
        $this->assertNotNull($res->json('meta.unlock.price_monthly'),
            'الردُّ بلا سعرٍ ولا اسمِ باقة — والمستعمل يعرف أنّه ممنوع ولا يعرف كيف يُسمح له');
    }

    public function test_the_gate_lets_a_subscribed_merchant_through(): void
    {
        $m = $this->merchant(A::PLAN_MERCHANT_PRO);

        $this->actingAs($m, 'api')
            ->getJson('/api/v1/amial/merchant/retail/transfers')
            ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑥ ملفُّ الخدمات — يُوصَل إليه
    // ══════════════════════════════════════════════════════════════════

    public function test_the_manifest_lists_locked_capabilities_too(): void
    {
        $m = $this->merchant(A::PLAN_FREE);

        $res = $this->actingAs($m, 'api')->getJson('/api/v1/amial/me/entitlements');
        $res->assertOk();

        $caps = $res->json('data.capabilities');

        $this->assertGreaterThan(20, count($caps));
        $this->assertGreaterThan(0, $res->json('data.summary.locked_by_plan'),
            'المقفلُ حُذف من الملفّ — وما يُخفى لا يُشترى');
        $this->assertGreaterThan(0, $res->json('data.summary.available'));

        $one = collect($caps)->firstWhere('state', 'locked_by_plan');
        $this->assertNotNull($one['unlock']['plan_name']);
        $this->assertNotNull($one['capability']['name'],
            'البطاقة بلا اسمٍ عربيّ — تُعرض رمزاً برمجيّاً للتاجر');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑦ صحّةُ السجلّ — **وهذا الحارسُ يمنع عودةَ التسرّب**
    // ══════════════════════════════════════════════════════════════════

    /**
     * **مسارُ تاجرٍ بلا بوّابةٍ ولا قدرةٍ تحرسه.**
     *
     * والقائمةُ البيضاء أدناه صريحة: ما فيها مفتوحٌ **عمداً** — قراءاتٌ
     * أساسيّةٌ أو مساراتُ حسابٍ لا تُباع. وإضافةُ مسارٍ جديدٍ لا تمرّ
     * صامتةً: إمّا بوّابة، وإمّا سطرٌ هنا يُقرأ في المراجعة.
     */
    public function test_no_merchant_route_escapes_the_capability_layer(): void
    {
        $exempt = [
            // حسابُ التاجر نفسُه وملفّ خدماته — لا يُباعان
            'retail/ops', 'retail/me/permissions',
            'fuel/ops', 'fuel/me/permissions',
            // قراءاتٌ تحرسها الأدوار وحدَها داخل المتحكّم
            'retail/products', 'retail/sales', 'retail/returns',
            'retail/counts/', 'retail/transfers/', 'retail/wastes/', 'retail/prices/',
        ];

        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/amial/merchant/retail/')) {
                continue;
            }
            $tail = substr($uri, strlen('api/v1/amial/merchant/'));

            $gated = collect($route->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'capability:'));

            $whitelisted = false;
            foreach ($exempt as $e) {
                if ($tail === $e || str_starts_with($tail, $e)) {
                    $whitelisted = true;
                    break;
                }
            }

            if (! $gated && ! $whitelisted) {
                $unguarded[] = $uri;
            }
        }

        $this->assertSame([], $unguarded,
            "مسارات تجزئة بلا بوّابة قدرة ولا استثناء صريح:\n"
            . implode("\n", $unguarded)
            . "\nإمّا `->middleware('capability:...')` وإمّا سطرٌ في القائمة البيضاء.");
    }

    /** كلُّ قدرةٍ تُباع لها اسمٌ عربيٌّ ومجموعة — **ولا رمزَ برمجيٌّ للتاجر**. */
    public function test_every_capability_is_presentable(): void
    {
        foreach (CapabilityRegistry::all() as $cap) {
            $this->assertNotSame($cap->code, $cap->name(),
                "القدرة «{$cap->code}» بلا اسمٍ عربيّ — تُعرض رمزاً في شاشة التاجر");
            $this->assertNotSame('', $cap->groupName());
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑧ يُوصَل إليه — القاعدة ١٢
    // ══════════════════════════════════════════════════════════════════

    public function test_the_admin_entitlements_centre_is_registered_and_linked(): void
    {
        foreach ([
            'admin.amial.entitlements.page',
            'admin.amial.entitlements.matrix',
            'admin.amial.entitlements.health',
            'admin.amial.entitlements.merchant',
        ] as $n) {
            $this->assertNotNull(Route::getRoutes()->getByName($n),
                "المسار «{$n}» غير مسجَّل");
        }

        $sidebar = file_get_contents(
            resource_path('views/admin-views/amial/partials/_sidebar.blade.php'));

        $this->assertStringContainsString('admin.amial.entitlements.page', $sidebar,
            'مركزُ الباقات بلا رابطٍ في القائمة — مبنيٌّ ولا يُوصَل إليه');
    }

    /**
     * **شاشةُ «خدماتي» في التطبيق تُرسم من الملفّ لا من قائمةٍ مكتوبة.**
     *
     * وحارسٌ نصّيٌّ لأنّ البديلَ عودةُ ٢٦ بوّابةً مكتوبةً بأسماء ميزات.
     */
    public function test_the_app_services_screen_is_data_driven_and_reachable(): void
    {
        $screen = base_path(
            '../02_flutter_app/lib/features/entitlements/screens/my_capabilities_screen.dart');
        $home = base_path(
            '../02_flutter_app/lib/features/access/screens/role_based_home_screens.dart');

        if (! file_exists($screen) || ! file_exists($home)) {
            $this->markTestSkipped('شيفرة التطبيق غير متاحة في هذه البيئة');
        }

        $this->assertStringContainsString('MyCapabilitiesScreen', file_get_contents($home),
            'شاشةُ «خدماتي» بلا بابٍ في الرئيسيّة');

        $src = file_get_contents($screen);
        $this->assertStringContainsString('c.visible', $src,
            'الشاشةُ لا تُرسم من الملفّ — قائمةٌ مكتوبةٌ تعود بنا إلى ٢٦ بوّابة');
    }

    /** كلُّ بادئةِ مسارٍ في السجلّ تخصّ مساراً حقيقيّاً — **ولا حراسةَ للهواء**. */
    public function test_every_declared_route_prefix_exists(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();
        $missing = [];

        foreach (CapabilityRegistry::all() as $cap) {
            foreach ($cap->routePrefixes() as $prefix) {
                $found = false;
                foreach ($uris as $u) {
                    if (str_contains($u, 'amial/merchant/' . trim($prefix, '/'))) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $missing[] = $cap->code . ' → ' . $prefix;
                }
            }
        }

        $this->assertSame([], $missing,
            "قدرات تحرس مساراتٍ لا وجود لها:\n" . implode("\n", $missing));
    }
}
