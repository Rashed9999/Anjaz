<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use App\Models\Retail\SaleLine;
use App\Models\User;
use App\Services\CashierService;
use App\Services\Retail\SaleLineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — حرّاسُ أسطر المبيعة والتكلفة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأكبرُ ما تحرسه هذه الاختبارات رقمٌ يتغيّر بأثرٍ رجعيّ.**
 *
 * كان الربحُ يُحسب من `merchant_products.cost_price` الحاليّ. فمن اشترى
 * بـ٥٠٠ وباع بـ٨٠٠، ثمّ ارتفع سعرُ الشراء إلى ٧٠٠، **يُعاد حسابُ ربح
 * أمس** فيهبط من ٣٠٠ إلى ١٠٠. التقريرُ نفسُه يُطبع مرّتين بعددين — ولا
 * خطأ في أيّ سجلّ، ولا شيء يشير إلى أنّ أحدَ العددين كذب.
 */
class RetailSaleLinesGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        // بوّابةُ مسارات التاجر تطلب نوعاً وملفّاً موثَّقاً — وحارسٌ
        // يتخطّاها يقيس مساراً لا يسلكه أحد.
        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        \App\Models\MerchantProfile::create([
            'user_id' => $this->merchant->id, 'verification_status' => 'verified',
        ]);
    }

    /**
     * **و`cost_price` لا يقبل الفراغ في جدول المنتجات** — قِيس:
     * `Column 'cost_price' cannot be null`. فالتاجرُ الذي لم يُدخل تكلفةً
     * يُخزَّن له صفر، **والصفرُ هنا هو صورةُ «غير معروف»** لا سعرُ شراءٍ
     * حقيقيّ (لا أحدَ يشتري ببلاش).
     *
     * ولذلك `SaleLineService` يعامل الصفر معاملةَ المجهول، ولا ينقله إلى
     * السطر رقماً يُبنى عليه هامشُ ١٠٠٪.
     */
    private function product(string $price, ?string $cost, string $name = 'صنف'): MerchantProduct
    {
        $cost ??= '0';

        return MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => $name,
            'price' => $price,
            'cost_price' => $cost,
            'quantity' => '100',
            'is_active' => true,
        ]);
    }

    private function cashier(): CashierService
    {
        return app(CashierService::class);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الأسطرُ تُكتب
    // ══════════════════════════════════════════════════════════════════

    public function test_a_sale_writes_one_row_per_item(): void
    {
        $a = $this->product('100', '60', 'ماء');
        $b = $this->product('250', '150', 'عصير');

        $sale = $this->cashier()->recordSale(
            merchant: $this->merchant,
            total: '450',
            paymentMethod: 'cash',
            items: [
                ['product_id' => $a->id, 'name' => 'ماء', 'qty' => 2, 'price' => '100'],
                ['product_id' => $b->id, 'name' => 'عصير', 'qty' => 1, 'price' => '250'],
            ],
        );

        $lines = SaleLine::where('sale_id', $sale->id)->orderBy('id')->get();

        $this->assertCount(2, $lines, 'البيعةُ سطران والجدولُ لا يعرف إلّا واحداً');
        $this->assertSame($a->id, $lines[0]->product_id);
        $this->assertSame('2.000', $lines[0]->quantity);
        $this->assertSame('200.0000', $lines[0]->line_total);
        $this->assertSame($sale->sale_ulid, $lines[0]->sale_ulid);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② التكلفةُ تُجمَّد — **العطلُ الأصليّ**
    // ══════════════════════════════════════════════════════════════════

    public function test_cost_is_frozen_at_sale_time_and_a_later_purchase_does_not_rewrite_yesterday(): void
    {
        $p = $this->product('800', '500', 'إطار');

        $sale = $this->cashier()->recordSale(
            merchant: $this->merchant,
            total: '800',
            paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'name' => 'إطار', 'qty' => 1, 'price' => '800']],
        );

        $line = SaleLine::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('500.0000', $line->unit_cost);
        $this->assertSame(SaleLine::COST_CAPTURED, $line->cost_source);

        // اشترى الشحنةَ التالية أغلى — والفاتورةُ القديمة **لا تتغيّر**.
        $p->update(['cost_price' => '700']);

        $line->refresh();
        $this->assertSame('500.0000', $line->unit_cost,
            'تكلفةُ بيعةٍ ماضية تغيّرت لأنّ سعرَ الشراء ارتفع اليوم — '
            . 'وربحُ الشهر الماضي يُعاد حسابُه بأثرٍ رجعيّ');

        $report = $this->cashier()->profitReport($this->merchant, 7);
        $this->assertSame('500.0000', $report['totals']['cost']);
        $this->assertSame('300.0000', $report['totals']['profit']);
    }

    /** **جرّبَ الحارسَ بالعكس**: القراءةُ من المنتج تُسقطه. */
    public function test_reading_cost_from_the_product_today_would_break_this_guard(): void
    {
        $p = $this->product('800', '500', 'إطار');

        $this->cashier()->recordSale(
            merchant: $this->merchant,
            total: '800',
            paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'name' => 'إطار', 'qty' => 1, 'price' => '800']],
        );

        $p->update(['cost_price' => '700']);

        // الطريقةُ القديمة — وهذا ما كان التقريرُ يفعله حرفيّاً.
        $oldWay = (string) MerchantProduct::find($p->id)->cost_price;

        $this->assertNotSame($oldWay, '500.0000',
            'المنتجُ لم يتغيّر أصلاً — فالحارسُ لا يفرّق بين الطريقتين ولا يحرس');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ «غير معروف» ليس صفراً (القاعدة ٧)
    // ══════════════════════════════════════════════════════════════════

    public function test_an_unknown_cost_is_null_not_zero(): void
    {
        $p = $this->product('300', null, 'بضاعة بلا تكلفة');

        $sale = $this->cashier()->recordSale(
            merchant: $this->merchant,
            total: '300',
            paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'name' => 'بضاعة بلا تكلفة', 'qty' => 1, 'price' => '300']],
        );

        $line = SaleLine::where('sale_id', $sale->id)->firstOrFail();

        $this->assertNull($line->unit_cost, 'تكلفةٌ مجهولةٌ كُتبت صفراً — والهامشُ يُقرأ ١٠٠٪');
        $this->assertNull($line->line_cost);
        $this->assertSame(SaleLine::COST_UNKNOWN, $line->cost_source);
    }

    public function test_the_profit_report_says_how_much_of_it_it_could_not_cost(): void
    {
        $known = $this->product('200', '120', 'معروفة');
        $blind = $this->product('300', null, 'مجهولة');

        $this->cashier()->recordSale(
            merchant: $this->merchant, total: '500', paymentMethod: 'cash',
            items: [
                ['product_id' => $known->id, 'name' => 'معروفة', 'qty' => 1, 'price' => '200'],
                ['product_id' => $blind->id, 'name' => 'مجهولة', 'qty' => 1, 'price' => '300'],
            ],
        );

        $r = $this->cashier()->profitReport($this->merchant, 7);

        $this->assertSame('120.0000', $r['totals']['cost'],
            'تكلفةُ سطرٍ مجهولٍ حُسبت صفراً فدخلت المجموع');
        $this->assertSame(1, $r['cost_coverage']['unknown_cost_lines']);
        $this->assertSame('300.0000', $r['cost_coverage']['unknown_cost_revenue']);
        $this->assertNotNull($r['cost_coverage']['note'],
            'الهامشُ محسوبٌ على جزءٍ من الإيراد ويُعرض كأنّه على كلّه — بلا كلمة');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ المفتاحان — العطلُ المقيس
    // ══════════════════════════════════════════════════════════════════

    public function test_qty_and_quantity_produce_the_same_line(): void
    {
        $p = $this->product('50', '30', 'طبق');

        $withQty = $this->cashier()->recordSale(
            merchant: $this->merchant, total: '250', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'name' => 'طبق', 'qty' => 5, 'price' => '50']],
        );
        $withQuantity = $this->cashier()->recordSale(
            merchant: $this->merchant, total: '250', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'name' => 'طبق', 'quantity' => 5, 'price' => '50']],
        );

        $this->assertSame(
            SaleLine::where('sale_id', $withQty->id)->value('quantity'),
            SaleLine::where('sale_id', $withQuantity->id)->value('quantity'),
            'الكاشير يرسل qty والمطعمُ يرسل quantity — والجدولُ يفهم واحداً'
        );
    }

    public function test_the_daily_report_counts_real_quantities_not_one_per_line(): void
    {
        $p = $this->product('50', '30', 'طبق سمك');

        // **الشكلُ الذي يرسله المطعم** — وكان التقريرُ يقرأ `qty` وحدَه،
        // فيعدّ عشرين طبقاً طبقاً واحداً.
        $this->cashier()->recordSale(
            merchant: $this->merchant, total: '1000', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'name' => 'طبق سمك', 'quantity' => 20, 'price' => '50']],
        );

        $r = $this->cashier()->dailyReport($this->merchant);
        $top = collect($r['top_products'])->firstWhere('name', 'طبق سمك');

        $this->assertNotNull($top, 'الصنفُ غاب عن «أكثر المبيعات»');
        $this->assertSame(20, $top['qty'],
            'عشرون طبقاً ظهرت ' . ($top['qty'] ?? '؟') . ' — والقرارُ يُبنى على هذا الرقم');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ الحساب
    // ══════════════════════════════════════════════════════════════════

    public function test_line_total_subtracts_the_line_discount(): void
    {
        $p = $this->product('100', '60');

        $sale = $this->cashier()->recordSale(
            merchant: $this->merchant, total: '270', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'qty' => 3, 'price' => '100', 'discount' => '30']],
        );

        $line = SaleLine::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('270.0000', $line->line_total);
        $this->assertSame('180.0000', $line->line_cost);
    }

    public function test_a_line_discount_larger_than_the_line_never_goes_negative(): void
    {
        $p = $this->product('100', '60');

        $sale = $this->cashier()->recordSale(
            merchant: $this->merchant, total: '1', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'qty' => 1, 'price' => '100', 'discount' => '500']],
        );

        $this->assertSame('0.0000', SaleLine::where('sale_id', $sale->id)->value('line_total'),
            'سطرٌ بإيرادٍ سالب — يُنقص المبيعات ويُقرأ مرتجعاً لم يقع');
    }

    public function test_a_zero_quantity_item_writes_no_line(): void
    {
        $p = $this->product('100', '60');

        $sale = $this->cashier()->recordSale(
            merchant: $this->merchant, total: '100', paymentMethod: 'cash',
            items: [
                ['product_id' => $p->id, 'qty' => 1, 'price' => '100'],
                ['product_id' => $p->id, 'qty' => 0, 'price' => '100'],
            ],
        );

        $this->assertSame(1, SaleLine::where('sale_id', $sale->id)->count());
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑥ اللقطةُ لا المرجع
    // ══════════════════════════════════════════════════════════════════

    public function test_renaming_the_product_does_not_rewrite_a_printed_invoice(): void
    {
        $p = $this->product('100', '60', 'اسم قديم');

        $sale = $this->cashier()->recordSale(
            merchant: $this->merchant, total: '100', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'name' => 'اسم قديم', 'qty' => 1, 'price' => '100']],
        );

        $p->update(['name' => 'اسم جديد']);

        $this->assertSame('اسم قديم', SaleLine::where('sale_id', $sale->id)->value('name'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑦ مرتجعُ سطرٍ واحد — ما كان مستحيلاً
    // ══════════════════════════════════════════════════════════════════

    public function test_a_single_line_can_be_identified_and_has_a_refundable_quantity(): void
    {
        $a = $this->product('100', '60', 'صنف أ');
        $b = $this->product('200', '150', 'صنف ب');

        $sale = $this->cashier()->recordSale(
            merchant: $this->merchant, total: '400', paymentMethod: 'cash',
            items: [
                ['product_id' => $a->id, 'qty' => 2, 'price' => '100'],
                ['product_id' => $b->id, 'qty' => 1, 'price' => '200'],
            ],
        );

        $line = SaleLine::where('sale_id', $sale->id)->where('product_id', $a->id)->firstOrFail();
        $this->assertSame('2.000', $line->refundableQuantity());

        $line->update(['returned_quantity' => '1']);
        $this->assertSame('1.000', $line->fresh()->refundableQuantity(),
            'المرتجعُ الجزئيّ لا يُنقص المتاح — فيُرتجع الصنفُ مرّتين');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑧ المخزون يُخصم من السطر
    // ══════════════════════════════════════════════════════════════════

    public function test_stock_is_decremented_by_the_line_quantity_from_either_key(): void
    {
        $p = $this->product('100', '60');
        $stock = app(\App\Services\Retail\StockService::class);

        $this->cashier()->recordSale(
            merchant: $this->merchant, total: '400', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'quantity' => 4, 'price' => '100']],
        );

        $location = $stock->defaultLocation($this->merchant->id);
        $this->assertSame('96.000', $stock->available($p->id, $location->id),
            'خُصم واحدٌ بدل أربعة — والمخزونُ ينحرف صامتاً كلّ يوم');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑨ البيعُ المعلّق: سطورُه تُكتب ومخزونُه لا يُخصم
    // ══════════════════════════════════════════════════════════════════

    public function test_a_pending_payment_sale_has_lines_but_no_stock_movement_yet(): void
    {
        $p = $this->product('100', '60');
        $stock = app(\App\Services\Retail\StockService::class);
        $location = $stock->defaultLocation($this->merchant->id);

        $sale = $this->cashier()->recordSale(
            merchant: $this->merchant, total: '300', paymentMethod: 'amial_pay',
            items: [['product_id' => $p->id, 'qty' => 3, 'price' => '100']],
        );

        $this->assertSame('pending_payment', $sale->status);
        $this->assertSame(1, SaleLine::where('sale_id', $sale->id)->count(),
            'بيعةٌ بلا أسطر — فإن دُفعت لم يُعرف ما بيع');
        $this->assertSame('100.000', $stock->available($p->id, $location->id),
            'خُصم المخزونُ لبيعةٍ لم تُدفَع بعد');

        $this->cashier()->linkPayment($sale->sale_ulid, 'TX123', $this->merchant->id);

        $this->assertSame('97.000', $stock->available($p->id, $location->id),
            'دُفعت البيعةُ ولم يُخصم المخزون');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑩ الهجرة التاريخيّة — **لا تدّعي معرفة**
    // ══════════════════════════════════════════════════════════════════

    public function test_the_backfill_marks_historic_cost_as_estimated_not_captured(): void
    {
        // بيعةٌ كُتبت كما كانت تُكتب قبل المرحلة ١: JSON بلا أسطر.
        $p = $this->product('100', '60', 'قديم');

        $sale = MerchantSale::create([
            'sale_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'merchant_user_id' => $this->merchant->id,
            'total_amount' => '200',
            'payment_method' => 'cash',
            'status' => 'completed',
            'items' => [['product_id' => $p->id, 'name' => 'قديم', 'qty' => 2, 'price' => '100']],
        ]);

        $this->assertSame(0, SaleLine::where('sale_id', $sale->id)->count());

        // تشغيلُ منطق الهجرة نفسِه على صفٍّ قديم.
        $migration = require database_path('migrations/2026_08_10_100000_amial_retail_sale_lines.php');
        $backfill = (fn () => $this->backfill())->call($migration);

        $line = SaleLine::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame(SaleLine::COST_ESTIMATED, $line->cost_source,
            'تكلفةٌ مقدَّرةٌ وُسمت «ملتقَطة» — فيُبنى عليها ربحٌ يُظنّ مقيساً');
        $this->assertFalse($line->hasKnownCost());
        $this->assertSame('2.000', $line->quantity);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑪ التطبيع في موضعٍ واحد
    // ══════════════════════════════════════════════════════════════════

    public function test_the_normalizer_is_the_only_place_that_knows_both_keys(): void
    {
        $svc = app(SaleLineService::class);

        $this->assertSame('7', $svc->normalize(['qty' => 7])['quantity']);
        $this->assertSame('7', $svc->normalize(['quantity' => 7])['quantity']);
        $this->assertNull($svc->normalize(['qty' => 0]), 'كمّيّةُ صفرٍ صارت سطراً');
        $this->assertNull($svc->normalize(['qty' => '-3']), 'كمّيّةٌ سالبةٌ صارت سطراً');
        $this->assertSame('صنف', $svc->normalize(['qty' => 1])['name'], 'سطرٌ بلا اسم');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑫ البابُ — القاعدة ١٢
    // ══════════════════════════════════════════════════════════════════

    public function test_the_sale_detail_endpoint_exposes_the_lines(): void
    {
        $known = $this->product('200', '120', 'معروفة');
        $blind = $this->product('300', null, 'مجهولة');

        $sale = $this->cashier()->recordSale(
            merchant: $this->merchant, total: '500', paymentMethod: 'cash',
            items: [
                ['product_id' => $known->id, 'qty' => 1, 'price' => '200'],
                ['product_id' => $blind->id, 'qty' => 1, 'price' => '300'],
            ],
        );

        $res = $this->actingAs($this->merchant, 'api')
            ->getJson('/api/v1/amial/merchant/cashier/sales/' . $sale->sale_ulid);

        $res->assertOk();
        $body = $res->json('meta') ?? $res->json();

        $this->assertCount(2, $body['lines'], 'البابُ مفتوحٌ والغرفةُ فارغة');
        $this->assertNull(
            collect($body['lines'])->firstWhere('name', 'مجهولة')['unit_cost'],
            'تكلفةٌ مجهولةٌ أُرسلت صفراً — والشاشةُ تعرضها ربحاً كاملاً'
        );
        $this->assertSame(1, $body['totals']['unknown_cost_lines']);
        $this->assertSame('120.0000', $body['totals']['known_cost']);
    }
}
