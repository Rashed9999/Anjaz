<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CashierService;
use App\Services\Retail\ProductCatalogService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-VARIANT-EDITOR-001 — **التوليدُ كان باباً بلا عودة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * قِيل للتاجر: «٢ متغيّراً — و١٠ وحداتٍ تنتظر التوزيع على المتغيّرات».
 * **ثمّ لا مكانَ يُوزَّع فيه**: لا مسارَ يقرأ متغيّراتِ صنف، ولا شاشةَ
 * تُدخِل سعرَ نوعٍ ولا مخزونَه.
 *
 * **وعطلٌ ثانٍ وُلد من إصلاحي أنا** — وهو أخطرُهما:
 *
 * استثناءُ مِظلّة المتغيّرات من `listProducts` صحيحٌ في **شبكة البيع**،
 * لكنّ **شاشةَ إدارة المنتجات تقرأ المسارَ نفسَه**. فبعد الإصلاح اختفت
 * المِظلّةُ عن الإدارة أيضاً: **لا تعديلَ اسمٍ، ولا وصولَ إلى أنواعها،
 * ولا بابَ إليها إطلاقاً** — والمخزونُ الذي قِيل «ينتظر التوزيع» يبقى
 * منتظراً أبداً.
 *
 * **ولا يمسكه شيء**: الاستعلامُ يعمل، والشاشةُ تُبنى، والقائمةُ تُعرَض
 * ناقصةً صفّاً واحداً لا يفتقده إلّا من يبحث عنه.
 */
class VariantEditorReachableGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => 'retail',
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'single_receive_limit' => '5000000', 'daily_receive_limit' => '50000000',
        ]);
    }

    private function shirtWithVariants(string $qty = '10'): MerchantProduct
    {
        $shirt = MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'قميص', 'price' => '2000', 'quantity' => $qty, 'is_active' => true,
        ]);

        app(ProductCatalogService::class)->generateVariants(
            $this->merchant, $shirt->id, ['اللون' => ['أحمر', 'أزرق']]);

        return $shirt->fresh();
    }

    private function app(string $rel): string
    {
        $p = dirname(base_path()).'/02_flutter_app/'.$rel;
        $this->assertFileExists($p, "مفقود: {$rel}");

        return (string) file_get_contents($p);
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① شاشةُ الإدارة ترى المِظلّة، وشبكةُ البيع لا تراها.**
     *
     * وهذا الفرقُ هو كلُّ المسألة: استثناءٌ في مكانه وحضورٌ في مكانه.
     */
    /** @test */
    public function the_catalogue_sees_the_umbrella_while_the_till_does_not(): void
    {
        $shirt = $this->shirtWithVariants();
        $svc = app(CashierService::class);

        $till = $svc->listProducts($this->merchant)->pluck('id')->all();
        $this->assertNotContains($shirt->id, $till,
            '**المِظلّةُ عادت إلى شبكة البيع** — تُقرأ «نفذ» ولا تُباع.');

        $catalogue = $svc->listProducts($this->merchant, null, true)->pluck('id')->all();
        $this->assertContains($shirt->id, $catalogue,
            '**المِظلّةُ غائبةٌ عن شاشة إدارة المنتجات** — فلا بابَ إليها '
            .'إطلاقاً: لا تعديلَ اسمٍ ولا وصولَ إلى أنواعها، والمخزونُ '
            .'الذي «ينتظر التوزيع» يبقى منتظراً أبداً.');

        // **وصفةُ المِظلّة تُقال للشاشة** — فزرُّ «الأنواع» لا يُعرَض إلّا
        // عليها، وشاشةٌ فارغةٌ على صنفٍ عاديٍّ تُقرأ عطلاً.
        $row = $svc->listProducts($this->merchant, null, true)
            ->firstWhere('id', $shirt->id);
        $this->assertTrue($row->is_variant_parent,
            '`is_variant_parent` غائبٌ عن الردّ — فالشاشةُ لا تعرف أين تعرض الزرّ');
    }

    /**
     * **② والمسارُ يقرأ المتغيّراتِ ويقول المجموعَ الموزَّع.**
     */
    /** @test */
    public function the_variants_of_a_product_can_be_read_back(): void
    {
        $shirt = $this->shirtWithVariants();

        $r = $this->actingAs($this->merchant, 'api')
            ->getJson("/api/v1/amial/merchant/retail/products/{$shirt->id}/variants");

        $this->assertSame(200, $r->status(), 'تعذّرت القراءة: '.$r->json('message'));
        $this->assertCount(2, $r->json('data.variants'),
            '**لا مسارَ يقرأ ما وُلّد** — فالشاشةُ تولّد ولا ترى.');

        $names = collect($r->json('data.variants'))->pluck('display_name')->all();
        $this->assertContains('قميص · أحمر', $names);

        $this->assertSame('0.000', (string) $r->json('data.allocated_total'),
            'المجموعُ الموزَّعُ يجب أن يكون صفراً قبل التوزيع');
    }

    /**
     * **③ وتوزيعُ المخزون يقع بحركةِ مخزونٍ لا بكتابةٍ مباشرة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كتابةُ الرقم تجعل الجردَ **يقارن رقماً بنفسه** فيُخرج الفرقَ صفراً
     * دائماً، ويضيع أثرُ من غيّره ومتى. (القاعدة السادسة: الرقمُ يُحسب
     * من مصدره لا من عمودٍ مخزَّن.)
     */
    /** @test */
    public function setting_a_variants_stock_writes_a_stock_movement(): void
    {
        $shirt = $this->shirtWithVariants();
        $variant = MerchantProduct::where('parent_product_id', $shirt->id)->firstOrFail();

        $before = \App\Models\Retail\StockMovement::where('product_id', $variant->id)->count();

        $r = $this->actingAs($this->merchant, 'api')->postJson(
            "/api/v1/amial/merchant/retail/variants/{$variant->id}",
            ['price' => '2500', 'quantity' => '6']
        );

        $this->assertSame(200, $r->status(), 'تعذّر الحفظ: '.$r->json('message'));
        $this->assertSame('6.000', (string) $variant->fresh()->quantity,
            '**المخزونُ لم يُحفَظ** — فالتوزيعُ لا يقع.');
        $this->assertSame('2500.0000', (string) $variant->fresh()->price);

        $after = \App\Models\Retail\StockMovement::where('product_id', $variant->id)->count();
        $this->assertGreaterThan($before, $after,
            '**المخزونُ كُتب مباشرةً بلا حركة** — فيقارن الجردُ رقماً بنفسه '
            .'ويضيع أثرُ من غيّره ومتى.');

        // وبعد التوزيع يُقال المجموع.
        $list = $this->actingAs($this->merchant, 'api')
            ->getJson("/api/v1/amial/merchant/retail/products/{$shirt->id}/variants");
        $this->assertSame('6.000', (string) $list->json('data.allocated_total'));
    }

    /**
     * **④ ولا يُحرَّر متغيّرُ تاجرٍ آخر.**
     *
     * (القاعدة الثامنة: الهويّةُ تحدّد النطاق.)
     */
    /** @test */
    public function a_merchant_cannot_edit_another_merchants_variant(): void
    {
        $shirt = $this->shirtWithVariants();
        $variant = MerchantProduct::where('parent_product_id', $shirt->id)->firstOrFail();

        $other = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT, 'is_active' => 1,
        ]);
        MerchantProfile::create([
            'user_id' => $other->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => 'retail',
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'single_receive_limit' => '1', 'daily_receive_limit' => '1',
        ]);

        $r = $this->actingAs($other, 'api')->postJson(
            "/api/v1/amial/merchant/retail/variants/{$variant->id}", ['quantity' => '999']);

        $this->assertNotSame(200, $r->status(),
            '**تاجرٌ حرّر متغيّرَ غيره** — ورفعُ مخزونِ صنفٍ ليس ملكَه '
            .'يُفسد جردَه وتقاريرَه.');
        $this->assertSame('0.000', (string) $variant->fresh()->quantity,
            'تغيّر المخزونُ رغم الرفض');
    }

    /**
     * **⑤ والشاشاتُ الثلاثُ يُوصَل إليها.**
     *
     * فمسارٌ مسجَّلٌ وشاشةٌ مكتوبةٌ بلا رابطٍ يقود إليهما = **مبنيٌّ ولا
     * يُوصَل إليه**، وهو نمطُ العطل الأكثر تكراراً في هذا المشروع.
     */
    /** @test */
    public function the_new_screens_are_linked_from_the_products_screen(): void
    {
        $products = $this->app('lib/features/merchant/screens/cashier_products_screen.dart');

        $missing = [];

        if (!str_contains($products, 'VariantEditorScreen')) {
            $missing[] = 'شاشةُ «الأنواع» (سعرٌ ومخزونٌ لكلّ نوع)';
        }
        if (!str_contains($products, 'ProductAttributesScreen')) {
            $missing[] = 'شاشةُ «سمات المنتجات»';
        }
        // **وشاشةُ الإدارة تطلب المِظلّات** — وإلّا لم يظهر زرُّ الأنواع أبداً
        // لأنّ الصفَّ نفسَه لا يصل.
        if (!str_contains($products, 'includeVariantParents: true')) {
            $missing[] = 'طلبُ المِظلّات في شاشة الإدارة (فلا يصل صفُّها أصلاً)';
        }

        $this->assertSame([], $missing, sprintf(
            "**مبنيٌّ ولا يُوصَل إليه:**\n  %s\n\n"
            .'ملفُّ الشاشة: `lib/features/merchant/screens/cashier_products_screen.dart`',
            implode("\n  ", $missing)));

        // والشاشتان موجودتان فعلاً — لا اسمان في استيرادٍ ميّت.
        $this->app('lib/features/retail/screens/variant_editor_screen.dart');
        $this->app('lib/features/retail/screens/product_attributes_screen.dart');
    }
}
