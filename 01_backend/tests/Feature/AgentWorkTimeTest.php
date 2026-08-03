<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\Agent\AgentTellerRequest;
use App\Models\Agent\AgentWorkEvent;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use App\Services\AgentTellerRequestService;
use App\Services\AgentWorkTimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-WORKTIME-001 — ساعاتُ الدوام: الحساب ومنعُ التلاعب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **«غير قابل للتلاعب» ثلاثةُ تهديداتٍ لا واحد — ولكلٍّ اختبارُه:**
 *
 * ١. **بالإدخال:** وقتٌ يأتي من المتصفّح.
 * ٢. **بالسلوك:** ورديّةٌ تُفتح ولا تُغلق، أو استراحةٌ تُستعمل ستراً.
 * ٣. **بالبيانات:** صفٌّ يُعدَّل في القاعدة بعد كتابته.
 *
 * وحارسٌ على واحدٍ منها وحده ليس حارساً: من يجد باباً مغلقاً يدخل من
 * الآخر.
 */
class AgentWorkTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private AgentStaff $teller;
    private AgentStaff $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = new User();
        $this->company->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967775100001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '750000']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');

        $bu = new User();
        $bu->forceFill([
            'f_name' => 'فرع المكلا', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967775100099', 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '5000000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => 'فرع المكلا', 'status' => 'active', 'zone_code' => 'SOUTH',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->branch = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => 'فرع المكلا', 'code' => 'MKL', 'city' => 'حضرموت',
            'phone' => $bu->phone, 'is_active' => true,
        ]);
        $this->branch->till()->create([
            'cash_on_hand' => '3000000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '100000',
        ]);

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'محمد علي', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
        ]);
        $this->teller->forceFill(['daily_hours_expected' => '8.00'])->save();
        $this->teller = $this->teller->fresh();

        $this->manager = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير المكلا', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->branch->id, 'password' => 'manager1',
        ]);
    }

    private function wt(): AgentWorkTimeService
    {
        return app(AgentWorkTimeService::class);
    }

    /** ورديّةٌ كاملة بساعاتٍ محدَّدة — الوقت يتقدّم، والأختام من الخادم. */
    private function workShift(string $openAt, string $closeAt): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse($openAt));
        $shift = app(AgentShiftService::class)->open($this->teller, '0');

        $this->travelTo(\Illuminate\Support\Carbon::parse($closeAt));
        app(AgentShiftService::class)->close($this->teller, $shift, '0');
    }

    // ══════════════════════════════════════════════════════════════════
    // الحساب: ساعاتٌ ودقائق
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function hours_and_minutes_are_read_as_hours_and_minutes(): void
    {
        $wt = $this->wt();

        // **الدقائق تُقرأ صعبةً والساعات العشريّة تُقرأ خطأً.** «٧٫٧٥س»
        // يقرأها كثيرون «سبعاً وخمساً وسبعين دقيقة».
        $this->assertSame('7س 45د', $wt->hm(465));
        $this->assertSame('8س', $wt->hm(480));
        $this->assertSame('45د', $wt->hm(45));
        $this->assertSame('0د', $wt->hm(0));
    }

    /** @test */
    public function a_plain_shift_counts_its_exact_minutes(): void
    {
        $this->workShift('2026-08-03 08:00:00', '2026-08-03 15:30:00');

        $s = $this->wt()->summary($this->teller, '2026-08-03', '2026-08-03');

        $this->assertSame(450, $s['totals']['worked_minutes']);
        $this->assertSame('7س 30د', $s['totals']['worked']);
        $this->assertSame(1, $s['totals']['days_worked']);
    }

    /** @test */
    public function daily_weekly_and_monthly_are_all_produced(): void
    {
        $this->workShift('2026-08-03 08:00:00', '2026-08-03 16:00:00');   // اثنين
        $this->workShift('2026-08-04 08:00:00', '2026-08-04 14:00:00');   // ثلاثاء

        $s = $this->wt()->summary($this->teller, '2026-08-01', '2026-08-31');

        $this->assertCount(2, $s['daily']);
        $this->assertCount(1, $s['weekly'], 'اليومان في أسبوعٍ واحد ولم يُجمّعا');
        $this->assertCount(1, $s['monthly']);
        $this->assertSame(840, $s['totals']['worked_minutes']);      // ٨س + ٦س
        $this->assertSame('14س', $s['totals']['worked']);
        $this->assertSame('14س', $s['monthly'][0]['worked']);
    }

    /** @test */
    public function a_break_is_deducted_from_the_worked_time(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 08:00:00'));
        $shift = app(AgentShiftService::class)->open($this->teller, '0');

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 12:00:00'));
        $this->wt()->record($this->teller, AgentWorkEvent::BREAK_START, (int) $shift->id);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 12:45:00'));
        $this->wt()->record($this->teller, AgentWorkEvent::BREAK_END, (int) $shift->id);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 16:00:00'));
        app(AgentShiftService::class)->close($this->teller, $shift, '0');

        $s = $this->wt()->summary($this->teller, '2026-08-03', '2026-08-03');

        $this->assertSame(480 - 45, $s['totals']['worked_minutes']);
        $this->assertSame('45د', $s['totals']['break']);
    }

    /**
     * @test
     *
     * **«ابدأ استراحةً ثمّ أغلق» لا يُلغي خصمها.** ولو أُلغي لصارت
     * الاستراحة مجّانيّةً لمن يتذكّر ألّا يُنهيها.
     */
    public function a_break_left_open_is_still_charged_until_the_close(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 08:00:00'));
        $shift = app(AgentShiftService::class)->open($this->teller, '0');

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 15:00:00'));
        $this->wt()->record($this->teller, AgentWorkEvent::BREAK_START, (int) $shift->id);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 16:00:00'));
        app(AgentShiftService::class)->close($this->teller, $shift, '0');

        $s = $this->wt()->summary($this->teller, '2026-08-03', '2026-08-03');

        $this->assertSame(60, (int) $s['sessions_list'][0]['break_minutes'],
            'استراحةٌ لم تُنهَ لم تُخصم — فصارت مجّانيّة لمن ينساها عمداً');
        $this->assertSame(420, $s['totals']['worked_minutes']);
    }

    // ══════════════════════════════════════════════════════════════════
    // ضدّ التلاعب بالسلوك
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **من يفتح ورديّته الفجر ويغلقها منتصف الليل لم يخالف قاعدة —
     * استغلّ غيابها.** فما تجاوز السقف يُفصل ولا يُبتلع.
     */
    public function time_beyond_the_shift_cap_is_separated_not_counted(): void
    {
        // عشرون ساعة: السقف ست عشرة.
        $this->workShift('2026-08-03 04:00:00', '2026-08-04 00:00:00');

        $s = $this->wt()->summary($this->teller, '2026-08-03', '2026-08-04');

        $this->assertSame(16 * 60, $s['totals']['worked_minutes'],
            'احتُسبت ساعاتٌ فوق السقف عملاً مؤكَّداً');
        $this->assertSame(4 * 60, $s['totals']['unverified_minutes'],
            'الفائض لم يُفصل — إمّا ابتُلع وإمّا حُذف، وكلاهما يُخفي ما وقع');
        // ولا يُحذف: يُقال إنّه وقعٌ لم يُتحقَّق منه.
        $this->assertSame('4س', $s['totals']['unverified']);
    }

    /**
     * @test
     *
     * **ورديّةٌ لم تُغلق أصلاً لا دقيقةَ مؤكَّدة فيها.**
     *
     * ولو حُسبت لصار أسهلُ طريقٍ لمضاعفة الساعات: افتح ولا تُغلق.
     */
    public function an_abandoned_shift_yields_no_confirmed_minutes(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 08:00:00'));
        $first = app(AgentShiftService::class)->open($this->teller, '0');

        // انصرف بلا إغلاق، ثمّ فتح ورديّةً جديدة في اليوم التالي.
        // (تُغلق الأولى قسراً في القاعدة كي يُسمح بفتح الثانية — وهو ما
        //  يفعله المدير فعلاً؛ والمهمّ هنا سجلّ الدوام لا سجلّ الدرج.)
        $first->forceFill(['status' => \App\Models\Agent\AgentShift::STATUS_CLOSED])->save();

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-04 08:00:00'));
        $second = app(AgentShiftService::class)->open($this->teller, '0');

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-04 14:00:00'));
        app(AgentShiftService::class)->close($this->teller, $second, '0');

        $s = $this->wt()->summary($this->teller, '2026-08-03', '2026-08-04');

        // ستّ ساعاتٍ من الورديّة الثانية وحدها.
        $this->assertSame(360, $s['totals']['worked_minutes'],
            'احتُسبت ورديّةٌ لم تُغلق — وهي أسهل طريقٍ لمضاعفة الساعات');

        $abandoned = collect($s['sessions_list'])->firstWhere('abandoned', true);
        $this->assertNotNull($abandoned, 'الورديّة المهجورة اختفت بدل أن تُعرَض');
    }

    /**
     * @test
     *
     * الخمول يُعرَض ولا يُخصَم — والقرار لإنسانٍ يعرف الفرع.
     */
    public function idle_time_is_reported_but_never_silently_deducted(): void
    {
        $this->workShift('2026-08-03 08:00:00', '2026-08-03 16:00:00');

        $s = $this->wt()->summary($this->teller, '2026-08-03', '2026-08-03');

        // ثماني ساعاتٍ كاملة رغم أنّه لم يُنفّذ عمليّةً واحدة.
        $this->assertSame(480, $s['totals']['worked_minutes'],
            'خُصم الخمول — وصرّافُ فرعٍ هادئ جالسٌ على شبّاكه يعمل');

        // ويُقال إنّها كانت بلا نشاط.
        $this->assertGreaterThan(0, (int) $s['sessions_list'][0]['idle_minutes'],
            'لم يُقَل إنّ الورديّة مرّت بلا نشاط — فمن فتح وانصرف كمن جلس');
    }

    /** @test */
    public function a_shift_closed_by_a_manager_is_marked_as_such(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 08:00:00'));
        $shift = app(AgentShiftService::class)->open($this->teller, '0');

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 20:00:00'));
        app(AgentShiftService::class)->close($this->manager, $shift, '0');

        $s = $this->wt()->summary($this->teller, '2026-08-03', '2026-08-03');

        $this->assertTrue($s['sessions_list'][0]['closed_by_manager'],
            'إغلاقُ المدير يبدو كإغلاق الموظّف — فيضيع أنّ الصرّاف انصرف ولم يُغلق');
        // والساعات تُنسب إلى الصرّاف لا إلى المدير.
        $this->assertSame(720, $s['totals']['worked_minutes']);
    }

    // ══════════════════════════════════════════════════════════════════
    // ضدّ التلاعب بالإدخال
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **لا يقبل التسجيلُ وقتاً من أحد.**
     *
     * ويُفحص بالتوقيع نفسه: معاملٌ اسمه `at` أو `occurred_at` في `record`
     * هو أوّل ما يُمرَّر من متحكّمٍ يقرأ `$request->input()` — وسقط
     * الدفاع كلُّه بسطر.
     */
    public function the_recorder_accepts_no_timestamp_from_anyone(): void
    {
        $params = (new \ReflectionMethod(AgentWorkTimeService::class, 'record'))->getParameters();

        foreach ($params as $p) {
            $this->assertNotContains(mb_strtolower($p->getName()),
                ['at', 'time', 'occurred_at', 'occurredat', 'when', 'date'],
                "المعامل «{$p->getName()}» يفتح باباً لوقتٍ يأتي من الطلب");
        }

        // والختمُ فعلاً من ساعة الخادم.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 09:15:00'));
        $e = $this->wt()->record($this->teller, AgentWorkEvent::SHIFT_OPEN);

        $this->assertSame('2026-08-03 09:15:00', $e->occurred_at->toDateTimeString());
    }

    // ══════════════════════════════════════════════════════════════════
    // ضدّ التلاعب بالبيانات — سلسلة التجزئة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_untouched_chain_verifies_clean(): void
    {
        $this->workShift('2026-08-03 08:00:00', '2026-08-03 16:00:00');

        $v = $this->wt()->verifyChain($this->teller);

        $this->assertTrue($v['ok']);
        $this->assertSame(2, $v['checked']);
    }

    /**
     * @test
     *
     * **تعديلُ وقتٍ في القاعدة يُكشف.**
     *
     * وهذا هو التهديد الذي لم يكن له دفاع: من يملك الوصول إلى القاعدة
     * كان يستطيع أن يزيد ساعاتِ من يشاء بلا أثر.
     */
    public function editing_a_stored_time_breaks_the_chain(): void
    {
        $this->workShift('2026-08-03 08:00:00', '2026-08-03 16:00:00');

        $open = AgentWorkEvent::where('staff_id', $this->teller->id)
            ->where('event', AgentWorkEvent::SHIFT_OPEN)->first();

        // زيادةُ ساعتين بتعديلٍ مباشر — بلا مرورٍ بأيّ خدمة.
        DB::table('agent_work_events')->where('id', $open->id)
            ->update(['occurred_at' => '2026-08-03 06:00:00']);

        $v = $this->wt()->verifyChain($this->teller);

        $this->assertFalse($v['ok'], 'عُدّل وقتٌ في القاعدة ولم يُكشف');
        $this->assertSame((int) $open->id, $v['broken_at']);
        $this->assertStringContainsString('تغيّر بعد كتابته', (string) $v['reason']);
    }

    /** @test */
    public function deleting_an_event_breaks_the_chain(): void
    {
        $this->workShift('2026-08-03 08:00:00', '2026-08-03 16:00:00');
        $this->workShift('2026-08-04 08:00:00', '2026-08-04 16:00:00');

        // حذفُ حدثٍ من الوسط: يترك بصمةً يتيمة في التالي.
        $mid = AgentWorkEvent::where('staff_id', $this->teller->id)
            ->orderBy('id')->skip(1)->first();
        DB::table('agent_work_events')->where('id', $mid->id)->delete();

        $v = $this->wt()->verifyChain($this->teller);

        $this->assertFalse($v['ok'], 'حُذف حدثٌ ولم يُكشف');
        $this->assertStringContainsString('حُذف حدثٌ', (string) $v['reason']);
    }

    /**
     * @test
     *
     * **وسلامةُ السلسلة تُعرَض مع الأرقام لا في شاشةٍ منفصلة.**
     *
     * رقمٌ من سلسلةٍ مكسورة لا يُقرأ قبل أن يُعرف أنّها مكسورة — وشاشةٌ
     * منفصلة للفحص تعني أنّه لن يُفتح.
     */
    public function the_summary_carries_its_own_integrity_verdict(): void
    {
        $this->workShift('2026-08-03 08:00:00', '2026-08-03 16:00:00');

        $s = $this->wt()->summary($this->teller, '2026-08-03', '2026-08-03');
        $this->assertTrue($s['integrity']['ok']);

        DB::table('agent_work_events')->where('staff_id', $this->teller->id)
            ->limit(1)->update(['occurred_at' => '2026-08-03 05:00:00']);

        $s2 = $this->wt()->summary($this->teller, '2026-08-03', '2026-08-03');
        $this->assertFalse($s2['integrity']['ok'], 'الكشف يعرض أرقاماً ولا يقول إنّ سجلَّها مكسور');
    }

    // ══════════════════════════════════════════════════════════════════
    // الوقت الإضافيّ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function overtime_is_measured_against_the_expected_day(): void
    {
        $this->workShift('2026-08-03 08:00:00', '2026-08-03 18:30:00');   // ١٠س ٣٠د

        $s = $this->wt()->summary($this->teller, '2026-08-03', '2026-08-03');

        $this->assertSame('8س', $s['daily'][0]['expected']);
        $this->assertSame('2س 30د', $s['daily'][0]['overtime']);
    }

    /**
     * @test
     *
     * **«غير مضبوط» ليس صفراً** (القاعدة السابعة): من لم يُحدَّد دوامُه
     * لا يُقال إنّ إضافيّه صفر — يُقال إنّه لا يُقاس لغياب المرجع.
     */
    public function a_staff_member_without_a_schedule_has_no_overtime_figure(): void
    {
        $this->teller->forceFill(['daily_hours_expected' => '0'])->save();

        $this->workShift('2026-08-03 08:00:00', '2026-08-03 20:00:00');

        $s = $this->wt()->summary($this->teller->fresh(), '2026-08-03', '2026-08-03');

        $this->assertNull($s['daily'][0]['expected']);
        $this->assertNull($s['daily'][0]['overtime'], 'قيل إنّ الإضافيّ صفر ولا مرجعَ يُقاس عليه');
        $this->assertNull($s['totals']['overtime']);
    }

    /**
     * @test
     *
     * **وقوعُ الإضافيّ ليس استحقاقَه.**
     *
     * موظّفٌ يبقى ساعتين بلا طلبٍ من أحد لا يُنشئ التزاماً على شركته
     * بمجرّد بقائه — وإلّا صار الجدولُ اختياراً بيد من يُجدوَل له.
     */
    public function overtime_that_nobody_approved_is_recorded_but_not_payable(): void
    {
        $this->teller->forceFill(['overtime_policy' => 'approved'])->save();

        $this->workShift('2026-08-03 08:00:00', '2026-08-03 18:00:00');   // ساعتان إضافيّتان

        $s = $this->wt()->summary($this->teller->fresh(), '2026-08-03', '2026-08-03');

        $this->assertSame('2س', $s['overtime']['occurred']);
        $this->assertSame('0د', $s['overtime']['payable'], 'صار البقاء وحده استحقاقاً');
        $this->assertSame('2س', $s['overtime']['pending']);
        // **ولا يُمحى.** بقاؤه واقعةٌ تُعرَض على المدير ليقرّرها.
        $this->assertSame('⏳ واقعٌ بلا موافقة', $s['overtime']['rows'][0]['label']);
    }

    /** @test */
    public function approved_overtime_becomes_payable(): void
    {
        $this->teller->forceFill(['overtime_policy' => 'approved'])->save();

        // الطلب يُقدَّم في يومه — وتاريخُه من الخادم لا من الطلب.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 17:00:00'));
        $row = app(AgentTellerRequestService::class)->submit($this->teller->fresh(), [
            'kind' => 'overtime', 'amount' => '0',
            'reason' => 'جردُ آخر الشهر يحتاج ساعتين إضافيّتين',
        ]);
        app(AgentTellerRequestService::class)->decide($this->manager, $row->id, true, 'موافق');

        $this->workShift('2026-08-03 08:00:00', '2026-08-03 18:00:00');

        $s = $this->wt()->summary($this->teller->fresh(), '2026-08-03', '2026-08-03');

        $this->assertSame('2س', $s['overtime']['payable']);
        $this->assertSame('0د', $s['overtime']['pending']);
    }

    /**
     * @test
     *
     * **الموافقة تخصّ يومها.** ولو غطّت أيّ يوم لصارت موافقةٌ واحدة
     * تُقرّ إضافيّ الشهر كلّه.
     */
    public function an_overtime_approval_covers_its_own_day_only(): void
    {
        $this->teller->forceFill(['overtime_policy' => 'approved'])->save();

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 17:00:00'));
        $row = app(AgentTellerRequestService::class)->submit($this->teller->fresh(), [
            'kind' => 'overtime', 'amount' => '0',
            'reason' => 'جردُ آخر الشهر يحتاج ساعتين إضافيّتين',
        ]);
        app(AgentTellerRequestService::class)->decide($this->manager, $row->id, true, 'موافق');

        $this->workShift('2026-08-03 08:00:00', '2026-08-03 18:00:00');   // مُقَرّ
        $this->workShift('2026-08-05 08:00:00', '2026-08-05 18:00:00');   // بلا موافقة

        $s = $this->wt()->summary($this->teller->fresh(), '2026-08-01', '2026-08-31');

        $this->assertSame('2س', $s['overtime']['payable']);
        $this->assertSame('2س', $s['overtime']['pending'],
            'موافقةُ يومٍ غطّت يوماً آخر — فموافقةٌ واحدة تُقرّ الشهر كلّه');
    }

    /** @test */
    public function a_no_overtime_policy_records_it_and_pays_nothing(): void
    {
        $this->teller->forceFill(['overtime_policy' => 'no'])->save();

        $this->workShift('2026-08-03 08:00:00', '2026-08-03 18:00:00');

        $s = $this->wt()->summary($this->teller->fresh(), '2026-08-03', '2026-08-03');

        $this->assertSame('2س', $s['overtime']['occurred'], 'مُحي البقاء بدل أن يُسجَّل');
        $this->assertSame('0د', $s['overtime']['payable']);
    }

    // ══════════════════════════════════════════════════════════════════
    // الاستراحة ليست ستراً
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **لا عمليّة بلا وقتٍ يُنسب إليها.**
     *
     * والخطر ليس أن يخصم الموظّف من نفسه — بل أن يُطلب منه العمل «خارج
     * الدوام»، فتجري العمليّات والسجلّ يقول إنّ الفرع مغلق.
     */
    public function no_operation_runs_while_on_break(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '100000');
        $this->wt()->record($this->teller, AgentWorkEvent::BREAK_START, (int) $shift->id);

        $customer = new User();
        $customer->forceFill([
            'f_name' => 'راشد', 'l_name' => 'معرابي', 'phone' => '967775100555',
            'type' => CUSTOMER_TYPE, 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $customer->id, 'current_balance' => '0']);

        $this->actingAs($this->teller->fresh(), 'agent_staff');

        $r = $this->postJson(route('agent.counter.deposit'), [
            'customer_id' => $customer->id, 'amount' => '1000',
        ])->assertStatus(422);

        $this->assertStringContainsString('استراحة', $r->json('message'));
    }

    /** @test */
    public function a_break_cannot_be_started_twice_nor_ended_without_starting(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '0');
        $this->actingAs($this->teller->fresh(), 'agent_staff');

        $this->postJson(route('agent.teller.break'), ['action' => 'start'])->assertOk();
        // مرّتان: لا تُنشئ استراحتين.
        $this->postJson(route('agent.teller.break'), ['action' => 'start'])->assertStatus(422);

        $this->postJson(route('agent.teller.break'), ['action' => 'end'])->assertOk();
        // ونهايةٌ بلا بداية: لا تترك حدثاً معلَّقاً يقتطع من ورديّةٍ لاحقة.
        $this->postJson(route('agent.teller.break'), ['action' => 'end'])->assertStatus(422);
    }

    /** @test */
    public function a_break_outside_a_shift_is_refused(): void
    {
        $this->actingAs($this->teller, 'agent_staff');

        $this->postJson(route('agent.teller.break'), ['action' => 'start'])
            ->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════
    // ضبطُ الحدود والدوام من الشاشة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function hiring_sets_the_limits_and_the_schedule_at_once(): void
    {
        $s = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صرّاف جديد', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'newbie12',
            'max_txn_amount' => '50000', 'daily_limit' => '400000',
            'daily_count_limit' => 30, 'daily_hours_expected' => '7.5',
            'overtime_policy' => 'no',
        ]);

        $this->assertSame(0, bccomp('400000', (string) $s->daily_limit, 4));
        $this->assertSame(30, (int) $s->daily_count_limit);
        $this->assertSame(0, bccomp('7.5', (string) $s->daily_hours_expected, 2));
        $this->assertSame('no', $s->overtime_policy);
    }

    /**
     * @test
     *
     * **رفعُ حدٍّ قرارٌ ماليّ** — ويُسجَّل بدرجة `high`. ومن رفع حدَّ
     * صرّافٍ قبل عمليّةٍ كبيرة بدقائق هو أوّل ما يُبحث عنه في تحقيق.
     */
    public function raising_a_limit_is_audited_as_a_financial_decision(): void
    {
        app(AgentStaffService::class)->updateLimits($this->hq, (int) $this->teller->id, [
            'max_txn_amount' => '900000', 'daily_limit' => '0',
            'daily_count_limit' => 0, 'daily_hours_expected' => '8',
            'overtime_policy' => 'approved',
        ]);

        $row = DB::table('audit_decisions')->where('action', 'agent.staff.limits')
            ->orderByDesc('id')->first();

        $this->assertNotNull($row, 'رُفع حدٌّ بلا أثرٍ في سجلّ التدقيق');
        // `high` تُطبَّع إلى `warning`: العمود محصورٌ بأربع قيم، وقيمةٌ خارجها
        // كانت تُسقط سطر التدقيق كلَّه بصمت.
        $this->assertSame('warning', $row->severity);
    }

    /** @test */
    public function a_teller_cannot_raise_their_own_limits(): void
    {
        $this->expectException(\DomainException::class);

        app(AgentStaffService::class)->updateLimits($this->teller, (int) $this->teller->id, [
            'max_txn_amount' => '9000000',
        ]);
    }

    /** @test */
    public function an_impossible_schedule_is_refused(): void
    {
        $this->expectException(\DomainException::class);

        app(AgentStaffService::class)->updateLimits($this->hq, (int) $this->teller->id, [
            'daily_hours_expected' => '30',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // النطاق
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_teller_reads_only_their_own_timesheet(): void
    {
        $other = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'زميل', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller99',
        ]);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 08:00:00'));
        $s2 = app(AgentShiftService::class)->open($other, '0');
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-03 18:00:00'));
        app(AgentShiftService::class)->close($other, $s2, '0');

        $this->actingAs($this->teller, 'agent_staff');

        $j = $this->getJson(route('agent.teller.timesheet') . '?from=2026-08-03&to=2026-08-03')
            ->assertOk()->json('meta');

        $this->assertSame((int) $this->teller->id, $j['staff']['id']);
        $this->assertSame(0, $j['totals']['worked_minutes'], 'رأى الصرّافُ ساعات زميله');
    }
}
