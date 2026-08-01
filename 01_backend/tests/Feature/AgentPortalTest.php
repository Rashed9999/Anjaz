<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use App\Services\AgentBranchService;
use App\Services\AgentCounterService;
use App\Services\AgentTillService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-AGENT-PORTAL-001 — بوّابة الوكيل وفروعه وخزنة النقد.
 *
 * ══════════════════════════════════════════════════════════════
 * **القيد الذي كان غائباً عن النظام كلّه، وأكثر ما يُختبَر هنا:**
 *
 * للوكيل رصيدان يتحرّكان في اتّجاهين **متعاكسين**:
 *   • الرصيد الإلكترونيّ يحدّ **الإيداع** (يمنح العميل من رصيده).
 *   • النقد الورقيّ يحدّ **السحب** (يسلّم العميل أوراقاً من درجه).
 *
 * وكان النظام يتتبّع الأوّل وحده — فيقبل سحباً لأنّ الرصيد الإلكترونيّ
 * **سيزيد** به، ويقف العميل أمام موظّفٍ درجُه فارغ.
 *
 * وليست حالةً نادرة: هي الحالة الطبيعية آخر يومٍ في فرعٍ نشط، حيث السحوبات
 * تفوق الإيداعات فيفرغ الدرج بينما الرصيد الإلكترونيّ يتضخّم.
 * ══════════════════════════════════════════════════════════════
 */
