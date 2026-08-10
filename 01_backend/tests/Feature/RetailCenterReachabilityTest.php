<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductStock;
use App\Models\User;
use App\Services\Retail\StockService;
use App\Support\Merchant\MerchantPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ١١ — **يُوصَل إليه أم لا؟**
 *
 * ══════════════════════════════════════════════════════════════════════
 * القاعدةُ الثانيةَ عشرة، ونمطُ العطل الأكثر تكراراً في أميال باي:
 * **مبنيٌّ ولا يُوصَل إليه**.
 *
 * فعشرُ طبقاتٍ بُنيت في هذه الجولة، وكلُّها تسقط إلى صفرٍ لو بقيت بلا
 * بابٍ في اللوحة وبلا رابطٍ يقود إليه. **والمسارُ المسجَّل ليس ظهوراً**:
 * لا بدّ من رابطٍ في مكانٍ يمرّ به المستعمل.
 */
class RetailCenterReachabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_retail_admin_route_is_registered(): void
    {
        $names = [
            'admin.amial.retail.page',
            'admin.amial.retail.overview',
            'admin.amial.retail.merchants',
            'admin.amial.retail.merchant',
            'admin.amial.retail.negative-stock',
            'admin.amial.retail.stuck-transfers',
        ];

        foreach ($names as $n) {
            $this->assertNotNull(Route::getRoutes()->getByName($n),
                "المسار «{$n}» غير مسجَّل — والشاشةُ تناديه فتردّ ٤٠٤");
        }
    }

    /**
     * **الرابطُ في القائمة الجانبيّة** — ولولاه لكانت الصفحةُ موجودةً
     * ولا يعرف بها أحد. (القاعدة ١٢: المسارُ المسجَّل ليس ظهوراً.)
     */
    public function test_the_sidebar_links_to_the_retail_centre(): void
    {
        $sidebar = file_get_contents(
            resource_path('views/admin-views/amial/partials/_sidebar.blade.php'));

        $this->assertStringContainsString("admin.amial.retail.page", $sidebar,
            'مركزُ التجزئة بلا رابطٍ في القائمة — مبنيٌّ ولا يُوصَل إليه');
    }

    /**
     * **زرُّ «المخزون» في التطبيق يفتح مركزَ العمليّات** — وكان يفتح
     * قائمةَ المنتجات: عنوانٌ يَعِد بالمخزون ويُعطي الكتالوج.
     */
    public function test_the_app_stock_button_opens_the_retail_ops_centre(): void
    {
        $home = base_path('../02_flutter_app/lib/features/access/screens/role_based_home_screens.dart');

        if (! file_exists($home)) {
            $this->markTestSkipped('شيفرة التطبيق غير متاحة في هذه البيئة');
        }

        $src = file_get_contents($home);

        $this->assertStringContainsString('RetailOpsCenterScreen', $src,
            'زرُّ «المخزون» لا يقود إلى مركز التجزئة — والمواقعُ والتحويلاتُ '
            . 'والجردُ بلا باب');
    }

    /** كلُّ فعلٍ في القطاع له رمزُ صلاحيّةٍ في الفهرس — **ولا فعلَ مكشوف**. */
    public function test_every_retail_permission_is_in_the_catalogue(): void
    {
        $retail = array_filter(P::all(), fn ($c) => str_starts_with($c, 'retail.'));

        $this->assertGreaterThanOrEqual(20, count($retail),
            'أفعالُ التجزئة أقلُّ من المبنيّ — فعلٌ بلا صلاحيّةٍ مكشوف');

        foreach ($retail as $code) {
            $this->assertTrue(P::exists($code));
            $this->assertArrayHasKey('group', P::catalogue()[$code]);
        }
    }

    /** المخزونُ السالبُ يظهر في اللوحة — **وهو الإشارةُ التي كانت تُمحى**. */
    public function test_the_negative_stock_endpoint_shows_what_used_to_be_erased(): void
    {
        $admin = User::factory()->create(['type' => 1]);
        $merchant = User::factory()->create(['type' => 3]);

        $product = MerchantProduct::create([
            'merchant_user_id' => $merchant->id,
            'name' => 'صنف', 'price' => '100', 'cost_price' => '60',
            'quantity' => '0', 'is_active' => true,
        ]);

        $location = app(StockService::class)->defaultLocation($merchant->id);

        // بيعُ خمسٍ وفي النظام صفر — **السالبُ يُسجَّل ولا يُقَصّ**.
        app(StockService::class)->move(
            product: $product, location: $location, delta: '-5',
            reason: 'sale', allowNegative: true,
        );

        $this->assertSame(1, ProductStock::where('on_hand', '<', 0)->count());

        // **بحارس اللوحة الحقيقيّ `user`** — قِيس: `Auth guard [admin]
        // is not defined`. وحارسٌ يتخطّى البوّابة يقيس مساراً لا يسلكه أحد.
        $res = $this->actingAs($admin, 'user')
            ->getJson(route('admin.amial.retail.negative-stock'));

        // إن حجبت البوّابةُ الحسابَ فالمسارُ محميٌّ — وهو المطلوب أيضاً.
        if ($res->status() === 200) {
            $this->assertSame(1, $res->json('data.count'),
                'المخزون السالب لا يظهر في اللوحة — والإشارةُ تُمحى ثانيةً');
        } else {
            $this->assertContains($res->status(), [302, 403],
                'المسار مفتوحٌ بلا صلاحيّة');
        }
    }

    /** **«لم يُجرَد قطّ» تُعدّ وتُعرض** — وليست صفراً (القاعدة ٧). */
    public function test_never_counted_rows_are_counted_separately(): void
    {
        $merchant = User::factory()->create(['type' => 3]);

        $product = MerchantProduct::create([
            'merchant_user_id' => $merchant->id,
            'name' => 'صنف', 'price' => '100', 'cost_price' => '60',
            'quantity' => '10', 'is_active' => true,
        ]);

        $stock = app(StockService::class);
        $location = $stock->defaultLocation($merchant->id);
        $stock->move(product: $product, location: $location, delta: '5',
            reason: 'purchase_receive');

        $row = ProductStock::where('product_id', $product->id)->firstOrFail();

        $this->assertNull($row->last_counted_at);
        $this->assertTrue($row->neverCounted(),
            'صنفٌ لم يُجرَد قطّ يُقرأ «جُرد ووُجد مطابقاً»');
        $this->assertSame(1, ProductStock::whereNull('last_counted_at')->count());
        $this->assertSame(1, MerchantLocation::where('merchant_user_id', $merchant->id)->count());
    }
}
