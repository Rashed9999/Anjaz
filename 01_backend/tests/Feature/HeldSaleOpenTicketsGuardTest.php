<?php

namespace Tests\Feature;

use App\Models\HeldSale;
use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\Retail\ProductStock;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\HeldSaleService;
use App\Services\Retail\StockService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-HELD-SALE-001 — **سلّةٌ تُعلَّق ولا تُلغى.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **من أين جاءت:** «التذاكر المفتوحة — السماح بحفظ وتعديل الطلبات قبل
 * إكمال عملية الدفع» في شاشة إعدادات المنافس، واختارها صاحبُ المشروع.
 *
 * **وقِيس ما هو قائم:**
 *
 *   held_sales · parked_sales · open_tickets · sale_drafts → **لا وجودَ لأيٍّ منها**
 *   restaurant_orders                                      → للمطاعم وحدَها
 *   وسلّةُ الكاشير `RxList<CartLine>` **في الذاكرة فقط**
 *
 * فأمام البقّال الذي يقول له الزبون «انتظر، نسيتُ الحليب» بابان: يُوقف
 * الطابورَ كلَّه، أو يُلغي السلّةَ ويُعيد مسحَ عشرين صنفاً. **والسلّةُ في
 * الذاكرة وحدَها** — مكالمةٌ واردةٌ تُخرج التطبيقَ فتذهب.
 */
class HeldSaleOpenTicketsGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'f_name' => 'راشد', 'l_name' => 'المعربي',
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);
    }

    private function product(string $qty = '50'): MerchantProduct
    {
        return MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id, 'name' => 'حليب',
            'price' => '300', 'cost_price' => '200', 'quantity' => $qty, 'is_active' => true,
        ]);
    }

    private function cart(int $productId = 0): array
    {
        return [
            ['name' => 'حليب', 'qty' => 2, 'price' => '300',
                'product_id' => $productId > 0 ? $productId : null],
            ['name' => 'خبز', 'qty' => 1, 'price' => '100'],
        ];
    }

    private function hold(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->merchant, 'api')->postJson(
            '/api/v1/amial/merchant/cashier/held',
            array_merge(['items' => $this->cart(), 'label' => 'زبون الحليب'], $body));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① تُعلَّق وتُستأنَف
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **السلّةُ تُحفَظ بمجموعها وأصنافها، وتُستأنَف كما هي.**
     */
    public function a_cart_is_held_and_comes_back_whole(): void
    {
        $r = $this->hold()->assertStatus(201);

        $ulid = $r->json('meta.ticket.ticket_ulid');
        $this->assertNotEmpty($ulid);

        // ٢×٣٠٠ + ١×١٠٠ = ٧٠٠
        $this->assertSame(0, bccomp((string) $r->json('meta.ticket.total'), '700', 4),
            'مجموعُ التذكرة ' . $r->json('meta.ticket.total') . ' — والمنتظَر ٧٠٠');

        $list = $this->actingAs($this->merchant, 'api')
            ->getJson('/api/v1/amial/merchant/cashier/held')->assertOk();

        $this->assertSame(1, $list->json('meta.count'));

        $back = $this->actingAs($this->merchant, 'api')
            ->postJson("/api/v1/amial/merchant/cashier/held/{$ulid}/resume")->assertOk();

        $items = $back->json('meta.ticket.items');
        $this->assertCount(2, $items, 'الأصنافُ لم تعد كما عُلّقت');
        $this->assertSame('حليب', $items[0]['name']);
        $this->assertSame(0, bccomp((string) $items[0]['price'], '300', 4));

        // **وتخرج من قائمة المفتوح** — وإلّا استُؤنفت مرّتين.
        $this->assertSame(0, $this->actingAs($this->merchant, 'api')
            ->getJson('/api/v1/amial/merchant/cashier/held')->json('meta.count'));
    }

    /**
     * @test
     *
     * **② ولا تُستأنَف مرّتين** — ولو من شبّاكين معاً.
     *
     * وهذا أخطرُ ما في الميزة: تذكرةٌ تُستأنَف مرّتين تُنتج **بيعتين
     * لسلّةٍ واحدة** — أي ضعفَ المبلغ على زبونٍ لم يشترِ مرّتين، ومخزوناً
     * يخرج مرّتين لبضاعةٍ خرجت مرّة.
     */
    public function a_ticket_is_never_resumed_twice(): void
    {
        $ulid = $this->hold()->json('meta.ticket.ticket_ulid');

        $this->actingAs($this->merchant, 'api')
            ->postJson("/api/v1/amial/merchant/cashier/held/{$ulid}/resume")->assertOk();

        $second = $this->actingAs($this->merchant, 'api')
            ->postJson("/api/v1/amial/merchant/cashier/held/{$ulid}/resume")
            ->assertStatus(422);

        $this->assertStringContainsString('شبّاك آخر', (string) $second->json('message'),
            'الرفضُ لا يقول لماذا — والكاشيرُ لا يعرف أنّ زميلَه أخذها');
    }

    /**
     * @test
     *
     * **③ والتراجعُ ممكن** — وإلّا ضاعت السلّةُ بلا رجعة.
     *
     * الاستئنافُ يقفلها قبل الدفع (وذاك صواب). لكنّ الكاشيرَ قد يتراجع أو
     * يسقط الدفع، **فتضيع السلّةُ** — وهو أسوأُ من العطل الذي بُنيت
     * الميزةُ لحلّه.
     */
    public function a_resumed_ticket_can_go_back_to_open(): void
    {
        $ulid = $this->hold()->json('meta.ticket.ticket_ulid');

        $this->actingAs($this->merchant, 'api')
            ->postJson("/api/v1/amial/merchant/cashier/held/{$ulid}/resume")->assertOk();

        $this->actingAs($this->merchant, 'api')
            ->postJson("/api/v1/amial/merchant/cashier/held/{$ulid}/reopen")->assertOk();

        $this->assertSame(1, $this->actingAs($this->merchant, 'api')
            ->getJson('/api/v1/amial/merchant/cashier/held')->json('meta.count'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ ولا تحجز مخزوناً — وهو القرارُ الذي يُقاس لا يُدَّعى
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **تذكرةٌ معلَّقةٌ لا تُنقص الرفّ.**
     *
     * قد تبقى ساعاتٍ أو تُهجَر، وحجزُها **يُفرّغ الرفَّ لبيعةٍ قد لا
     * تقع** — فيُمنَع زبونٌ حاضرٌ يدفع الآن من أجل تذكرةٍ نسيها صاحبُها.
     * والبضاعةُ تخرج عند الدفع.
     */
    public function holding_a_ticket_does_not_touch_stock(): void
    {
        $stock = app(StockService::class);
        $p = $this->product('0');
        $main = $stock->defaultLocation($this->merchant->id);
        $stock->move($p, $main, '50', 'opening_balance', actor: $this->merchant);

        $before = (string) ProductStock::where('product_id', $p->id)->value('on_hand');

        $this->actingAs($this->merchant, 'api')->postJson(
            '/api/v1/amial/merchant/cashier/held',
            ['items' => $this->cart($p->id), 'label' => 'زبون'])->assertStatus(201);

        $after = (string) ProductStock::where('product_id', $p->id)->value('on_hand');

        $this->assertSame(0, bccomp($before, $after, 3),
            "المخزون تغيّر بالتعليق: {$before} ← {$after}. "
            . 'وتذكرةٌ قد تُهجَر لا تُفرّغ الرفَّ أمام زبونٍ يدفع الآن.');

        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $p->id, 'reason' => 'sale',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ الأثر: من فتحها، وما مصيرُها
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **اسمُ من فتحها لقطةٌ لا مرجع** — كالورديّة بالحرف.
     */
    public function the_opener_name_is_a_snapshot(): void
    {
        $r = $this->hold()->assertStatus(201);

        $this->assertSame('راشد المعربي', $r->json('meta.ticket.opened_by_name'));

        $this->merchant->update(['f_name' => 'محمد', 'l_name' => 'آخر']);

        $this->assertSame('راشد المعربي',
            HeldSale::where('ticket_ulid', $r->json('meta.ticket.ticket_ulid'))
                ->value('opened_by_name'),
            'اسمُ من فتح التذكرة تغيّر بتغيير اسم المستخدم — فضاع الأثر');
    }

    /**
     * @test
     *
     * **⑥ وتُختَم بالبيعة التي وُلدت منها.**
     *
     * فيُعرف مصيرُها: دُفعت أم ما زالت معلَّقة. **وربطُ الأثر لا يُسقط
     * بيعةً ناجحة** — تذكرةٌ وهميّةٌ في الطلب لا تمنع البيع.
     */
    public function a_paid_ticket_is_stamped_with_its_sale(): void
    {
        app(CashierShiftService::class)->open($this->merchant, null, '0');

        $p = $this->product();
        $ulid = $this->actingAs($this->merchant, 'api')->postJson(
            '/api/v1/amial/merchant/cashier/held',
            ['items' => $this->cart($p->id)])->json('meta.ticket.ticket_ulid');

        $this->actingAs($this->merchant, 'api')
            ->postJson("/api/v1/amial/merchant/cashier/held/{$ulid}/resume")->assertOk();

        $sale = $this->actingAs($this->merchant, 'api')->postJson(
            '/api/v1/amial/merchant/cashier/sales', [
                'total' => '700', 'payment_method' => 'cash',
                'items' => [['name' => 'حليب', 'qty' => 2, 'price' => '300', 'product_id' => $p->id]],
                'held_ticket_ulid' => $ulid,
            ])->assertOk();

        $saleUlid = $sale->json('meta.sale.sale_ulid');
        $this->assertNotEmpty($saleUlid);

        $this->assertSame($saleUlid,
            HeldSale::where('ticket_ulid', $ulid)->value('sale_ulid'),
            'التذكرةُ لم تُربَط ببيعتها — فلا يُعرف مصيرُها');

        // **وتذكرةٌ لا وجودَ لها لا تُسقط بيعة.**
        $this->actingAs($this->merchant, 'api')->postJson(
            '/api/v1/amial/merchant/cashier/sales', [
                'total' => '100', 'payment_method' => 'cash',
                'items' => [['name' => 'خبز', 'qty' => 1, 'price' => '100']],
                'held_ticket_ulid' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ',
            ])->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑦ الحدود
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **سقفٌ للمفتوح** — لا لضيق الجدول بل لأنّ قائمةً بلا حدٍّ لا تُقرأ.
     */
    public function the_open_tickets_are_capped(): void
    {
        for ($i = 0; $i < HeldSale::MAX_OPEN; $i++) {
            $this->hold(['label' => "تذكرة {$i}"])->assertStatus(201);
        }

        $over = $this->hold(['label' => 'الزائدة'])->assertStatus(422);

        $this->assertStringContainsString((string) HeldSale::MAX_OPEN,
            (string) $over->json('message'),
            'الرفضُ لا يقول ما الحدّ — فالكاشيرُ يعيد المحاولة');
    }

    /**
     * @test
     *
     * **وسلّةٌ فارغةٌ لا تُعلَّق** — تذكرةٌ بلا أصناف تملأ القائمة بلا معنى.
     */
    public function an_empty_cart_is_refused(): void
    {
        $this->actingAs($this->merchant, 'api')->postJson(
            '/api/v1/amial/merchant/cashier/held',
            ['items' => [['name' => 'وهم', 'qty' => 0, 'price' => '100']]])
            ->assertStatus(422);
    }

    /**
     * @test
     *
     * **⑧ والملكيّةُ للمنشأة لا للجهاز** — أيُّ كاشيرٍ يستأنف تذكرةَ زميله.
     *
     * وهذا عينُ الغرض: الزبونُ انتقل إلى الصندوق الآخر. **ولا يراها تاجرٌ
     * آخر** — والحدُّ من الهويّة لا من الطلب (القاعدة الثامنة).
     */
    public function any_cashier_of_the_same_store_resumes_it_but_no_one_else(): void
    {
        $staffUser = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'f_name' => 'أحمد', 'l_name' => 'صالح',
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
        PosUser::create([
            'user_id' => $staffUser->id, 'merchant_user_id' => $this->merchant->id,
            'pos_number' => 'POS-1', 'display_name' => 'كاشير ١', 'is_active' => true,
        ]);

        $ulid = $this->hold()->json('meta.ticket.ticket_ulid');

        // **تاجرٌ آخرُ لا يراها ولا يستأنفها.**
        $stranger = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $stranger->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        $this->assertSame(0, $this->actingAs($stranger, 'api')
            ->getJson('/api/v1/amial/merchant/cashier/held')->json('meta.count'),
            'تذاكرُ منشأةٍ تظهر لمنشأةٍ أخرى');

        $this->actingAs($stranger, 'api')
            ->postJson("/api/v1/amial/merchant/cashier/held/{$ulid}/resume")
            ->assertStatus(422);

        // **وموظّفُ المنشأة نفسِها يستأنفها** — عبر الخدمة، لأنّ المسار
        // يمرّ بمقعد الجهاز وله حرّاسُه.
        $t = app(HeldSaleService::class)->resume($this->merchant, $ulid);
        $this->assertSame('resumed', $t['status']);
    }

    /**
     * @test
     *
     * **⑨ والإلغاءُ بسببٍ مكتوب** — تذكرةٌ تختفي بلا سببٍ تُقرأ عطلاً.
     */
    public function voiding_needs_a_written_reason(): void
    {
        $ulid = $this->hold()->json('meta.ticket.ticket_ulid');

        $this->actingAs($this->merchant, 'api')
            ->postJson("/api/v1/amial/merchant/cashier/held/{$ulid}/void", [])
            ->assertStatus(422);

        $this->actingAs($this->merchant, 'api')
            ->postJson("/api/v1/amial/merchant/cashier/held/{$ulid}/void",
                ['reason' => 'الزبون انصرف'])->assertOk();

        $this->assertDatabaseHas('held_sales', [
            'ticket_ulid' => $ulid, 'status' => 'voided', 'void_reason' => 'الزبون انصرف',
        ]);

        $this->assertSame(0, $this->actingAs($this->merchant, 'api')
            ->getJson('/api/v1/amial/merchant/cashier/held')->json('meta.count'));
    }

    /**
     * @test
     *
     * **⑩ والتعليقُ لا يشترط ورديّة.**
     *
     * لا يقبض ريالاً ولا يُخرج بضاعة، **والحدُّ يقع حيث يقع المال**. ووضعُ
     * حارس الورديّة هنا يمنع كاشيراً من حفظ سلّةٍ بناها فيُلغيها — وهو
     * العطلُ الذي بُنيت الميزةُ لحلّه.
     */
    public function holding_does_not_require_an_open_shift(): void
    {
        $this->assertNull(app(CashierShiftService::class)->current($this->merchant, null));

        $this->hold()->assertStatus(201);
    }
}
