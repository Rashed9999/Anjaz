<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentCounterService;
use App\Services\AgentShiftService;
use App\Services\AgentShiftStatementService;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-SHIFT-STATEMENT-001 — كشفُ تسوية الورديّة يرفعه الصرّاف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأوّل ما يُثبته: أنّ «المتوقَّع» صحيحٌ بلا تزويرٍ في عمود.**
 *
 * كان المتوقَّع يُحسب من ثلاثة أعمدة: `العهدة + الإيداعات − السحوبات`،
 * وهي لا تشمل التوريد أثناء الورديّة. فكان `refill` يُصلح المجموع بأن
 * **يُعدّل العهدة نفسها**: من سحب خمسين ألفاً ثمّ استلم مئةً يُسجَّل أنّه
 * سحب مئةً وخمسين.
 *
 * والمجموع كان يصحّ — فلا فرق وهميّ. **لكنّ العمود يكذب.** ولا يظهر
 * كذبُه حتى يُطلب كشفٌ مفصَّل: فسطرُ «العهدة الافتتاحيّة» يقول ما لم يقع،
 * وسطرُ التوريد يختفي، ولا يُعرف متى دخل المال ولا بيد من. وكشفٌ يُبنى
 * على عمودٍ مزوَّر يُقرأ في تحقيقٍ فيُضلّله.
 *
 * فصارت العهدة واقعةً تاريخيّةً لا تُمَسّ، والمتوقَّع يُحسب من سجلّ
 * الحركة — وهو مصدرٌ يُلحَق ولا يُعدَّل.
 */
