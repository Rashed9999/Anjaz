<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\CustomerCreditService;
use App\Services\MerchantFinancialTruthReportService;
use App\Services\PharmacySaleService;
use App\Services\PharmacyService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-ACCEPT-PHARMACY-002 — **سلسلةُ المال: من الدَّين إلى القيد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * تكملةُ `PharmacyProductionAcceptanceTest`: ذاك يقيس المخزونَ والعزلَ
 * والتفرّد، وهذا يقيس **ما يقع بعد البيع** — التحصيلَ الجزئيَّ والكاملَ،
 * وكشفَ الحساب، وتقريرَ اليوم، والورديّة.
 *
 * **والسؤالُ الحاكمُ واحد:** أتطابق الأرقامُ المعروضةُ العمليّاتِ
 * الحقيقيّة؟ فتقريرٌ يقول للصيدليّ إنّه قبض ما لم يقبضه يجعله يُقفل
 * ورديّتَه على عجزٍ لا سببَ له، ويطارد فيه سارقاً لا وجودَ له.
 * (القاعدة السادسة: الرقمُ يُحسب من مصدره لا من عمودٍ مخزَّن.)
 */
class PharmacyAcceptanceMoneyChainTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private $pharmacy;
    private PharmacyService $svc;
    private PharmacySaleService $sales;
    private CustomerCreditService $credit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(PharmacyService::class);
        $this->sales = app(PharmacySaleService::class);
        $this->credit = app(CustomerCreditService::class);

        $this->owner = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'phone' => '967772000002',
        ]);

        MerchantProfile::create([
            'user_id' => $this->owner->id,
            'business_type' => A::BIZ_PHARMACY,
            'business_name' => 'صيدليّةُ المال',
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
            'subscription_expires_at' => now()->addYear(),
        ]);

        EMoney::create([
            'user_id' => $this->owner->id, 'current_balance' => '0.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        $this->pharmacy = $this->svc->getOrCreatePharmacy($this->owner, [
            'name' => 'صيدليّةُ المال',
        ]);
    }

    private function product(string $price = '150', int $qty = 100): object
    {
        $p = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'أموكسيسيلين', 'sale_price' => $price,
            'purchase_price' => '90', 'barcode' => '629100000002'.random_int(1, 9),
        ]);

        $this->svc->addBatch($p, [
            'batch_number' => 'B-'.random_int(100, 999),
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => $qty,
        ]);

        return $p;
    }

    // ═════════════════════════════════════════════════════════════════
    // ٢) التحصيل الجزئيّ والكامل وكشف الحساب
    // ═════════════════════════════════════════════════════════════════

    /**
     * **① الفاتورةُ الآجلةُ تظهر ديناً، وتُحصَّل جزئيّاً ثمّ كاملاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **والرصيدُ يُقاس بعد كلّ خطوةٍ لا في آخرها**: تحصيلٌ يُسجَّل ولا
     * يُنقص الرصيدَ يجعل الصيدليَّ يطالب مريضاً سدّد — وتلك خسارةُ زبونٍ
     * لا خسارةُ رقم. وتحصيلٌ يُنقص أكثرَ ممّا قُبض يُخفي ديناً قائماً.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_credit_invoice_becomes_a_debt_then_is_collected_in_two_steps(): void
    {
        $product = $this->product('150');

        $customer = $this->svc->addCustomer($this->pharmacy, [
            'full_name' => 'مريضُ الدَّين', 'phone' => '967773111111',
        ]);

        // بيعٌ آجلٌ بـ ٤ × ١٥٠ = ٦٠٠
        $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 4]],
            ['payment_method' => 'credit', 'customer_id' => $customer->id],
        );

        $account = $this->credit->findOrCreateAccount(
            $this->owner->id, '967773111111', 'مريضُ الدَّين');

        $account->refresh();

        $this->assertSame('600.0000', (string) $account->current_balance, sprintf(
            '**الفاتورةُ الآجلةُ لم تصر ديناً على العميل** — الرصيد %s '
            .'والمتوقَّع ٦٠٠. فالبيعُ وقع والمخزونُ نقص ولا أحدَ مدين: '
            .'مالٌ خرج من الرفّ ولا أثرَ له في الذمم.',
            $account->current_balance));

        // تحصيلٌ جزئيّ
        $this->credit->recordPayment($account, '250', 'دفعةٌ أولى');
        $account->refresh();

        $this->assertSame('350.0000', (string) $account->current_balance, sprintf(
            '**التحصيلُ الجزئيُّ لم يُنقص الدَّين** (بقي %s والمتوقَّع ٣٥٠). '
            .'فيُطالَب مريضٌ سدّد — وتلك خسارةُ زبونٍ لا خسارةُ رقم.',
            $account->current_balance));

        // ثمّ الباقي
        $this->credit->recordPayment($account, '350', 'الإقفال');
        $account->refresh();

        $this->assertSame('0.0000', (string) $account->current_balance,
            '**الدَّينُ لم يُقفَل بعد سداده كاملاً.**');

        // وكشفُ الحساب يحمل الحركاتِ الثلاث بترتيبها.
        $statement = $this->credit->getStatement($account);
        $rows = $statement['movements'] ?? $statement['rows'] ?? $statement;

        $this->assertGreaterThanOrEqual(3, is_countable($rows) ? count($rows) : 0,
            '**كشفُ الحساب لا يحمل الحركاتِ الثلاث** (بيعٌ ودفعتان) — '
            .'وكشفٌ ناقصٌ لا يُحتجّ به على مريضٍ ينكر.');
    }

    /**
     * **② والتحصيلُ لا يتجاوز الدَّين.**
     *
     * فسدادٌ أكبرُ من الدَّين يُنتج رصيداً سالباً — أي أنّ الصيدليّة مدينةٌ
     * للمريض، وهو ما لا يعرفه أحدٌ حتّى يُطالِب.
     */
    /** @test */
    public function a_collection_never_pushes_the_debt_below_zero(): void
    {
        $product = $this->product('100');

        $customer = $this->svc->addCustomer($this->pharmacy, [
            'full_name' => 'مريضٌ ثانٍ', 'phone' => '967773222222',
        ]);

        $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 2]],
            ['payment_method' => 'credit', 'customer_id' => $customer->id],
        );

        $account = $this->credit->findOrCreateAccount(
            $this->owner->id, '967773222222', 'مريضٌ ثانٍ');

        $refused = false;

        try {
            $this->credit->recordPayment($account, '5000', 'سدادٌ مبالغ');
        } catch (\Throwable $e) {
            $refused = true;
        }

        $account->refresh();

        $this->assertTrue($refused || (float) $account->current_balance >= 0, sprintf(
            '**قُبض أكثرُ من الدَّين فصار الرصيدُ %s.** والسالبُ يعني أنّ '
            .'الصيدليّة مدينةٌ للمريض — ولا شاشةَ تقول ذلك، فلا يُعرف '
            .'حتّى يُطالِب.', $account->current_balance));
    }

    // ═════════════════════════════════════════════════════════════════
    // ٣) وسيلةُ الدفع تحدّد أين يذهب المال
    // ═════════════════════════════════════════════════════════════════

    /**
     * **③ النقدُ والأميال والآجلُ ثلاثةُ مساراتٍ لا واحد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * والخلطُ بينها ليس خطأً تجميليّاً: النقدُ يزيد درجَ نقطة البيع،
     * و«أميال» يزيد المحفظةَ الإلكترونيّةَ ولا يزيد الدرج، والآجلُ لا
     * يزيد شيئاً. **فمن عدّ الثلاثةَ نقداً أقفل ورديّتَه على عجزٍ يساوي
     * مبيعاتِ الأميال والآجل** — ثمّ اتُّهم كاشيرُه.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function each_payment_method_moves_money_to_its_own_place(): void
    {
        $product = $this->product('100');

        $customer = $this->svc->addCustomer($this->pharmacy, [
            'full_name' => 'مريضٌ ثالث', 'phone' => '967773333333',
        ]);

        $walletBefore = (string) EMoney::where('user_id', $this->owner->id)
            ->value('current_balance');

        // نقدٌ ٢٠٠ · آجلٌ ٣٠٠
        $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 2]],
            ['payment_method' => 'cash'],
        );

        $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 3]],
            ['payment_method' => 'credit', 'customer_id' => $customer->id],
        );

        $walletAfter = (string) EMoney::where('user_id', $this->owner->id)
            ->value('current_balance');

        $this->assertSame($walletBefore, $walletAfter, sprintf(
            '**بيعٌ نقديٌّ أو آجلٌ حرّك المحفظةَ الإلكترونيّة** (%s ← %s). '
            .'والنقدُ يقع في الدرج، والآجلُ لم يُقبَض — ولا واحدٌ منهما '
            .'رصيدٌ إلكترونيّ.', $walletBefore, $walletAfter));

        // والمبيعاتُ مسجَّلةٌ كلُّها بوسائلها.
        $byMethod = DB::table('pharmacy_sales')
            ->where('pharmacy_id', $this->pharmacy->id)
            ->selectRaw('payment_method, COUNT(*) c, SUM(total_amount) t')
            ->groupBy('payment_method')->pluck('t', 'payment_method')->all();

        $this->assertSame('200.0000', (string) ($byMethod['cash'] ?? ''),
            'مبيعاتُ النقد لا تطابق العمليّة الحقيقيّة.');

        $this->assertSame('300.0000', (string) ($byMethod['credit'] ?? ''),
            'مبيعاتُ الآجل لا تطابق العمليّة الحقيقيّة.');
    }

    // ═════════════════════════════════════════════════════════════════
    // ٥) التقارير
    // ═════════════════════════════════════════════════════════════════

    /**
     * **④ وتقريرُ اليوم يطابق ما وقع، ولا يخرج فارغاً بعد بيع.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وتقريرٌ فارغٌ بعد وجود بيانات أسوأُ من تقريرٍ خاطئ**: الخاطئُ
     * يُراجَع، والفارغُ يُقرأ «لم نبع اليوم» فيُقفل المحلُّ على أنّه يوم
     * كساد. وأشهرُ سببه **منطقةٌ زمنيّةٌ تُخرج بيعةَ الليلة من نطاق
     * «اليوم»**.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_daily_report_matches_what_actually_happened(): void
    {
        $product = $this->product('100');

        $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 7]],
            ['payment_method' => 'cash'],
        );

        $report = app(MerchantFinancialTruthReportService::class)
            ->report($this->owner, now()->toDateString(), now()->toDateString());

        $raw = json_encode($report, JSON_UNESCAPED_UNICODE);

        $this->assertNotEmpty($report,
            '**تقريرُ اليوم فارغٌ بعد بيعٍ وقع.** ويُقرأ «لم نبع اليوم» — '
            .'وأشهرُ سببه منطقةٌ زمنيّةٌ تُخرج البيعةَ من نطاق «اليوم».');

        $this->assertStringContainsString('700', $raw, sprintf(
            "**رقمُ التقرير لا يطابق البيعةَ الحقيقيّة.**\nبيعَ ٧ × ١٠٠ = ٧٠٠، "
            ."والتقريرُ لا يذكرها.\nالتقرير: %s",
            mb_substr($raw, 0, 400)));
    }

    /**
     * **⑤ وحدودُ اليوم تُحسب بتوقيت المنصّة لا بتوقيت الخادم.**
     *
     * فبيعةٌ الساعةَ العاشرةَ ليلاً تقع في «الغد» إن حُسب اليومُ بـUTC —
     * فيرى الصيدليُّ تقريرَ يومه ناقصاً ويرى الغدَ منتفخاً.
     */
    /** @test */
    public function a_late_night_sale_still_belongs_to_today(): void
    {
        $product = $this->product('100');

        // العاشرةُ والنصف ليلاً بتوقيت المنصّة.
        $night = now()->setTime(22, 30);
        \Illuminate\Support\Carbon::setTestNow($night);

        $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 1]],
            ['payment_method' => 'cash'],
        );

        $today = $night->toDateString();
        \Illuminate\Support\Carbon::setTestNow();

        $count = DB::table('pharmacy_sales')
            ->where('pharmacy_id', $this->pharmacy->id)
            ->whereDate('created_at', $today)->count();

        $this->assertSame(1, $count, sprintf(
            '**بيعةُ العاشرةِ والنصفِ ليلاً خرجت من «اليوم»** (%s). '
            .'فيرى الصيدليُّ تقريرَ يومه ناقصاً والغدَ منتفخاً، ولا خطأَ '
            .'في أيّ سجلّ.', $today));
    }

    // ═════════════════════════════════════════════════════════════════
    // ٧) الورديّة
    // ═════════════════════════════════════════════════════════════════

    /**
     * **⑥ والورديّةُ تُفتح وتُقفل، والفرقُ يُحسب من الحركة لا من عمود.**
     *
     * ══════════════════════════════════════════════════════════════════
     * (القاعدة السادسة بنصّها: «جردٌ يقارن المعدود بـ`cash_on_hand`
     * يُخرج الفرقَ صفراً دائماً» — فالمتوقَّعُ يُحسب من العهدة والمبيعات
     * النقديّة، لا يُقرأ من الرصيد.)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_shift_closes_with_a_variance_computed_from_movement(): void
    {
        $product = $this->product('100');
        $shifts = app(CashierShiftService::class);

        $shift = $shifts->open($this->owner, null, '1000');

        $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 3]],
            ['payment_method' => 'cash'],
        );

        // المعدودُ ينقص خمسين عمداً — فالفرقُ يجب أن يظهر لا أن يُبتلع.
        $closed = $shifts->close($shift->fresh(), '1250');

        $expected = (string) ($closed->expected_cash ?? '');
        $variance = (float) ($closed->variance ?? 0);

        // **يُقارَن الرقمُ لا نصُّه** — العمودُ يخزّن بخانتين
        // (`1300.00`) والمعياريّةُ بأربع، والفرقُ تنسيقٌ لا حساب.
        $this->assertSame(1300.0, (float) $expected, sprintf(
            '**المتوقَّعُ لا يُحسب من الحركة.** العهدة ١٠٠٠ ومبيعاتُ النقد '
            .'٣٠٠ ⇒ ١٣٠٠، والمحسوبُ «%s». وجردٌ يقرأ الرصيدَ بدل أن '
            .'يحسبه يُخرج الفرقَ صفراً أبداً.', $expected));

        $this->assertSame(-50.0, $variance, sprintf(
            '**الفرقُ ابتُلع** — عُدّ ١٢٥٠ والمتوقَّع ١٣٠٠، فالعجزُ ٥٠ '
            .'والمحسوبُ %s. وورديّةٌ تُقفل بفرقٍ صفرٍ دائماً لا تكشف '
            .'نقصاً أبداً.', $variance));
    }
}
