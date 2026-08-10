<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductPriceVersion;
use App\Models\Retail\ProductStock;
use App\Models\Retail\SaleLine;
use App\Models\Retail\StockCount;
use App\Models\Retail\StockReservation;
use App\Models\Retail\StockTransfer;
use App\Models\User;
use App\Services\CashierService;
use App\Services\Retail\ProductCatalogService;
use App\Services\Retail\ProductPriceService;
use App\Services\Retail\SaleReturnService;
use App\Services\Retail\StockCountService;
use App\Services\Retail\StockReservationService;
use App\Services\Retail\StockService;
use App\Services\Retail\StockTransferService;
use App\Services\Retail\StockWasteService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المراحل ٢–٩ — حرّاسُ العمليّات.
 *
 * ══════════════════════════════════════════════════════════════════════
 * وأربعةُ أعطالٍ تحرسها هذه الاختبارات، **ولا واحدٌ منها يُنتج خطأً في
 * أيّ سجلّ**:
 *
 * ① تحويلٌ بلا مراحل: بضاعةٌ تختفي بين موقعين، أو تظهر في الوجهة قبل
 *   وصولها فتُباع وهي في السيّارة.
 * ② جردٌ يُصفّر ما لم يُعدّ: صنفٌ لم يصل إليه العادُّ يُشطب من المخزون.
 * ③ مرتجعٌ يُقبل مرّتين: تُرتجَع الحبّةُ نفسُها فتعود للرفّ حبّتان.
 * ④ حجزٌ بلا أجل: زبونٌ انصرف يترك بضاعةً محجوزةً إلى الأبد.
 */
class RetailOperationsGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'verification_status' => 'verified',
        ]);
        $this->stock = app(StockService::class);
    }

    private function product(string $name = 'صنف', string $qty = '0'): MerchantProduct
    {
        return MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => $name, 'price' => '100', 'cost_price' => '60',
            // **صفرٌ عمداً**: `openStockRow` يتبنّى الرقمَ القديم رصيداً
            // افتتاحيّاً عند أوّل حركة، فبدءُ الاختبار بـ١٠٠ يجعل كلَّ
            // إدخالٍ بعده يقيس ١٠٠ + ما أُدخل — ويُخفي ما نقيسه هنا.
            'quantity' => $qty, 'is_active' => true, 'track_stock' => true,
        ]);
    }

    private function warehouse(string $code = 'WH1'): MerchantLocation
    {
        return $this->stock->addLocation($this->merchant, [
            'code' => $code, 'name' => 'مستودع ' . $code, 'kind' => 'warehouse',
        ]);
    }

    private function stockIn(MerchantProduct $p, MerchantLocation $loc, string $qty): void
    {
        $this->stock->move(
            product: $p, location: $loc, delta: $qty, reason: 'purchase_receive',
            unitCost: '60',
        );
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٢ — التصنيفات والوحدات والباركودات
    // ══════════════════════════════════════════════════════════════════

    public function test_the_category_tree_answers_for_the_whole_branch(): void
    {
        $svc = app(ProductCatalogService::class);

        $food = $svc->addCategory($this->merchant, ['name' => 'مواد غذائية']);
        $dairy = $svc->addCategory($this->merchant, ['name' => 'ألبان', 'parent_id' => $food->id]);
        $cheese = $svc->addCategory($this->merchant, ['name' => 'أجبان', 'parent_id' => $dairy->id]);

        $ids = $food->selfAndDescendantIds();

        $this->assertContains($cheese->id, $ids,
            'حفيدٌ خارج شجرة جدّه — و«كم بعتُ من المواد الغذائية؟» يُجاب ناقصاً');
        $this->assertCount(3, $ids);
    }

    public function test_two_categories_cannot_share_a_code(): void
    {
        $svc = app(ProductCatalogService::class);

        $a = $svc->addCategory($this->merchant, ['name' => 'مشروبات']);
        $b = $svc->addCategory($this->merchant, ['name' => 'مشروبات']);

        $this->assertNotSame($a->code, $b->code,
            'رمزان متطابقان — والفريدُ في القاعدة يرفض الثاني فيسقط الإنشاء');
    }

    public function test_a_carton_barcode_carries_its_pack_size(): void
    {
        $svc = app(ProductCatalogService::class);
        $p = $this->product('ماء');

        $svc->addBarcode($this->merchant, $p->id, ['barcode' => '111', 'pack_size' => '1']);
        $svc->addBarcode($this->merchant, $p->id, ['barcode' => '222', 'pack_size' => '24']);

        $this->assertSame('24.000', $svc->scan($this->merchant, '222')['pack_size'],
            'الكرتونُ يُقرأ حبّةً — فينحرف المخزون ٢٣ في كلّ مسحة');
        $this->assertSame('1.000', $svc->scan($this->merchant, '111')['pack_size']);
    }

    public function test_a_barcode_already_used_by_another_product_is_refused(): void
    {
        $svc = app(ProductCatalogService::class);
        $a = $this->product('أ');
        $b = $this->product('ب');

        $svc->addBarcode($this->merchant, $a->id, ['barcode' => '999']);

        $this->expectException(DomainException::class);
        $svc->addBarcode($this->merchant, $b->id, ['barcode' => '999']);
    }

    public function test_the_unit_refuses_a_fraction_it_does_not_allow(): void
    {
        $svc = app(ProductCatalogService::class);

        $piece = $svc->addUnit($this->merchant, ['name' => 'حبة', 'code' => 'PC', 'decimals' => 0]);
        $kilo = $svc->addUnit($this->merchant, ['name' => 'كيلو', 'code' => 'KG', 'decimals' => 3]);

        $this->assertFalse($piece->accepts('2.5'), 'نصفُ ثلّاجةٍ صار كمّيّةً مقبولة');
        $this->assertTrue($piece->accepts('3'));
        $this->assertTrue($kilo->accepts('2.5'), 'نصفُ كيلو مرفوض — وهو بيعٌ يوميّ');
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٣ — المتغيّرات
    // ══════════════════════════════════════════════════════════════════

    public function test_variants_are_generated_and_the_parent_stops_being_sellable(): void
    {
        $svc = app(ProductCatalogService::class);
        $shirt = $this->product('قميص');

        $made = $svc->generateVariants($this->merchant, $shirt->id, [
            'اللون' => ['أحمر', 'أزرق'],
            'المقاس' => ['S', 'L'],
        ]);

        $this->assertCount(4, $made);
        $this->assertTrue($shirt->fresh()->is_variant_parent);
        $this->assertFalse($shirt->fresh()->track_stock,
            'الأبُ ما زال يُخزَّن — فيُقرأ مخزونُه مضافاً إلى متغيّراته');

        $names = collect($made)->map(fn ($v) => $v->displayName())->all();
        $this->assertContains('قميص · أحمر · S', $names,
            'تسعةُ صفوفٍ باسم «قميص» في شاشة البيع، ولا يُعرف أيّها في اليد');
    }

    public function test_regenerating_variants_does_not_duplicate_them(): void
    {
        $svc = app(ProductCatalogService::class);
        $shirt = $this->product('قميص');

        $svc->generateVariants($this->merchant, $shirt->id, ['اللون' => ['أحمر']]);
        $again = $svc->generateVariants($this->merchant, $shirt->id, ['اللون' => ['أحمر', 'أزرق']]);

        $this->assertCount(1, $again, 'أُعيد توليدُ ما وُلد — فصار للصنف مخزونان');
        $this->assertSame(2, MerchantProduct::where('parent_product_id', $shirt->id)->count());
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٤ — التحويلات
    // ══════════════════════════════════════════════════════════════════

    public function test_goods_leave_the_source_on_ship_and_arrive_only_on_receive(): void
    {
        $svc = app(StockTransferService::class);
        $p = $this->product('بضاعة');
        $main = $this->stock->defaultLocation($this->merchant->id);
        $wh = $this->warehouse();
        $this->stockIn($p, $main, '50');

        $t = $svc->request($this->merchant, [
            'from_location_id' => $main->id, 'to_location_id' => $wh->id,
            'items' => [['product_id' => $p->id, 'quantity' => '20']],
        ]);

        // ① الطلبُ وحدَه لا يمسّ شيئاً
        $this->assertSame('50.000', $this->stock->available($p->id, $main->id));

        $svc->approve($this->merchant, $t);
        $svc->ship($this->merchant, $t->fresh());

        // ② خرجت من المصدر — **ولم تصل الوجهةَ بعد**
        $this->assertSame('30.000', $this->stock->available($p->id, $main->id));
        $this->assertSame('0', $this->stock->available($p->id, $wh->id),
            'ظهرت البضاعةُ في الوجهة وهي في السيّارة — فتُباع قبل وصولها');

        $svc->receive($this->merchant, $t->fresh());

        $this->assertSame('20.000', $this->stock->available($p->id, $wh->id));
        $this->assertSame(StockTransfer::RECEIVED, $t->fresh()->status);
    }

    public function test_a_shortage_on_receive_is_recorded_with_a_reason_not_forced_equal(): void
    {
        $svc = app(StockTransferService::class);
        $p = $this->product('بضاعة');
        $main = $this->stock->defaultLocation($this->merchant->id);
        $wh = $this->warehouse();
        $this->stockIn($p, $main, '50');

        $t = $svc->request($this->merchant, [
            'from_location_id' => $main->id, 'to_location_id' => $wh->id,
            'items' => [['product_id' => $p->id, 'quantity' => '20']],
        ]);
        $svc->approve($this->merchant, $t);
        $svc->ship($this->merchant, $t->fresh());

        $item = $t->items()->first();
        $svc->receive($this->merchant, $t->fresh(), [
            $item->id => ['quantity' => '18', 'reason' => 'كسر في الطريق'],
        ]);

        $item->refresh();
        $this->assertSame('2.000', $item->shortage());
        $this->assertSame('كسر في الطريق', $item->variance_reason);
        $this->assertSame(StockTransfer::PARTIALLY_RECEIVED, $t->fresh()->status,
            'نقصٌ في الطريق أُغلق بحالة «مستلَم كاملاً» — فلا يُراجَع');

        // **والحبّتان لا تُخترعان**: خرجت ٢٠ ووصلت ١٨، والفرقُ خارج المخزونين.
        $this->assertSame('30.000', $this->stock->available($p->id, $main->id));
        $this->assertSame('18.000', $this->stock->available($p->id, $wh->id));
    }

    public function test_receiving_more_than_shipped_is_refused(): void
    {
        $svc = app(StockTransferService::class);
        $p = $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $wh = $this->warehouse();
        $this->stockIn($p, $main, '50');

        $t = $svc->request($this->merchant, [
            'from_location_id' => $main->id, 'to_location_id' => $wh->id,
            'items' => [['product_id' => $p->id, 'quantity' => '10']],
        ]);
        $svc->approve($this->merchant, $t);
        $svc->ship($this->merchant, $t->fresh());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('لا تتكاثر في الطريق');
        $svc->receive($this->merchant, $t->fresh(), [
            $t->items()->first()->id => ['quantity' => '15'],
        ]);
    }

    public function test_a_shipped_transfer_cannot_be_cancelled(): void
    {
        $svc = app(StockTransferService::class);
        $p = $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $wh = $this->warehouse();
        $this->stockIn($p, $main, '50');

        $t = $svc->request($this->merchant, [
            'from_location_id' => $main->id, 'to_location_id' => $wh->id,
            'items' => [['product_id' => $p->id, 'quantity' => '10']],
        ]);
        $svc->approve($this->merchant, $t);
        $svc->ship($this->merchant, $t->fresh());

        $this->expectException(DomainException::class);
        $svc->cancel($this->merchant, $t->fresh(), 'غيّرنا رأينا');
    }

    public function test_transferring_more_than_the_source_holds_is_refused(): void
    {
        $svc = app(StockTransferService::class);
        $p = $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $wh = $this->warehouse();
        $this->stockIn($p, $main, '5');

        $t = $svc->request($this->merchant, [
            'from_location_id' => $main->id, 'to_location_id' => $wh->id,
            'items' => [['product_id' => $p->id, 'quantity' => '50']],
        ]);
        $svc->approve($this->merchant, $t);

        $this->expectException(DomainException::class);
        $svc->ship($this->merchant, $t->fresh());
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٥ — الجرد
    // ══════════════════════════════════════════════════════════════════

    public function test_an_uncounted_line_is_not_zeroed_on_approval(): void
    {
        $svc = app(StockCountService::class);
        $counted = $this->product('معدود');
        $missed = $this->product('لم يصل إليه العادّ');
        $main = $this->stock->defaultLocation($this->merchant->id);

        $this->stockIn($counted, $main, '40');
        $this->stockIn($missed, $main, '70');

        $count = $svc->open($this->merchant, $main->id, 'full');
        $svc->countLine($count, $counted->id, '37', 'damaged');
        $svc->submit($count->fresh());

        $out = $svc->approve($this->merchant, $count->fresh());

        $this->assertSame(1, $out['adjusted_lines']);
        $this->assertSame(1, $out['not_counted_lines'],
            'صنفٌ لم يُعدّ لم يُقَل — فيُقرأ الجردُ كاملاً وهو ناقص');

        $this->assertSame('37.000', $this->stock->available($counted->id, $main->id));
        $this->assertSame('70.000', $this->stock->available($missed->id, $main->id),
            'صنفٌ لم يصل إليه العادُّ صار صفراً — شُطب مخزونٌ موجودٌ على الرفّ');
    }

    public function test_the_system_quantity_is_frozen_when_the_count_opens(): void
    {
        $svc = app(StockCountService::class);
        $p = $this->product('صنف');
        $main = $this->stock->defaultLocation($this->merchant->id);
        $this->stockIn($p, $main, '20');

        $count = $svc->open($this->merchant, $main->id, 'full');

        // بيعةٌ تقع **أثناء العدّ** — والنظامُ يتغيّر تحت يد العادّ.
        app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '300', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'qty' => 3, 'price' => '100']],
        );

        $item = $count->items()->first();
        $this->assertSame('20.000', (string) $item->system_quantity,
            'لقطةُ النظام تتبع البيع — فيُنسب بيعٌ صحيحٌ فرقاً في الجرد');
    }

    public function test_a_second_count_cannot_open_while_one_is_running(): void
    {
        $svc = app(StockCountService::class);
        $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $svc->open($this->merchant, $main->id, 'full');

        $this->expectException(DomainException::class);
        $svc->open($this->merchant, $main->id, 'full');
    }

    public function test_a_count_cannot_be_approved_before_review(): void
    {
        $svc = app(StockCountService::class);
        $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $count = $svc->open($this->merchant, $main->id, 'full');

        $this->expectException(DomainException::class);
        $svc->approve($this->merchant, $count);
    }

    public function test_the_counter_is_not_the_approver(): void
    {
        $svc = app(StockCountService::class);
        $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);

        $employee = User::factory()->create();
        $count = $svc->open($this->merchant, $main->id, 'full', null, [], $employee->id);
        $svc->submit($count->fresh());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('من بدأ الجرد لا يعتمده');
        $svc->approve($employee, $count->fresh());
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٦ — الهالك والمرتجعات
    // ══════════════════════════════════════════════════════════════════

    public function test_waste_does_not_touch_stock_until_it_is_approved(): void
    {
        $svc = app(StockWasteService::class);
        $p = $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $this->stockIn($p, $main, '30');

        $w = $svc->record($this->merchant, [
            'product_id' => $p->id, 'quantity' => '5', 'reason' => 'expired',
            'location_id' => $main->id,
        ]);

        $this->assertSame('30.000', $this->stock->available($p->id, $main->id),
            'خُصمت البضاعةُ بمجرّد التسجيل — و«انتهت صلاحيتها» جملةٌ تُخرج ما شاء صاحبُها');

        $svc->approve($this->merchant, $w);

        $this->assertSame('25.000', $this->stock->available($p->id, $main->id));
        $this->assertSame('300.0000', (string) $w->fresh()->total_cost);
    }

    public function test_a_rejected_waste_never_touches_stock(): void
    {
        $svc = app(StockWasteService::class);
        $p = $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $this->stockIn($p, $main, '30');

        $w = $svc->record($this->merchant, [
            'product_id' => $p->id, 'quantity' => '5', 'reason' => 'theft',
        ]);
        $svc->reject($this->merchant, $w, 'غير مقنع');

        $this->assertSame('30.000', $this->stock->available($p->id, $main->id));
        $this->assertSame('rejected', $w->fresh()->status);
    }

    public function test_a_damaged_return_never_goes_back_on_the_shelf(): void
    {
        $returns = app(SaleReturnService::class);
        $p = $this->product('صنف');
        $main = $this->stock->defaultLocation($this->merchant->id);
        $this->stockIn($p, $main, '10');

        $sale = app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '200', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'qty' => 2, 'price' => '100']],
        );
        $line = SaleLine::where('sale_id', $sale->id)->firstOrFail();

        $r = $returns->create($this->merchant, $sale->sale_ulid, [[
            'sale_item_id' => $line->id, 'quantity' => '1',
            'condition' => 'damaged', 'restock' => true,
        ]]);
        $returns->approve($this->merchant, $r);

        $this->assertFalse($r->items()->first()->restock,
            'تالفٌ طُلبت إعادتُه فأُعيد — ويشتريه غيرُ الذي أعاده');
        $this->assertSame('8.000', $this->stock->available($p->id, $main->id));
    }

    public function test_a_good_return_goes_back_and_closes_the_original_line(): void
    {
        $returns = app(SaleReturnService::class);
        $p = $this->product('صنف');
        $main = $this->stock->defaultLocation($this->merchant->id);
        $this->stockIn($p, $main, '10');

        $sale = app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '200', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'qty' => 2, 'price' => '100']],
        );
        $line = SaleLine::where('sale_id', $sale->id)->firstOrFail();

        $r = $returns->create($this->merchant, $sale->sale_ulid, [[
            'sale_item_id' => $line->id, 'quantity' => '1', 'condition' => 'good',
        ]]);
        $returns->approve($this->merchant, $r);

        $this->assertSame('9.000', $this->stock->available($p->id, $main->id));
        $this->assertSame('1.000', $line->fresh()->returned_quantity);
        $this->assertSame('1.000', $line->fresh()->refundableQuantity());
    }

    public function test_the_same_unit_cannot_be_returned_twice(): void
    {
        $returns = app(SaleReturnService::class);
        $p = $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $this->stockIn($p, $main, '10');

        $sale = app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '200', paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'qty' => 2, 'price' => '100']],
        );
        $line = SaleLine::where('sale_id', $sale->id)->firstOrFail();

        $first = $returns->create($this->merchant, $sale->sale_ulid, [[
            'sale_item_id' => $line->id, 'quantity' => '2',
        ]]);
        $returns->approve($this->merchant, $first);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('المتاح للارتجاع');
        $returns->create($this->merchant, $sale->sale_ulid, [[
            'sale_item_id' => $line->id, 'quantity' => '1',
        ]]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٧ — الأسعار
    // ══════════════════════════════════════════════════════════════════

    public function test_the_proposer_is_not_the_approver(): void
    {
        $svc = app(ProductPriceService::class);
        $p = $this->product();
        $employee = User::factory()->create();

        $v = $svc->propose($this->merchant, ['product_id' => $p->id, 'price' => '150'], $employee->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('من اقترح السعر لا يعتمده');
        $svc->approve($employee, $v);
    }

    public function test_an_approved_price_becomes_active_and_expires_the_previous_one(): void
    {
        $svc = app(ProductPriceService::class);
        $p = $this->product();

        $first = $svc->approve($this->merchant,
            $svc->propose($this->merchant, ['product_id' => $p->id, 'price' => '150']));
        $second = $svc->approve($this->merchant,
            $svc->propose($this->merchant, ['product_id' => $p->id, 'price' => '180']));

        $this->assertSame(ProductPriceVersion::EXPIRED, $first->fresh()->status,
            'نسختان ساريتان معاً — والسعرُ يختلف بحسب أيّهما قُرئت أوّلاً');
        $this->assertSame(ProductPriceVersion::ACTIVE, $second->fresh()->status);
        $this->assertSame('180.0000', (string) $p->fresh()->price);
    }

    public function test_a_price_cannot_take_effect_in_the_past(): void
    {
        $svc = app(ProductPriceService::class);
        $p = $this->product();

        $this->expectException(DomainException::class);
        $svc->propose($this->merchant, [
            'product_id' => $p->id, 'price' => '150',
            'effective_from' => now()->subDays(3)->toDateTimeString(),
        ]);
    }

    public function test_the_price_history_says_unknown_margin_rather_than_zero(): void
    {
        $svc = app(ProductPriceService::class);
        $p = MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'بلا تكلفة', 'price' => '100', 'cost_price' => '0',
            'quantity' => '5', 'is_active' => true,
        ]);

        $svc->approve($this->merchant,
            $svc->propose($this->merchant, ['product_id' => $p->id, 'price' => '150']));

        $row = $svc->history($this->merchant, $p->id)[0];
        $this->assertNull($row['margin_at_time'],
            'هامشٌ محسوبٌ على تكلفةٍ صفرٍ يُقرأ ١٠٠٪ على بضاعةٍ لم يُعرف ثمنُها');
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٨ — سقفُ الخصم
    // ══════════════════════════════════════════════════════════════════

    public function test_a_cashier_cannot_discount_beyond_the_role_ceiling(): void
    {
        $perm = app(\App\Services\Merchant\MerchantPermissionService::class);
        $roles = $perm->seedRetailRoles($this->merchant);

        $cashierRole = collect($roles)->firstWhere('code', 'cashier');
        $employee = User::factory()->create();
        $perm->assign($this->merchant, $employee, $cashierRole);

        $pos = \App\Models\PosUser::create([
            'merchant_user_id' => $this->merchant->id,
            'user_id' => $employee->id,
            'display_name' => 'كاشير',
            'pos_number' => 'POS1',
            'is_active' => true,
        ]);

        $cashier = app(CashierService::class);

        // تحت السقف (٥٠٠) — يمرّ
        $ok = $cashier->recordSale(
            merchant: $this->merchant, total: '1000', paymentMethod: 'cash',
            posUserId: $pos->id, discountAmount: '400',
        );
        $this->assertSame('400.0000', (string) $ok->discount_amount);

        // فوق السقف — يُرفض
        $this->expectException(DomainException::class);
        $cashier->recordSale(
            merchant: $this->merchant, total: '1000', paymentMethod: 'cash',
            posUserId: $pos->id, discountAmount: '900',
        );
    }

    public function test_the_owner_is_not_capped_by_a_role(): void
    {
        app(\App\Services\Merchant\MerchantPermissionService::class)
            ->seedRetailRoles($this->merchant);

        $sale = app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '10000', paymentMethod: 'cash',
            discountAmount: '9000',
        );

        $this->assertSame('9000.0000', (string) $sale->discount_amount);
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٩ — الحجز
    // ══════════════════════════════════════════════════════════════════

    public function test_a_pending_sale_reserves_instead_of_deducting(): void
    {
        $p = $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $this->stockIn($p, $main, '10');

        $sale = app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '300', paymentMethod: 'amial_pay',
            items: [['product_id' => $p->id, 'qty' => 3, 'price' => '100']],
        );

        $row = ProductStock::where('product_id', $p->id)->where('location_id', $main->id)->first();

        $this->assertSame('10.000', (string) $row->on_hand,
            'نقص المخزونُ لبيعةٍ لم تُدفَع بعد');
        $this->assertSame('3.000', (string) $row->reserved,
            'لم تُحجز — فتُباع آخرُ حبّةٍ لزبونين في الدقيقة نفسها');
        $this->assertSame('7.000', $this->stock->available($p->id, $main->id));

        $this->assertSame(1, StockReservation::where('sale_id', $sale->id)
            ->where('status', StockReservation::HELD)->count());
    }

    public function test_a_successful_payment_turns_the_hold_into_a_real_movement(): void
    {
        $p = $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $this->stockIn($p, $main, '10');

        $sale = app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '300', paymentMethod: 'amial_pay',
            items: [['product_id' => $p->id, 'qty' => 3, 'price' => '100']],
        );

        app(CashierService::class)->linkPayment($sale->sale_ulid, 'TX1', $this->merchant->id);

        $row = ProductStock::where('product_id', $p->id)->where('location_id', $main->id)->first();

        $this->assertSame('7.000', (string) $row->on_hand);
        $this->assertSame('0.000', (string) $row->reserved,
            'بقي الحجزُ بعد الدفع — فخُصمت البضاعةُ مرّتين من المتاح');
        $this->assertSame(StockReservation::CONSUMED,
            StockReservation::where('sale_id', $sale->id)->first()->status);
    }

    public function test_an_expired_hold_is_released_and_the_goods_are_available_again(): void
    {
        $p = $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $this->stockIn($p, $main, '10');

        app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '300', paymentMethod: 'amial_pay',
            items: [['product_id' => $p->id, 'qty' => 3, 'price' => '100']],
        );

        // زبونٌ انصرف — **وحجزٌ بلا أجلٍ أسوأ من غيابه**.
        StockReservation::query()->update(['expires_at' => now()->subMinutes(30)]);

        $freed = app(StockReservationService::class)->releaseExpired();

        $this->assertSame(1, $freed);
        $this->assertSame('10.000', $this->stock->available($p->id, $main->id),
            'بقيت البضاعةُ محجوزةً إلى الأبد — ويُقرأ المتجرُ فارغاً وأرففُه ملأى');
    }

    public function test_releasing_twice_never_makes_reserved_negative(): void
    {
        $p = $this->product();
        $main = $this->stock->defaultLocation($this->merchant->id);
        $this->stockIn($p, $main, '10');

        $sale = app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '300', paymentMethod: 'amial_pay',
            items: [['product_id' => $p->id, 'qty' => 3, 'price' => '100']],
        );

        $svc = app(StockReservationService::class);
        $svc->releaseForSale($sale, 'cancelled');
        $svc->releaseForSale($sale, 'cancelled');

        $row = ProductStock::where('product_id', $p->id)->where('location_id', $main->id)->first();
        $this->assertSame('0.000', (string) $row->reserved,
            'محجوزٌ سالبٌ يجعل المتاح أكبر من الموجود — فيبيع الوهم');
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٧ — نقدُ الوردية معمَّماً
    // ══════════════════════════════════════════════════════════════════

    public function test_the_generalised_shift_cash_refuses_a_reason_in_the_wrong_direction(): void
    {
        $svc = app(\App\Services\Retail\MerchantShiftCashService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('يقلب إشارة الفرق');
        $svc->record('cashier', 1, $this->merchant, 'in', 'expense', '500');
    }

    public function test_the_expected_cash_is_computed_from_movements_not_a_stored_column(): void
    {
        $svc = app(\App\Services\Retail\MerchantShiftCashService::class);

        $svc->record('cashier', 7, $this->merchant, 'out', 'expense', '2000');
        $svc->record('cashier', 7, $this->merchant, 'in', 'change_fund', '500');

        $this->assertSame('8500.0000',
            $svc->expectedCash('cashier', 7, '5000', '5000'),
            'مصروفُ الكاشير يظهر عجزاً في وجهه — فيُطالَب بما أنفقه للمتجر');
    }
}
