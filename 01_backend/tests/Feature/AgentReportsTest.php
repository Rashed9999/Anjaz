<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentCounterService;
use App\Services\AgentReportsService;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-AGENT-REPORTS-001 — تقارير شركة الصرافة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما يُثبته: أنّ التقرير يقول الحقيقة حتى حين تكون غير مريحة.**
 *
 * فرعٌ لم يعمل يجب أن يُقال عنه «متوقّف» لا أن يُعرَض صفراً بين العاملين.
 * ونموٌّ من صفرٍ ليس «١٠٠٪». وموظّفٌ لم يُغلق ورديّةً لا دقّة له.
 * وثلاثتها تجميلاتٌ يسهل الوقوع فيها، وكلٌّ منها يُضلّل قارئ التقرير.
 */
class AgentReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;
    private AgentBranch $busy;
    private AgentBranch $idle;
    private AgentStaff $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = new User();
        $this->company->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967771900001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');
        $this->busy = $this->makeBranch('MKL', 'فرع المكلا');
        $this->idle = $this->makeBranch('SHT', 'فرع سيحوت');

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'محمد علي', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->busy->id, 'password' => 'teller12',
        ]);
    }

    private function makeBranch(string $code, string $name): AgentBranch
    {
        $bu = new User();
        $bu->forceFill([
            'f_name' => $name, 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '9677719' . random_int(10000, 99999),
            'password' => Hash::make('secret123'), 'is_active' => 1,
            'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
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

    private function customer(): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '96777290' . random_int(1000, 9999),
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => '0']);

        return $c;
    }

    private function work(string $deposit, string $varianceDelta = '0'): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '200000');

        app(AgentCounterService::class)->deposit(
            $this->busy, $this->customer(), $deposit,
            $this->busy->account, 'إيداع', $shift->fresh(),
        );

        $s = $shift->fresh();
        app(AgentShiftService::class)->close(
            $this->teller, $s, bcadd($s->expectedCash(), $varianceDelta, 4),
            bccomp($varianceDelta, '0', 4) === 0 ? '' : 'فرقٌ يُراجَع من الإدارة',
        );
    }

    private function report(): array
    {
        return app(AgentReportsService::class)->full(
            $this->company, now()->subDays(6)->toDateString(), now()->toDateString(),
        );
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_report_carries_every_section(): void
    {
        $this->work('100000');
        $r = $this->report();

        foreach (['period', 'agent', 'summary', 'daily', 'by_branch', 'by_staff',
                  'variances', 'settlements', 'generated_at'] as $k) {
            $this->assertArrayHasKey($k, $r, "القسم «{$k}» غائبٌ عن التقرير");
        }
    }

    /**
     * @test
     *
     * **الأيّام الصامتة تُرسم.** رسمٌ يقفز فوقها يجعل أسبوعاً بلا عملٍ يبدو
     * خطّاً متّصلاً صاعداً — والعين تصدّق الخطّ لا الجدول.
     */
    public function the_daily_series_includes_the_silent_days(): void
    {
        $this->work('100000');
        $r = $this->report();

        $this->assertCount(7, $r['daily']);
        $this->assertSame(7, $r['period']['days']);

        $zero = collect($r['daily'])->firstWhere('count', 0);
        $this->assertNotNull($zero, 'لا يوم صامتٌ في السلسلة — الأيّام الفارغة أُسقطت');
        $this->assertSame('0.0000', bcadd($zero['volume'], '0', 4));
    }

    /**
     * @test
     *
     * **فرعٌ لم يعمل ليس فرعاً حصيلتُه صفر — هو فرعٌ متوقّف.**
     */
    public function an_idle_branch_is_named_not_shown_as_a_zero(): void
    {
        $this->work('100000');
        $r = $this->report();

        $busy = collect($r['by_branch'])->firstWhere('code', 'MKL');
        $idle = collect($r['by_branch'])->firstWhere('code', 'SHT');

        $this->assertFalse($busy['idle']);
        $this->assertTrue($idle['idle']);
        $this->assertSame('100000.0000', bcadd($busy['deposits'], '0', 4));
    }

    /**
     * @test
     *
     * **النموّ من صفرٍ ليس «١٠٠٪».** لا أساس يُقاس عليه، فتُقال «جديد» ولا
     * تُخترع نسبة.
     */
    public function growth_from_nothing_is_not_a_percentage(): void
    {
        $this->work('100000');
        $r = $this->report();

        $this->assertNull($r['summary']['volume']['change_pct']);
        $this->assertTrue($r['summary']['volume']['is_new']);
        $this->assertSame('0.0000', bcadd($r['summary']['volume']['previous'], '0', 4));
    }

    /** @test */
    public function the_comparison_period_is_exactly_as_long_as_the_period(): void
    {
        $r = $this->report();

        $days = (int) \Illuminate\Support\Carbon::parse($r['period']['prev_from'])
            ->diffInDays(\Illuminate\Support\Carbon::parse($r['period']['prev_to'])) + 1;

        // وطولُ الفترة عددٌ صحيح — لا «7.999999999988426» تُطبَع على ورقة.
        $this->assertIsInt($r['period']['days']);
        $this->assertSame($r['period']['days'], $days);
    }

    /**
     * @test
     *
     * العجز والفائض في التقرير أيضاً: منفصلَين، ولا يُقاصّان.
     */
    public function shortages_and_overages_stay_apart_in_the_report(): void
    {
        $this->work('100000', '-5000');
        $this->work('100000', '5000');

        $r = $this->report();

        $this->assertSame(1, $r['variances']['shortage_count']);
        $this->assertSame('5000.0000', bcadd($r['variances']['shortage_total'], '0', 4));
        $this->assertSame(1, $r['variances']['overage_count']);
        $this->assertSame('5000.0000', bcadd($r['variances']['overage_total'], '0', 4));
        $this->assertCount(2, $r['variances']['rows']);
    }

    /** @test */
    public function staff_rows_carry_accuracy_and_volumes(): void
    {
        $this->work('100000');
        $this->work('50000', '-2000');

        $r = $this->report();

        $this->assertCount(1, $r['by_staff']);
        $row = $r['by_staff'][0];

        $this->assertSame('محمد علي', $row['name']);
        $this->assertSame('150000.0000', bcadd($row['volume'], '0', 4));
        $this->assertSame(2, $row['shifts_closed']);
        $this->assertSame(50.0, $row['accuracy_pct']);
        $this->assertSame(1, $row['shortage_count']);
    }

    /** @test */
    public function the_endpoint_is_reachable_for_head_office(): void
    {
        $this->work('100000');

        $j = $this->actingAs($this->hq, 'agent_staff')
            ->getJson(route('agent.reports', [
                'from' => now()->subDays(6)->toDateString(),
                'to' => now()->toDateString(),
            ]))->assertOk()->json('meta.report');

        $this->assertSame('البسيري للصرافة', $j['agent']['name']);
        $this->assertNotEmpty($j['daily']);
    }

    /**
     * @test
     *
     * مديرُ الفرع يرى فرعه وحده — والتقرير لا يستثنى من حدود الرؤية.
     */
    public function a_branch_manager_sees_only_their_own_branch(): void
    {
        $this->work('100000');

        $manager = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير سيحوت', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->idle->id, 'password' => 'manager1',
        ]);

        $j = $this->actingAs($manager, 'agent_staff')
            ->getJson(route('agent.reports'))->assertOk()->json('meta.report');

        $codes = array_column($j['by_branch'], 'code');
        $this->assertSame(['SHT'], $codes);
        $this->assertSame('0.0000', bcadd($j['summary']['volume']['value'], '0', 4));
    }
}
