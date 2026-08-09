<?php

namespace Tests\Feature;

use App\Models\Fuel\FuelNozzle;
use App\Models\Fuel\FuelTank;
use App\Models\Merchant\MerchantRole;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Fuel\FuelDeliveryService;
use App\Services\Fuel\FuelPriceService;
use App\Services\Fuel\FuelShiftCashService;
use App\Services\Fuel\FuelTankService;
use App\Services\Fuel\FuelWetStockService;
use App\Services\FuelShiftService;
use App\Services\FuelStationService;
use App\Services\Merchant\MerchantPermissionService;
use App\Support\Merchant\MerchantPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FUEL-VERTICAL-001 · المراحل ١–٧ — **حرّاسٌ تقيس السلوك**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * ولا واحدٌ منها يقرأ نصَّ ملفّ: كلُّها تُنشئ خزّاناً وتبيع وتُورّد وتقيس
 * وتُصالح، ثمّ تقرأ الرقمَ الذي يراه صاحبُ المحطّة.
 */
class FuelVerticalGuardTest extends TestCase
{
    use RefreshDatabase;

    private FuelStationService $fuel;
    private FuelTankService $tanks;
    private FuelDeliveryService $deliveries;
    private FuelWetStockService $wet;
    private FuelShiftService $shifts;
    private User $merchant;
    private $station;
    private $pump;
    private $product;
    private FuelTank $tank;
    private FuelNozzle $nozzle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fuel = app(FuelStationService::class);
        $this->tanks = app(FuelTankService::class);
        $this->deliveries = app(FuelDeliveryService::class);
        $this->wet = app(FuelWetStockService::class);
        $this->shifts = app(FuelShiftService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'verification_status' => 'verified',
        ]);

        $this->station = $this->fuel->getOrCreateStation($this->merchant, [
            'station_name' => 'محطة القياس',
        ]);
        $this->pump = $this->fuel->addPump($this->station, ['pump_number' => 1]);
        $this->product = $this->fuel->addProduct($this->station, [
            'name' => 'بنزين 91', 'price_per_liter' => '1000',
        ]);

        $this->tank = $this->tanks->addTank($this->station, [
            'tank_number' => 1, 'fuel_product_id' => $this->product->id,
            'capacity_liters' => '20000', 'min_alert_liters' => '2000',
        ]);

        $this->nozzle = $this->tanks->addNozzle($this->pump, [
            'nozzle_number' => 1, 'fuel_product_id' => $this->product->id,
            'tank_id' => $this->tank->id,
        ]);
    }

    private function openShift()
    {
        return $this->shifts->openShift($this->station, $this->merchant, '0');
    }

    private function sell(string $liters, string $method = 'cash')
    {
        return $this->fuel->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id,
            'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_liters',
            'liters' => $liters,
            'payment_method' => $method,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ١ — المسدس والخزّان
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **كلُّ بيعةٍ تُنسب إلى خزّانها** — وبلا ذلك لا مصالحة.
     */
    public function a_sale_is_attributed_to_its_tank(): void
    {
        $this->openShift();

        $sale = $this->sell('40');

        $this->assertSame((int) $this->nozzle->id, (int) $sale->nozzle_id,
            'المبيعة بلا مسدس — فلا يُعرف من أيّ فوّهة خرجت');

        $this->assertSame((int) $this->tank->id, (int) $sale->tank_id,
            'المبيعة بلا خزان — لتراتها خارج المصالحة كلّها');
    }

    /**
     * @test
     *
     * **والخزّانُ ينقص باللترات المباعة.**
     */
    public function selling_reduces_the_tank_book_volume(): void
    {
        $this->openShift();
        $this->tanks->addFromDelivery($this->tank, '5000');

        $before = (string) $this->tank->fresh()->book_liters;
        $this->sell('40');
        $after = (string) $this->tank->fresh()->book_liters;

        $this->assertSame(0, bccomp(bcsub($before, $after, 3), '40', 3), sprintf(
            'الخزان لم ينقص باللترات المباعة: قبل %s بعد %s', $before, $after));
    }

    /**
     * @test
     *
     * **وعدّادُ المسدس يتقدّم — لا عدّادُ المضخّة وحدَه.**
     *
     * فمضخّةٌ بمسدسَي بنزينٍ وديزل لها عدّادٌ واحدٌ في التصميم القديم،
     * فلا يُعرف كم خرج من أيّ نوع.
     */
    public function the_nozzle_meter_advances_by_the_litres_sold(): void
    {
        $this->openShift();

        $before = (string) $this->nozzle->fresh()->current_meter_reading;
        $this->sell('25');
        $after = (string) $this->nozzle->fresh()->current_meter_reading;

        $this->assertSame(0, bccomp(bcsub($after, $before, 3), '25', 3),
            "عدّاد المسدس لم يتقدّم: قبل {$before} بعد {$after}");
    }

    /**
     * @test
     *
     * **ولا يُربط مسدسٌ بخزّانٍ من نوعٍ آخر.**
     *
     * ربطُ مسدس بنزينٍ بخزّان ديزل يخصم كلَّ بيعةٍ من الخزّان الخطأ: فائضٌ
     * في واحدٍ وعجزٌ في الآخر، **ويبدو الأمرُ سرقةً وهو خطأُ ربطٍ وقع مرّة**.
     */
    public function a_nozzle_cannot_point_at_a_tank_of_another_fuel(): void
    {
        $diesel = $this->fuel->addProduct($this->station, [
            'name' => 'ديزل', 'price_per_liter' => '800',
        ]);

        $dieselTank = $this->tanks->addTank($this->station, [
            'tank_number' => 2, 'fuel_product_id' => $diesel->id,
            'capacity_liters' => '10000',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/يخالف/');

        $this->tanks->linkNozzleToTank($this->nozzle, $dieselTank->id);
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٢ — التوريدات
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **المخزونُ لا يرتفع إلّا بالترحيل.**
     *
     * ورفعُه عند الإدخال يجعل ورقةً مكتوبةً بالخطأ ترفع الرصيدَ فوراً،
     * فتظهر مصالحةُ الليلة عجزاً لا وجود له — **ويُفتح تحقيقٌ في سرقةٍ
     * سببُها رقمٌ زائد**.
     */
    public function stock_rises_only_when_the_delivery_is_posted(): void
    {
        $start = (string) $this->tank->fresh()->book_liters;

        $d = $this->deliveries->receive($this->station, $this->tank, $this->merchant, [
            'quantity_liters' => '5000',
            'dip_before_liters' => '1000',
        ]);

        $this->assertSame(0, bccomp((string) $this->tank->fresh()->book_liters, $start, 3),
            'المخزون ارتفع عند الاستلام — قبل التحقق والترحيل');

        $this->deliveries->verify($d, $this->merchant, '1000', '6000');

        $this->assertSame(0, bccomp((string) $this->tank->fresh()->book_liters, $start, 3),
            'المخزون ارتفع عند التحقق — والترحيل هو الحدّ');

        $this->deliveries->post($d->fresh(), $this->merchant);

        $this->assertSame(0,
            bccomp((string) $this->tank->fresh()->book_liters, bcadd($start, '5000', 3), 3),
            'المخزون لم يرتفع بعد الترحيل');
    }

    /**
     * @test
     *
     * **والتحقّقُ يقارن الورقةَ بالخزّان.**
     *
     * فناقلٌ يسلّم أقلَّ مِمّا كُتب يُكتشف هنا لا بعد شهر.
     */
    public function verification_rejects_a_dip_that_contradicts_the_invoice(): void
    {
        $d = $this->deliveries->receive($this->station, $this->tank, $this->merchant, [
            'quantity_liters' => '5000',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/يخالف الفاتورة/');

        // الفاتورة ٥٠٠٠ والقياس يقول ٤٠٠٠ — فرقُ ألفِ لتر.
        $this->deliveries->verify($d, $this->merchant, '1000', '5000');
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٣ — مصالحة المخزون الرطب
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **المعادلةُ تُخرج الفقد — وهذا هو جوهرُ نظام المحطّة.**
     *
     * ══════════════════════════════════════════════════════════════════
     *   افتتاحيّ ١٠٬٠٠٠ + مورَّد ٥٬٠٠٠ − مباع ١٢٬٧٠٠ = متوقَّع ٢٬٣٠٠
     *   متوقَّع ٢٬٣٠٠ − مقيس ٢٬٢١٠ ⇒ **فقدُ ٩٠ لتراً**
     */
    public function the_wet_stock_equation_finds_the_loss(): void
    {
        // القياسُ الافتتاحيّ — **وهو الأساس، لا `book_liters`**.
        //
        // ويُوضع قبل بداية المدّة صراحةً: الاختبارُ كلُّه يقع في ثانيةٍ
        // واحدة، ولو تُرك على `now()` لالتقط `dipAt()` قياسَ التوريد
        // (الأحدثَ معرّفاً في الثانية نفسها) فيصير الافتتاحيُّ ١٥٬٠٠٠.
        $opening = $this->tanks->recordDip(
            $this->tank, $this->merchant, '10000', 'opening');
        $opening->update(['taken_at' => now()->subHours(2)]);

        $this->tank->update(['book_liters' => '10000']);

        $from = now()->subHour();

        // توريدٌ مرحَّل ٥٬٠٠٠
        $d = $this->deliveries->receive($this->station, $this->tank, $this->merchant, [
            'quantity_liters' => '5000', 'dip_before_liters' => '10000',
        ]);
        $this->deliveries->verify($d, $this->merchant, '10000', '15000');
        $this->deliveries->post($d->fresh(), $this->merchant);

        // مبيعاتٌ ١٢٬٧٠٠ لتر
        $this->openShift();
        $this->sell('12700');

        $r = $this->wet->compute($this->tank, $from, now()->addMinute(), '2210');

        $this->assertTrue($r['computable'], $r['reason'] ?? '');

        $this->assertSame(0, bccomp($r['sold_liters'], '12700', 3),
            "المباع {$r['sold_liters']} والصواب ١٢٧٠٠ — النسبةُ إلى الخزّان مكسورة");

        $this->assertSame(0, bccomp($r['delivered_liters'], '5000', 3),
            "المورَّد {$r['delivered_liters']} والصواب ٥٠٠٠");

        $this->assertSame(0, bccomp($r['expected_closing_liters'], '2300', 3),
            "المتوقَّع {$r['expected_closing_liters']} والصواب ٢٣٠٠");

        $this->assertSame(0, bccomp($r['variance_liters'], '-90', 3), sprintf(
            "الفرق %s والصواب ٩٠- لتراً.\n"
            . 'وهذا هو الرقم الذي يفصل نظام محطّةٍ عن تطبيق فواتير.',
            $r['variance_liters']));

        $this->assertSame('loss', $r['direction']);
    }

    /**
     * @test
     *
     * **وبلا قياسٍ افتتاحيٍّ لا مصالحة — ولا يُفترض صفراً.**
     *
     * صفرٌ هنا يُنتج فرقاً بحجم الخزّان كلِّه. (القاعدة السابعة.)
     */
    public function no_opening_dip_means_no_reconciliation_not_a_zero(): void
    {
        $r = $this->wet->compute($this->tank, now()->subHour(), now(), '5000');

        $this->assertFalse($r['computable'],
            'حُسبت مصالحةٌ بلا قياسٍ افتتاحيّ — والفرقُ سيكون بحجم الخزّان');

        $this->assertStringContainsString('قياس افتتاحي', $r['reason']);
    }

    /**
     * @test
     *
     * **واللتراتُ غيرُ المنسوبة تُقال ولا تُبتلع.**
     *
     * مسدسٌ بلا خزّان يُخرج لتراتِه من المعادلة، **فيظهر فائضٌ يُقرأ ربحاً**.
     */
    public function unattributed_litres_are_reported(): void
    {
        $orphan = $this->tanks->addNozzle($this->pump, [
            'nozzle_number' => 2, 'fuel_product_id' => $this->product->id,
        ]);

        $this->openShift();

        $this->fuel->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id,
            'fuel_product_id' => $this->product->id,
            'nozzle_id' => $orphan->id,
            'sale_type' => 'by_liters', 'liters' => '77',
            'payment_method' => 'cash',
        ]);

        $u = $this->wet->unattributedLiters(
            $this->station, now()->subHour(), now()->addMinute());

        $this->assertSame(0, bccomp($u, '77', 3),
            "اللترات غير المنسوبة {$u} والصواب ٧٧ — وسكوتُنا عنها يُنتج فائضاً وهميّاً");
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٦ — الأسعار
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **السعرُ لا يسري قبل الاعتماد، ولا يعتمد المقترحُ اقتراحَه.**
     */
    public function a_price_does_not_take_effect_before_approval(): void
    {
        $prices = app(FuelPriceService::class);
        $employee = User::factory()->create(['type' => 3]);

        $v = $prices->propose($this->product, $employee, '1200', 'تحديث رسمي');

        $this->assertSame(0,
            bccomp((string) $this->product->fresh()->price_per_liter, '1000', 4),
            'السعر المعروض تغيّر قبل الاعتماد');

        try {
            $prices->approve($v, $employee);
            $this->fail('المقترح اعتمد اقتراحه — وفصلُ اليدين هو كلُّ الفائدة');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('لا يعتمد', $e->getMessage());
        }

        $prices->approve($v->fresh(), $this->merchant);

        $this->assertSame(0,
            bccomp((string) $this->product->fresh()->price_per_liter, '1200', 4),
            'السعر لم يسرِ بعد الاعتماد');
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلة ٧ — نقد الوردية
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **المصروفُ لا يظهر عجزاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * باع الكاشيرُ بعشرة آلاف نقداً واشترى ماءً للمحطّة بألفين. الدرجُ فيه
     * ثمانية آلاف — **وهو مضبوط**.
     *
     * وقبل هذه المرحلة كان المتوقَّع عشرةَ آلافٍ فيظهر **عجزُ ألفين** في
     * وجه موظّفٍ لم يخطئ.
     */
    public function an_expense_does_not_look_like_a_shortage(): void
    {
        $shift = $this->openShift();
        $cash = app(FuelShiftCashService::class);

        $this->sell('10');   // ١٠ لتر × ١٠٠٠ = ١٠٬٠٠٠ نقداً

        $cash->record($shift, $this->merchant, 'out', 'expense', '2000', 'ماء للمحطة');

        $closed = $this->shifts->closeShift($shift->fresh(), $this->merchant, '8000');

        $this->assertSame(0, bccomp((string) $closed->expected_cash, '8000', 4), sprintf(
            "المتوقَّع %s والصواب ٨٬٠٠٠ (١٠٬٠٠٠ مبيعات − ٢٬٠٠٠ مصروف).\n"
            . 'وكلُّ ريالٍ للمصروفات كان يظهر عجزاً في وجه الكاشير.',
            $closed->expected_cash));

        $this->assertSame(0, bccomp((string) $closed->variance, '0', 4),
            "الفرق {$closed->variance} والدرجُ مطابق — وهذا عجزٌ وهميّ");
    }

    /**
     * @test
     *
     * **والمصروفُ خروجٌ دائماً — والخلطُ يقلب الإشارة.**
     */
    public function a_reason_cannot_carry_the_wrong_direction(): void
    {
        $shift = $this->openShift();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/يقلب إشارة/');

        app(FuelShiftCashService::class)
            ->record($shift, $this->merchant, 'in', 'expense', '2000');
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرحلتان ٤ و٥ — الصلاحيّات والأدوار
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الأدوارُ الستّةُ تُنشأ ويملكها التاجر.**
     */
    public function the_six_roles_are_created_for_the_merchant(): void
    {
        $perm = app(MerchantPermissionService::class);

        $perm->seedFuelRoles($this->merchant);

        $codes = MerchantRole::where('merchant_user_id', $this->merchant->id)
            ->pluck('code')->sort()->values()->all();

        $this->assertSame(
            ['accountant', 'cashier', 'manager', 'owner', 'stock_keeper', 'supervisor'],
            $codes,
            'الأدوار الستة غير مكتملة: ' . implode('، ', $codes));
    }

    /**
     * @test
     *
     * **والكاشيرُ لا يغيّر سعراً ولا يرحّل توريداً.**
     *
     * وهذا هو الفرقُ بين العَلَم والفعل: `F_FUEL_POS` كان يفتح الشاشةَ
     * كلَّها، وهنا يُسأل عن كلّ فعلٍ وحدَه.
     */
    public function a_cashier_cannot_approve_prices_or_post_deliveries(): void
    {
        $perm = app(MerchantPermissionService::class);
        $perm->seedFuelRoles($this->merchant);

        $cashierRole = MerchantRole::where('merchant_user_id', $this->merchant->id)
            ->where('code', 'cashier')->firstOrFail();

        // **الانتماءُ يُنشأ بإسناد الدور** — لا بعمودٍ في `users`.
        $cashier = User::factory()->create(['type' => 3]);
        $perm->assign($this->merchant, $cashier, $cashierRole);

        $this->assertTrue($perm->can($cashier, P::FUEL_SALE_CREATE),
            'الكاشير لا يستطيع البيع — وهذا كلُّ عمله');

        foreach ([
            P::FUEL_PRICE_APPROVE => 'اعتماد سعر',
            P::FUEL_DELIVERY_POST => 'ترحيل توريد',
            P::STAFF_MANAGE => 'إدارة الموظفين',
            P::SETTLEMENT_REQUEST => 'طلب تسوية',
            P::LEDGER_VIEW => 'عرض الدفتر',
        ] as $perm_code => $label) {
            $this->assertFalse($perm->can($cashier, $perm_code),
                "الكاشير يملك «{$label}» — وهذا تجاوزٌ لمبدأ أقلّ صلاحيّة");
        }
    }

    /**
     * @test
     *
     * **والحدُّ المالي يُطبَّق — لا يُعرض ويُتجاهل.**
     */
    public function an_amount_over_the_role_limit_is_refused(): void
    {
        $perm = app(MerchantPermissionService::class);
        $perm->seedFuelRoles($this->merchant);

        $role = MerchantRole::where('merchant_user_id', $this->merchant->id)
            ->where('code', 'manager')->firstOrFail();

        $manager = User::factory()->create(['type' => 3]);
        $perm->assign($this->merchant, $manager, $role);

        $under = $perm->evaluate($manager, P::FUEL_SALE_CANCEL, [], '500000');
        $over = $perm->evaluate($manager, P::FUEL_SALE_CANCEL, [], '5000000');

        $this->assertTrue($under['allowed'],
            'المدير مُنع من إلغاءٍ تحت حدّه: ' . ($under['reason'] ?? ''));

        $this->assertFalse($over['allowed'],
            'المدير ألغى فوق حدّه — والحدُّ معروضٌ ولا يُطبَّق');

        $this->assertStringContainsString('يتجاوز حدّك', (string) $over['reason']);
    }

    /**
     * @test
     *
     * **وصاحبُ الحساب يملك كلَّ شيءٍ بلا صفِّ دور.**
     */
    public function the_owner_needs_no_role_row(): void
    {
        $perm = app(MerchantPermissionService::class);

        foreach ([P::FUEL_PRICE_APPROVE, P::SETTLEMENT_REQUEST, P::ROLE_MANAGE] as $p) {
            $this->assertTrue($perm->can($this->merchant, $p),
                "المالك لا يملك «{$p}» — وهو أعلى مستخدمٍ في المنشأة");
        }
    }

    /**
     * @test
     *
     * **وصلاحيّةٌ مجهولةٌ تُرفض ولا تُتجاهَل.**
     *
     * خطأُ إملاءٍ في نداءٍ يجب أن يسقط، **لا أن يفتح البابَ صامتاً**.
     */
    public function an_unknown_permission_throws_rather_than_passing(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/غير معروفة/');

        app(MerchantPermissionService::class)
            ->evaluate($this->merchant, 'fuel.sale.cancle');   // إملاءٌ خاطئ عمداً
    }
}
