<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentDailySettlement;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentCounterService;
use App\Services\AgentDailySettlementService;
use App\Services\AgentNetworkService;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-DAILY-SETTLEMENT-001 — إقفال يوم الوكيل، وتحوّل الورق إلى رصيد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما يُثبته هذا الملفّ: أنّ المال ينتقل فعلاً عند القبول.**
 *
 * تسويةٌ تُعلَّم «مقبولة» ولا يتحرّك بها ريالٌ أسوأ من غيابها: الوكيل يرى
 * يومه مُقفلاً والمنصّة تظنّ أنّها استلمت. وقد وقع هذا في هذا المشروع
 * حرفيّاً حين كان `payout` يمرّ بلا معالجة — فيُقاس هنا الرصيد قبل وبعد.
 */
class AgentDailySettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private AgentStaff $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770007700',
        ]);
        EMoney::updateOrCreate(['user_id' => $this->admin->id], ['current_balance' => '9000000']);
        // AMIAL-ADMIN-DOORS-002 — لوحةُ الإقفال اليوميّ وقراراتُها صارت
        // خلف صلاحيّتها: القراءةُ رقابةٌ والقبولُ قرارُ مال.
        app(\App\Services\PlatformRoleService::class)
            ->assign($this->admin, \App\Services\PlatformRoleService::ADMIN);
        $this->admin->refresh();

        $this->company = new User();
        $this->company->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967771800001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');

        $bu = new User();
        $bu->forceFill([
            'f_name' => 'فرع المكلا', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967771800099', 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '5000000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => 'فرع المكلا', 'status' => 'active',
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
        $this->branch = $this->branch->fresh('till');

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'محمد علي', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
        ]);
    }

    private function customer(): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '96777280' . random_int(1000, 9999),
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => '500000']);

        return $c;
    }

    /** يومُ عملٍ: ورديّةٌ فيها إيداعاتٌ وسحوبات، ثمّ تُغلق. */
    private function workDay(string $deposits = '0', string $withdrawals = '0'): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '500000');

        if (bccomp($deposits, '0', 4) > 0) {
            app(AgentCounterService::class)->deposit(
                $this->branch, $this->customer(), $deposits,
                $this->branch->account, 'إيداع', $shift->fresh(),
            );
        }

        if (bccomp($withdrawals, '0', 4) > 0) {
            app(AgentCounterService::class)->withdraw(
                $this->branch, $this->customer(), $withdrawals,
                $this->branch->account, 'سحب', $shift->fresh(),
            );
        }

        $s = $shift->fresh();
        app(AgentShiftService::class)->close($this->teller, $s, $s->expectedCash());
    }

    private function inWindow(): Carbon
    {
        return now()->startOfDay()->addHours(22)->addMinutes(30);
    }

    // ══════════════════════════════════════════════════════════════════
    // النافذة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_window_is_closed_before_ten_at_night(): void
    {
        $w = app(AgentDailySettlementService::class)->windowState(
            now()->toDateString(), now()->startOfDay()->addHours(15));

        $this->assertFalse($w['open']);
        $this->assertSame('not_yet', $w['state']);
        $this->assertStringContainsString('لم تُفتح', $w['message']);
    }

    /** @test */
    public function the_window_is_open_between_ten_and_midnight(): void
    {
        $w = app(AgentDailySettlementService::class)->windowState(
            now()->toDateString(), $this->inWindow());

        $this->assertTrue($w['open']);
        $this->assertSame('on_time', $w['state']);
    }

    /**
     * @test
     *
     * **ومن تأخّر لا يُفتح له الباب تلقائياً.** مالٌ بلا غطاءٍ مقابلٍ ليلةً
     * كاملة يُسجَّل ويُراجَع، ولا يُغتفر بصمت.
     */
    public function after_midnight_the_day_is_locked_and_says_who_can_open_it(): void
    {
        $date = now()->subDay()->toDateString();

        $w = app(AgentDailySettlementService::class)->windowState($date, now());
        $this->assertFalse($w['open']);
        $this->assertSame('closed', $w['state']);
        $this->assertStringContainsString('فكّاً من إدارة أميال', $w['message']);

        $this->workDay('100000');

        $this->expectExceptionMessageMatches('/فكّاً من إدارة أميال/');
        app(AgentDailySettlementService::class)->submit($this->company, $this->hq, $date, now());
    }

    /** @test */
    public function amial_can_unlock_a_late_day_and_the_lateness_is_never_erased(): void
    {
        $date = now()->subDay()->toDateString();
        $this->workDay('100000');

        app(AgentDailySettlementService::class)
            ->unlock($this->company, $date, $this->admin, 'انقطاع الإنترنت في المكلا ليلة أمس');

        $row = app(AgentDailySettlementService::class)
            ->submit($this->company, $this->hq, $date, now());

        // رُفعت — لكنّها ليست «في وقتها».
        $this->assertSame(AgentDailySettlement::STATUS_SUBMITTED, $row->status);
        $this->assertSame('unlocked', $row->window_state);
        $this->assertNotSame('on_time', $row->window_state);
        $this->assertStringContainsString('الإنترنت', $row->unlock_reason);
    }

    /** @test */
    public function a_teller_cannot_file_the_company_day(): void
    {
        $this->workDay('100000');

        $this->expectExceptionMessage('الإدارة العامّة');
        app(AgentDailySettlementService::class)
            ->submit($this->company, $this->teller, now()->toDateString(), $this->inWindow());
    }

    /** @test */
    public function a_day_cannot_be_filed_twice(): void
    {
        $this->workDay('100000');
        $date = now()->toDateString();

        app(AgentDailySettlementService::class)->submit($this->company, $this->hq, $date, $this->inWindow());

        $this->expectExceptionMessage('مرفوعةٌ بالفعل');
        app(AgentDailySettlementService::class)->submit($this->company, $this->hq, $date, $this->inWindow());
    }

    // ══════════════════════════════════════════════════════════════════
    // التحوّل: الريال الحقيقيّ ⇄ الإلكترونيّ
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * إيداعاتٌ أكثر ⇒ في يد الوكيل ورقٌ زائدٌ ورصيدٌ ناقص. فيسلّم الورق
     * ويستلم رصيداً — **والرصيد يزيد فعلاً عند القبول.**
     */
    public function a_day_of_deposits_turns_the_agents_paper_cash_into_float(): void
    {
        $this->workDay('300000', '100000');   // صافي +٢٠٠٠٠٠ ورقاً

        $row = app(AgentDailySettlementService::class)
            ->submit($this->company, $this->hq, now()->toDateString(), $this->inWindow());

        $this->assertSame('topup', $row->conversion);
        $this->assertSame('200000.0000', bcadd((string) $row->conversion_amount, '0', 4));

        $before = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');

        app(AgentDailySettlementService::class)->accept($row, $this->admin, 'استُلم النقد في خزينة الشحر');

        $after = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');

        // **المال تحرّك.** ولولا هذا القياس لكانت الحالة «مقبولة» بلا نقل.
        $this->assertSame('200000.0000', bcsub($after, $before, 4));
        $this->assertSame(AgentDailySettlement::STATUS_ACCEPTED, $row->fresh()->status);
        $this->assertNotNull($row->fresh()->linked_settlement_ulid);
    }

    /**
     * @test
     *
     * سحوباتٌ أكثر ⇒ امتلأ رصيدُه وفرغ درجُه. فيعيد الرصيد ويستلم ورقاً —
     * **والرصيد ينقص فعلاً.**
     */
    public function a_day_of_withdrawals_turns_float_back_into_paper_cash(): void
    {
        // رصيدٌ ابتدائيّ للشركة ليُصرَف منه.
        app(AgentNetworkService::class)->adminCreditAgent($this->company, '400000', $this->admin);

        $this->workDay('50000', '250000');    // صافي −٢٠٠٠٠٠ ورقاً

        $row = app(AgentDailySettlementService::class)
            ->submit($this->company, $this->hq, now()->toDateString(), $this->inWindow());

        $this->assertSame('payout', $row->conversion);
        $this->assertSame('200000.0000', bcadd((string) $row->conversion_amount, '0', 4));

        $before = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');
        app(AgentDailySettlementService::class)->accept($row, $this->admin, 'سُلّم النقد للوكيل');
        $after = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');

        $this->assertSame('-200000.0000', bcsub($after, $before, 4));
    }

    /** @test */
    public function a_balanced_day_moves_nothing_and_says_so(): void
    {
        $this->workDay('150000', '150000');

        $row = app(AgentDailySettlementService::class)
            ->submit($this->company, $this->hq, now()->toDateString(), $this->inWindow());

        $this->assertSame('none', $row->conversion);

        $before = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');
        app(AgentDailySettlementService::class)->accept($row, $this->admin);
        $after = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');

        $this->assertSame('0.0000', bcsub($after, $before, 4));
    }

    /**
     * @test
     *
     * **الورقُ والرصيد متعاكسان دائماً.** كلّ ريالٍ إلكترونيٍّ خرج من الوكيل
     * يقابله ريالٌ ورقيٌّ دخل درجه. ولو انفكّ هذا الترابط لصار في الشبكة
     * رصيدٌ بلا غطاء.
     */
    public function cash_and_float_always_move_in_opposite_directions(): void
    {
        $this->workDay('300000', '100000');

        $day = app(AgentDailySettlementService::class)
            ->computeDay($this->company, now()->toDateString());

        $this->assertSame('200000.0000', bcadd($day['net_cash'], '0', 4));
        $this->assertSame('-200000.0000', bcadd($day['net_float'], '0', 4));
        $this->assertSame('0.0000', bcadd(bcadd($day['net_cash'], $day['net_float'], 4), '0', 4));
    }

    // ══════════════════════════════════════════════════════════════════
    // التفاصيل التي تُرفع
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_filing_carries_the_details_not_just_a_number(): void
    {
        $this->workDay('300000', '100000');

        $day = app(AgentDailySettlementService::class)
            ->computeDay($this->company, now()->toDateString());

        $this->assertSame(1, $day['deposits_count']);
        $this->assertSame('300000.0000', bcadd($day['deposits_total'], '0', 4));
        $this->assertSame(1, $day['withdrawals_count']);
        $this->assertSame('100000.0000', bcadd($day['withdrawals_total'], '0', 4));
        $this->assertSame(1, $day['shifts_closed']);
        $this->assertArrayHasKey('flags', $day);
    }

    /** @test */
    public function a_large_variance_is_flagged_for_amial_to_look_at(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '500000');
        app(AgentCounterService::class)->deposit(
            $this->branch, $this->customer(), '100000',
            $this->branch->account, 'إيداع', $shift->fresh());

        $s = $shift->fresh();
        // عجزٌ ٦٠٬٠٠٠ فوق الحدّ ٥٠٬٠٠٠.
        app(AgentShiftService::class)->close(
            $this->teller, $s, bcsub($s->expectedCash(), '60000', 4), 'نقصٌ كبير يُحقَّق فيه');

        $day = app(AgentDailySettlementService::class)
            ->computeDay($this->company, now()->toDateString());

        $this->assertSame(1, $day['shortage_count']);
        $this->assertSame('60000.0000', bcadd($day['shortage_total'], '0', 4));
        $this->assertGreaterThan(0, $day['suspicious_count']);
        $this->assertNotEmpty($day['flags']);
    }

    /**
     * @test
     *
     * **ورديّةٌ لم تُغلق تُرفع صراحةً** — درجٌ بلا شهادة إنسان، وهي حالةٌ
     * تُذكر لا تُترك ضمن «لا فروق».
     */
    public function an_unclosed_shift_is_reported_not_silently_ignored(): void
    {
        app(AgentShiftService::class)->open($this->teller, '100000');

        $day = app(AgentDailySettlementService::class)
            ->computeDay($this->company, now()->toDateString());

        $this->assertSame(1, $day['unclosed_shifts']);
        $this->assertGreaterThan(0, $day['suspicious_count']);
    }

    /** @test */
    public function an_unclosed_shift_blocks_the_normal_daily_close(): void
    {
        app(AgentShiftService::class)->open($this->teller, '100000');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ورديات مفتوحة');
        app(AgentDailySettlementService::class)
            ->submit($this->company, $this->hq, now()->toDateString(), $this->inWindow());
    }

    /** فرقٌ كبيرٌ مغلق بلا مراجعة ليس «يوماً سليماً» يمكن تحويله. */
    public function an_unreviewed_shift_variance_blocks_the_normal_daily_close(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '500000');
        app(AgentCounterService::class)->deposit(
            $this->branch, $this->customer(), '100000',
            $this->branch->account, 'إيداع', $shift->fresh(),
        );

        $fresh = $shift->fresh();
        app(AgentShiftService::class)->close(
            $this->teller, $fresh, bcsub($fresh->expectedCash(), '60000', 4), 'فرق كبير قيد المراجعة',
        );

        $day = app(AgentDailySettlementService::class)
            ->computeDay($this->company, now()->toDateString());
        $this->assertGreaterThan(0, $day['pending_review']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('مراجعة فروق الورديات');
        app(AgentDailySettlementService::class)
            ->submit($this->company, $this->hq, now()->toDateString(), $this->inWindow());
    }

    // ══════════════════════════════════════════════════════════════════
    // شاشة أميال
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **من لم يرفع يتصدّر الجدول.** قائمةٌ تعرض المرفوع وحده تُخفي بالضبط
     * ما يُفتح لأجله هذا التبويب.
     */
    public function the_network_board_shows_who_did_not_file(): void
    {
        $board = app(AgentDailySettlementService::class)->networkDay(now()->toDateString());

        $this->assertSame(1, $board['totals']['agents']);
        $this->assertSame(1, $board['totals']['not_submitted']);
        $this->assertSame('not_submitted', $board['rows'][0]['status']);
        $this->assertSame('لم يرفع تسوية اليوم', $board['rows'][0]['status_label']);
    }

    /** @test */
    public function the_board_endpoint_is_reachable_and_lists_the_day(): void
    {
        $this->workDay('300000');
        app(AgentDailySettlementService::class)
            ->submit($this->company, $this->hq, now()->toDateString(), $this->inWindow());

        $j = $this->actingAs($this->admin, 'user')
            ->getJson(route('admin.amial.hub.agents.daily', ['date' => now()->toDateString()]))
            ->assertOk()->json();

        $this->assertSame(1, $j['totals']['awaiting']);
        $this->assertSame('topup', $j['rows'][0]['conversion']);
    }

    /** @test */
    public function rejecting_without_a_reason_is_refused(): void
    {
        $this->workDay('100000');
        $row = app(AgentDailySettlementService::class)
            ->submit($this->company, $this->hq, now()->toDateString(), $this->inWindow());

        $this->expectExceptionMessage('سببُ الرفض إلزاميّ');
        app(AgentDailySettlementService::class)->reject($row, $this->admin, 'لا');
    }

    /** @test */
    public function a_rejected_day_moves_no_money(): void
    {
        $this->workDay('300000');
        $row = app(AgentDailySettlementService::class)
            ->submit($this->company, $this->hq, now()->toDateString(), $this->inWindow());

        $before = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');
        app(AgentDailySettlementService::class)
            ->reject($row, $this->admin, 'النقد المسلَّم لا يطابق الكشف — أعد الجرد');
        $after = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');

        $this->assertSame('0.0000', bcsub($after, $before, 4));
        $this->assertSame(AgentDailySettlement::STATUS_REJECTED, $row->fresh()->status);
    }
}
