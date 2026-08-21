<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Services\AgentBranchService;
use App\Services\AgentCounterService;
use App\Services\AgentReportService;
use App\Services\AgentTillService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-AGENT-PORTAL-002 — عمولات الوكيل وتقاريره (الفصل ٠٣).
 *
 * ══════════════════════════════════════════════════════════════
 * **نقصٌ كان في أوّل صياغةٍ لشبّاك الفرع: لا عمولة إطلاقاً.**
 *
 * أي أنّ شركة الصرافة تُنفّذ الإيداعات والسحوبات **مجّاناً** — بلا نموذج
 * عملٍ يجعلها توقّع. وكان سيُكتشف عند أوّل شركةٍ تسأل: «وماذا نكسب؟».
 * ══════════════════════════════════════════════════════════════
 *
 * **وأين يقع الرسم من العملية ليس تفصيلاً:**
 *
 *   • **الإيداع**: العميل يسلّم ١٠٠٠ ورقاً ويستلم ٩٩٥ رصيداً. الرسم يُدفع
 *     نقداً، فيرى العميل الفرق ويفهمه.
 *   • **السحب**: العميل يطلب ١٠٠٠ فيستلم ١٠٠٠ **ورقاً بالضبط**، ويُخصم
 *     من رصيده ١٠٠٥. ولو نقصت الأوراق لعدّها وظنّ الموظّف أخطأ أو سرق.
 */
class AgentCommissionAndReportTest extends TestCase
{
    /**
     * جلسةُ بوّابةٍ لحساب وكيل.
     *
     * البوّابة صار لها حارسها الخاصّ (`agent_staff`) ولا تقبل حارس `user` —
     * لأنّ ذاك حارس لوحة الإدارة، وجلسةٌ عليه كانت تُعطّلها بحلقة إعادة
     * توجيه. فيُفتح لصاحب الشركة حسابُ «إدارةٍ عامّة» كما يقع في الإنتاج.
     */
    private function portalAs(\App\Models\User $agent): self
    {
        $staff = app(\App\Services\AgentStaffService::class)->ensureHeadOfficeAccount($agent);

        return $this->actingAs($staff, 'agent_staff');
    }

    use RefreshDatabase;

    private User $agent;
    private User $customer;
    private AgentBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->create([
            'type' => AGENT_TYPE, 'phone' => '770004401', 'f_name' => 'صرافة',
            'zone_code' => 'SOUTH', 'is_kyc_verified' => 1,
            'password' => Hash::make('agent-pass-123'),
        ]);
        EMoney::updateOrCreate(['user_id' => $this->agent->id],
            ['current_balance' => '5000000', 'zone_code' => 'SOUTH']);
        app(\App\Services\LedgerService::class)->reconcileWalletBalance(
            $this->agent->id,
            'رصيد افتتاحيّ للاختبار',
            $this->approvedControlFor($this->agent),
        );

