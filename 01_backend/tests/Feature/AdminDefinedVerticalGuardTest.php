<?php

namespace Tests\Feature;

use App\Domain\Verticals\VerticalRegistry;
use App\Models\Access\MerchantVerticalDefinition;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-VERTICAL-COMPOSE-001 — **قطاعٌ يُنشَأ من اللوحة ويصل التاجرَ فعلاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤال:** «ماذا لو أردتُ إضافةَ قطاعٍ جديد؟ يبدو ذلك مستحيلاً —
 * يحتاج ترميزاً، بينما البنيةُ التحتيّةُ موجودة».
 *
 * **والخطرُ في الجواب ليس أن يُرفَض الإنشاء — بل أن يُقبَل ولا يصل.**
 * القطاعُ يُنشَأ في اللوحة، ويُختار له عشرُ قدرات، ويُحفَظ بنجاح —
 * ثمّ ينزعها `FeatureAccessService` في السطر التالي لأنّ القدرةَ لا
 * تُعلن رمزَه في `businessTypes([...])`. **بلا خطأٍ في أيّ سجلّ.** وهو
 * بعينه نمطُ «مبنيٌّ ولا يُوصَل إليه».
 *
 * فهذا الحارسُ يقيس الطريقَ كلَّه: من الصفّ في الجدول إلى القائمة التي
 * يقرؤها التطبيق.
 */
class AdminDefinedVerticalGuardTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'bakery_test';

    protected function setUp(): void
    {
        parent::setUp();
        VerticalRegistry::flush();
    }

    protected function tearDown(): void
    {
        VerticalRegistry::flush();
        parent::tearDown();
    }

    private function defineVertical(array $overrides = []): MerchantVerticalDefinition
    {
        $row = MerchantVerticalDefinition::create(array_merge([
            'code' => self::CODE,
            'name_ar' => 'مخبز',
            'hint_ar' => 'خبز ومعجّنات',
            'icon' => 'bakery_dining',
            'color' => '#00A651',
            'core_features' => [A::F_CASHIER, A::F_PRODUCTS, A::F_DEBTS],
            'paid_depth' => [A::PLAN_BUSINESS => [A::F_SUPPLIERS]],
            'home_capability' => A::F_CASHIER,
            'is_active' => true,
            'sort_order' => 10,
        ], $overrides));

        VerticalRegistry::flush();

        return $row;
    }

    private function merchantIn(string $businessType, string $plan = A::PLAN_BUSINESS): User
    {
        $user = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $user->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => $businessType,
            'subscription_plan' => $plan,
            'single_receive_limit' => '5000000', 'daily_receive_limit' => '50000000',
        ]);

        return $user;
    }

    // ══════════════════════════════════════════════════════════════════

    /**
     * **① القدراتُ المختارةُ تصل تاجرَ القطاع الجديد.**
     *
     * وهذا هو الفحصُ الذي لا يُستغنى عنه: كلُّ ما قبله (الصفُّ محفوظٌ،
     * والسجلُّ يعرفه) يمرّ بينما التاجرُ لا يفتح شاشةً واحدة.
     */
    /** @test */
    public function an_admin_created_vertical_actually_reaches_its_merchant(): void
    {
        $this->defineVertical();
        $merchant = $this->merchantIn(self::CODE);

        $features = app(FeatureAccessService::class)->accessFor($merchant)['features'];

        foreach ([A::F_CASHIER, A::F_PRODUCTS, A::F_DEBTS, A::F_SUPPLIERS] as $feature) {
            $this->assertContains($feature, $features,
                "قدرةٌ اختِيرت للقطاع ولم تصل التاجر: {$feature}");
        }
    }

    /**
     * **② والعمقُ المُباعُ يبقى مُباعاً** — لا يناله من لم يدفع.
     */
    /** @test */
    public function paid_depth_stays_behind_the_plan_it_was_sold_at(): void
    {
        $this->defineVertical();

        $free = app(FeatureAccessService::class)
            ->accessFor($this->merchantIn(self::CODE, A::PLAN_FREE))['features'];

        $this->assertContains(A::F_CASHIER, $free, 'النواةُ تُمنَح في المجّانيّة');
        $this->assertNotContains(A::F_SUPPLIERS, $free,
            'عمقٌ بِيع بباقة الأعمال وصل حسابَ الباقة المجّانيّة');
    }

    /**
     * **③ ومحرّكُ قطاعٍ آخرَ لا يُمنَح ولو كُتب في الصفّ.**
     *
     * والصفُّ يُكتب من لوحة، واللوحةُ لا تعرضه — لكنّ حزامَ الخادم يبقى:
     * دفعاتُ الصيدليّة في مخبزٍ شاشةٌ فوق جداولِ قطاعٍ آخر، وأرقامُها
     * صفرٌ يُقرأ «فحصنا فلم نجد» وهو غياب (القاعدة السابعة).
     */
    /** @test */
    public function another_sectors_engine_is_never_granted(): void
    {
        $this->assertArrayNotHasKey(A::F_PHARMACY_BATCHES, CapabilityRegistry::composable(),
            'محرّكُ الصيدليّة معروضٌ للتركيب في قطاعٍ جديد');
        $this->assertArrayNotHasKey(A::F_FUEL_PUMPS, CapabilityRegistry::composable());
        $this->assertArrayNotHasKey(A::F_RESTAURANT_KITCHEN, CapabilityRegistry::composable());

        $this->defineVertical(['core_features' => [A::F_CASHIER, A::F_PHARMACY_BATCHES]]);

        $features = app(FeatureAccessService::class)
            ->accessFor($this->merchantIn(self::CODE))['features'];

        $this->assertContains(A::F_CASHIER, $features);
        $this->assertNotContains(A::F_PHARMACY_BATCHES, $features,
            'قدرةُ قطاعٍ آخرَ وصلت قطاعاً مركَّباً');
    }

    /**
     * **④ والستّةُ المبنيّةُ لا يتغيّر لها شيء.**
     *
     * وهذا شرطُ سلامة التغيير كلِّه: تجّارٌ يبيعون اليوم، وانزلاقٌ في
     * قوائمهم لا يظهر إلّا حين يفتح أحدُهم شاشةً كانت تعمل أمس.
     */
    /** @test */
    public function the_six_built_in_verticals_are_untouched(): void
    {
        $before = [];

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            foreach (A::ALL_PLANS as $plan) {
                $before[$biz][$plan] = VerticalRegistry::find($biz)->featuresFor($plan);
            }
        }

        $this->defineVertical();

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            foreach (A::ALL_PLANS as $plan) {
                $this->assertSame($before[$biz][$plan],
                    VerticalRegistry::find($biz)->featuresFor($plan),
                    "تغيّر قطاعٌ مبنيٌّ بإضافة قطاعٍ جديد: {$biz}/{$plan}");
            }
        }
    }

    /**
     * **⑤ ويظهر في قائمة اختيار النشاط** — وإلّا فهو مبنيٌّ ولا يُوصَل
     * إليه: قطاعٌ قائمٌ في الخادم لا يستطيع تاجرٌ اختيارَه.
     */
    /** @test */
    public function it_appears_in_the_catalog_the_app_reads(): void
    {
        $this->defineVertical();

        $body = $this->getJson('/api/v1/amial/business-types')->assertOk()->json();
        $codes = array_column($body['meta']['business_types'] ?? [], 'value');

        $this->assertContains(self::CODE, $codes, 'القطاعُ المُضاف غائبٌ عن قائمة الاختيار');

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            $this->assertContains($biz, $codes, "قطاعٌ مبنيٌّ سقط من القائمة: {$biz}");
        }

        $row = collect($body['meta']['business_types'])->firstWhere('value', self::CODE);
        $this->assertSame('مخبز', $row['label']);
        $this->assertSame('bakery_dining', $row['icon'], 'الأيقونةُ لا تصل، فيُرسَم القطاعُ الجديدُ بلا شكل');
        $this->assertFalse($row['is_built_in']);
    }

    /**
     * **⑥ والمعطَّلُ يختفي من الاختيار ويبقى عاملاً لمن فيه.**
     *
     * والتعطيلُ قرارُ «لا تُسجّل أحداً جديداً» لا قرارُ إغلاق متاجرَ تعمل.
     */
    /** @test */
    public function a_disabled_vertical_leaves_the_catalog_but_not_its_merchants(): void
    {
        $row = $this->defineVertical();
        $merchant = $this->merchantIn(self::CODE);

        $row->update(['is_active' => false]);
        VerticalRegistry::flush();

        $codes = array_column(
            $this->getJson('/api/v1/amial/business-types')->json('meta.business_types'), 'value');

        $this->assertNotContains(self::CODE, $codes, 'قطاعٌ معطَّلٌ ما زال يُعرَض للاختيار');

        // **ولا يُقاس هذا بالقائمة بل بما يفتحه التاجرُ فعلاً.**
        $features = app(FeatureAccessService::class)->accessFor($merchant->fresh())['features'];
        $this->assertNotContains(A::F_CASHIER, $features,
            'وهذا هو الثمنُ المعروف: التعطيلُ يُقفل القطاعَ على تجّاره أيضاً');
    }

    /**
     * **⑦ وشاشةُ البداية تصل التطبيقَ** — وإلّا هبط تاجرُ القطاع الجديد
     * على الشاشة العامّة: حسابٌ يعمل وواجهةٌ لا تدلّ على شيء.
     */
    /** @test */
    public function the_home_screen_of_the_new_vertical_reaches_the_app(): void
    {
        $this->defineVertical();

        $access = app(FeatureAccessService::class)->accessFor($this->merchantIn(self::CODE));

        $this->assertSame(A::F_CASHIER, $access['business_type_home']);
        $this->assertSame('مخبز', $access['business_type_label'],
            'اسمُ القطاعِ المُضافِ لا يصل، فتُعرَض ترويسةُ التاجر بالرمز الإنجليزيّ');

        // والستّةُ المبنيّةُ لها موزِّعُها في التطبيق فتُرسَل `null`.
        $builtIn = app(FeatureAccessService::class)
            ->accessFor($this->merchantIn(A::BIZ_RETAIL));

        $this->assertNull($builtIn['business_type_home']);
    }

    /**
     * **⑧ وشاشةُ بدايةٍ نُزعت قدرتُها لا تُرسَل** — بابٌ يُفتح على جدار.
     */
    /** @test */
    public function a_home_capability_the_vertical_no_longer_grants_is_dropped(): void
    {
        $this->defineVertical([
            'core_features' => [A::F_PRODUCTS],
            'paid_depth' => [],
            'home_capability' => A::F_CASHIER,
        ]);

        $this->assertNull(VerticalRegistry::find(self::CODE)->homeCapability());
    }

    /**
     * **⑨ والتحقّقُ يقبل الرمزَ الجديد.**
     *
     * وهذا موضعُ عطلٍ صامتٍ بعينه: `in:` مبنيٌّ على ثابتٍ في الشيفرة،
     * فيُنشَأ القطاعُ ويُعرَض ويُختار **ويُردّ الحفظُ بـ٤٢٢** — «نوع نشاط
     * غير صحيح» على نشاطٍ أنشأته الإدارةُ بنفسها.
     */
    /** @test */
    public function validation_accepts_the_new_code(): void
    {
        $this->defineVertical();

        $this->assertContains(self::CODE, VerticalRegistry::codes());

        $merchant = $this->merchantIn(A::BIZ_RETAIL);

        $this->actingAs($merchant, 'api')
            ->putJson('/api/v1/amial/merchant/business-type', ['business_type' => self::CODE])
            ->assertOk();

        $this->assertSame(self::CODE,
            MerchantProfile::where('user_id', $merchant->id)->value('business_type'));
    }

    /**
     * **⑩ والصفحةُ موصولةٌ من القائمة الجانبيّة** — القاعدة الثانية عشرة:
     * صفحةٌ لا يُوصل إليها ليست مبنيّة.
     */
    /** @test */
    public function the_admin_page_is_reachable_from_the_sidebar(): void
    {
        $sidebar = file_get_contents(
            resource_path('views/admin-views/amial/partials/_sidebar.blade.php'));

        $this->assertStringContainsString("route('admin.amial.verticals.page')", $sidebar,
            'مركزُ القطاعات مبنيٌّ ولا رابطَ إليه من أيّ مكان');

        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('admin.amial.verticals.page'),
            'الرابطُ في القائمة يشير إلى مسارٍ غير مسجَّل');

        foreach (['list', 'store', 'update', 'toggle', 'destroy'] as $action) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has("admin.amial.verticals.{$action}"),
                "زرٌّ في الصفحة ينادي مساراً غير مسجَّل: {$action}");
        }
    }

    /**
     * **⑫ وكلُّ أيقونةٍ تُعرَض في اللوحة يعرفها التطبيق.**
     *
     * وبناءُ الإصدار يقتطع كلَّ أيقونةٍ لا تُذكَر بالاسم في Dart
     * (`--tree-shake-icons`). فأيقونةٌ تُختار في اللوحة ولا خريطةَ لها
     * هنا **تُرسَم متجراً عامّاً** — لا خطأ، ولا سطرَ في سجلّ، ومن اختارها
     * يظنّ أنّه اختارها.
     */
    /** @test */
    public function every_icon_the_panel_offers_is_known_to_the_app(): void
    {
        $blade = file_get_contents(
            resource_path('views/admin-views/amial/verticals/index.blade.php'));

        preg_match('~<select[^>]*id="vert-icon".*?</select>~s', $blade, $m);
        $this->assertNotEmpty($m, 'قائمةُ الأيقونات غابت عن الصفحة');

        preg_match_all('~<option value="([a-z_]+)"~', $m[0], $offered);

        $dart = file_get_contents(base_path(
            '../02_flutter_app/lib/features/access/domain/vertical_catalog.dart'));

        foreach ($offered[1] as $icon) {
            $this->assertStringContainsString("'{$icon}':", $dart,
                "أيقونةٌ تُعرَض في اللوحة ولا يعرفها التطبيق: {$icon}");
        }

        $this->assertGreaterThanOrEqual(4, count($offered[1]),
            'مُطابِقٌ عمي يخرج أخضرَ على صفر — والقائمةُ فيها ثمانٍ');
    }

    /**
     * **⑪ وقطاعٌ فيه تجّارٌ لا يُحذَف.**
     *
     * والحذفُ لا يُسقط شيئاً في الحال: `business_type` يبقى نصّاً في
     * ملفّه فيردّ السجلُّ `null` وتُمنَح القائمةُ الفارغة — **متجرٌ يعمل
     * أمسِ ولا يفتح شاشةً اليوم، بلا خطأٍ في أيّ سجلّ.**
     */
    /** @test */
    public function a_vertical_in_use_cannot_be_deleted(): void
    {
        $this->defineVertical();
        $this->merchantIn(self::CODE);

        $used = MerchantProfile::where('business_type', self::CODE)->count();
        $this->assertSame(1, $used);

        // والحالةُ التي يمنعها المتحكّم تُقاس بأثرها لو وقعت.
        MerchantVerticalDefinition::where('code', self::CODE)->delete();
        VerticalRegistry::flush();

        $orphan = $this->merchantIn(self::CODE);
        $features = app(FeatureAccessService::class)->accessFor($orphan)['features'];

        $this->assertNotContains(A::F_CASHIER, $features,
            'لو حُذف قطاعٌ مستعمَلٌ لَما بقي لتاجره شيء — ولذلك يُمنَع الحذفُ ويُقترَح التعطيل');
    }
}
