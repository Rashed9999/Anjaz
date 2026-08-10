<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductStock;
use App\Models\Retail\StockMovement;
use App\Models\User;
use App\Services\CashierService;
use App\Services\Retail\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٠ — **المخزون بالموقع وبالحركة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان المخزونُ عموداً واحداً يُكتب فوقه:
 *
 *     $product->update(['quantity' => $newQty]);
 *     if ($newQty < 0) $newQty = '0';   // ← والأخطر
 *
 * **والقصُّ الصامت إلى الصفر محوُ دليل**: من باع خمساً وفي النظام ثلاث،
 * سُجّلت بيعةُ خمسٍ وصار المخزونُ صفراً — **والحبّتان الوهميّتان تختفيان
 * بلا أثر**. فيُقفَل الفرقُ قبل أن يُرى، ولا يعرف صاحبُ المتجر أنّ
 * بياناته انحرفت أصلاً.
 */
class RetailStockGuardTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;
    private User $merchant;
    private MerchantLocation $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'verification_status' => 'verified',
        ]);

        $this->main = $this->stock->defaultLocation($this->merchant->id);
    }

    private function product(string $qty = '0', string $cost = '100'): MerchantProduct
    {
        return MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'صنف قياس',
            'price' => '300',
            'cost_price' => $cost,
            'quantity' => $qty,
            'is_active' => true,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  الحركة مصدرُ الحقيقة
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **كلُّ تغيّرٍ يترك حركةً بسببها وموقعها وفاعلها.**
     */
    public function every_change_leaves_a_movement_with_its_reason(): void
    {
        $p = $this->product();

        $this->stock->move($p, $this->main, '50', 'purchase_receive',
            actor: $this->merchant, note: 'أوّل توريد');

        $m = StockMovement::where('product_id', $p->id)->latest('id')->first();

        $this->assertNotNull($m, 'لا حركة — والرصيدُ تغيّر بلا أثر');
        $this->assertSame('purchase_receive', $m->reason);
        $this->assertSame((int) $this->main->id, (int) $m->location_id,
            'الحركة بلا موقع — وهذا هو العطل الأصليّ');
        $this->assertSame((int) $this->merchant->id, (int) $m->actor_user_id);
        $this->assertSame(0, bccomp((string) $m->balance_after, '50', 3));
    }

    /**
     * @test
     *
     * **واللقطةُ تساوي مجموعَ الحركات — دائماً.**
     *
     * فلقطةٌ تنحرف عن مصدرها تصير رقماً يُقرأ حقيقةً وهو ليس كذلك.
     * (القاعدة السادسة: الرقم يُحسب من مصدره.)
     */
    public function the_snapshot_always_equals_the_sum_of_movements(): void
    {
        $p = $this->product();

        $this->stock->move($p, $this->main, '100', 'purchase_receive');
        $this->stock->move($p, $this->main, '-30', 'sale');
        $this->stock->move($p, $this->main, '-5', 'waste');
        $this->stock->move($p, $this->main, '12', 'sale_return');

        $snapshot = (string) ProductStock::where('product_id', $p->id)
            ->where('location_id', $this->main->id)->value('on_hand');

        $computed = $this->stock->computedFromMovements($p->id, $this->main->id);

        $this->assertSame(0, bccomp($snapshot, $computed, 3), sprintf(
            "اللقطة %s ومجموع الحركات %s — انحرفت عن مصدرها",
            $snapshot, $computed,
        ));

        $this->assertSame(0, bccomp($snapshot, '77', 3),
            "الرصيد {$snapshot} والصواب ٧٧ (١٠٠ − ٣٠ − ٥ + ١٢)");
    }

    /**
     * @test
     *
     * **والإشارةُ تُفحص ضدّ السبب — فالانقلابُ يقلب الجرد كلَّه.**
     */
    public function a_reason_cannot_carry_the_wrong_sign(): void
    {
        $p = $this->product();
        $this->stock->move($p, $this->main, '10', 'purchase_receive');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/يُنقص المخزون دائماً/');

        // «بيع» بمقدارٍ موجب — يظهر فائضٌ حيث العجز.
        $this->stock->move($p, $this->main, '5', 'sale');
    }

    /**
     * @test
     *
     * **وسببٌ مجهولٌ يُرفض ولا يُسجَّل.**
     */
    public function an_unknown_reason_is_refused(): void
    {
        $p = $this->product();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/غير معروف/');

        $this->stock->move($p, $this->main, '5', 'shrinkage');
    }

    // ══════════════════════════════════════════════════════════════════
    //  الموقع
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **مخزونُ فرعٍ لا يُنقص ببيعٍ في فرعٍ آخر.**
     *
     * وهذا هو العطلُ الأوّل: رقمٌ واحدٌ عالميّ، فبيعٌ في عدن ينقص مخزونَ
     * المكلا — **ويُطلب توريدٌ لفرعٍ ممتلئ ويقف فرعٌ فارغ**.
     */
    public function selling_in_one_branch_does_not_touch_another(): void
    {
        $aden = $this->stock->addLocation($this->merchant, [
            'code' => 'ADEN', 'name' => 'فرع عدن', 'kind' => 'store',
        ]);
        $mukalla = $this->stock->addLocation($this->merchant, [
            'code' => 'MKL', 'name' => 'فرع المكلا', 'kind' => 'store',
        ]);

        $p = $this->product();

        $this->stock->move($p, $aden, '40', 'purchase_receive');
        $this->stock->move($p, $mukalla, '60', 'purchase_receive');

        $this->stock->move($p, $aden, '-10', 'sale');

        $this->assertSame(0, bccomp($this->stock->available($p->id, $aden->id), '30', 3),
            'رصيد عدن خطأ بعد البيع');

        $this->assertSame(0, bccomp($this->stock->available($p->id, $mukalla->id), '60', 3),
            'بيعٌ في عدن نقص مخزونَ المكلا — وهذا العطل الأصليّ بعينه');
    }

    /**
     * @test
     *
     * **والمستودعُ موقعٌ من نوعٍ آخر — لا فرعُ بيع.**
     */
    public function a_warehouse_is_not_a_store(): void
    {
        $w = $this->stock->addLocation($this->merchant, [
            'code' => 'WH1', 'name' => 'المستودع', 'kind' => 'warehouse',
        ]);

        $this->assertTrue($w->isWarehouse(),
            'المستودع يُعدّ متجراً — فيدخل تقاريرَ المبيعات بصفرٍ دائم');
    }

    // ══════════════════════════════════════════════════════════════════
    //  البيع — والعطلُ الذي كان يمحو نفسه
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **البيعُ يمرّ عبر الحركة لا بكتابةٍ على عمود.**
     */
    public function a_cashier_sale_creates_a_stock_movement(): void
    {
        $p = $this->product('10');

        app(CashierService::class)->recordSale(
            merchant: $this->merchant,
            total: '900',
            paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'name' => 'صنف', 'quantity' => 3, 'price' => '300']],
        );

        $m = StockMovement::where('product_id', $p->id)
            ->where('reason', 'sale')->first();

        $this->assertNotNull($m,
            'البيع لم يترك حركة — فلا يُعرف من أين نقص المخزون');

        $this->assertSame(0, bccomp((string) $m->quantity_delta, '-3', 3));

        // **والتكلفةُ ملتقَطةٌ لحظةَ البيع** — بها تُحسب تكلفةُ المبيعات.
        $this->assertNotNull($m->unit_cost,
            'الحركة بلا تكلفة — والربح الإجماليّ يصير تقديراً يتغيّر بأثرٍ رجعيّ');
    }

    /**
     * @test
     *
     * **والنقصُ لا يُقَصّ إلى صفرٍ صامتاً — يُسجَّل ويُرى.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كان: `if ($newQty < 0) $newQty = '0';`
     *
     * **وهو محوُ دليل.** بيعةُ خمسٍ وفي النظام ثلاث تُسجَّل، والمخزون
     * يصير صفراً — والحبّتان الوهميّتان تختفيان. فيُقفَل الفرقُ قبل أن
     * يُرى، ولا يعرف صاحبُ المتجر أنّ بياناته انحرفت.
     *
     * والآن يبقى `-2` ظاهراً حتّى يُصلحه جرد.
     */
    public function overselling_is_recorded_not_silently_clamped(): void
    {
        $p = $this->product('3');

        app(CashierService::class)->recordSale(
            merchant: $this->merchant,
            total: '1500',
            paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'name' => 'صنف', 'quantity' => 5, 'price' => '300']],
        );

        $onHand = (string) ProductStock::where('product_id', $p->id)
            ->where('location_id', $this->main->id)->value('on_hand');

        $this->assertSame(0, bccomp($onHand, '-2', 3), sprintf(
            "الرصيد %s والصواب ‎-٢.\n"
            . 'قُصَّ إلى صفرٍ صامتاً — والحبّتان الوهميّتان اختفتا بلا أثر.',
            $onHand,
        ));

        $this->assertSame(0, bccomp(
            $this->stock->computedFromMovements($p->id, $this->main->id), '-2', 3),
            'الحركات لا تُظهر النقص — فالجرد سيبدأ من رقمٍ كاذب');
    }

    /**
     * @test
     *
     * **وصنفٌ أُنشئ بعد الهجرة يتبنّى رصيدَه القديم ولا يبدأ من صفر.**
     *
     * فالهجرةُ ملأت الجدولَ للأصناف القائمة يومَها، وما أُنشئ بعدها
     * بكمّيّةٍ في العمود القديم لا صفَّ له — **فيختفي رصيدٌ كان مكتوباً**.
     */
    public function a_product_created_after_the_migration_adopts_its_legacy_quantity(): void
    {
        $p = $this->product('10');

        // أوّلُ حركةٍ عليه — قبلها لا صفَّ مخزونٍ له.
        $this->stock->move($p, $this->main, '-4', 'sale');

        $onHand = (string) ProductStock::where('product_id', $p->id)
            ->where('location_id', $this->main->id)->value('on_hand');

        $this->assertSame(0, bccomp($onHand, '6', 3),
            "الرصيد {$onHand} والصواب ٦ — الرصيد القديم (١٠) لم يُتبنَّ فبدأ من صفر");

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $p->id,
            'reason' => 'opening_balance',
        ]);
    }

    /**
     * @test
     *
     * **والعمودُ القديم يبقى مرآةً لمجموع المواقع.**
     *
     * تقرؤه شاشاتٌ وتقاريرُ كثيرة، وانحرافُه عن المجموع يجعلها تعرض
     * رقماً لا يوجد في أيّ موقع.
     */
    public function the_legacy_column_mirrors_the_sum_of_all_locations(): void
    {
        $aden = $this->stock->addLocation($this->merchant, [
            'code' => 'ADEN', 'name' => 'فرع عدن',
        ]);

        $p = $this->product();

        $this->stock->move($p, $this->main, '25', 'purchase_receive');
        $this->stock->move($p, $aden, '15', 'purchase_receive');

        $this->assertSame(0, bccomp((string) $p->fresh()->quantity, '40', 3),
            'العمود القديم لا يساوي مجموع المواقع — والشاشاتُ تقرؤه');
    }

    /**
     * @test
     *
     * **وما لم يُجرَد يُقال «لم يُجرَد» — لا «مطابق».**
     */
    public function never_counted_is_stated_not_assumed_matching(): void
    {
        $p = $this->product();
        $this->stock->move($p, $this->main, '10', 'purchase_receive');

        $rows = $this->stock->acrossLocations($p);

        $this->assertTrue($rows[0]['never_counted'],
            'صنفٌ لم يُجرَد قطّ يُعرض كأنّه مطابق — والصفر يُقرأ «فُحص فلم يوجد»');
    }
}
