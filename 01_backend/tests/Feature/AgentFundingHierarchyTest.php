<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentBranchService;
use App\Services\AgentNetworkService;
use App\Services\AgentSettlementEngine;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-FUNDING-HIERARCHY-001 — للتمويل طبقتان، ولكلٍّ طرفاها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **المنصّة تموّل الوكيل الأمّ وحده. والوكيل يوزّع على فروعه.**
 *
 *      المنصّة ──topup/payout──► الوكيل الأمّ ──fundBranch──► الفرع
 *
 * فالتسوية الأولى بين المنصّة والوكيل، والثانية بين الوكيل وفروعه. وليس
 * بينهما طريقٌ ثالث.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا التمويل المباشر للفرع خطأٌ حسابيّ لا خطأ ترتيب:**
 *
 * محرّك التسوية يقرأ الوكيل وفروعه **كتلةً واحدة** — محافظ الفروع ضمن
 * «الفعليّ» عند الأمّ، لأنّ ما بينهما نقلٌ داخليّ لا يغيّر ما تدين به
 * المنصّة. أمّا «المتوقَّع» فيُبنى من تسويات الأمّ وحده.
 *
 * فلو موّلت المنصّةُ الفرعَ مباشرةً:
 *
 *   • الفعليّ يرتفع  (محفظة الفرع داخل الكتلة)
 *   • المتوقَّع لا يرتفع (التسوية مسجَّلةٌ باسم الفرع لا الأمّ)
 *   • ⇒ **فائضٌ وهميّ دائم** عند الأمّ لا يُفسَّر ولا يُصحَّح
 *
 * وأسوأ من الرقم أنّ المسحَ الشبكيّ يستثني حسابات الفروع، فالتسويةُ نفسها
 * لا تظهر في أيّ شاشة. مالٌ خرج ولا سطر يحمله.
 *
 * وهذا ما يقيسه الاختبار الأخير هنا بالأرقام.
 */
class AgentFundingHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $company;
    private AgentBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770008800',
        ]);
        EMoney::updateOrCreate(['user_id' => $this->admin->id], ['current_balance' => '5000000']);

        $this->company = new User();
        $this->company->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967771500001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);

        $this->branch = app(AgentBranchService::class)->create($this->company, [
            'name' => 'فرع المكلا', 'code' => 'MKL', 'phone' => '967771500099',
            'city' => 'المكلا', 'password' => 'branch123',
        ]);
    }

    private function branchUser(): User
    {
        return User::findOrFail($this->branch->branch_user_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // الطبقة الأولى: المنصّة ← الوكيل الأمّ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_platform_funds_the_parent_agent(): void
    {
        $settlement = app(AgentNetworkService::class)
            ->adminCreditAgent($this->company, '100000', $this->admin, 'REF-1');

        $this->assertSame('completed', $settlement->status);
        $this->assertSame(
            '100000.0000',
            bcadd((string) EMoney::where('user_id', $this->company->id)->value('current_balance'), '0', 4),
        );
    }

    /**
     * @test
     *
     * الرفض في **الخدمة** لا في الشاشة: معرّفٌ يأتي من المتصفّح يمكن
     * تغييره، وما لا يُقبل في الخدمة لا يحتاج إخفاءً في الواجهة.
     */
    public function the_platform_refuses_to_fund_a_branch_directly(): void
    {
        $this->expectExceptionMessageMatches('/البسيري/');

        app(AgentNetworkService::class)
            ->adminCreditAgent($this->branchUser(), '50000', $this->admin, 'REF-2');
    }

    /** @test */
    public function the_refusal_names_the_parent_so_the_admin_knows_where_to_send_it(): void
    {
        try {
            app(AgentNetworkService::class)
                ->adminCreditAgent($this->branchUser(), '50000', $this->admin);
            $this->fail('قُبل تمويل الفرع مباشرةً من المنصّة');
        } catch (\Throwable $e) {
            // اسم الأمّ في الرسالة: الرفض بلا وجهةٍ بديلة يُوقف العمل.
            $this->assertStringContainsString('البسيري', $e->getMessage());
        }

        // ولا يُترك أثر: لا تسوية ولا رصيد.
        $this->assertSame(0, DB::table('agent_settlements')
            ->where('agent_user_id', $this->branch->branch_user_id)->count());
        $this->assertSame(
            '0.0000',
            bcadd((string) EMoney::where('user_id', $this->branch->branch_user_id)->value('current_balance'), '0', 4),
        );
    }

    /** @test */
    public function a_branch_cannot_ask_the_platform_to_cash_it_out(): void
    {
        // للفرع رصيدٌ فعليّ — فالرفض ليس لأنّه فارغ.
        EMoney::where('user_id', $this->branch->branch_user_id)->update(['current_balance' => '80000']);

        $this->expectExceptionMessageMatches('/البسيري/');

        app(AgentNetworkService::class)->requestPayout($this->branchUser(), '10000');
    }

    // ══════════════════════════════════════════════════════════════════
    // نقاط النهاية — الطبقة التي يمسّها المستعمل
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_hub_credit_endpoint_refuses_a_branch(): void
    {
        $r = $this->actingAs($this->admin, 'user')
            ->postJson(route('admin.amial.hub.agents.credit', ['id' => $this->branch->branch_user_id]),
                ['amount' => '50000'])
            ->assertStatus(422);

        $this->assertStringContainsString('البسيري', (string) $r->json('message'));

        $this->assertSame('0.0000', bcadd(
            (string) EMoney::where('user_id', $this->branch->branch_user_id)->value('current_balance'), '0', 4));
    }

    /**
     * @test
     *
     * البابُ الخلفيّ: التحويل العامّ يقبل أيّ `to_user_id`. فلو أُغلق مسار
     * الشحن وحده لبقي هذا مفتوحاً — ونفسُ المال يصل بنفس الأثر.
     */
    public function the_generic_admin_transfer_refuses_any_agent_network_account(): void
    {
        foreach ([$this->branch->branch_user_id, $this->company->id] as $target) {
            $this->actingAs($this->admin, 'user')
                ->postJson(route('admin.amial.hub.transfer'), ['to_user_id' => $target, 'amount' => '1000'])
                ->assertStatus(422);
        }

        $this->assertSame('0.0000', bcadd(
            (string) EMoney::where('user_id', $this->company->id)->value('current_balance'), '0', 4));
    }

    // ══════════════════════════════════════════════════════════════════
    // الطبقة الثانية: الوكيل ← فروعه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_parent_agent_funds_its_own_branch(): void
    {
        app(AgentNetworkService::class)->adminCreditAgent($this->company, '100000', $this->admin);

        $out = app(AgentBranchService::class)
            ->fundBranch($this->branch, $this->company, '40000', 'تمويل افتتاحيّ');

        $this->assertSame('40000.0000', bcadd($out['branch_balance'], '0', 4));
        $this->assertSame('60000.0000', bcadd(
            (string) EMoney::where('user_id', $this->company->id)->value('current_balance'), '0', 4));
    }

    /**
     * @test
     *
     * **الاتّجاه المعاكس — وهو نفسُ النقص الذي وقع في الطبقة الأولى حين كان
     * `payout` غائباً.** فرعٌ يخدم السحوبات يمتلئ رصيدُه ويفرغ درجُه، وبلا
     * مسار عودةٍ يبقى الرصيد حبيساً عنده: لا يُوزَّع على فرعٍ يحتاجه، ولا
     * يُعاد إلى المنصّة — لأنّ طلب الصرف يُقدَّم من محفظة الأمّ وحدها.
     */
    public function the_parent_agent_collects_float_back_from_its_branch(): void
    {
        app(AgentNetworkService::class)->adminCreditAgent($this->company, '100000', $this->admin);
        app(AgentBranchService::class)->fundBranch($this->branch, $this->company, '40000', 'تمويل افتتاحيّ');

        $out = app(AgentBranchService::class)
            ->collectFromBranch($this->branch, $this->company, '15000', 'سحب فائض');

        $this->assertSame('25000.0000', bcadd($out['branch_balance'], '0', 4));
        $this->assertSame('75000.0000', bcadd($out['company_balance'], '0', 4));

        // وتظلّ الكتلة متوازنة: النقل داخليّ ولا يغيّر ما تدين به المنصّة.
        $p = app(AgentSettlementEngine::class)->position($this->company);
        $this->assertSame('0.0000', bcadd($p['float']['difference'], '0', 4));
    }

    /** @test */
    public function collecting_more_than_the_branch_holds_is_refused(): void
    {
        app(AgentNetworkService::class)->adminCreditAgent($this->company, '100000', $this->admin);
        app(AgentBranchService::class)->fundBranch($this->branch, $this->company, '10000', 'تمويل');

        $this->expectExceptionMessage('ولا يكفي لسحب');
        app(AgentBranchService::class)->collectFromBranch($this->branch, $this->company, '11000', 'محاولة');
    }

    /**
     * @test
     *
     * كشفُ الطبقة الثانية يُحسب من **سطور الدفتر** لا من الرصيد الحاليّ:
     * الرصيد الحاليّ لا يقول شيئاً عن الحركة التي أوصلته إليه. فبعد شحن
     * ٤٠ ألفاً وسحب ١٥، الرصيد ٢٥ — والكشف يقول ٤٠ سُلِّمت و١٥ استُردّت.
     */
    public function the_branch_statement_reads_the_movement_not_the_balance(): void
    {
        app(AgentNetworkService::class)->adminCreditAgent($this->company, '100000', $this->admin);
        app(AgentBranchService::class)->fundBranch($this->branch, $this->company, '40000', 'تمويل');
        app(AgentBranchService::class)->collectFromBranch($this->branch, $this->company, '15000', 'سحب');

        $rows = app(AgentBranchService::class)->settlementWithBranches($this->company);

        $this->assertCount(1, $rows);
        $this->assertSame('40000.0000', bcadd($rows[0]['float_given'], '0', 4));
        $this->assertSame('15000.0000', bcadd($rows[0]['float_returned'], '0', 4));
        $this->assertSame('25000.0000', bcadd($rows[0]['float_net'], '0', 4));
        $this->assertSame('25000.0000', bcadd($rows[0]['emoney_balance'], '0', 4));
    }

    /** @test */
    public function an_agent_cannot_fund_a_branch_that_is_not_its_own(): void
    {
        $other = new User();
        $other->forceFill([
            'f_name' => 'وكيل', 'l_name' => 'آخر', 'phone' => '967771500777',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'), 'is_active' => 1,
        ])->save();
        EMoney::create(['user_id' => $other->id, 'current_balance' => '900000']);

        $this->expectExceptionMessage('الشحن من حساب الشركة الأمّ وحده');
        app(AgentBranchService::class)->fundBranch($this->branch, $other, '1000', 'محاولة');
    }

    /**
     * @test
     *
     * **المال يخرج من محفظة الشركة، فالقرار قرارُ إدارتها.** ومديرُ فرعٍ
     * كان يستطيع سحب سيولة الشركة إلى فرعه بنفسه: فرعُه ضمن فروعه
     * المسموحة، والخدمة تفحص أبوّة الفرع لا رتبةَ الطالب.
     */
    public function a_branch_manager_cannot_move_the_company_float(): void
    {
        $hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');
        $manager = app(AgentStaffService::class)->hire($hq, [
            'name' => 'مدير المكلا', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->branch->id, 'password' => 'manager1',
        ]);

        app(AgentNetworkService::class)->adminCreditAgent($this->company, '100000', $this->admin);

        $this->actingAs($manager, 'agent_staff')
            ->postJson(route('agent.branch.fund', ['id' => $this->branch->id]),
                ['amount' => '10000', 'note' => 'محاولة تمويل'])
            ->assertStatus(403);

        $this->actingAs($manager, 'agent_staff')
            ->postJson(route('agent.branch.collect', ['id' => $this->branch->id]),
                ['amount' => '1000', 'note' => 'محاولة سحب'])
            ->assertStatus(403);

        // ولا ريال تحرّك.
        $this->assertSame('100000.0000', bcadd(
            (string) EMoney::where('user_id', $this->company->id)->value('current_balance'), '0', 4));

        // والإدارة العامّة تفعلها.
        $this->actingAs($hq, 'agent_staff')
            ->postJson(route('agent.branch.fund', ['id' => $this->branch->id]),
                ['amount' => '10000', 'note' => 'تمويل نظاميّ'])
            ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════
    // الأثر الحسابيّ — لماذا الترتيب ليس شكليّاً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_correct_path_leaves_the_agent_balanced(): void
    {
        app(AgentNetworkService::class)->adminCreditAgent($this->company, '100000', $this->admin);
        app(AgentBranchService::class)->fundBranch($this->branch, $this->company, '40000', 'توزيع');

        $p = app(AgentSettlementEngine::class)->position($this->company);

        // المال كلّه ما زال داخل كتلة الوكيل: ١٠٠٠٠٠ موزّعةً على محفظتين.
        $this->assertSame('100000.0000', bcadd($p['float']['actual'], '0', 4));
        $this->assertSame('100000.0000', bcadd($p['float']['expected'], '0', 4));
        $this->assertSame('0.0000', bcadd($p['float']['difference'], '0', 4));
        $this->assertSame(AgentSettlementEngine::STATUS_BALANCED, $p['status']);
    }

    /**
     * @test
     *
     * **الإثبات بالعكس.** يُحقن الأثر الذي كان التمويل المباشر يُحدثه —
     * رصيدٌ في محفظة الفرع بلا تسويةٍ باسم الأمّ — ويُتأكَّد أنّ المحرّك
     * يسمّيه فائضاً. فلو لم يظهر لكان الحارس بلا معنى: نمنع شيئاً لا أثر له.
     */
    public function crediting_a_branch_outside_the_parent_shows_as_a_phantom_surplus(): void
    {
        app(AgentNetworkService::class)->adminCreditAgent($this->company, '100000', $this->admin);

        // ما كان يفعله التمويل المباشر: رصيدٌ يدخل محفظة الفرع، والتسوية
        // تُسجَّل باسم الفرع — فلا يراها «متوقَّع» الأمّ.
        EMoney::where('user_id', $this->branch->branch_user_id)->update(['current_balance' => '50000']);

        $p = app(AgentSettlementEngine::class)->position($this->company);

        $this->assertSame('150000.0000', bcadd($p['float']['actual'], '0', 4));
        $this->assertSame('100000.0000', bcadd($p['float']['expected'], '0', 4));
        $this->assertSame('50000.0000', bcadd($p['float']['difference'], '0', 4));
        $this->assertSame(AgentSettlementEngine::STATUS_OVER, $p['status']);
    }
}