class AgentShiftStatementTest extends TestCase
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
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967771700001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');

        $bu = new User();
        $bu->forceFill([
            'f_name' => 'فرع المكلا', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967771700099', 'password' => Hash::make('secret123'),
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

    private function customer(string $phone): User
    {
        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => $phone, 'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => '0']);

        return $c;
    }

    private function deposit(AgentShift $shift, string $amount): void
    {
        app(AgentCounterService::class)->deposit(
            $this->branch, $this->customer('96777260' . random_int(1000, 9999)),
            $amount, $this->branch->account, 'إيداع', $shift,
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // المتوقَّع صار صحيحاً
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **العطل بعينه.** صرّافٌ عهدتُه ٥٠٬٠٠٠ يستلم ١٠٠٬٠٠٠ من الخزنة، فيصير
     * في درجه ١٥٠٬٠٠٠. ويعدّها فيجدها ١٥٠٬٠٠٠ — فالفرق **صفر**.
     *
     * وكان المتوقَّع يبقى ٥٠٬٠٠٠ فيُحتسب فائضاً بمئة ألف.
     */
    public function a_refill_from_the_branch_safe_does_not_create_a_phantom_variance(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '50000');
        app(AgentShiftService::class)->refill($this->teller, $shift, '100000', 'to_drawer');

        $shift = $shift->fresh();

        $this->assertSame('150000.0000', bcadd((string) $shift->cash_on_hand, '0', 4));
        $this->assertSame('150000.0000', bcadd($shift->expectedCash(), '0', 4),
            'المتوقَّع لا يشمل التوريد — الصرّاف سيُتّهم بفائضٍ لم يقع');

        $closed = app(AgentShiftService::class)->close($this->teller, $shift, '150000');

        $this->assertSame('0.0000', bcadd((string) $closed->variance, '0', 4));
        $this->assertSame(AgentShift::REVIEW_BALANCED, $closed->review_status);
    }

    /** @test */
    public function handing_cash_back_to_the_safe_does_not_create_a_phantom_shortage(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '200000');
        app(AgentShiftService::class)->refill($this->teller, $shift, '80000', 'to_safe');

        $shift = $shift->fresh();

        $this->assertSame('120000.0000', bcadd($shift->expectedCash(), '0', 4));

        $closed = app(AgentShiftService::class)->close($this->teller, $shift, '120000');
        $this->assertSame('0.0000', bcadd((string) $closed->variance, '0', 4));
    }

    /**
     * @test
     *
     * **الإثبات بالعكس: العجز الحقيقيّ ما زال يظهر.** حارسٌ يُصلح الفائض
     * الوهميّ بأن يجعل الفرق صفراً دائماً لا يحرس شيئاً.
     */
    public function a_real_shortage_still_shows_after_the_fix(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '50000');
        app(AgentShiftService::class)->refill($this->teller, $shift, '100000', 'to_drawer');
        $this->deposit($shift->fresh(), '30000');

        $shift = $shift->fresh();
        $this->assertSame('180000.0000', bcadd($shift->expectedCash(), '0', 4));

        $closed = app(AgentShiftService::class)->close(
            $this->teller, $shift, '175000', 'نقصٌ في العدّ يُراجَع من الإدارة');

        $this->assertSame('-5000.0000', bcadd((string) $closed->variance, '0', 4));
        $this->assertSame(AgentShift::REVIEW_PENDING, $closed->review_status);
    }

    // ══════════════════════════════════════════════════════════════════
    // الكشف نفسه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_statement_shows_the_whole_chain_not_just_the_result(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '50000');
        app(AgentShiftService::class)->refill($this->teller, $shift, '100000', 'to_drawer');
        $this->deposit($shift->fresh(), '30000');
        $this->deposit($shift->fresh(), '20000');

        $shift = $shift->fresh();
        $closed = app(AgentShiftService::class)->close(
            $this->teller, $shift, '198000', 'فرقٌ يُراجَع من الإدارة');

        $st = app(AgentShiftStatementService::class)->build($closed->fresh());

        $this->assertSame('SHIFT-' . $closed->id, $st['statement_no']);
        $this->assertSame('محمد علي', $st['staff']['name']);
        $this->assertSame('فرع المكلا', $st['branch']['name']);

        // السلسلة: عهدة + إيداعات + توريد
        $labels = array_column($st['cash']['lines'], 'label');
        $this->assertContains('العهدة الافتتاحيّة (من خزنة الفرع)', $labels);
        $this->assertContains('نقدُ إيداعات العملاء', $labels);
        $this->assertContains('توريدٌ من خزنة الفرع إلى الدرج', $labels);

        $this->assertSame('200000.0000', bcadd($st['cash']['expected'], '0', 4));
        $this->assertSame('198000.0000', bcadd($st['cash']['counted'], '0', 4));
        $this->assertSame('-2000.0000', bcadd($st['cash']['variance'], '0', 4));

        // العجز يُسمَّى ولا يُدمج في كلمة «فرق».
        $this->assertSame('shortage', $st['cash']['variance_kind']);

        // وكلّ عمليّةٍ باسم عميلها.
        $this->assertCount(2, $st['operations']);
        $this->assertNotNull($st['operations'][0]['customer']);
        $this->assertNotNull($st['operations'][0]['reference']);

        // والجانب الإلكترونيّ من الدفتر.
        $this->assertTrue(bccomp($st['emoney']['out_on_deposits'], '0', 4) > 0);
    }

    /**
     * @test
     *
     * فحصُ السلامة: طريقان إلى الرقم نفسه. وحين لا يمكن الفحص يُقال ذلك
     * ولا يُدّعى تطابق — «غير معروف» ليس «مطابق».
     */
    public function the_statement_carries_an_integrity_check_and_says_when_it_cannot_run(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '50000');
        $this->deposit($shift->fresh(), '10000');

        $open = app(AgentShiftStatementService::class)->build($shift->fresh());
        $this->assertTrue($open['cash']['integrity']['checkable']);
        $this->assertTrue($open['cash']['integrity']['matches']);

        $closed = app(AgentShiftService::class)->close($this->teller, $shift->fresh(), '60000');
        $after = app(AgentShiftStatementService::class)->build($closed->fresh());

        $this->assertFalse($after['cash']['integrity']['checkable']);
        $this->assertNull($after['cash']['integrity']['matches']);
    }

    // ══════════════════════════════════════════════════════════════════
    // من يرى الكشف
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_teller_sees_the_statements_they_filed(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '50000');
        $this->deposit($shift->fresh(), '10000');
        $closed = app(AgentShiftService::class)->close($this->teller, $shift->fresh(), '60000');

        $rows = $this->actingAs($this->teller, 'agent_staff')
            ->getJson(route('agent.counter.statements'))->assertOk()->json('meta.rows');

        $this->assertCount(1, $rows);
        $this->assertSame('SHIFT-' . $closed->id, $rows[0]['statement_no']);
        $this->assertSame(AgentShift::REVIEW_BALANCED, $rows[0]['review_status']);

        $st = $this->actingAs($this->teller, 'agent_staff')
            ->getJson(route('agent.counter.statement', ['id' => $closed->id]))
            ->assertOk()->json('meta');

        $this->assertSame('60000.0000', bcadd($st['cash']['counted'], '0', 4));
    }

    /** @test */
    public function a_teller_cannot_open_a_colleagues_statement(): void
    {
        $other = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'زميل', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'other123',
        ]);

        $shift = app(AgentShiftService::class)->open($other, '10000');
        app(AgentShiftService::class)->close($other, $shift->fresh(), '10000');

        $this->actingAs($this->teller, 'agent_staff')
            ->getJson(route('agent.counter.statement', ['id' => $shift->id]))
            ->assertStatus(404);

        $this->actingAs($this->teller, 'agent_staff')
            ->getJson(route('agent.staff.shifts.statement', ['id' => $shift->id]))
            ->assertStatus(403);
    }

    /**
     * @test
     *
     * **الإدارة تقرأ نفس المستند حرفيّاً.** ولو بُني لها كشفٌ آخر لصار
     * الخلاف على أيّ الرقمين صحيح بدل الخلاف على الفرق.
     */
    public function management_reads_the_very_same_document(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '50000');
        $this->deposit($shift->fresh(), '10000');
        $closed = app(AgentShiftService::class)->close(
            $this->teller, $shift->fresh(), '57000', 'فرقٌ يُراجَع من الإدارة');

        $mine = $this->actingAs($this->teller, 'agent_staff')
            ->getJson(route('agent.counter.statement', ['id' => $closed->id]))
            ->assertOk()->json('meta');

        $theirs = $this->actingAs($this->hq, 'agent_staff')
            ->getJson(route('agent.staff.shifts.statement', ['id' => $closed->id]))
            ->assertOk()->json('meta');

        $this->assertSame($mine, $theirs);
    }

    /** @test */
    public function the_decision_appears_on_the_statement_the_teller_reads(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '50000');
        $closed = app(AgentShiftService::class)->close(
            $this->teller, $shift->fresh(), '47000', 'نقصٌ لا أعرف سببه');

        $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.staff.shifts.review', ['id' => $closed->id]),
                ['decision' => 'accepted', 'note' => 'خصمٌ من عمولة الشهر بموافقته'])
            ->assertOk();

        $st = $this->actingAs($this->teller, 'agent_staff')
            ->getJson(route('agent.counter.statement', ['id' => $closed->id]))
            ->assertOk()->json('meta.review');

        $this->assertSame(AgentShift::REVIEW_ACCEPTED, $st['status']);
        $this->assertStringContainsString('عمولة الشهر', $st['note']);
        $this->assertNotNull($st['reviewed_by']);
        $this->assertSame('نقصٌ لا أعرف سببه', $st['teller_note']);
    }
}
