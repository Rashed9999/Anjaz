<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\MerchantRefund;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductStock;
use App\Models\Retail\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use App\Services\MerchantFinancialTruthReportService;
use App\Services\PurchaseReturnService;
use App\Services\Retail\StockService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-DAILY-MOVEMENT-001 — **الحركةُ اليوميّة الكاملة، وشراءٌ لا يصل الرفّ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **من أين جاءت:** أرسل صاحبُ المشروع خمسَ شاشاتٍ لتطبيقٍ محاسبيٍّ منافس
 * وسأل «هل هناك شيءٌ يجب أخذُ فكرته منهم». واختار من بين ما عُرض عليه
 * **مصفوفةَ الحركة اليوميّة**: مبيعٌ وشراءٌ ومرتجعاهما، نقداً وآجلاً.
 *
 * وقبل أن تُبنى المصفوفةُ قِيس ما يمكن أن تقرأه، **فخرج عطلٌ لم يكن
 * أحدٌ يبحث عنه**.
 *
 * ─────────────────────────────────────────────────────────────────────
 * **العطلُ الأوّل: بضاعةٌ استُلمت ودُفع ثمنُها ولا تُباع.**
 *
 * `poReceive` كان يكتب `$product->quantity` مباشرةً **ولا يمرّ من
 * `StockService`** — وهو صاحبُ الحقيقة. وقِيس بالتشغيل:
 *
 *     استُلمت ١٠٠ حبّة
 *     products.quantity      = 110.000
 *     product_stocks.on_hand =  10.000   ← لم تتحرّك
 *     الحركات: opening_balance وحدها — لا حركةَ استلامٍ قطّ
 *     ثمّ بيعُ ٦٠ يسقط: «الكمية غير كافية… المتاح 10»
 *     ثمّ بيعُ ٥ ينجح  ⇒ quantity = 5.000  ← **ومحت المئةَ**
 *
 * فالمرآةُ تُعاد بناؤها من مجموع المواقع (`syncLegacyQuantity`)،
 * **فيُمحى ما كُتب فوقها بلا خطأ ولا أثر**. (القاعدة السادسة: الرقمُ
 * يُحسب من مصدره؛ ومن كتب فوق المرآة ضاع.)
 *
 * **و`purchase_receive` سببُ حركةٍ معرَّفٌ في `StockMovement::INBOUND`
 * منذ بُني المخزون، ولا مُصدِرَ له في المشروع كلِّه** — قِيس بالبحث.
 * أي أنّ الرفَّ كان ينتظر نداءً لم يُكتَب.
 *
 * ─────────────────────────────────────────────────────────────────────
 * **والعطلُ الثاني: نصفُ المصفوفة بلا مصدر.**
 *
 *   الشراء       — كلُّه آجلٌ بلا استثناء: لا بابَ لشراءٍ نقديّ.
 *   مرتجع الشراء — **لا جدولَ ولا خدمةَ ولا نقطةَ نهاية.**
 *
 * **ومصفوفةٌ نصفُها أصفارٌ كاذبةٌ أسوأ من غيابها** (القاعدة السابعة).
 */
class DailyMovementMatrixGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private StockService $stock;
    private MerchantLocation $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->merchantOf(A::BIZ_RETAIL);
        $this->stock = app(StockService::class);
        $this->main = $this->stock->defaultLocation($this->merchant->id);
    }

    private function merchantOf(string $vertical): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'tier' => 'small', 'verification_status' => 'verified',
            'business_type' => $vertical,
            // **باقةُ الأعمال** — «أوامر الشراء» تبدأ عندها، والفحصُ عن
            // الحركة لا عن التسعير.
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        return $u;
    }

    private function product(string $qty = '0', string $cost = '100'): MerchantProduct
    {
        return MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id, 'name' => 'صنف قياس',
            'price' => '300', 'cost_price' => $cost, 'quantity' => $qty, 'is_active' => true,
        ]);
    }

    /** أمرُ شراءٍ معتمَدٌ ببندٍ واحد. */
    private function approvedOrder(MerchantProduct $p, string $qty, string $cost): array
    {
        $supplier = Supplier::create([
            'merchant_user_id' => $this->merchant->id, 'name' => 'مورد قياس',
        ]);

        $this->actingAs($this->merchant, 'api');

        $r = $this->postJson('/api/v1/amial/merchant/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [[
                'product_id' => $p->id, 'name' => $p->name,
                'quantity' => $qty, 'unit_cost' => $cost,
            ]],
        ])->assertStatus(201);

        $poId = (int) $r->json('meta.order.id');
        $this->postJson("/api/v1/amial/merchant/purchase-orders/{$poId}/approve")->assertOk();

        return [$supplier, PurchaseOrder::find($poId), PurchaseOrderItem::where('purchase_order_id', $poId)->first()];
    }

    private function report(?User $m = null): array
    {
        return app(MerchantFinancialTruthReportService::class)->report($m ?? $this->merchant);
    }

    private function rowOf(array $report, string $code): array
    {
        foreach ($report['movement']['rows'] as $row) {
            if ($row['code'] === $code) return $row;
        }

        $this->fail("صفُّ «{$code}» غائبٌ عن مصفوفة الحركة");
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الشراءُ يصل الرفّ
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **ما استُلم يُباع** — وهذا هو الفحصُ الذي كان يسقط قبل الإصلاح.
     */
    public function received_goods_reach_the_shelf_and_can_be_sold(): void
    {
        $p = $this->product();
        $this->stock->move($p, $this->main, '10', 'opening_balance', actor: $this->merchant);

        [, $po, $item] = $this->approvedOrder($p, '100', '100');

        $this->postJson("/api/v1/amial/merchant/purchase-orders/{$po->id}/receive", [
            'items' => [['item_id' => $item->id, 'received_quantity' => 100]],
        ])->assertOk();

        $onHand = ProductStock::where('product_id', $p->id)
            ->where('location_id', $this->main->id)->value('on_hand');

        $this->assertSame(0, bccomp((string) $onHand, '110', 3),
            "المستلَمُ لم يصل دفترَ المخزون: on_hand={$onHand} والمنتظَر ١١٠. "
            . 'وهو ما يجعل بضاعةً دُفع ثمنُها غيرَ قابلةٍ للبيع.');

        // **والحركةُ تترك أثرَها بسببها المعرَّف** — بلا هذا لا جردَ ولا
        // تكلفةَ مبيعاتٍ صحيحة.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $p->id, 'reason' => 'purchase_receive',
        ]);

        // **والبيعُ يمرّ الآن** — وكان يسقط بـ«الكمية غير كافية».
        $this->stock->move($p, $this->main, '-60', 'sale', actor: $this->merchant);

        $p->refresh();
        $this->assertSame(0, bccomp((string) $p->quantity, '50', 3),
            'العمودُ القديم لا يطابق مجموعَ المواقع بعد البيع');
    }

    /**
     * @test
     *
     * **والمئةُ لا تُمحى بأوّل بيعةٍ بعدها.**
     *
     * هذا هو الوجهُ الثاني للعطل نفسِه، ويُفحص وحدَه: الكتابةُ فوق العمود
     * القديم كانت تنجو حتّى أوّل حركةٍ سليمة، **فتُعيد `syncLegacyQuantity`
     * بناءَه من المواقع فتختفي المئة**. فحصٌ على الاستلام وحدَه كان يمرّ
     * ولا يرى المحو.
     */
    public function test_received_stock_survives_the_next_sale(): void
    {
        $p = $this->product();
        $this->stock->move($p, $this->main, '10', 'opening_balance', actor: $this->merchant);

        [, $po, $item] = $this->approvedOrder($p, '100', '100');
        $this->postJson("/api/v1/amial/merchant/purchase-orders/{$po->id}/receive", [
            'items' => [['item_id' => $item->id, 'received_quantity' => 100]],
        ])->assertOk();

        $this->stock->move($p, $this->main, '-5', 'sale', actor: $this->merchant);

        $p->refresh();
        $this->assertSame(0, bccomp((string) $p->quantity, '105', 3),
            "بعد بيع ٥ من ١١٠ صار المخزون {$p->quantity}. "
            . 'المئةُ المستلمةُ مُحيت بلا خطأ ولا أثر — وهي بضاعةٌ دُفع ثمنُها.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الشراءُ النقديّ
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **`paid_now` يُنشئ شراءً نقديّاً في خطوةٍ واحدة** — ويبقى الدفترُ
     * مقروءاً: يُرفَع الدينُ بقيمة المستلَم ثمّ يُخفَض بما دُفع.
     */
    public function a_cash_purchase_is_one_action_and_leaves_both_entries(): void
    {
        $p = $this->product();
        [$supplier, $po, $item] = $this->approvedOrder($p, '10', '100');

        $this->postJson("/api/v1/amial/merchant/purchase-orders/{$po->id}/receive", [
            'items' => [['item_id' => $item->id, 'received_quantity' => 10]],
            'paid_now' => 600,
        ])->assertOk();

        $supplier->refresh();
        $this->assertSame(0, bccomp((string) $supplier->current_debt, '400', 4),
            "دينُ المورد بعد شراءٍ بألفٍ دُفع منه ٦٠٠ يجب أن يكون ٤٠٠، وهو {$supplier->current_debt}");

        $receive = SupplierLedgerEntry::where('supplier_id', $supplier->id)
            ->where('entry_type', 'po_receive')->first();
        $this->assertNotNull($receive);
        $this->assertSame(0, bccomp((string) $receive->cash_amount, '600', 4),
            'ما دُفع فوراً لم يُحفظ في سطر الاستلام — والتقريرُ يقرؤه منه، '
            . 'فيصير الشراءُ النقديُّ آجلاً في المصفوفة.');

        $this->assertDatabaseHas('supplier_ledger', [
            'supplier_id' => $supplier->id, 'entry_type' => 'payment',
        ]);
    }

    /**
     * @test
     *
     * **ولا يُدفَع فوراً أكثرُ من قيمة ما استُلم.**
     *
     * دفعةٌ أكبرُ ليست شراءً نقديّاً بل سدادُ دينٍ سابق. وقبولُها هنا
     * يجعل «مشترياتِ اليوم النقديّة» أكبرَ ممّا دخل المخزنَ فعلاً — رقمٌ
     * لا يُطابَق بشيء.
     */
    public function paying_more_than_received_is_refused(): void
    {
        $p = $this->product();
        [, $po, $item] = $this->approvedOrder($p, '10', '100');

        $this->postJson("/api/v1/amial/merchant/purchase-orders/{$po->id}/receive", [
            'items' => [['item_id' => $item->id, 'received_quantity' => 10]],
            'paid_now' => 5000,
        ])->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ مرتجعُ الشراء
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **البضاعةُ تخرج من الرفّ والدينُ ينقص** — والصفُّ يظهر في المصفوفة.
     */
    public function a_purchase_return_takes_goods_off_the_shelf_and_cuts_the_debt(): void
    {
        $p = $this->product();
        [$supplier, $po, $item] = $this->approvedOrder($p, '10', '100');

        $this->postJson("/api/v1/amial/merchant/purchase-orders/{$po->id}/receive", [
            'items' => [['item_id' => $item->id, 'received_quantity' => 10]],
        ])->assertOk();

        $r = $this->postJson('/api/v1/amial/merchant/purchase-returns', [
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'settlement_type' => 'credit_note',
            'reason' => 'ثلاثُ حبّاتٍ تالفة',
            'items' => [[
                'purchase_order_item_id' => $item->id, 'quantity' => 3,
            ]],
        ])->assertStatus(201);

        $returnId = (int) $r->json('meta.return.id');

        // **ولا تتحرّك البضاعةُ قبل الاعتماد.**
        $this->assertSame(0, bccomp(
            (string) ProductStock::where('product_id', $p->id)->value('on_hand'), '10', 3),
            'خرجت البضاعةُ قبل اعتماد المرتجع');

        $this->postJson("/api/v1/amial/merchant/purchase-returns/{$returnId}/approve")->assertOk();

        $this->assertSame(0, bccomp(
            (string) ProductStock::where('product_id', $p->id)->value('on_hand'), '7', 3),
            'المرتجعُ اعتُمد ولم تنقص البضاعةُ من الرفّ — فيبقى التالفُ معروضاً للبيع');

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $p->id, 'reason' => 'purchase_return',
        ]);

        $supplier->refresh();
        $this->assertSame(0, bccomp((string) $supplier->current_debt, '700', 4),
            "الدينُ بعد ردّ ٣٠٠ من ألفٍ يجب أن يكون ٧٠٠، وهو {$supplier->current_debt}");

        $row = $this->rowOf($this->report(), 'purchase_return');
        $this->assertTrue($row['available']);
        $this->assertSame(0, bccomp((string) $row['credit'], '300', 4),
            'مرتجعُ الشراء لا يظهر في مصفوفة الحركة');
    }

    /**
     * @test
     *
     * **ولا يُردُّ أكثرُ ممّا استُلم** — ولا مرّتين.
     *
     * ولو مرّ لصار المخزونُ سالباً ودينُ المورد له لا عليه.
     */
    public function returning_more_than_received_is_refused(): void
    {
        $p = $this->product();
        [$supplier, $po, $item] = $this->approvedOrder($p, '10', '100');

        $this->postJson("/api/v1/amial/merchant/purchase-orders/{$po->id}/receive", [
            'items' => [['item_id' => $item->id, 'received_quantity' => 4]],
        ])->assertOk();

        $this->postJson('/api/v1/amial/merchant/purchase-returns', [
            'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id,
            'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 5]],
        ])->assertStatus(422);

        // وردٌّ سليمٌ ثمّ ثانٍ يتجاوز الباقي
        $ok = $this->postJson('/api/v1/amial/merchant/purchase-returns', [
            'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id,
            'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 4]],
        ])->assertStatus(201);

        $this->postJson('/api/v1/amial/merchant/purchase-returns/'
            . $ok->json('meta.return.id') . '/approve')->assertOk();

        $this->postJson('/api/v1/amial/merchant/purchase-returns', [
            'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id,
            'items' => [['purchase_order_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    /**
     * @test
     *
     * **والاستردادُ النقديُّ لا يمسّ الدين.**
     *
     * المالُ عاد نقداً؛ وخصمُه من الدين فوقَ ذلك ردٌّ يُحاسَب مرّتين.
     */
    public function a_cash_refund_does_not_also_cut_the_debt(): void
    {
        $p = $this->product();
        [$supplier, $po, $item] = $this->approvedOrder($p, '10', '100');

        $this->postJson("/api/v1/amial/merchant/purchase-orders/{$po->id}/receive", [
            'items' => [['item_id' => $item->id, 'received_quantity' => 10]],
        ])->assertOk();

        $return = app(PurchaseReturnService::class)->create(
            $this->merchant, $supplier->id,
            [['purchase_order_item_id' => $item->id, 'quantity' => 2]],
            ['purchase_order_id' => $po->id, 'settlement_type' => PurchaseReturn::SETTLE_CASH_REFUND],
        );

        app(PurchaseReturnService::class)->approve($this->merchant, $return);

        $supplier->refresh();
        $this->assertSame(0, bccomp((string) $supplier->current_debt, '1000', 4),
            'الاستردادُ النقديُّ خصم من الدين أيضاً — فحُوسب الردُّ مرّتين');

        $row = $this->rowOf($this->report(), 'purchase_return');
        $this->assertSame(0, bccomp((string) $row['cash'], '200', 4),
            'الاستردادُ النقديُّ لم يظهر في العمود النقديّ');
    }

    /**
     * @test
     *
     * **والدينُ لا يصير سالباً** — ويُقال الفائضُ في سطر الدفتر.
     *
     * دينٌ سالبٌ يُقرأ «المورد مدينٌ لنا»، وهو معنىً لم يقصده أحد.
     */
    public function the_debt_never_goes_negative_and_the_excess_is_stated(): void
    {
        $p = $this->product();
        [$supplier, $po, $item] = $this->approvedOrder($p, '10', '100');

        $this->postJson("/api/v1/amial/merchant/purchase-orders/{$po->id}/receive", [
            'items' => [['item_id' => $item->id, 'received_quantity' => 10]],
            'paid_now' => 900,          // يبقى الدين ١٠٠ فقط
        ])->assertOk();

        $return = app(PurchaseReturnService::class)->create(
            $this->merchant, $supplier->id,
            [['purchase_order_item_id' => $item->id, 'quantity' => 5]],  // بقيمة ٥٠٠
            ['purchase_order_id' => $po->id],
        );
        app(PurchaseReturnService::class)->approve($this->merchant, $return);

        $supplier->refresh();
        $this->assertSame(0, bccomp((string) $supplier->current_debt, '0', 4),
            "الدينُ صار {$supplier->current_debt} — وسالبُه يُقرأ عكسَ معناه");

        $entry = SupplierLedgerEntry::where('supplier_id', $supplier->id)
            ->where('entry_type', 'po_return')->first();
        $this->assertStringContainsString('بقي', (string) $entry->note,
            'الفائضُ الذي لم يُخصَم لم يُذكَر في الدفتر — فيختفي بلا أثر');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ المصفوفةُ نفسُها
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **أربعةُ صفوفٍ في اتّجاهين، وكلٌّ يقول مصدرَه.**
     */
    public function the_matrix_has_four_rows_each_naming_its_source(): void
    {
        $m = $this->report()['movement'];

        $this->assertSame('daily-movement/v1', $m['contract']);
        $this->assertSame(['cash', 'amial_pay', 'credit'], $m['columns']);

        $codes = array_column($m['rows'], 'code');
        $this->assertSame(
            ['sale', 'sale_return', 'purchase', 'purchase_return'], $codes,
            'صفوفُ المصفوفة أو ترتيبُها تغيّر — والشاشةُ تقرأ بالترتيب');

        foreach ($m['rows'] as $row) {
            $this->assertContains($row['direction'], ['in', 'out']);
            if ($row['available'] === true) {
                $this->assertNotNull($row['source'],
                    "صفُّ «{$row['code']}» بلا مصدرٍ مذكور");
            }
        }
    }

    /**
     * @test
     *
     * **والصفُّ الغائبُ يُقال غائباً — بلا أرقامٍ إطلاقاً.**
     *
     * (القاعدة السابعة.) صفرٌ في «مرتجع الشراء» لمن لا مورّدين له يُقرأ
     * «لم يُرتجَع اليوم»، والحقيقةُ «لا بابَ للردّ في هذا القطاع أصلاً».
     */
    public function an_absent_row_says_so_and_carries_no_numbers(): void
    {
        // **والصيدليّةُ هي الحالةُ الحيّة**: قِيس أنّ «أوامر الشراء»
        // مقصورةٌ اليومَ على التجزئة والجملة — `businessTypes()` **يُقاطع
        // ولا يستبدل**، فالنداءُ الثاني `self::GOODS` سقفٌ لا توسيع.
        // فصيدليّةٌ تشتري من موزّعٍ ولا بابَ لها، **وهذا قرارُ منتجٍ لا
        // قرارُ شيفرة** — والمصفوفةُ تقوله ولا تُخفيه بصفر.
        $pharmacy = $this->merchantOf(A::BIZ_PHARMACY);

        $row = $this->rowOf($this->report($pharmacy), 'purchase');

        $this->assertFalse($row['available'],
            'الصيدليّةُ خارجَ نطاق «أوامر الشراء» في سجلّ القدرات — والصفُّ يدّعي أنّه مقيس');
        $this->assertNull($row['cash'], 'صفٌّ غائبٌ يحمل رقماً — والصفرُ هنا كذب');
        $this->assertNull($row['total']);
        $this->assertNotEmpty($row['unavailable_reason_ar'],
            'الغيابُ بلا سببٍ مكتوب — والقارئُ لا يعرف أهو عطلٌ أم قطاع');
    }

    /**
     * @test
     *
     * **والصافي نقديٌّ وحدَه، ولا يخلط الآجل بالدرج.**
     *
     * قِيس: بيعٌ نقديٌّ ٥٠٠ · بيعٌ آجلٌ ٩٠٠ · شراءٌ نقديٌّ ٢٠٠
     * ⇒ صافي النقد ٣٠٠، **لا ١٢٠٠**.
     */
    public function the_net_is_cash_only_and_never_mixes_credit_into_the_drawer(): void
    {
        $p = $this->product();
        [, $po, $item] = $this->approvedOrder($p, '10', '20');

        $this->postJson("/api/v1/amial/merchant/purchase-orders/{$po->id}/receive", [
            'items' => [['item_id' => $item->id, 'received_quantity' => 10]],
            'paid_now' => 200,
        ])->assertOk();

        \App\Models\MerchantSale::create([
            'sale_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'merchant_user_id' => $this->merchant->id, 'total_amount' => '500',
            'payment_method' => 'cash', 'status' => 'completed', 'items' => [],
        ]);
        \App\Models\MerchantSale::create([
            'sale_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'merchant_user_id' => $this->merchant->id, 'total_amount' => '900',
            'payment_method' => 'credit', 'status' => 'credit_unpaid', 'items' => [],
        ]);

        $m = $this->report()['movement'];

        $this->assertSame(0, bccomp((string) $m['net_cash']['amount'], '300', 4),
            'صافي النقد ' . $m['net_cash']['amount'] . ' — والمنتظَر ٣٠٠ '
            . '(٥٠٠ نقداً ناقصَ ٢٠٠ شراءً نقديّاً). والآجلُ ليس في الدرج.');

        $sale = $this->rowOf($this->report(), 'sale');
        $this->assertSame(0, bccomp((string) $sale['credit'], '900', 4));
        $this->assertSame(0, bccomp((string) $sale['total'], '1400', 4));
    }

    /**
     * @test
     *
     * **ومرتجعُ المبيع يُقرأ من المال لا من البضاعة.**
     *
     * في التجزئة جدولان متلازمان: `sale_returns` تملك البضاعةَ،
     * و`merchant_refunds` تملك المال. ومرتجعٌ مقبولُ البضاعةِ **مرفوضُ
     * الصرف** يُقرأ من الأوّل مالاً خرج — وهو لم يخرج.
     */
    public function a_sale_return_is_read_from_the_money_not_the_goods(): void
    {
        MerchantRefund::create([
            'refund_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'merchant_user_id' => $this->merchant->id,
            'original_transaction_id' => 'TX-1', 'original_amount' => '300',
            'refund_amount' => '300', 'refund_method' => 'cash',
            'status' => 'completed', 'zone_code' => 'SOUTH',
        ]);

        // **ومرتجعٌ لم يُصرَف** — ولا يُحتسب.
        MerchantRefund::create([
            'refund_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'merchant_user_id' => $this->merchant->id,
            'original_transaction_id' => 'TX-2', 'original_amount' => '900',
            'refund_amount' => '900', 'refund_method' => 'cash',
            'status' => 'pending_approval', 'zone_code' => 'SOUTH',
        ]);

        $row = $this->rowOf($this->report(), 'sale_return');

        $this->assertSame('merchant_refunds', $row['source']);
        $this->assertSame(0, bccomp((string) $row['cash'], '300', 4),
            'مرتجعٌ ينتظر الموافقة حُسب مالاً خرج — وهو لم يخرج. '
            . 'القيمةُ المقيسة: ' . $row['cash']);
        $this->assertSame('out', $row['direction']);
    }

    /**
     * @test
     *
     * **وقائمةُ القطاعات تُقرأ من سجلّ القدرات لا تُكتب في التقرير.**
     *
     * قائمةٌ ثانيةٌ تشيخ: يُفتح الشراءُ لقطاعٍ في السجلّ فيبقى صفُّه
     * غائباً في التقرير أبداً، ولا خطأ في أيّ سجلّ.
     */
    public function the_report_reads_applicability_from_the_capability_registry(): void
    {
        $src = file_get_contents(app_path('Services/MerchantFinancialTruthReportService.php'));

        $this->assertStringContainsString('CapabilityRegistry::find', $src,
            'التقريرُ لا يسأل سجلَّ القدرات — فقائمتُه تشيخ وحدَها');

        // **ولا تُكتب قائمةٌ هنا**: يُسأل السجلُّ عن كلّ قطاع، ويُطابَق
        // جوابُه بما تقوله المصفوفة. فمن وسّع النطاقَ في السجلّ غداً
        // (والصيدليّةُ مرشّحةٌ) وجد التقريرَ يتبعه من تلقائه — ومن ضيّقه
        // كذلك.
        foreach (A::ALL_BUSINESS_TYPES as $vertical) {
            $expected = \App\Support\Access\CapabilityRegistry::find(A::F_PURCHASES)
                ?->appliesTo($vertical) === true;

            $row = $this->rowOf($this->report($this->merchantOf($vertical)), 'purchase');

            $this->assertSame($expected, $row['available'],
                "قطاع «{$vertical}»: سجلُّ القدرات يقول "
                . ($expected ? 'يشتري' : 'لا يشتري')
                . ' والتقريرُ يقول عكسَه — وقائمتان تفترقان.');
        }
    }
}
