<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-PRODUCT-QUOTA-001 · AMIAL-VARIANTS-REACH-001
 *
 * ══════════════════════════════════════════════════════════════════════
 * ملاحظتان من صاحب المشروع، وكلتاهما كشفت محرّكاً كاملاً بلا مفتاح.
 *
 *  ① «لا تنسَ أنّ عدد المنتجات مرتبطٌ بالباقات»
 *
 *     وكلُّ شيءٍ كان مبنيّاً **إلّا الفحص**: `PLAN_LIMITS` تقول ٠ · ١٠٠ ·
 *     ٣٠٠ · بلا حدّ، و`usageFor` تعدّ الأصناف، و`evaluate()` تُرجع
 *     `LIMIT_REACHED`، و`EnsureCapability` تردّ ٤٠٢ برسالةٍ فيها الرقمان
 *     وسعرُ الباقة التالية.
 *
 *     **وبابُ إنشاء المنتج لا يمرّ بشيءٍ من ذلك.** فالباقةُ المجّانيّة
 *     تعطي ما تبيعه المدفوعة — ومحرّكُ الباقات كلُّه بلا أثرٍ ماليّ.
 *
 *  ② «عند إضافة منتج من التطبيق، هل يوجد متغيّرات؟»
 *
 *     في الخادم نعم — ضربٌ ديكارتيّ وسقفُ ٢٠٠ وإعادةُ توليدٍ لا تكرّر.
 *     **وفي التطبيق لا**: المستودعُ يحمل النداء، ولا متحكّمَ يناديه ولا
 *     شاشةَ تفتحه. مبنيٌّ من أوّله إلى آخره إلّا آخر شبر.
 * ══════════════════════════════════════════════════════════════════════
 */
class ProductQuotaAndVariantsGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** تاجرُ تجزئةٍ بباقةٍ محدّدة — من مصنعه لا من زرّ اللوحة (أسرع وأدقّ هنا). */
    private function merchant(string $plan, string $phone): User
    {
        // **والدورُ يُصرَّح به**: المصنعُ يضع `role => 'customer'` صراحةً،
        // والمُطابِقُ التلقائيُّ في `User::booted` لا يدهس دوراً صريحاً.
        // فحسابٌ بنوع تاجرٍ ودورِ عميلٍ يُقرأ عميلاً في محرّك الباقات —
        // وهو ما جعل الحارسَ يقرأ «مجّانيّة» على تاجرٍ ناشئ.
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'phone' => $phone,
        ]);

        DB::table('merchant_profiles')->updateOrInsert(
            ['user_id' => $u->id],
            [
                // **الأعمدةُ تُقرأ من المخطّط لا من الذاكرة**: `plan` و
                // `store_name` لا وجودَ لهما هنا — الأوّلُ `subscription_plan`
                // والثاني في جدولٍ آخر.
                'business_type' => A::BIZ_RETAIL,
                'subscription_plan' => $plan,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );

        return $u->refresh();
    }

    private function addProduct(User $merchant, string $name): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($merchant, 'api')
            ->postJson('/api/v1/amial/merchant/cashier/products', [
                'name' => $name,
                'price' => 100,
            ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① حدُّ الأصناف يُفرَض عند بابه
    // ══════════════════════════════════════════════════════════════════

    public function test_the_product_route_declares_its_capability(): void
    {
        // **البوّابةُ تُعلَن على المسار** — والمتحكّمُ لا يعرف ما الباقات.
        $routes = file_get_contents(base_path('routes/api/amial.php'));

        $this->assertMatchesRegularExpression(
            "/'addProduct'\]\)\s*\n\s*->middleware\('capability:/u",
            $routes,
            'بابُ إنشاء المنتج بلا بوّابة قدرات — الحدُّ معلَنٌ ولا يُفرَض',
        );
    }

    public function test_a_merchant_at_the_plan_limit_cannot_add_another_product(): void
    {
        $limit = A::PLAN_LIMITS[A::PLAN_STARTER]['products'];
        $this->assertGreaterThan(0, $limit, 'الباقةُ الناشئة بلا حدٍّ مقروء');

        $merchant = $this->merchant(A::PLAN_STARTER, '967771960001');

        // يُملأ الحدُّ مباشرةً — لا بـ$limit نداءَ شبكة.
        $rows = [];
        for ($i = 0; $i < $limit; $i++) {
            $rows[] = [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'merchant_user_id' => $merchant->id,
                'name' => 'صنف ' . $i,
                'price' => '10.0000',
                'is_variant_parent' => false,
                'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        MerchantProduct::insert($rows);

        $r = $this->addProduct($merchant, 'الصنف الزائد');

        // **٤٠٢ لا ٤٠٣**: نقصُ الباقة يذهب لصاحب المتجر ليرقّي، ونقصُ
        // الدور يذهب لمديره. وردُّ ٤٠٣ يُرسله يبحث عن دورٍ لن يجده.
        $r->assertStatus(402);
        $this->assertSame('PLAN_LIMIT_REACHED', $r->json('code'));

        // **والرقمان يُقالان** — «بلغتَ الحدّ» وحدها لا تقول كم ولا كم بقي.
        $this->assertSame($limit, $r->json('meta.usage.max'));
        $this->assertSame($limit, $r->json('meta.usage.used'));

        // ولم يُنشأ شيء.
        $this->assertSame($limit, MerchantProduct::where('merchant_user_id', $merchant->id)->count());
    }

    public function test_a_merchant_below_the_limit_still_adds_normally(): void
    {
        // **الحارسُ يُجرَّب من الجهتين** — وبوّابةٌ تمنع الجميع «تنجح» في
        // اختبار المنع وتُوقف المتجر.
        $merchant = $this->merchant(A::PLAN_STARTER, '967771960002');

        $this->addProduct($merchant, 'صنفٌ أوّل')->assertOk();

        $this->assertSame(1, MerchantProduct::where('merchant_user_id', $merchant->id)->count());
    }

    public function test_the_free_plan_is_told_which_plan_opens_products(): void
    {
        // المجّانيّة: `products => 0` — بيعٌ سريعٌ بلا أصناف.
        $merchant = $this->merchant(A::PLAN_FREE, '967771960003');

        $r = $this->addProduct($merchant, 'صنف');
        $r->assertStatus(402);

        // **ورسالةٌ بلا طريق خروجٍ تُخبره أنّه ممنوع ولا تقول كيف يُسمح له.**
        $this->assertNotNull($r->json('meta.unlock'),
            'مُنع بلا أن يُقال له أيُّ باقةٍ تفتحها');
    }

    public function test_an_unlimited_plan_is_not_blocked(): void
    {
        // `-1` تعني بلا حدّ — و`used >= max` على `-1` تُقفل كلَّ شيءٍ لو
        // نُسي فحصُ السالب. والمحرّكُ يفحصه (`max >= 0`)، وهذا يُثبته.
        // **الباقةُ تُقرأ من الجدول لا من اسمها**: `business` ليست بلا حدّ
        // — حدُّها ٣٠٠. وبلا حدٍّ هما `merchant_pro` و`enterprise`.
        $merchant = $this->merchant(A::PLAN_MERCHANT_PRO, '967771960004');
        $this->assertSame(-1, A::PLAN_LIMITS[A::PLAN_MERCHANT_PRO]['products']);

        MerchantProduct::insert(array_map(fn ($i) => [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'name' => 'صنف ' . $i, 'price' => '10.0000',
            'is_variant_parent' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], range(1, 400)));

        $this->addProduct($merchant, 'الرابع بعد الأربعمئة')->assertOk();
    }

    public function test_editing_an_existing_product_is_not_blocked_by_the_limit(): void
    {
        // **الحدُّ على الإنشاء لا على التصحيح.** تاجرٌ بلغ ١٠٠/١٠٠ يجب أن
        // يبقى قادراً على تصحيح سعرٍ خطأ — ومنعُه عقوبةٌ لا حدّ.
        $limit = A::PLAN_LIMITS[A::PLAN_STARTER]['products'];
        $merchant = $this->merchant(A::PLAN_STARTER, '967771960005');

        MerchantProduct::insert(array_map(fn ($i) => [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'name' => 'صنف ' . $i, 'price' => '10.0000',
            'is_variant_parent' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], range(1, $limit)));

        $id = MerchantProduct::where('merchant_user_id', $merchant->id)->value('id');

        $this->actingAs($merchant, 'api')
            ->putJson('/api/v1/amial/merchant/cashier/products/' . $id, ['price' => 55])
            ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ①ب الحدُّ على الأبواب الأربعة، والعدّادُ يعرف كلَّ قطاع
    // ══════════════════════════════════════════════════════════════════

    public function test_every_product_door_declares_a_limit(): void
    {
        // **أربعةُ أبوابٍ تُنشئ صنفاً** — كاشير · صيدليّة · جملة · وقود.
        // وكان الرابعُ بلا حدٍّ إطلاقاً، فالباقةُ تُباع بحدٍّ يلتفّ عليه
        // من يفتح محطّة. (كشفه محورُ المواصفة في مراجعةٍ آليّة.)
        $routes = file_get_contents(base_path('routes/api/amial.php'));

        foreach ([
            'CashierController::class, \'addProduct\'' => 'capability:',
            'PharmacyController::class, \'addProduct\'' => 'amial.usage:add_product',
            'WholesaleController::class, \'addProduct\'' => 'amial.usage:add_product',
            'FuelStationController::class, \'addProduct\'' => 'amial.usage:add_product',
        ] as $needle => $guard) {
            $at = mb_strpos($routes, $needle);
            $this->assertNotFalse($at, "بابٌ مفقود: {$needle}");

            $block = mb_substr($routes, $at, 320);
            $this->assertStringContainsString($guard, $block,
                "بابُ «{$needle}» بلا حدِّ باقة");
        }
    }

    public function test_an_unknown_vertical_is_counted_not_read_as_zero(): void
    {
        // **«غير معروف» ليس صفراً** (القاعدة السابعة). كان `countProducts`
        // يعرف قطاعين و`default => 0` — فقطاعٌ لا يعرفه العدّادُ يُقرأ
        // «بلا منتجات» فيمرّ أبداً مهما أضاف.
        $merchant = $this->merchant(A::PLAN_STARTER, '967771960020');

        DB::table('merchant_profiles')->where('user_id', $merchant->id)
            ->update(['business_type' => 'restaurant']);   // قطاعٌ بلا جدولٍ خاصّ

        MerchantProduct::insert(array_map(fn ($i) => [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'name' => 'صنف ' . $i, 'price' => '10.0000',
            'is_variant_parent' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], range(1, 7)));

        $counted = (new \ReflectionClass(\App\Services\UsageLimitService::class))
            ->getMethod('countProducts');
        $counted->setAccessible(true);

        $this->assertSame(7,
            $counted->invoke(app(\App\Services\UsageLimitService::class), $merchant->refresh(), 'auto'),
            'قطاعٌ لا جدولَ خاصَّ له يُعدّ صفراً — فحدُّ الباقة لا يُفرض عليه');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ①ج تبنّي صنفٍ من الكتالوج — الاتّفاق المسبق
    // ══════════════════════════════════════════════════════════════════

    private function catalogEntry(string $barcode, string $name = 'شاي ليبتون'): void
    {
        DB::table('product_catalog_entries')->insert([
            'entry_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'barcode' => $barcode, 'name' => $name,
            'category' => 'مشروبات', 'unit' => 'قطعة',
            'status' => 'verified', 'adoption_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_merchant_adopts_a_catalog_item_in_one_press(): void
    {
        // «إضافة منتج مع باركود ومعلومات، ثمّ يستطيع التاجرُ **تحميلَه**
        // إلى منتجاته بدل إضافته يدويّاً» — والنصفُ الناقصُ كان الضغطة.
        $merchant = $this->merchant(A::PLAN_STARTER, '967771960021');
        $this->catalogEntry('6281000900001');

        $r = $this->actingAs($merchant, 'api')
            ->postJson('/api/v1/amial/merchant/cashier/products/adopt', [
                'barcode' => '6281000900001',
                'price' => 350,
                'quantity' => 12,
            ])->assertCreated();

        $product = MerchantProduct::where('merchant_user_id', $merchant->id)->first();

        $this->assertNotNull($product, 'ضُغط التبنّي ولا صنفَ في القاعدة');
        $this->assertSame('شاي ليبتون', $product->name, 'الاسمُ لم يأتِ من الكتالوج');
        $this->assertSame('مشروبات', $product->category);
        $this->assertSame('6281000900001', $product->barcode);

        // **ويُقال من أين جاء** — اسمٌ لم يكتبه التاجرُ يستحقّ مصدراً.
        $this->assertSame('شاي ليبتون', $r->json('meta.adopted_from.name'));

        // **والعدّادُ يقيس النفعَ لا الكتابة** — كان يزيده الاقتراحُ وحدَه.
        $this->assertSame(1, (int) DB::table('product_catalog_entries')
            ->where('barcode', '6281000900001')->value('adoption_count'));
    }

    public function test_the_same_item_is_not_adopted_twice(): void
    {
        // ولولا هذا لأنتجت الضغطتان صنفين بالباركود نفسِه، فيُمسح فيُوجد
        // اثنان ولا يُعرف أيُّهما يُباع.
        $merchant = $this->merchant(A::PLAN_STARTER, '967771960022');
        $this->catalogEntry('6281000900002');

        $body = ['barcode' => '6281000900002', 'price' => 100];

        $this->actingAs($merchant, 'api')
            ->postJson('/api/v1/amial/merchant/cashier/products/adopt', $body)->assertCreated();

        $this->actingAs($merchant, 'api')
            ->postJson('/api/v1/amial/merchant/cashier/products/adopt', $body)
            ->assertStatus(422);

        $this->assertSame(1, MerchantProduct::where('merchant_user_id', $merchant->id)->count());
    }

    public function test_adopting_is_bound_by_the_plan_limit_too(): void
    {
        // **بابُ التبنّي بابُ إنشاءٍ أيضاً** — ولو تُرك بلا حدٍّ لصار طريقاً
        // يلتفّ على الباقة بمسحِ باركود.
        $limit = A::PLAN_LIMITS[A::PLAN_STARTER]['products'];
        $merchant = $this->merchant(A::PLAN_STARTER, '967771960023');
        $this->catalogEntry('6281000900003');

        MerchantProduct::insert(array_map(fn ($i) => [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'name' => 'صنف ' . $i, 'price' => '10.0000',
            'is_variant_parent' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], range(1, $limit)));

        $this->actingAs($merchant, 'api')
            ->postJson('/api/v1/amial/merchant/cashier/products/adopt', [
                'barcode' => '6281000900003', 'price' => 100,
            ])->assertStatus(402);
    }

    public function test_the_app_offers_the_adopt_press(): void
    {
        // القاعدة ١٢: نقطةٌ بلا زرٍّ ليست ظهوراً.
        $app = base_path('../02_flutter_app/lib');

        $screen = file_get_contents($app . '/features/merchant/screens/cashier_products_screen.dart');
        $this->assertStringContainsString("Key('catalog-adopt')", $screen,
            'لا زرَّ تبنٍّ في شاشة الأصناف');
        $this->assertStringContainsString('adoptFromCatalog', $screen);

        foreach (['controllers/cashier_controller.dart',
                  'domain/repositories/cashier_repo.dart'] as $f) {
            $this->assertStringContainsString('adoptFromCatalog',
                file_get_contents($app . '/features/merchant/' . $f),
                "السلسلةُ مقطوعةٌ عند: {$f}");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② المتغيّرات — يُوصَل إليها من التطبيق
    // ══════════════════════════════════════════════════════════════════

    public function test_the_app_has_a_screen_that_generates_variants(): void
    {
        $app = base_path('../02_flutter_app/lib');

        // القاعدة ١٢: المستودعُ يحمل النداءَ منذ بُني القطاع — وذاك ليس ظهوراً.
        $screen = $app . '/features/retail/screens/product_variants_screen.dart';
        $this->assertFileExists($screen, 'لا شاشةَ متغيّرات في التطبيق');

        $code = file_get_contents($screen);
        $this->assertStringContainsString('generateVariants', $code);

        // والمتحكّمُ يصل بين الشاشة والمستودع.
        $ctrl = file_get_contents($app . '/features/retail/controllers/retail_vertical_controller.dart');
        $this->assertStringContainsString('generateVariants', $ctrl,
            'الشاشةُ تنادي متحكّماً لا يملك الدالّة');
    }

    public function test_the_variants_screen_is_reachable_from_the_products_list(): void
    {
        // **زرٌّ لم يُضغط ليس مبنيّاً** — وشاشةٌ لا يُوصل إليها كذلك.
        $list = file_get_contents(base_path(
            '../02_flutter_app/lib/features/merchant/screens/cashier_products_screen.dart'));

        $this->assertStringContainsString('ProductVariantsScreen', $list,
            'قائمةُ الأصناف بلا مدخلٍ إلى المتغيّرات');
        $this->assertStringContainsString('product_variants_screen.dart', $list,
            'استُعمل الصنفُ بلا استيراده — لا يُصرَّف');
    }

    public function test_the_variants_endpoint_generates_a_product_per_combination(): void
    {
        $merchant = $this->merchant(A::PLAN_MERCHANT_PRO, '967771960006');

        $parent = MerchantProduct::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'name' => 'قميص', 'price' => '5000.0000',
            'is_variant_parent' => false, 'is_active' => true,
        ]);

        $this->actingAs($merchant, 'api')
            ->postJson('/api/v1/amial/merchant/retail/products/' . $parent->id . '/variants', [
                'axes' => ['اللون' => ['أحمر', 'أزرق'], 'المقاس' => ['S', 'L']],
            ])->assertSuccessful();

        // ٢ × ٢ = ٤.
        $this->assertSame(4, MerchantProduct::where('parent_product_id', $parent->id)->count());

        // **والأبُ يصير مِظلّةً لا يُباع** — وبيعُه يعني بيعَ قميصٍ بلا لون.
        $this->assertTrue((bool) $parent->refresh()->is_variant_parent);
    }

    public function test_variants_do_not_count_against_the_product_quota_twice(): void
    {
        // `usageFor` تستثني `is_variant_parent` — فالأبُ لا يُحسب مع أبنائه.
        // ولو حُسب لاستهلك قميصٌ بأربعة ألوانٍ خمسةَ مقاعدَ لا أربعة.
        $merchant = $this->merchant(A::PLAN_MERCHANT_PRO, '967771960007');

        $parent = MerchantProduct::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'name' => 'حذاء', 'price' => '3000.0000',
            'is_variant_parent' => false, 'is_active' => true,
        ]);

        $this->actingAs($merchant, 'api')
            ->postJson('/api/v1/amial/merchant/retail/products/' . $parent->id . '/variants', [
                'axes' => ['المقاس' => ['40', '41', '42']],
            ])->assertSuccessful();

        // **يُسأل المحرّكُ لا الجدول.** أوّلُ صياغةٍ عدّت الصفوفَ بنفس
        // شرط الخدمة، فكانت تُثبت أنّ الشرطَ يساوي نفسَه — وجُرّبت بالعكس
        // (نُزع الشرطُ من الخدمة) فمرّت. فصار السؤالُ للخدمة نفسِها.
        $usage = app(\App\Services\Access\EntitlementService::class)
            ->state($merchant->refresh(), A::F_PRODUCTS)['usage'];

        $this->assertSame(3, $usage['used'] ?? null,
            'الأبُ يُحسب مع أبنائه — فالمتغيّراتُ تأكل من الحصّة مرّتين');
    }
}
