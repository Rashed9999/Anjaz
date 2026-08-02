<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashTill;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentCounterService;
use App\Services\AgentShiftService;
use App\Services\AgentStaffProfileService;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-STAFF-360-001 — ملفّ الموظّف، وتقييمه، ودرجة مخاطرته.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما يُثبته هذا الملفّ قبل كلّ شيء: أنّ الدرجة تعني شيئاً.**
 *
 * درجةُ مخاطرةٍ تقول «منخفض» دائماً أسوأ من غيابها — تُطمئن ولا تحرس.
 * فيُصنع هنا صرّافان: منضبطٌ ومضطرب، ويُتأكَّد أنّ الدرجة تفرّق بينهما،
 * وأنّ من لم يُختبَر لا يُعطى درجةً أصلاً.
 */
class AgentStaffProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private AgentStaff $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = new User();
        $this->company->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967771600001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');
        $this->branch = $this->makeBranch('MKL', 'فرع المكلا');

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'محمد علي', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
        ]);
    }

    private function makeBranch(string $code, string $name): AgentBranch
    {
        $bu = new User();
        $bu->forceFill([
            'f_name' => $name, 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '9677716' . random_int(10000, 99999),
            'password' => Hash::make('secret123'), 'is_active' => 1, 'is_kyc_verified' => 1,
            'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '5000000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => $name, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $b = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => $name, 'code' => $code, 'city' => 'حضرموت',
            'phone' => $bu->phone, 'is_active' => true,
        ]);

        $b->till()->create([
            'cash_on_hand' => '2000000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '100000',
        ]);

        return $b->fresh('till');
    }

    private function makeCustomer(string $phone): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => $phone, 'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => '0']);

        return $c;
    }

    /** ورديّةٌ كاملة: تُفتح، يُودَع فيها، ثمّ تُغلق بفرقٍ محدَّد. */
    private function workAShift(AgentStaff $teller, string $deposit, string $varianceDelta = '0'): AgentShift
    {
        $shift = app(AgentShiftService::class)->open($teller, '100000');

        if (bccomp($deposit, '0', 4) > 0) {
            // الفاعل حسابُ الفرع، والورديّة تُمرَّر — فالنقد يقع في **درج
            // الصرّاف** لا في خزنة الفرع، وهو ما ينسب العملية إلى صاحبها.
            app(AgentCounterService::class)->deposit(
                $this->branch, $this->makeCustomer('9677725' . random_int(10000, 99999)),
                $deposit, $this->branch->account, 'إيداع', $shift,
            );
        }

        $shift = $shift->fresh();
        $counted = bcadd((string) $shift->expectedCash(), $varianceDelta, 4);

        return app(AgentShiftService::class)->close(
            $teller, $shift, $counted,
            bccomp($varianceDelta, '0', 4) === 0 ? '' : 'فرقٌ في العدّ يُراجَع',
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // الدرجة تعني شيئاً
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **«غير معروف» ليس صفراً.** موظّفٌ لم يُغلق ورديّةً ليس منخفض المخاطر
     * — هو غير مُقيَّم. وإظهارُه أخضرَ يجعل المدير يثق بمن لم يُجرَّب.
     */
    public function a_staff_member_with_no_closed_shifts_has_no_score_not_a_zero(): void
    {
        $p = app(AgentStaffProfileService::class)->profile($this->teller);

        $this->assertNull($p['risk']['score']);
        $this->assertSame('unrated', $p['risk']['level']);
        $this->assertStringContainsString('لم يُختبَر', $p['risk']['reason']);
    }

    /** @test */
    public function a_clean_teller_scores_low(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->workAShift($this->teller, '50000');
        }

        $p = app(AgentStaffProfileService::class)->profile($this->teller);

        $this->assertSame(4, $p['shifts']['closed']);
        $this->assertSame(4, $p['shifts']['exact_count']);
        $this->assertSame(100.0, $p['shifts']['accuracy_pct']);
        $this->assertSame(0, $p['risk']['score']);
        $this->assertSame('low', $p['risk']['level']);
    }

    /**
     * @test
     *
     * **الإثبات بالعكس.** لو أعطت الدرجة «منخفض» للمضطرب أيضاً لكانت بلا
     * معنى. فيُصنع صرّافٌ ينقص درجُه في ثلاث ورديّاتٍ من أربع.
     */
    public function a_teller_with_repeated_shortages_scores_higher(): void
    {
        $this->workAShift($this->teller, '50000');
        $this->workAShift($this->teller, '50000', '-5000');
        $this->workAShift($this->teller, '50000', '-3000');
        $this->workAShift($this->teller, '50000', '-2000');

        $p = app(AgentStaffProfileService::class)->profile($this->teller);

        $this->assertSame(3, $p['shifts']['shortage_count']);
        $this->assertSame('10000.0000', bcadd($p['shifts']['shortage_total'], '0', 4));
        $this->assertGreaterThan(30, $p['risk']['score']);
        $this->assertContains($p['risk']['level'], ['medium', 'high']);

        // والإشارات تُعرَض واحدةً واحدة: رقمٌ بلا تفصيلٍ لا يُتَّخذ عليه قرار.
        $keys = array_column($p['risk']['signals'], 'key');
        $this->assertContains('shortage_rate', $keys);
        $this->assertContains('shortage_weight', $keys);
        $this->assertContains('unreviewed', $keys);
    }

    /**
     * @test
     *
     * **العجز والفائض لا يُقاصّان.** من نقص خمسةً وزاد خمسةً ليس «صفراً»:
     * هما حادثتان تستحقّان سؤالين، والمقاصّة تُخفي الاثنين معاً.
     */
    public function shortages_and_overages_are_never_netted(): void
    {
        $this->workAShift($this->teller, '50000', '-5000');
        $this->workAShift($this->teller, '50000', '5000');
        $this->workAShift($this->teller, '50000');

        $p = app(AgentStaffProfileService::class)->profile($this->teller);

        $this->assertSame(1, $p['shifts']['shortage_count']);
        $this->assertSame('5000.0000', bcadd($p['shifts']['shortage_total'], '0', 4));
        $this->assertSame(1, $p['shifts']['overage_count']);
        $this->assertSame('5000.0000', bcadd($p['shifts']['overage_total'], '0', 4));

        // ولو قُوصّا لخرجت الدقّة ١٠٠٪ ولخرجت الدرجة صفراً.
        $this->assertSame(33.3, $p['shifts']['accuracy_pct']);
        $this->assertGreaterThan(0, $p['risk']['score']);
    }

    /** @test */
    public function the_operations_are_counted_from_the_movement_log(): void
    {
        $this->workAShift($this->teller, '80000');
        $this->workAShift($this->teller, '20000');

        $p = app(AgentStaffProfileService::class)->profile($this->teller);

        $this->assertSame(2, $p['operations']['deposits_count']);
        $this->assertSame('100000.0000', bcadd($p['operations']['deposits_total'], '0', 4));
        $this->assertSame('80000.0000', bcadd($p['operations']['deposits_biggest'], '0', 4));
        $this->assertSame(2, $p['operations']['distinct_customers']);
        $this->assertCount(2, $p['recent_operations']);
    }

    // ══════════════════════════════════════════════════════════════════
    // ملفّ التسوية: الورديّة المغلقة إقرارٌ ينتظر قراراً
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **«مطابقة» ليست «قُبلت».** الأولى لا تحتاج قراراً، والثانية تعني أنّ
     * إنساناً نظر. وخلطُهما يجعل عجزاً بخمسة آلاف يبدو مراجَعاً وهو لم
     * يُقرأ.
     */
    public function closing_a_shift_files_a_settlement_that_waits_for_a_human(): void
    {
        $clean = $this->workAShift($this->teller, '50000');
        $this->assertSame(AgentShift::REVIEW_BALANCED, $clean->review_status);

        $short = $this->workAShift($this->teller, '50000', '-4000');
        $this->assertSame(AgentShift::REVIEW_PENDING, $short->review_status);
        $this->assertNull($short->reviewed_by);
    }

    /** @test */
    public function head_office_decides_the_variance_and_the_decision_carries_a_name(): void
    {
        $short = $this->workAShift($this->teller, '50000', '-4000');

        $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.staff.shifts.review', ['id' => $short->id]),
                ['decision' => 'investigating', 'note' => 'يُراجَع تسجيل الكاميرا لهذه الورديّة'])
            ->assertOk();

        $short->refresh();
        $this->assertSame(AgentShift::REVIEW_INVESTIGATING, $short->review_status);
        $this->assertSame($this->hq->id, (int) $short->reviewed_by);
        $this->assertNotNull($short->reviewed_at);
        $this->assertStringContainsString('الكاميرا', $short->review_note);
    }

    /**
     * @test
     *
     * من عدّ الدرج لا يُصدّق على عدّ نفسه.
     */
    public function a_teller_cannot_sign_off_on_their_own_count(): void
    {
        $short = $this->workAShift($this->teller, '50000', '-4000');

        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.staff.shifts.review', ['id' => $short->id]),
                ['decision' => 'accepted', 'note' => 'كان خطأً في العدّ لا أكثر'])
            ->assertStatus(403);

        $this->assertSame(AgentShift::REVIEW_PENDING, $short->fresh()->review_status);
    }

    /** @test */
    public function a_decision_without_a_written_reason_is_refused(): void
    {
        $short = $this->workAShift($this->teller, '50000', '-4000');

        $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.staff.shifts.review', ['id' => $short->id]),
                ['decision' => 'accepted', 'note' => 'تمام'])
            ->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════
    // سجلّ العمليات ونطاق الرؤية
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_operations_log_names_who_did_what(): void
    {
        $this->workAShift($this->teller, '70000');

        $j = $this->actingAs($this->hq, 'agent_staff')
            ->getJson(route('agent.operations'))->assertOk()->json('meta');

        $this->assertCount(1, $j['rows']);
        $this->assertSame('محمد علي', $j['rows'][0]['staff']);
        $this->assertSame('فرع المكلا', $j['rows'][0]['branch']);
        $this->assertSame('customer_deposit', $j['rows'][0]['reason']);
        $this->assertSame(1, $j['totals']['deposits_count']);
        $this->assertSame('70000.0000', bcadd($j['totals']['deposits_total'], '0', 4));
    }

    /** @test */
    public function a_teller_sees_only_their_own_profile(): void
    {
        $other = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صرّافٌ آخر', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'other123',
        ]);

        $this->actingAs($this->teller, 'agent_staff')
            ->getJson(route('agent.staff.profile', ['id' => $this->teller->id]))
            ->assertOk();

        $this->actingAs($this->teller, 'agent_staff')
            ->getJson(route('agent.staff.profile', ['id' => $other->id]))
            ->assertStatus(403);
    }

    /** @test */
    public function a_profile_from_another_company_is_not_reachable(): void
    {
        $rival = new User();
        $rival->forceFill([
            'f_name' => 'منافس', 'l_name' => 'للصرافة', 'phone' => '967771699999',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $rival->id, 'current_balance' => '0']);
        $rivalHq = app(AgentStaffService::class)->ensureHeadOfficeAccount($rival, 'rival123');

        $this->actingAs($this->hq, 'agent_staff')
            ->getJson(route('agent.staff.profile', ['id' => $rivalHq->id]))
            ->assertStatus(404);
    }

    /** @test */
    public function the_shift_list_carries_the_settlement_state_so_a_variance_cannot_pass_silently(): void
    {
        $this->workAShift($this->teller, '50000', '-4000');

        $rows = $this->actingAs($this->hq, 'agent_staff')
            ->getJson(route('agent.staff.shifts', ['branch_id' => $this->branch->id]))
            ->assertOk()->json('data');

        $closed = collect($rows)->firstWhere('status', AgentShift::STATUS_CLOSED);
        $this->assertNotNull($closed);
        $this->assertSame(AgentShift::REVIEW_PENDING, $closed['review_status']);
        $this->assertSame('بانتظار مراجعة الإدارة', $closed['review_label']);
    }
}