        $this->customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '770004410',
            'zone_code' => 'SOUTH', 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $this->customer->id],
            ['current_balance' => '100000', 'zone_code' => 'SOUTH']);

        $this->branch = app(AgentBranchService::class)->create($this->agent, [
            'name' => 'فرع المكلا', 'code' => 'MKL-01',
            'phone' => '770004402', 'password' => 'branch-pass-123',
        ]);

        app(AgentBranchService::class)->fundBranch(
            $this->branch, $this->agent, '1000000', 'رصيد تشغيل الفرع');

        app(AgentTillService::class)->record(
            $this->branch, 'in', 'opening', '500000', $this->agent);
    }

    private function approvedControlFor(User $walletOwner): array
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $case = ReconciliationCase::create([
            'case_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'case_type' => 'wallet', 'source' => 'test',
            'subject_user_id' => $walletOwner->id,
            'expected_amount' => '0', 'actual_amount' => '0', 'difference' => '0',
            'currency' => 'YER', 'status' => 'pending_approval', 'severity' => 'warning',
            'first_detected_at' => now(), 'last_detected_at' => now(), 'detection_count' => 1,
            'maker_admin_id' => $maker->id, 'checker_admin_id' => $checker->id,
        ]);

        return ['case_ulid' => $case->case_ulid, 'maker_admin_id' => $maker->id,
            'checker_admin_id' => $checker->id,
            'approval_note' => 'اختبار فتح رصيد مع مراجعة مستقلة.'];
    }

    /** رسمٌ ١٪ يُقسم: ٦٠٪ للوكيل و٤٠٪ للمنصّة. */
    private function seedFee(string $code): void
    {
        FeeScheme::create([
            'code' => $code,
            'label' => 'رسم ' . $code,
            'fee_type' => 'percent',
            'percent_rate' => '1.00',
            'fixed_amount' => '0',
            'agent_commission_percent' => '60',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'zone_code' => 'SOUTH',
            'applies_to' => 'agent',
            'version' => 1,
            'is_active' => true,
        ]);
    }

    // ══ الرسم والعمولة ═════════════════════════════════════════════════

    /** @test */
    public function without_a_fee_scheme_the_service_is_free_not_broken(): void
    {
        // بلا نسخةٍ نشطة يعيد المحرّك صفراً. والخدمة مجانيّة حتى تُضبط
        // الرسوم من اللوحة — لا معطَّلة.
        $out = app(AgentCounterService::class)
            ->deposit($this->branch, $this->customer, '10000', $this->agent);

        $this->assertSame(0, bccomp($out['fee'], '0', 4));
        $this->assertSame(0, bccomp($out['net'], '10000', 4));
    }

    /** @test */
    public function on_a_deposit_the_customer_hands_cash_and_receives_net(): void
    {
        // الرسم يُدفع نقداً: من يودع ١٠٠٠ ويرى ٩٩٥ يفهم أنّه دفع ٥.
        $this->seedFee('agent_deposit');

        $out = app(AgentCounterService::class)
            ->deposit($this->branch, $this->customer, '10000', $this->agent);

        $this->assertSame(0, bccomp($out['fee'], '100', 4));
        $this->assertSame(0, bccomp($out['net'], '9900', 4));

        // رصيد العميل زاد بالصافي.
        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $this->customer->id)->value('current_balance'),
            '109900', 4));

        // **والنقد الداخل هو الإجماليّ**: العميل سلّم ١٠٠٠٠ ورقاً.
        // وتسجيلُ الصافي يجعل الجرد ينقص بمقدار الرسوم كلّ يوم.
        $this->assertSame(0, bccomp(
            (string) app(AgentTillService::class)->tillFor($this->branch)->cash_on_hand,
            '510000', 4), 'سُجّل الصافي في الدرج بدل ما سلّمه العميل فعلاً');
    }

    /** @test */
    public function on_a_withdrawal_the_customer_receives_exactly_what_was_asked_in_cash(): void
    {
        // من يطلب ١٠٠٠ ويُعطى ٩٩٥ يعدّ الأوراق ويظنّ الموظّف أخطأ أو سرق.
        // فالرسم يُخصم من الرصيد حيث يُقرأ، لا من الأوراق حيث تُعدّ.
        $this->seedFee('agent_withdraw');

        $out = app(AgentCounterService::class)
            ->withdraw($this->branch, $this->customer, '10000', $this->agent);

        $this->assertSame(0, bccomp($out['fee'], '100', 4));
        $this->assertSame(0, bccomp($out['total_debited'], '10100', 4));

        // خرج من الدرج ما طلبه العميل بالضبط — لا أكثر ولا أقلّ.
        $this->assertSame(0, bccomp(
            (string) app(AgentTillService::class)->tillFor($this->branch)->cash_on_hand,
            '490000', 4), 'خرج من الدرج غير ما طلبه العميل');

        // وخُصم من رصيده المبلغ والرسم.
        $this->assertSame(0, bccomp($out['customer_balance'], '89900', 4));
    }

    /** @test */
    public function the_platform_share_leaves_the_branch_and_the_commission_stays(): void
    {
        // العمولة تبقى عند الفرع بلا قيدٍ إضافيّ: هي الفرق بين ما قبضه نقداً
        // وما منحه رصيداً. وإخراجُها ثمّ إعادتُها قيدان لا يغيّران شيئاً.
        $this->seedFee('agent_deposit');

        $before = (string) EMoney::where('user_id', $this->branch->branch_user_id)
            ->value('current_balance');

        app(AgentCounterService::class)
            ->deposit($this->branch, $this->customer, '10000', $this->agent);

        $after = (string) EMoney::where('user_id', $this->branch->branch_user_id)
            ->value('current_balance');

        // نقص الصافي (٩٩٠٠) + حصّة المنصّة (٤٠) = ٩٩٤٠
        $this->assertSame(0, bccomp(bcsub($before, $after, 4), '9940', 4),
            'حصّة المنصّة لم تخرج من الفرع أو خرجت العمولة معها');

        $this->assertSame(1,
            LedgerJournalEntry::where('source_type', 'agent_deposit_fee')->count(),
            'حصّة المنصّة انتقلت بلا قيد');
    }

    /**
     * @test
     *
     * الحصّتان تساويان الرسم بالضبط — مهما ضُبطت النسب.
     *
     * **وهذا فحصُ ثابتٍ لا فحصُ ضبط.** كتبتُ أوّلاً اختباراً يُضبط فيه ٨٠٪
     * للوكيل و٨٠٪ للمنصّة ليُرفض — ثمّ قرأتُ المحرّك فوجدتُه يحسب حصّة
     * المنصّة **طرحاً** (`fee − agent`) ويقصّ حصّة الوكيل عند الرسم. أي أنّ
     * الحالة التي كنتُ أختبرها **مستحيلة بالتصميم**.
     *
     * فالاختبار الصادق هو تثبيت الثابت نفسه: مهما بولغ في النسبة، لا
     * تتجاوز الحصّتان الرسم ولا تنقصان عنه. ولولا ذلك لدفعت المنصّة من
     * جيبها بلا أن ينتبه أحد — والدفتر يبقى متوازناً لأنّ كلّ قيدٍ متوازنٌ
     * وحده.
     */
    public function the_two_shares_always_sum_to_exactly_the_fee(): void
    {
        FeeScheme::create([
            'code' => 'agent_deposit', 'label' => 'نسبة مبالَغ فيها',
            'fee_type' => 'percent', 'percent_rate' => '1.00', 'fixed_amount' => '0',
            'agent_commission_percent' => '200', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'zone_code' => 'SOUTH', 'applies_to' => 'agent',
            'version' => 1, 'is_active' => true,
        ]);

        $out = app(AgentCounterService::class)
            ->deposit($this->branch, $this->customer, '10000', $this->agent);

        // العمولة قُصّت عند الرسم، وحصّة المنصّة صفر — والمجموع الرسم بالضبط.
        $this->assertSame(0, bccomp($out['fee'], '100', 4));
        $this->assertSame(0, bccomp($out['commission'], '100', 4),
            'تجاوزت العمولة الرسم — تُدفع من جيب المنصّة');

        $c = app(AgentReportService::class)->commissions($this->branch);
        $this->assertSame(0, bccomp($c['platform_share'], '0', 4));
    }

    // ══ العمولات ═══════════════════════════════════════════════════════

    /** @test */
    public function commissions_are_derived_from_the_ledger_not_a_running_counter(): void
    {
        // عدّادٌ تراكميّ يُزاد عند كلّ عملية ينحرف: عمليةٌ تفشل بعد زيادته،
        // أو تُعاد محاولتها فيُزاد مرّتين — فيطالب الوكيل بما لم يكسبه.
        // والقيود تُلحَق ولا تُعدَّل.
        $this->seedFee('agent_deposit');

        app(AgentCounterService::class)->deposit($this->branch, $this->customer, '10000', $this->agent);
        app(AgentCounterService::class)->deposit($this->branch, $this->customer, '20000', $this->agent);

        $c = app(AgentReportService::class)->commissions($this->branch);

        // ٦٠٪ من ١٪ من ٣٠٠٠٠ = ١٨٠
        $this->assertSame(0, bccomp($c['total_commission'], '180', 4));
        $this->assertSame(0, bccomp($c['total_fee'], '300', 4));
        $this->assertSame(0, bccomp($c['platform_share'], '120', 4),
            'حصّة المنصّة لا تُعرَض — فيظنّ الوكيل الرسم كلّه له');
        $this->assertSame(2, $c['operations']);
    }

    /** @test */
    public function one_branch_never_sees_another_branchs_commissions(): void
    {
        $this->seedFee('agent_deposit');

        $other = app(AgentBranchService::class)->create($this->agent, [
            'name' => 'فرع ثانٍ', 'code' => 'MKL-02',
            'phone' => '770004403', 'password' => 'branch-pass-456',
        ]);

        app(AgentCounterService::class)->deposit($this->branch, $this->customer, '10000', $this->agent);

        $this->assertSame(0, bccomp(
            app(AgentReportService::class)->commissions($other)['total_commission'], '0', 4),
            'تسرّبت عمولة فرعٍ إلى تقرير فرعٍ آخر');
    }

    // ══ تقرير اليوم ════════════════════════════════════════════════════

    /** @test */
    public function the_daily_report_reconciles_movement_against_the_recorded_balance(): void
    {
        // الرقم المهمّ ليس كم أُودع وكم سُحب، بل **هل يطابق ما في الدرج ما
        // يقوله النظام**. فالفرق هو الخبر، والباقي سياق.
        app(AgentCounterService::class)->deposit($this->branch, $this->customer, '10000', $this->agent);
        app(AgentCounterService::class)->withdraw($this->branch, $this->customer, '4000', $this->agent);

        $r = app(AgentReportService::class)->dailyReport($this->branch);

        $this->assertTrue($r['cash']['reconciles'], 'التقرير لا يطابق وقد سارت الحركة سليمة');
        $this->assertSame(0, bccomp($r['cash']['deposits'], '10000', 4));
        $this->assertSame(0, bccomp($r['cash']['withdrawals'], '4000', 4));
        $this->assertSame(0, bccomp($r['cash']['closing'], '506000', 4));
        $this->assertSame(1, $r['counts']['deposits']);
    }

    /** @test */
    public function the_expected_balance_is_computed_not_read_from_the_till(): void
    {
        // قراءتُه من الخزنة تجعل المطابقة تقارن الرقم بنفسه — فتمرّ دائماً.
        app(AgentCounterService::class)->deposit($this->branch, $this->customer, '10000', $this->agent);

        // عبثٌ بالخزنة وحدها بلا حركة.
        DB::table('agent_cash_tills')->where('branch_id', $this->branch->id)
            ->update(['cash_on_hand' => '999999']);

        $r = app(AgentReportService::class)->dailyReport($this->branch);

        // المتوقَّع محسوبٌ من الحركة، لكن المسجَّل يُقرأ من الخزنة الآن؛
        // لذلك يظهر العبث بدلاً من أن يطابق التقريرُ صفّ الحركة مع نفسه.
        $this->assertSame(0, bccomp($r['cash']['expected'], '510000', 4),
            'المتوقَّع قُرئ من الخزنة فتأثّر بالعبث');
        $this->assertSame(0, bccomp($r['cash']['closing'], '999999', 4));
        $this->assertFalse($r['cash']['reconciles']);
        $this->assertSame(0, bccomp($r['cash']['difference'], '-489999', 4));
    }

    /** @test */
    public function a_branch_that_was_not_counted_today_is_flagged(): void
    {
        // فرعٌ ينتهي يومه بلا جردٍ ينتهي بلا تأكيدٍ من إنسان.
        $r = app(AgentReportService::class)->dailyReport($this->branch);
        $this->assertFalse($r['counted_today']);

        app(AgentTillService::class)->count($this->branch, $this->agent, '500000',
            'جرد نهاية اليوم بحضور مدير الفرع');

        $this->assertTrue(app(AgentReportService::class)->dailyReport($this->branch)['counted_today']);
    }

    // ══ ساعات العمل ════════════════════════════════════════════════════

    /** @test */
    public function opening_after_closing_is_refused(): void
    {
        // يمرّ في الحفظ ويُغلق الفرع طول اليوم في التنفيذ.
        $this->expectException(DomainException::class);

        app(AgentReportService::class)->setWorkingHours($this->branch, [
            'sun' => ['open' => '18:00', 'close' => '09:00'],
        ]);
    }

    /** @test */
    public function a_day_with_no_hours_is_stored_as_closed_not_dropped(): void
    {
        // يومٌ محذوف من البيانات يُقرأ لاحقاً «غير معرَّف» فيُفتح أو يُغلق
        // بالتخمين. و`null` صريحةٌ تقول: مغلق.
        $b = app(AgentReportService::class)->setWorkingHours($this->branch, [
            'sun' => ['open' => '09:00', 'close' => '17:00'],
        ]);

        $this->assertSame(['open' => '09:00', 'close' => '17:00'], $b->working_hours['sun']);
        $this->assertNull($b->working_hours['fri'], 'يومٌ بلا ساعات سقط بدل أن يُسجَّل مغلقاً');
        $this->assertArrayHasKey('sat', $b->working_hours);
    }

    // ══ الوصول ═════════════════════════════════════════════════════════

    /** @test */
    public function the_new_tabs_answer_through_the_portal(): void
    {
        foreach ([
            "/agent/branches/{$this->branch->id}/commissions",
            "/agent/branches/{$this->branch->id}/report",
            '/agent/settlements',
        ] as $url) {
            $this->portalAs($this->agent)->getJson($url)
                ->assertOk()->assertJsonPath('success', true);
        }
    }

    /** @test */
    public function a_rival_agent_cannot_read_the_commissions(): void
    {
        $rival = User::factory()->create([
            'type' => AGENT_TYPE, 'phone' => '770004450',
            'zone_code' => 'SOUTH', 'is_kyc_verified' => 1,
        ]);

        $this->portalAs($rival)
            ->getJson("/agent/branches/{$this->branch->id}/commissions")
            ->assertStatus(403);
    }
}