class AgentPortalTest extends TestCase
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
        $staff = app(\App\Services\AgentStaffService::class)->ensurePortalAccount($agent);

        return $this->actingAs($staff, 'agent_staff');
    }

    use RefreshDatabase;

    private User $agent;
    private User $customer;
    private AgentBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->makeAgent('770002201', 'شركة الصرافة');
        $this->customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '770002210',
            'f_name' => 'أحمد', 'l_name' => 'صالح',
            'zone_code' => 'SOUTH', 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $this->customer->id],
            ['current_balance' => '50000', 'zone_code' => 'SOUTH']);

        $this->branch = app(AgentBranchService::class)->create($this->agent, [
            'name' => 'فرع عدن', 'code' => 'ADEN-01',
            'phone' => '770002202', 'password' => 'branch-pass-123',
            'city' => 'عدن',
        ]);

        // الفرع يُموَّل بالمسار الحقيقيّ لا بتحديثٍ مباشر.
        //
        // (وهذا ما كشف نقصاً حقيقياً: رسالةُ رفض الإيداع تقول «اطلب شحن رصيد
        // من الوكيل الأمّ» ولم يكن في النظام مسارٌ يفعل ذلك. فبُني.)
        EMoney::where('user_id', $this->agent->id)->update(['current_balance' => '5000000']);
        app(\App\Services\LedgerService::class)->reconcileWalletBalance(
            $this->agent->id, 'رصيد افتتاحيّ لاختبار البوّابة');

        app(AgentBranchService::class)->fundBranch(
            $this->branch, $this->agent, '1000000', 'رصيد تشغيل افتتاحيّ للفرع');
    }

    private function makeAgent(string $phone, string $name): User
    {
        $u = User::factory()->create([
            'type' => AGENT_TYPE, 'phone' => $phone, 'f_name' => $name,
            'zone_code' => 'SOUTH', 'is_kyc_verified' => 1,
            'password' => Hash::make('agent-pass-123'),
        ]);
        EMoney::updateOrCreate(['user_id' => $u->id],
            ['current_balance' => '0', 'zone_code' => 'SOUTH']);

        return $u->fresh();
    }

    private function till(): AgentTillService
    {
        return app(AgentTillService::class);
    }

    private function counter(): AgentCounterService
    {
        return app(AgentCounterService::class);
    }

    // ══ القيد المزدوج ══════════════════════════════════════════════════

    /** @test */
    public function a_withdrawal_is_refused_when_the_drawer_is_empty_even_with_huge_emoney(): void
    {
        // **هذا هو العطل الذي كان قائماً.** رصيد الفرع الإلكترونيّ مليون،
        // ودرجُه فارغ. والنظام القديم يقبل لأنّ الرصيد سيزيد.
        $this->assertSame(0, bccomp(
            (string) $this->till()->tillFor($this->branch)->cash_on_hand, '0', 4));

        try {
            $this->counter()->withdraw($this->branch, $this->customer, '10000', $this->agent);
            $this->fail('قُبل سحبٌ والدرج فارغ — العميل يقف أمام موظّفٍ لا يملك أوراقاً');
        } catch (DomainException $e) {
            $this->assertStringContainsString('النقد المتوفّر', $e->getMessage());
        }

        // ولم يُمسّ رصيد العميل: الرفض قبل الخصم لا بعده.
        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $this->customer->id)->value('current_balance'),
            '50000', 4), 'خُصم من العميل ثمّ فشلت العملية');
    }

    /** @test */
    public function a_deposit_is_refused_when_the_branch_has_no_emoney_even_with_a_full_drawer(): void
    {
        // القيد المقابل: الدرج ممتلئ والرصيد الإلكترونيّ صفر. والإيداع يمنح
        // العميل رصيداً من رصيد الفرع — فلا يقع.
        $this->till()->record($this->branch, 'in', 'opening', '5000000', $this->agent);
        EMoney::where('user_id', $this->branch->branch_user_id)->update(['current_balance' => '0']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/رصيد الفرع الإلكترونيّ/');

        $this->counter()->deposit($this->branch, $this->customer, '10000', $this->agent);
    }

    /** @test */
    public function a_deposit_moves_all_three_balances_in_one_transaction(): void
    {
        $this->till()->record($this->branch, 'in', 'opening', '100000', $this->agent);

        $out = $this->counter()->deposit($this->branch, $this->customer, '20000', $this->agent);

        // ١) رصيد الفرع الإلكترونيّ نقص
        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $this->branch->branch_user_id)->value('current_balance'),
            '980000', 4), 'لم يُخصم من رصيد الفرع الإلكترونيّ');

        // ٢) رصيد العميل زاد
        $this->assertSame(0, bccomp($out['customer_balance'], '70000', 4));

        // ٣) نقد الدرج زاد — الطرف الذي كان غائباً
        $this->assertSame(0, bccomp(
            (string) $this->till()->tillFor($this->branch)->cash_on_hand, '120000', 4),
            'لم يُسجَّل دخول النقد إلى الدرج — فيبدو فارغاً وهو ممتلئ');
    }

    /** @test */
    public function a_withdrawal_moves_all_three_balances_the_other_way(): void
    {
        $this->till()->record($this->branch, 'in', 'opening', '100000', $this->agent);

        $this->counter()->withdraw($this->branch, $this->customer, '15000', $this->agent);

        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $this->customer->id)->value('current_balance'),
            '35000', 4));
        $this->assertSame(0, bccomp(
            (string) EMoney::where('user_id', $this->branch->branch_user_id)->value('current_balance'),
            '1015000', 4));
        $this->assertSame(0, bccomp(
            (string) $this->till()->tillFor($this->branch)->cash_on_hand, '85000', 4),
            'لم يُسجَّل خروج النقد — فيبدو الدرج ممتلئاً وقد دُفع منه');
    }

    /** @test */
    public function both_counter_operations_post_to_the_ledger(): void
    {
        // مالٌ يتحرّك بلا قيدٍ محاسبيّ هو العطل الذي قُضيت الجلسة في إصلاحه.
        $this->till()->record($this->branch, 'in', 'opening', '100000', $this->agent);

        $this->counter()->deposit($this->branch, $this->customer, '10000', $this->agent);
        $this->counter()->withdraw($this->branch, $this->customer, '5000', $this->agent);

        $this->assertSame(1, LedgerJournalEntry::where('source_type', 'agent_deposit')->count(),
            'إيداعٌ بلا قيد في الدفتر');
        $this->assertSame(1, LedgerJournalEntry::where('source_type', 'agent_withdraw')->count(),
            'سحبٌ بلا قيد في الدفتر');
    }

    // ══ الخزنة ═════════════════════════════════════════════════════════

    /** @test */
    public function cash_can_never_go_negative(): void
    {
        // ورقةٌ لا تُدفع من العدم. ولو سُمح بذلك لصار الجرد يوازن حساباً
        // وهمياً بدل أن يكشف عجزاً.
        $this->till()->record($this->branch, 'in', 'opening', '1000', $this->agent);

        $this->expectException(DomainException::class);
        $this->till()->record($this->branch, 'out', 'treasury_out', '5000', $this->agent);
    }

    /** @test */
    public function a_count_difference_is_recorded_as_a_movement_not_swallowed(): void
    {
        // لو كُتب الرقم المعدود فوق الرصيد مباشرةً لاختفى العجز بلا أثر —
        // وهو أوّل ما يُبحث عنه في أيّ تحقيق.
        $this->till()->record($this->branch, 'in', 'opening', '100000', $this->agent);

        $out = $this->till()->count($this->branch, $this->agent, '97000',
            'عُدّ الدرج بحضور مدير الفرع ووُجد نقصٌ يُراجَع');

        $this->assertFalse($out['balanced']);
        $this->assertSame(0, bccomp($out['difference'], '-3000', 4));

        $adj = AgentCashMovement::where('branch_id', $this->branch->id)
            ->where('reason', 'count_adjustment')->first();

        $this->assertNotNull($adj, 'فرقُ الجرد ابتُلع بلا حركة');
        $this->assertSame('out', $adj->direction);
        $this->assertSame(0, bccomp((string) $adj->amount, '3000', 4));

        // والرصيد صار المعدود.
        $this->assertSame(0, bccomp(
            (string) $this->till()->tillFor($this->branch)->cash_on_hand, '97000', 4));
    }

    /** @test */
    public function a_count_difference_is_audited_as_critical(): void
    {
        $this->till()->record($this->branch, 'in', 'opening', '50000', $this->agent);
        $this->till()->count($this->branch, $this->agent, '49000',
            'نقصٌ في الجرد اليوميّ يحتاج مراجعة كاميرات الفرع');

        $row = DB::table('audit_decisions')
            ->where('action', 'AGENT_TILL_COUNT_DIFF')->orderByDesc('id')->first();

        $this->assertNotNull($row, 'فرقُ جردٍ نقديّ بلا أثر في التدقيق');
        $this->assertSame('critical', (string) $row->severity);
    }

    /** @test */
    public function a_count_without_an_explanation_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->till()->count($this->branch, $this->agent, '1000', 'عدّ');
    }

    /** @test */
    public function the_summary_never_merges_cash_with_emoney(): void
    {
        // رقمان من جنسين مختلفين. وجمعُهما يُنتج رقماً بلا معنى، وعرضُ
        // الأكبر يعِد بما لا يُوفى.
        $this->till()->record($this->branch, 'in', 'opening', '30000', $this->agent);

        $s = $this->till()->summary($this->branch);

        $this->assertSame(0, bccomp($s['effective_payout_capacity'], '30000', 4),
            'قدرة الدفع النقديّ ليست نقد الدرج');
        $this->assertSame(0, bccomp($s['effective_deposit_capacity'], '1000000', 4),
            'قدرة الإيداع ليست الرصيد الإلكترونيّ');
    }

    // ══ الفروع ═════════════════════════════════════════════════════════

    /** @test */
    public function a_branch_is_an_agent_account_with_a_parent(): void
    {
        // الفرع يفعل ما يفعله الوكيل ويحتاج ما يحتاجه — فيُعاد استعمال
        // الحساب بدل جدولٍ موازٍ ينحرف.
        $account = User::find($this->branch->branch_user_id);

        $this->assertSame(AGENT_TYPE, (int) $account->type);
        $this->assertNotNull(EMoney::where('user_id', $account->id)->first(),
            'فرعٌ بلا محفظة — يُقبل في الشاشة ويسقط عند أوّل عملية');

        $this->assertSame($this->agent->id, (int) DB::table('agent_profiles')
            ->where('user_id', $account->id)->value('parent_agent_id'));
    }

    /** @test */
    public function a_duplicate_branch_code_is_refused(): void
    {
        $this->expectException(DomainException::class);
        app(AgentBranchService::class)->create($this->agent, [
            'name' => 'فرع آخر', 'code' => 'ADEN-01',
            'phone' => '770002299', 'password' => 'x-pass-1234',
        ]);
    }

    // ══ الحصر بين الوكلاء ══════════════════════════════════════════════

    /** @test */
    public function one_exchange_company_cannot_touch_another_ones_branch(): void
    {
        // شركةُ صرافةٍ ترى خزنة منافستها كارثةٌ تجارية قبل أن تكون خرقاً
        // أمنياً. والمعرّف يأتي من المتصفّح — فلا يُوثَق به.
        $rival = $this->makeAgent('770002250', 'صرافة منافسة');

        $this->portalAs($rival)
            ->getJson("/agent/branches/{$this->branch->id}/till")
            ->assertStatus(403);

        // ولم يعد هناك مسارُ سحبٍ يقبل معرّف فرعٍ أصلاً: صار الفرع يأتي من
        // ورديّة الداخل. وما لا يُقبل من الطلب لا يحتاج فحص ملكيّة.
        $this->assertFalse(
            collect(\Illuminate\Support\Facades\Route::getRoutes())
                ->contains(fn ($r) => str_contains($r->uri(), 'agent/branches')
                    && (str_contains($r->uri(), 'withdraw') || str_contains($r->uri(), 'deposit'))),
            'عاد مسارُ شبّاكٍ يقبل معرّف فرعٍ من المتصفّح',
        );
    }

    /** @test */
    public function a_branch_account_sees_only_itself(): void
    {
        $branchUser = User::find($this->branch->branch_user_id);

        $ids = $this->portalAs($branchUser)
            ->getJson('/agent/overview')->assertOk()
            ->json('meta.branches');

        // حساب الفرع يرى فرعه هو — لا بقيّة فروع الشركة.
        $this->assertCount(1, $ids);
        $this->assertSame($this->branch->id, $ids[0]['id']);
    }

    /** @test */
    public function a_branch_cannot_create_branches(): void
    {
        // التسلسل مستوىً واحد: فروعُ الفروع تُضيّع مسؤولية النقد.
        $branchUser = User::find($this->branch->branch_user_id);

        $this->portalAs($branchUser)
            ->postJson('/agent/branches', [
                'name' => 'فرع فرعيّ', 'code' => 'X-1',
                'phone' => '770002298', 'password' => 'pass-12345',
            ])->assertStatus(403);
    }

    // ══ البوّابة ═══════════════════════════════════════════════════════

    /** @test */
    public function the_portal_is_closed_to_non_agents(): void
    {
        $customer = $this->customer;

        // بلا جلسة: البوّابة تردّ إلى صفحة الدخول.
        $this->get('/agent')->assertRedirect(route('agent.login'));

        // ولا يُفتح حساب بوّابةٍ لعميل — لا من الدخول ولا من الخدمة.
        try {
            app(\App\Services\AgentStaffService::class)->ensurePortalAccount($customer);
            $this->fail('فُتح حساب بوّابةٍ لعميل');
        } catch (DomainException $e) {
            $this->assertStringContainsString('للوكلاء', $e->getMessage());
        }

        // والمدير كذلك: البوّابة ليست امتداداً للوحة الإدارة.
        $admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770002260',
        ]);

        try {
            app(\App\Services\AgentStaffService::class)->ensurePortalAccount($admin);
            $this->fail('فُتح حساب بوّابةٍ لمدير المنصّة');
        } catch (DomainException $e) {
            $this->assertStringContainsString('للوكلاء', $e->getMessage());
        }

        // ولو دخل المدير بحساب لوحة الإدارة، لا تقبله البوّابة: حارسها آخر.
        $this->actingAs($admin, 'user')
            ->get('/agent')->assertRedirect(route('agent.login'));
    }

    /** @test */
    public function the_login_page_opens_and_the_dashboard_needs_a_session(): void
    {
        $this->get('/agent/login')->assertOk()->assertSee('بوّابة الوكيل', false);
        $this->get('/agent')->assertRedirect('/agent/login');
    }

    /** @test */
    public function an_agent_logs_in_with_phone_and_password(): void
    {
        $this->post('/agent/login', [
            'username' => $this->agent->phone, 'password' => 'agent-pass-123',
        ])->assertRedirect('/agent');

        // الجلسة تُفتح على حارس البوّابة لا على حارس لوحة الإدارة — وهذا
        // ما يمنع تعطُّل اللوحة بحلقة إعادة توجيه.
        $this->assertAuthenticated('agent_staff');
        $this->assertGuest('user');
    }

    /** @test */
    public function a_wrong_password_does_not_reveal_whether_the_phone_exists(): void
    {
        // رسالتان مختلفتان تُخبران من يجرّب أنّ الرقم صحيح، فيبقى له كلمة
        // السرّ وحدها.
        //
        // (وكان هنا `assertSame($x, $x)` — يقارن التعبير بنفسه فيمرّ مهما
        // اختلفت الرسالتان. تأكيدٌ يمرّ دائماً أسوأ من غيابه: يُطمئن ولا
        // يحرس. فتُلتقط الرسالتان فعلاً وتُقارنان.)
        $this->post('/agent/login', ['username' => $this->agent->phone, 'password' => 'wrong'])
            ->assertSessionHasErrors('username');
        $known = session()->get('errors')->first('username');

        session()->forget('errors');

        $this->post('/agent/login', ['username' => '779999999', 'password' => 'wrong'])
            ->assertSessionHasErrors('username');
        $unknown = session()->get('errors')->first('username');

        $this->assertNotSame('', $known);
        $this->assertSame($known, $unknown,
            'الرسالة تختلف بين رقمٍ مسجَّل وآخر غير مسجَّل — فتُفشي أيّهما صحيح');
    }

    // ══ حماية العميل ═══════════════════════════════════════════════════

    /** @test */
    public function a_frozen_customer_is_refused_at_the_counter(): void
    {
        // المنع يقع عند المال لا عند الشاشة: موظّف الشبّاك قد لا يقرأ
        // التحذير.
        $this->till()->record($this->branch, 'in', 'opening', '100000', $this->agent);
        $this->customer->forceFill(['is_temp_blocked' => 1])->save();

        $this->expectException(DomainException::class);
        $this->counter()->withdraw($this->branch, $this->customer->fresh(), '1000', $this->agent);
    }

    /** @test */
    public function the_counter_search_does_not_leak_the_customer_balance(): void
    {
        // موظّف الشبّاك يحتاج أن يعرف من أمامه وهل يُخدَم — لا كم يملك.
        // وعرضُ الرصيد يجعل كلّ موظّفٍ يرى ثروة كلّ من يمرّ به.
        $body = $this->portalAs($this->agent)
            ->getJson('/agent/counter/customer?phone=' . $this->customer->phone)
            ->assertOk()->json('meta.customer');

        $this->assertArrayNotHasKey('balance', $body);
        $this->assertArrayNotHasKey('current_balance', $body);
        $this->assertArrayHasKey('can_transact', $body);
    }
}
