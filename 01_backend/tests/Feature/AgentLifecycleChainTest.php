<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\OpensAccountsFromHub;
use Tests\TestCase;

/**
 * AMIAL-AGENT-CHAIN-001 — السلسلة كاملةً، من إنشاء الوكيل إلى إيداع العميل.
 *
 * **لماذا اختبارٌ واحدٌ طويلٌ بدل خمسة قصيرة.** كلّ حلقةٍ من هذه السلسلة
 * مُختبَرةٌ وحدها ومارّة. وما لم يكن مُختبَراً قطّ هو **الوصلات بينها**:
 *
 *   أميال تُنشئ وكيلاً  →  هل يستطيع الوكيل الدخول إلى بوّابته؟
 *   الوكيل يُنشئ فرعاً  →  هل يصير للفرع محفظةٌ وخزنة؟
 *   الوكيل يُعيّن صرّافاً →  هل يستطيع الصرّاف الدخول برمزه؟
 *   الصرّاف يفتح ورديّة →  هل يستطيع خدمة عميل؟
 *
 * وهذا بالضبط شكلُ العطل المتكرّر في هذا المشروع: كلّ قطعةٍ تعمل وحدها،
 * ولا أحد جرّب المسار من طرفه إلى طرفه. فيُبنى كلّ شيءٍ ولا يعمل شيء.
 */
