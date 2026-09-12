<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductStock;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\Retail\StockService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-NEGATIVE-STOCK-001 — **السالبُ يصل من يستطيع إصلاحَه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **تصحيحٌ لِما قلتُه لصاحب المشروع، ويُكتب هنا لئلّا يُعاد.**
 *
 * عرضتُ عليه فكرةً من شاشات المنافس وقلتُ: «المخزونُ السالب **يمنع اليوم**
 * ويجب أن يُخيَّر». **وكان ذلك خطأً في القياس**: عمّمتُ من نداءٍ في
 * اختباري أنا (‏`$stock->move(...)` بلا `allowNegative`) على مسار المنتج.
 *
 * **والمقيسُ في مسار البيع عكسُه**: `decrementStockForSale` يمرّر
 * `allowNegative: true` **عمداً وبتعليقٍ يشرح لماذا**: «البضاعةُ خرجت من
 * الرفّ فعلاً، ورفضُها بعد خروجها لا يُفيد أحداً — لكنّ الرصيدَ السالب
 * يبقى ظاهراً». فالبيعُ **لا يُقفَل**، والسلوكُ أصحُّ ممّا في المنافس.
 *
 * ─────────────────────────────────────────────────────────────────────
 * **والعطلُ الحقيقيُّ في مكانٍ آخر، وهو الذي يحرسه هذا الملفّ:**
 *
 * السالبُ **إشارةٌ مقصودةٌ محفوظة**، ولوحةُ المنصّة تعرضها أوّلَ ما تعرض
 * (`negative_stock_rows`). **والتاجرُ صاحبُ المتجر لا يراها إطلاقاً.**
 *
 *   lowStock() يشترط  reorder_level > 0   ← وهو صفرٌ بالافتراض
 *   ⇒ صنفٌ رصيدُه ‎-٤٠ ولا حدَّ طلبٍ له **لا يظهر في أيّ شاشةٍ للتاجر**
 *
 * فالإشارةُ تصل من لا يملك النظرَ في الرفّ، وتُحجَب عمّن يملكه. **وهو
 * «مبنيٌّ ولا يُوصَل إليه» مقلوباً.**
 *
 * **وأنفعُ لحظةٍ لقولها هي لحظةُ البيع** — الكاشيرُ واقفٌ أمام الرفّ.
 */
class NegativeStockReachesTheMerchantGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private StockService $stock;
    private MerchantLocation $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => A::BIZ_RETAIL,
            // **المجّانيّة عمداً** — رؤيةُ خللٍ في بياناته لا تُشترى.
            'subscription_plan' => A::PLAN_FREE,
        ]);

        $this->stock = app(StockService::class);
        $this->main = $this->stock->defaultLocation($this->merchant->id);
    }

    private function product(string $onHand = '2'): MerchantProduct
    {
        $p = MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id, 'name' => 'حليب',
            'price' => '300', 'cost_price' => '200', 'quantity' => '0', 'is_active' => true,
        ]);

        if (bccomp($onHand, '0', 3) > 0) {
            $this->stock->move($p, $this->main, $onHand, 'opening_balance', actor: $this->merchant);
        }

        return $p;
    }

    /** بيعٌ عبر البابِ الذي يدخل منه إنسان. */
    private function sell(MerchantProduct $p, int $qty): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->merchant, 'api')->postJson(
            '/api/v1/amial/merchant/cashier/sales', [
                'total' => (string) ($qty * 300),
                'payment_method' => 'cash',
                'items' => [[
                    'product_id' => $p->id, 'name' => $p->name,
                    'qty' => $qty, 'price' => '300',
                ]],
            ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① السلوكُ القائمُ صحيحٌ ويُثبَّت
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **البيعُ لا يُقفَل حين ينقص المخزون — والسالبُ يبقى ظاهراً.**
     *
     * وهذا هو التصحيحُ نفسُه، مثبَّتاً بحارس: **لا يُقفَل شبّاكُ متجرٍ
     * لأنّ جردَه منحرف**. البضاعةُ في يد الزبون، ورفضُ البيعة بعد خروجها
     * يُنتج بيعةً غيرَ مسجَّلةٍ وجرداً منحرفاً معاً.
     */
    public function selling_more_than_the_shelf_shows_is_not_blocked(): void
    {
        app(CashierShiftService::class)->open($this->merchant, null, '0');

        $p = $this->product('2');

        $this->sell($p, 5)->assertOk();

        $onHand = (string) ProductStock::where('product_id', $p->id)
            ->where('location_id', $this->main->id)->value('on_hand');

        $this->assertSame(0, bccomp($onHand, '-3', 3),
            "المخزون بعد بيع ٥ من ٢ هو {$onHand} — والمنتظَر ‎-٣. "
            . 'والسالبُ يبقى ظاهراً: هو الإشارةُ على أنّ الجرد منحرف.');

        $this->assertDatabaseCount('merchant_sales', 1);
    }

    /**
     * @test
     *
     * **② والكاشيرُ يُخبَر لحظتَها — لا بعد يومين.**
     *
     * أنفعُ لحظةٍ لقول «نزل تحت الصفر» هي والكاشيرُ واقفٌ أمام الرفّ
     * يستطيع النظرَ فيه الآن.
     */
    public function the_cashier_is_told_which_lines_went_negative(): void
    {
        app(CashierShiftService::class)->open($this->merchant, null, '0');

        $p = $this->product('2');
        $r = $this->sell($p, 5)->assertOk();

        $lines = $r->json('meta.negative_stock');

        $this->assertIsArray($lines,
            'البيعُ نزل بالمخزون تحت الصفر ولم يُقَل للكاشير — '
            . 'فالإشارةُ تنتظر مالكاً يفتح شاشةً بعد يومين');
        $this->assertCount(1, $lines);
        $this->assertSame('حليب', $lines[0]['product']);
        $this->assertSame(0, bccomp((string) $lines[0]['on_hand'], '-3', 3));
        // **والناقصُ موجبٌ ليُقرأ** — «ينقص ٣» أوضحُ من «‎-٣».
        $this->assertSame(0, bccomp((string) $lines[0]['shortfall'], '3', 3));
    }

    /**
     * @test
     *
     * **③ ولا يُرسَل مفتاحٌ حين لا سالب.**
     *
     * قائمةٌ فارغةٌ في كلّ ردٍّ تُعوّد الشاشةَ على تجاهلها، **فلا تُرى يومَ
     * تمتلئ**. (وهو الدرسُ نفسُه من لافتة «كُسرت السلسلة»: تحذيرٌ يظهر بلا
     * سببٍ يُفقِد التحذيرَ معناه.)
     */
    public function a_healthy_sale_carries_no_warning_key(): void
    {
        app(CashierShiftService::class)->open($this->merchant, null, '0');

        $p = $this->product('50');
        $r = $this->sell($p, 2)->assertOk();

        $this->assertNull($r->json('meta.negative_stock'),
            'تحذيرٌ يُرسَل على بيعةٍ سليمة — فيُعتاد تجاهلُه يومَ يصدق');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ والتاجرُ يراه في شاشته — وهذا هو العطلُ الذي أُصلح
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **صنفٌ سالبٌ بلا حدِّ طلبٍ كان يختفي تماماً.**
     *
     * `lowStock()` يشترط `reorder_level > 0` وهو صفرٌ بالافتراض. فيُقاس
     * الفرقُ صراحةً: الصنفُ نفسُه غائبٌ عن «قرب النفاد» وحاضرٌ في «السالب».
     */
    public function a_negative_product_without_a_reorder_level_still_reaches_the_owner(): void
    {
        app(CashierShiftService::class)->open($this->merchant, null, '0');

        $p = $this->product('2');
        $this->sell($p, 5)->assertOk();

        // **وحدُّ الطلب صفرٌ** — كما يخرج كلُّ صنفٍ جديد.
        $this->assertSame(0, bccomp(
            (string) ProductStock::where('product_id', $p->id)->value('reorder_level'), '0', 3));

        $low = $this->stock->lowStock($this->merchant->id);
        $this->assertCount(0, $low,
            'تغيّر شرطُ «قرب النفاد» — ويجب أن يُراجَع هذا الحارسُ معه');

        $negative = $this->stock->negativeStock($this->merchant->id);

        $this->assertCount(1, $negative,
            'الصنفُ السالبُ لا يظهر للتاجر في أيّ قائمة — '
            . 'والإشارةُ تصل المنصّةَ وحدَها وهي لا تنظر في رفّه');
        $this->assertSame('حليب', $negative[0]['product']);
        $this->assertSame(0, bccomp((string) $negative[0]['shortfall'], '3', 3));
    }

    /**
     * @test
     *
     * **⑤ ويصل شاشتَه فعلاً — لا الخدمةَ وحدَها.**
     *
     * (القاعدة الثانية عشرة: مبنيٌّ ولا يُوصَل إليه.) خدمةٌ تُرجع القائمةَ
     * ولا نقطةَ نهايةٍ تحملها ليست مبنيّة.
     */
    public function the_overview_endpoint_carries_it(): void
    {
        app(CashierShiftService::class)->open($this->merchant, null, '0');

        $p = $this->product('2');
        $this->sell($p, 5)->assertOk();

        $r = $this->actingAs($this->merchant, 'api')
            ->getJson('/api/v1/amial/merchant/retail/ops')->assertOk();

        // **والمفتاحُ يُقرأ من المصدر لا يُفترَض.** هذا المتحكّم يردّ
        // تحت `data` لا `meta` — والصيغتان قائمتان في المشروع، وافتراضُ
        // إحداهما يجعل حارساً سليماً يسقط على قراءةٍ لا على عطل.
        $rows = $r->json('data.negative_stock');

        $this->assertIsArray($rows, 'مركزُ العمليّات لا يحمل السالب');
        $this->assertCount(1, $rows);
        $this->assertSame('حليب', $rows[0]['product']);
    }

    /**
     * @test
     *
     * **⑥ ولا يُحرَس بقدرةٍ مدفوعة.**
     *
     * تنبيهُ النفاد خدمةٌ تُشترى («متى أطلب؟»)، **وهذا خللٌ في بياناته هو**
     * — وبيعُ رؤيةِ الخلل بيعُ أرقامٍ خاطئةٍ لمن دفع أقلّ. (وهو حدُّ
     * `core()` نفسُه المكتوب في سجلّ القدرات.)
     */
    public function seeing_your_own_broken_count_is_not_sold(): void
    {
        $this->assertSame(A::PLAN_FREE,
            MerchantProfile::where('user_id', $this->merchant->id)->value('subscription_plan'));

        app(CashierShiftService::class)->open($this->merchant, null, '0');
        $p = $this->product('2');
        $this->sell($p, 5)->assertOk();

        $r = $this->actingAs($this->merchant, 'api')
            ->getJson('/api/v1/amial/merchant/retail/ops')->assertOk();

        // **تنبيهُ النفاد مقفولٌ للمجّانيّ ويُقال ذلك** — وهو السلوكُ القائم.
        $this->assertNotNull($r->json('data.low_stock_locked'),
            'تغيّر تسعيرُ تنبيه النفاد — ويجب أن يُراجَع هذا الحارسُ معه');

        // **والسالبُ يصل رغم ذلك.**
        $this->assertCount(1, $r->json('data.negative_stock'),
            'السالبُ حُرِس بقدرةٍ مدفوعة — فبِيعت للتاجر رؤيةُ خللٍ في بياناته');
    }

    /**
     * @test
     *
     * **⑦ ويختفي حين يُصلَّح الجرد** — فالقائمةُ تُحسب من المخزون لا تُخزَّن.
     */
    public function it_clears_when_the_count_is_fixed(): void
    {
        app(CashierShiftService::class)->open($this->merchant, null, '0');

        $p = $this->product('2');
        $this->sell($p, 5)->assertOk();

        $this->assertCount(1, $this->stock->negativeStock($this->merchant->id));

        // توريدٌ يُصلح الرفّ
        $this->stock->move($p, $this->main, '10', 'purchase_receive', actor: $this->merchant);

        $this->assertCount(0, $this->stock->negativeStock($this->merchant->id),
            'السالبُ بقي بعد إصلاح الجرد — فالقائمةُ مخزَّنةٌ لا محسوبة');
    }
}
