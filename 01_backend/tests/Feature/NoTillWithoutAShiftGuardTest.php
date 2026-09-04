<?php

namespace Tests\Feature;

use App\Models\CashierShift;
use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-SHIFT-GATE-001 — **لا شبّاكَ بلا ورديّة، ولا فاتورةَ بلا اسم.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **بنصّ صاحب المشروع:** «لا يتمّ فتح الكاشير **حتّى لو كان مالك المتجر**
 * إلّا بفتح ورديّة تعمل اسمَه ووقتَ عمله اليوميّ والشهريّ، وعند الإقفال
 * يجب مطابقةُ ما فيه دفعٌ نقديّ ومعرفةُ الفائض والناقص… وأيضاً الفاتورة
 * يجب أن تحمل اسمَ فاتح الورديّة أسفلها».
 *
 * **وقِيس قبل البناء، فالحالُ أسوأُ ممّا يُظنّ:**
 *
 *   grep -c "shift" app/Services/CashierService.php  ⇒  **0**
 *
 * أي أنّ مسارَ البيع **لا يعرف بوجود الورديّات إطلاقاً**: تُقبَض النقودُ
 * ولا يُعرف من قبضها، ولا شيءَ يُطابَق آخرَ اليوم لأنّه لا يوجد «آخرُ
 * يومٍ» أصلاً. و`merchant_sales` بلا `shift_id`، و`cashier_shifts` بلا
 * اسمِ فاتح.
 *
 * ─────────────────────────────────────────────────────────────────────
 * **وأخطرُ ما كُشف في الطريق — ولم يكن في الطلب:**
 *
 * `F_SHIFT_CLOSE` كانت **باقةَ الأعمال فأعلى**، وردُّها ٤٠٢ لمن دونها.
 * فلو صار البيعُ يشترط ورديّةً وهي مدفوعة، **لصار كلُّ تاجرٍ مجّانيٍّ
 * عاجزاً عن البيع إطلاقاً** — أي بيعُ القدرةِ على تشغيل الشبّاك.
 *
 * فصارت **أساسيّةً لا تُباع** (`core()`)، وهو الحدُّ المكتوب في سجلّ
 * القدرات نفسِه: «قدرةٌ أساسيّةٌ لا تُباع… هذه تمنع أرقاماً كاذبة، لا
 * تُضيف قيمة». وورديّةٌ تُنسَب إليها كلُّ بيعةٍ وتُجرَد آخرَ اليوم **تمنع
 * رقماً كاذباً**: درجاً لا صاحبَ له، وفرقاً لا يُنسَب.
 */
class NoTillWithoutAShiftGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->merchantOn(A::PLAN_FREE);
    }

    private function merchantOn(string $plan, string $vertical = A::BIZ_RETAIL): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'f_name' => 'راشد', 'l_name' => 'المعربي',
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'tier' => 'small', 'verification_status' => 'verified',
            'business_type' => $vertical, 'subscription_plan' => $plan,
        ]);

        return $u;
    }

    private function product(User $m): MerchantProduct
    {
        return MerchantProduct::create([
            'merchant_user_id' => $m->id, 'name' => 'صنف قياس',
            'price' => '300', 'cost_price' => '100', 'quantity' => '50', 'is_active' => true,
        ]);
    }

    /** بيعةٌ نقديّةٌ عبر البابِ الذي يدخل منه إنسان. */
    private function sell(User $actor, User $merchant): \Illuminate\Testing\TestResponse
    {
        $p = MerchantProduct::where('merchant_user_id', $merchant->id)->first()
            ?? $this->product($merchant);

        return $this->actingAs($actor, 'api')->postJson('/api/v1/amial/merchant/cashier/sales', [
            'total' => '300',
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $p->id, 'name' => $p->name,
                'quantity' => 1, 'unit_price' => '300',
            ]],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الحدّ — ولا استثناءَ للمالك
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **صاحبُ المتجر نفسُه لا يبيع بلا ورديّة.**
     *
     * وهو صلبُ الطلب: بلا ذلك لا تُنسَب بيعتُه إلى أحد، ويصير الفرقُ في
     * الدرج بلا صاحب.
     */
    public function even_the_owner_cannot_sell_without_opening_a_shift(): void
    {
        $r = $this->sell($this->owner, $this->owner);

        $r->assertStatus(409);
        $this->assertSame('SHIFT_REQUIRED', $r->json('code'),
            'البيعُ مرّ بلا ورديّة — والدرجُ مفتوحٌ بلا أحد. الردّ: '
            . $r->status() . ' / ' . $r->json('code'));

        $this->assertTrue($r->json('meta.can_open'),
            'الرفضُ لا يقول للشاشة أنّها تستطيع فتحَ ورديّة، فتعرض عطلاً بدل نافذة');
        $this->assertTrue($r->json('meta.is_owner'));

        $this->assertDatabaseCount('merchant_sales', 0);
    }

    /**
     * @test
     *
     * **وموظّفُ نقطة البيع كذلك** — والمدخلان يُجرَّبان (القاعدة الرابعة).
     *
     * والفرقُ بينهما حقيقيّ: `current()` يفرّق بالفاعل، فورديّةُ المالك
     * `pos_user_id = null` وورديّةُ كلّ كاشيرٍ برقمه. **فورديّةُ المالك
     * لا تفتح الشبّاكَ لموظّفه** — وإلّا حُوسب الموظّفُ على درجٍ لم يفتحه.
     */
    public function a_pos_employee_needs_their_own_shift_not_the_owners(): void
    {
        // **وباقةُ الأعمال هنا لا المجّانيّة** — والمجّانيّةُ بلا موظّفين
        // بقرارٍ صريحٍ موثَّقٍ في `AccessConstants` (`employees => 0`).
        $boss = $this->merchantOn(A::PLAN_BUSINESS);

        $staffUser = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'f_name' => 'أحمد', 'l_name' => 'صالح',
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);

        $pos = PosUser::create([
            'user_id' => $staffUser->id, 'merchant_user_id' => $boss->id,
            'pos_number' => 'POS-1', 'display_name' => 'كاشير ١', 'is_active' => true,
        ]);

        // **والحارسُ يُجرَّب وحدَه هنا، لا عبر المسار الكامل.**
        //
        // مسارُ البيع يمرّ أوّلاً بـ`amial.pos-device` — مقعدُ جهازٍ
        // مسجَّلٍ لجلسة الموظّف، وهو حدٌّ آخرُ سليمٌ له حرّاسُه
        // (`PosDeviceSessionBindingGuardTest`). فتجريبُ الورديّة من خلفه
        // يقيس ذاك الحدَّ لا هذا، **ويُخرج ٤٠٣ فيُقرأ «الورديّة تعمل»**
        // وهي لم تُسأل أصلاً. (وهو عينُ «حارسٌ يمرّ والعطل قائم».)
        $ask = function (User $actor) {
            $request = \Illuminate\Http\Request::create(
                '/api/v1/amial/merchant/cashier/sales', 'POST');
            $request->setUserResolver(fn () => $actor);

            return app(\App\Http\Middleware\EnsureOpenShift::class)->handle(
                $request, fn () => response()->json(['ok' => true]));
        };

        // **المالكُ فتح ورديّتَه** — ولا تنفع الموظّف.
        app(CashierShiftService::class)->open($boss, null, '1000');

        $this->assertSame(409, $ask($staffUser)->getStatusCode(),
            'ورديّةُ المالك فتحت الشبّاكَ لموظّفه — فيُحاسَب على درجٍ لم يفتحه');

        // وبورديّته هو، يمرّ.
        $staffShift = app(CashierShiftService::class)->open($boss, $pos->id, '500');

        $this->assertSame(200, $ask($staffUser)->getStatusCode(),
            'الموظّفُ فتح ورديّتَه والحارسُ ما زال يمنعه');

        // **واسمُ الموظّف هو المحفوظ، لا اسمُ صاحب المتجر.**
        $this->assertSame('أحمد صالح', $staffShift->opened_by_name);
        $this->assertSame('pos', $staffShift->opened_by_role);
    }

    /**
     * @test
     *
     * **وبورديّةٍ مفتوحة يمرّ البيع** — والحارسُ ليس حاجزاً دائماً.
     */
    public function with_an_open_shift_the_sale_goes_through(): void
    {
        app(CashierShiftService::class)->open($this->owner, null, '1000');

        $this->sell($this->owner, $this->owner)->assertStatus(200);

        $this->assertDatabaseCount('merchant_sales', 1);
    }

    /**
     * @test
     *
     * **② والبيعةُ تحمل ورديّتَها — لا تُستنتَج بالزمن.**
     *
     * ورديّةٌ نُسي إقفالُها ثمّ فُتحت أخرى، أو ساعةٌ تُعدَّل: الحسابُ
     * بالمدى الزمنيّ ينكسر، والرابطُ الصريحُ لا ينكسر.
     */
    public function the_sale_carries_its_shift_id(): void
    {
        $shift = app(CashierShiftService::class)->open($this->owner, null, '1000');

        $this->sell($this->owner, $this->owner)->assertStatus(200);

        $this->assertDatabaseHas('merchant_sales', [
            'merchant_user_id' => $this->owner->id,
            'shift_id' => $shift->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ الورديّة تحمل اسمَ فاتحها — لقطةً لا مرجعاً
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الاسمُ يُلتقَط عند الفتح ولا يُعاد قراءتُه.**
     *
     * وهذا هو الفحصُ الذي يحمي ورقةً في يد زبون: موظّفٌ يُعاد تسميتُه
     * غداً **لا يُعيد كتابةَ فاتورةٍ طُبعت أمس**.
     */
    public function the_opener_name_is_a_snapshot_not_a_live_lookup(): void
    {
        $shift = app(CashierShiftService::class)->open($this->owner, null, '1000');

        $this->assertSame('راشد المعربي', $shift->opened_by_name);
        $this->assertSame('owner', $shift->opened_by_role);

        // **يُعاد تسميةُ المستخدم** — والورديّةُ لا تتبدّل.
        $this->owner->update(['f_name' => 'محمد', 'l_name' => 'آخر']);

        $this->assertSame('راشد المعربي', $shift->fresh()->opened_by_name,
            'اسمُ فاتح الورديّة تغيّر بتغيير اسم المستخدم — فأُعيدت كتابةُ '
            . 'فواتيرَ طُبعت سلفاً، والورقةُ في يد الزبون تقول غيرَ الشاشة.');
    }

    /**
     * @test
     *
     * **④ والفاتورةُ تحمل الاسمَ أسفلها.**
     *
     * ويُقاس على **وثيقة الإيصال** لا على القالب: القالبُ يقرأ ما تُخرجه
     * الوثيقة، و`merchantInvoice` **تنتقي مفاتيحَ المصدر واحداً واحداً**
     * — فمفتاحٌ يُبنى ولا يُذكَر هناك يسقط صامتاً، وهو ما وقع حرفيّاً مع
     * `tendered_lines` قبله.
     */
    public function the_invoice_carries_the_opener_name_at_its_foot(): void
    {
        $src = file_get_contents(app_path('Services/ReceiptDocumentService.php'));

        $this->assertStringContainsString("'shift_line' => \$this->shiftLine(", $src,
            'مصدرُ التجزئة لا يبني سطرَ الوردية');
        $this->assertStringContainsString("'shift_line' => \$source['shift_line'] ?? null", $src,
            'سطرُ الوردية يُبنى ولا يُنقَل إلى الوثيقة — فيسقط صامتاً كما '
            . 'وقع مع «المبلغ المستلم» من قبل.');

        foreach (['thermal', 'merchant-invoice'] as $view) {
            $tpl = file_get_contents(resource_path("views/receipts/{$view}.blade.php"));

            $this->assertStringContainsString("shift_line", $tpl,
                "قالبُ «{$view}» لا يطبع اسمَ فاتح الوردية — والمطلبُ أن يكون أسفلَ الفاتورة");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ الإقفال: مطابقةُ النقد والفائض والناقص
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الفرقُ يُحسب من الحركة ويُسمّى فائضاً أو عجزاً.**
     *
     * ولا يُقرأ من عمودٍ مخزَّن (القاعدة السادسة): «المتوقَّع» = العهدة +
     * نقدُ المبيعات المحسوب، لا رصيدٌ يساوي نفسَه.
     */
    public function closing_reconciles_the_drawer_and_names_the_gap(): void
    {
        app(CashierShiftService::class)->open($this->owner, null, '1000');
        $this->sell($this->owner, $this->owner)->assertStatus(200);   // ٣٠٠ نقداً

        // عُدَّ ١٢٥٠ والمتوقَّع ١٣٠٠ ⇒ عجزُ ٥٠
        $r = $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/amial/cashier/shift/close', ['counted_cash' => 1250])
            ->assertOk();

        $this->assertSame(0, bccomp((string) $r->json('meta.shift.expected_cash'), '1300', 4),
            'المتوقَّع ' . $r->json('meta.shift.expected_cash') . ' — والمنتظَر ١٣٠٠ (عهدة ١٠٠٠ + بيع ٣٠٠)');
        $this->assertSame(0, bccomp((string) $r->json('meta.shift.variance'), '-50', 4));
        $this->assertSame('shortage', $r->json('meta.shift.variance_kind'),
            'الفرقُ رقمٌ بإشارةٍ ولا يُسمّى — و«‎-٥٠» تُقرأ خطأً على شاشةٍ صغيرة');
        $this->assertSame('راشد المعربي', $r->json('meta.shift.closed_by_name'));

        // **والفائضُ يُسمّى فائضاً** — وهو الوجهُ الآخر، ويُفحص وحدَه.
        app(CashierShiftService::class)->open($this->owner, null, '1000');
        $r2 = $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/amial/cashier/shift/close', ['counted_cash' => 1075])
            ->assertOk();

        $this->assertSame('surplus', $r2->json('meta.shift.variance_kind'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑥ وقتُ العمل — اليومَ وهذا الشهر
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الساعاتُ تُحسب من الورديّات لا من عمودٍ مخزَّن.**
     *
     * **والورديّةُ المفتوحةُ جاريةٌ لا صفر** (القاعدة السابعة): صفرٌ هناك
     * يقتطع من أجرِ من هو واقفٌ على الشبّاك الآن.
     */
    public function work_time_is_computed_from_the_shifts_and_running_is_not_zero(): void
    {
        // ورديّةٌ مُقفلةٌ بثلاث ساعات
        CashierShift::create([
            'merchant_user_id' => $this->owner->id, 'pos_user_id' => null,
            'opening_float' => '0', 'status' => 'closed',
            'opened_by' => $this->owner->id, 'opened_by_name' => 'راشد المعربي',
            'opened_by_role' => 'owner',
            'opened_at' => now()->startOfDay()->addHours(8),
            'closed_at' => now()->startOfDay()->addHours(11),
            'zone_code' => 'SOUTH',
        ]);

        // وأخرى جاريةٌ منذ ساعتين
        app(CashierShiftService::class)->open($this->owner, null, '500');
        CashierShift::where('merchant_user_id', $this->owner->id)
            ->where('status', 'open')->update(['opened_at' => now()->subHours(2)]);

        $rows = $this->actingAs($this->owner, 'api')
            ->getJson('/api/v1/amial/cashier/shift/work-time')
            ->assertOk()->json('meta.people');

        $this->assertCount(1, $rows, 'الشخصُ الواحد يجب أن يُجمَع في صفٍّ واحد');

        $me = $rows[0];
        $this->assertSame('راشد المعربي', $me['name']);
        $this->assertTrue($me['is_running'],
            'الورديّةُ الجاريةُ لا تُوسَم — فتُقرأ منتهيةً وهو واقفٌ الآن');
        $this->assertSame(2, $me['shifts_today']);

        // ٣ ساعاتٍ مقفلة + ساعتان جاريتان = ٥
        $this->assertEqualsWithDelta(5.0, $me['hours_today'], 0.05,
            'ساعاتُ اليوم ' . $me['hours_today'] . ' — والمنتظَر ٥ '
            . '(٣ مُقفلة + ٢ جارية). والورديّةُ الجاريةُ ليست صفراً.');
        $this->assertEqualsWithDelta(5.0, $me['hours_month'], 0.05);
    }

    /**
     * @test
     *
     * **وساعاتُ الزملاء للمالك وحدَه** (القاعدة الثامنة).
     */
    public function work_time_is_owner_only(): void
    {
        $staffUser = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
        PosUser::create([
            'user_id' => $staffUser->id, 'merchant_user_id' => $this->owner->id,
            'pos_number' => 'POS-9', 'display_name' => 'كاشير ٩', 'is_active' => true,
        ]);

        $this->actingAs($staffUser, 'api')
            ->getJson('/api/v1/amial/cashier/shift/work-time')
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑦ ولا يُباع حقُّ تشغيل الشبّاك
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **التاجرُ المجّانيُّ يفتح ورديّتَه ويبيع.**
     *
     * ولولا هذا لصار الحارسُ **قفلاً على البيع كلِّه** لمن لم يشترِ باقة:
     * `F_SHIFT_CLOSE` كانت ترجع ٤٠٢ لمن دون «الأعمال».
     */
    public function a_free_plan_merchant_can_still_open_a_shift_and_sell(): void
    {
        $this->assertSame(A::PLAN_FREE,
            MerchantProfile::where('user_id', $this->owner->id)->value('subscription_plan'));

        $this->actingAs($this->owner, 'api')
            ->postJson('/api/v1/amial/cashier/shift/open', ['opening_float' => 500])
            ->assertStatus(201);

        $this->sell($this->owner, $this->owner)->assertStatus(200);
    }

    /**
     * @test
     *
     * **والقدرةُ أساسيّةٌ في السجلّ نفسِه** — لا في وسيطٍ يلتفّ عليها.
     */
    public function the_shift_capability_is_core_and_free(): void
    {
        $cap = \App\Support\Access\CapabilityRegistry::find(A::F_SHIFT_CLOSE);

        $this->assertNotNull($cap);
        $this->assertTrue($cap->isCore(),
            'الورديّةُ ليست أساسيّة — فتُقفَل بباقة، ويُقفَل معها البيعُ كلُّه');
        $this->assertSame(A::PLAN_FREE, $cap->toArray()['min_plan'] ?? A::PLAN_FREE);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑧ ولا بابَ بيعٍ يفلت من الحارس
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **كلُّ نقدٍ يعدّه الدرجُ يمرّ من الحارس.**
     *
     * وهذه هي القاعدةُ التي تُبقي الحارسَ حيّاً بعد سنة: `computeCash`
     * يجمع نقدَ **الكاشير والصيدليّة وتحصيلات الجملة**. فبابٌ منها بلا
     * حارسٍ يُنتج نقداً في «المتوقَّع» لم تأذن به ورديّة — أي فائضاً أو
     * عجزاً في وجه من لم يقبضه.
     *
     * **والوقودُ مستثنىً صراحةً** — له `fuel_shifts` وخدمتُه، و`computeCash`
     * يستثنيه لئلّا يُعدّ بيعُه مرّتين.
     */
    public function every_route_whose_cash_lands_in_the_drawer_is_gated(): void
    {
        $mustBeGated = [
            'api/v1/amial/merchant/cashier/sales' => 'بيعُ الكاشير العامّ',
            'api/v1/amial/merchant/pharmacy/sales' => 'شبّاكُ الصيدليّة',
            'api/v1/amial/merchant/wholesale/invoices/{id}/collect' => 'تحصيلُ الجملة النقديّ',
        ];

        foreach ($mustBeGated as $uri => $what) {
            $route = collect(Route::getRoutes())->first(
                fn ($r) => $r->uri() === $uri && in_array('POST', $r->methods(), true));

            $this->assertNotNull($route, "المسارُ «{$uri}» غير مسجَّل — تغيّر اسمُه؟");

            $this->assertContains('amial.shift', $route->gatherMiddleware(),
                "«{$what}» ({$uri}) بلا حارس ورديّة. نقدُه يدخل «المتوقَّع» "
                . 'في الدرج ولا ورديّةَ أذنت به — فيظهر فائضاً في وجه من لم يقبضه.');
        }

        // **والوقودُ مستثنىً — ويُفحَص أنّه مستثنىً** فلا يُضاف سهواً
        // فيُطلَب من عامل المحطّة ورديّتان.
        $fuel = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/amial/merchant/fuel/sales'
                && in_array('POST', $r->methods(), true));

        if ($fuel) {
            $this->assertNotContains('amial.shift', $fuel->gatherMiddleware(),
                'الوقودُ له ورديّاتُه الخاصّة (`fuel_shifts`)، وحارسُ درج '
                . 'الكاشير هنا يطلب ورديّةً ثانيةً لا معنى لها.');
        }
    }

    /**
     * @test
     *
     * **⑨ والحدُّ يُطفأ من اللوحة بقرارٍ مكتوب، لا بتعليق سطر.**
     *
     * بسطةٌ يعمل فيها صاحبُها وحدَه قد لا يريد خطوةً قبل كلّ بيعة —
     * **والقرارُ التجاريُّ ليس قرارَ شيفرة**. والافتراضيُّ الإلزام.
     */
    public function the_gate_can_be_turned_off_from_the_merchant_profile(): void
    {
        $this->assertTrue(
            (bool) MerchantProfile::where('user_id', $this->owner->id)
                ->value('require_shift_to_sell'),
            'الافتراضيُّ ليس الإلزام — والمطلبُ أن يكون كذلك');

        $this->sell($this->owner, $this->owner)->assertStatus(409);

        MerchantProfile::where('user_id', $this->owner->id)
            ->update(['require_shift_to_sell' => false]);

        $this->sell($this->owner, $this->owner)->assertStatus(200);
    }
}