class AgentLifecycleChainTest extends TestCase
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

    use OpensAccountsFromHub;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770005500',
        ]);

        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');
        DB::table('admin_user_roles')->updateOrInsert(
            ['user_id' => $this->admin->id, 'role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()],
        );
    }

    /** @test */
    public function amial_creates_an_agent_who_opens_a_branch_hires_a_teller_and_serves_a_customer(): void
    {
        // ══ ١ ══ أميال تُنشئ الوكيل من «مركز الوكلاء» ══════════════════
        $this->actingAs($this->admin, 'user')
            ->postJson(route('admin.amial.hub.users.store', ['slug' => 'agents']), [
                'f_name' => 'العمقي', 'l_name' => 'للصرافة',
                'phone' => '967771600001', 'password' => 'agent-pass-1',
            ] + $this->kycDossier('967771600001'))->assertCreated();

        $agent = User::where('phone', '967771600001')->first();

        $this->assertNotNull($agent, 'الوكيل لم يُنشأ');
        $this->assertSame(AGENT_TYPE, (int) $agent->type);
        // بلا محفظةٍ لا يستطيع تمويل فرعٍ لاحقاً — والخطأ سيظهر بعد أسبوع.
        $this->assertNotNull(EMoney::where('user_id', $agent->id)->first(),
            'الوكيل أُنشئ بلا محفظة');

        // ══ ٢ ══ الوكيل يدخل بوّابته بهاتفه وكلمة سرّه ══════════════════
        //
        // هذه أوّل وصلةٍ لم تُختبر قطّ: حسابٌ أنشأته لوحةُ الإدارة، وبوّابةٌ
        // مصادقتُها مستقلّة. ولو لم يعمل الدخول لبقي الوكيل بحسابٍ لا يفتح
        // شيئاً — وهو ما لا تكشفه أيّ اختباراتٍ للطرفين منفصلَين.
        $this->post(route('agent.login.submit'), [
            'username' => '967771600001', 'password' => 'agent-pass-1',
        ])->assertRedirect(route('agent.dashboard'));

        // الجلسة على حارس البوّابة لا على حارس لوحة الإدارة: خلطُهما كان
        // يُعطّل اللوحة بحلقة إعادة توجيه لا تنتهي.
        $this->assertAuthenticated('agent_staff');
        $this->assertGuest('user');

        // ولوحته تُفتح ودورُه «الإدارة العامّة» — يرى كلّ فروعه.
        $this->get(route('agent.dashboard'))->assertOk()
            ->assertSee('الموظّفون', false)
            ->assertSee('الفروع', false);

        // ══ ٣ ══ الوكيل يُنشئ فرعاً من بوّابته ═════════════════════════
        $this->postJson(route('agent.branch.create'), [
            'name' => 'فرع المكلا', 'code' => 'MKL', 'city' => 'حضرموت',
            'phone' => '967771600002', 'password' => 'branch-pass-1',
        ])->assertOk();

        $branch = AgentBranch::where('code', 'MKL')->first();

        $this->assertNotNull($branch, 'الفرع لم يُنشأ');
        $this->assertSame($agent->id, (int) $branch->agent_user_id);
        $this->assertNotNull(EMoney::where('user_id', $branch->branch_user_id)->first(),
            'الفرع بلا محفظة — يُقبل في الشاشة ويسقط عند أوّل إيداع');
        $this->assertNotNull($branch->till, 'الفرع بلا خزنة نقد');

        // ══ ٤ ══ أميال تشحن الوكيل، والوكيل يموّل فرعه ═════════════════
        //
        // السيولة تنزل من أعلى: المنصّة → الوكيل → الفرع. وبلا الحلقة
        // الوسطى يبقى الفرع بصفرٍ ولا يستطيع إيداعاً.
        $this->actingAs($this->admin, 'user')
            ->postJson(route('admin.amial.hub.agents.credit', ['id' => $agent->id]), [
                'amount' => '1000000', 'reference' => 'شحن افتتاحيّ',
            ])->assertOk();

        $this->assertSame('1000000.0000',
            (string) EMoney::where('user_id', $agent->id)->value('current_balance'));

        $this->portalAs($agent)
            ->postJson(route('agent.branch.fund', ['id' => $branch->id]), [
                // السبب إلزاميّ في الخادم والشاشة تسأله — شحنُ سيولةٍ بلا
                // سببٍ مكتوب لا يُفسَّر يوم التسوية.
                'amount' => '400000', 'note' => 'تمويل افتتاحيّ لفرع المكلا',
            ])->assertOk();

        $this->assertSame('400000.0000',
            (string) EMoney::where('user_id', $branch->branch_user_id)->value('current_balance'));

        // ══ ٥ ══ الوكيل يُعيّن صرّافاً ══════════════════════════════════
        $hire = $this->portalAs($agent)
            ->postJson(route('agent.staff.store'), [
                'name' => 'أحمد الصرّاف', 'role' => 'teller',
                'branch_id' => $branch->id, 'password' => 'teller-1',
            ])->assertOk();

        $code = $hire->json('username');
        $this->assertSame('MKL-001', $code);

        // والرمز يُعرَض للوكيل ليسلّمه للموظّف — لا يُخبَّأ في جدول.
        $this->assertStringContainsString('MKL-001', $hire->json('message'));

        // ══ ٦ ══ الصرّاف يدخل برمزه ═══════════════════════════════════
        $this->post(route('agent.login.submit'), [
            'username' => $code, 'password' => 'teller-1',
        ])->assertRedirect(route('agent.dashboard'));

        $teller = AgentStaff::where('username', $code)->first();
        $this->assertAuthenticatedAs($teller, 'agent_staff');

        // وفرعُه يأتي من حسابه — لا يُسأل عنه ولا يستطيع اختيار غيره.
        $state = $this->getJson(route('agent.counter.state'))->assertOk()->json();
        $this->assertSame($branch->id, $state['branch']['id']);
        $this->assertSame('افتح ورديّتك لتبدأ العمل', $state['why_not']);

        // ══ ٧ ══ الصرّاف يفتح ورديّته ══════════════════════════════════
        $this->postJson(route('agent.counter.shift.open'), ['opening_float' => '0'])
            ->assertOk();

        // ══ ٨ ══ عميلٌ يودع نقداً على الشبّاك ═══════════════════════════
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '967772600001',
            'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $customer->id], ['current_balance' => '0']);

        $dep = $this->postJson(route('agent.counter.deposit'), [
            'customer_id' => $customer->id, 'amount' => '50000',
        ])->assertOk();

        // ══ ٩ ══ كلّ شيءٍ مسجَّل ═══════════════════════════════════════
        $this->assertSame('50000.0000',
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'),
            'المبلغ لم يصل محفظة العميل');

        $this->assertSame('350000.0000',
            (string) EMoney::where('user_id', $branch->branch_user_id)->value('current_balance'),
            'رصيد الفرع لم يُخصم — المنصّة منحت رصيداً لا يقابله شيء');

        $shift = $teller->openShift();
        $this->assertSame('50000.0000', (string) $shift->cash_on_hand,
            'النقد لم يدخل درج الصرّاف');

        // حركةُ نقدٍ منسوبةٌ إلى صرّافها وورديّته — لا إلى الفرع وحده.
        $mv = AgentCashMovement::where('reason', 'customer_deposit')->latest('id')->first();
        $this->assertNotNull($mv);
        $this->assertSame($teller->id, (int) $mv->staff_id);
        $this->assertSame($shift->id, (int) $mv->shift_id);
        $this->assertSame($customer->id, (int) $mv->customer_user_id);

        // وقيدٌ في دفتر الأستاذ بمرجع العملية.
        $ref = $dep->json('result.reference');
        $this->assertDatabaseHas('ledger_journal_entries', [
            'source_type' => 'agent_deposit', 'source_id' => $ref,
        ]);

        // ══ ١٠ ══ وأميال ترى ذلك كلّه من مركز الوكلاء ══════════════════
        $net = $this->actingAs($this->admin, 'user')
            ->getJson(route('admin.amial.hub.agents.network'))->assertOk()->json();

        $this->assertSame(1, $net['branches']);
        $this->assertSame(1, $net['open_shifts']);
        // نقدُ الدرج جزءٌ من نقد الشبكة — وإلّا بدا فرعٌ نشِطٌ فارغاً.
        $this->assertSame('50000.0000', $net['cash_on_hand']);

        $rows = $this->actingAs($this->admin, 'user')
            ->getJson(route('admin.amial.hub.agents.movements'))->json('data');

        $this->assertNotEmpty($rows, 'حركة نقد الفرع لا تظهر للإدارة');
        $this->assertSame('إيداع عميل (نقد داخل)', $rows[0]['reason_label']);
    }

    /**
     * @test
     *
     * وكيلٌ أنشأته أميال ولم يُنشئ موظّفيه بعد يجد بوّابته عاملة.
     *
     * هذه الحالة هي الأولى دائماً — لحظةَ يُسلَّم الحساب لشركة الصرافة. ولو
     * تعطّلت لبقي الوكيل عالقاً قبل أن يبدأ، وهي أسوأ لحظةٍ ليتعطّل فيها
     * شيء.
     */
    public function a_brand_new_agent_with_no_staff_yet_still_gets_a_working_portal(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson(route('admin.amial.hub.users.store', ['slug' => 'agents']), [
                'f_name' => 'البسيري', 'l_name' => 'للصرافة',
                'phone' => '967771600009', 'password' => 'agent-pass-9',
            ] + $this->kycDossier('967771600009'))->assertCreated();

        $agent = User::where('phone', '967771600009')->first();

        $this->assertSame(0, AgentStaff::where('agent_user_id', $agent->id)->count(),
            'الافتراض: لا موظّفين بعد');

        $this->portalAs($agent);

        // اللوحة تُفتح…
        $this->get(route('agent.dashboard'))->assertOk();

        // …وتبويب الموظّفين يعمل: يُنشأ له حساب الإدارة العامّة عند الحاجة
        // بدل أن تُغلق الشاشة في وجهه.
        $staff = $this->getJson(route('agent.staff.index'))->assertOk();
        $this->assertTrue($staff->json('can_manage'));

        $this->assertSame(1, AgentStaff::where('agent_user_id', $agent->id)
            ->where('role', AgentStaff::ROLE_HEAD_OFFICE)->count());

        // ولوحة المؤشّرات تُرجع أصفاراً صادقة لا تنهار على شركةٍ بلا فروع.
        $m = $this->getJson(route('agent.overview'))->assertOk()->json('meta');
        $this->assertSame([], $m['branches']);
        $this->assertSame(0, $m['today']['shifts_open_now']);
        $this->assertSame(0, $m['today']['branches_idle'],
            'شركةٌ بلا فروع ليس فيها «فرعٌ متوقّف» — الصفر هنا صحيح لا نقص');
    }
}
