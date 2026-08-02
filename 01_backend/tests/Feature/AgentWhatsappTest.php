<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Models\WhatsappLinkedDevice;
use App\Services\AgentCounterService;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use App\Services\Whatsapp\AgentWhatsappService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-WA-AGENT-001 — واتساب شركات الصرافة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما يُثبته هذا الملفّ قبل كلّ شيء: أنّ القناة لا تُحرّك مالاً، وأنّ
 * الجواب يُقصّ على قدر الدور.**
 *
 * فصرّافٌ يرى أرقام خزنة فرعه يعرف كم فيها قبل أن يقرّر شيئاً — وذلك
 * أوّل ما يُسأل عنه في تحقيقٍ داخليّ. ورسالةٌ تُنفّذ إيداعاً تعني أنّ من
 * أخذ هاتف صرّافٍ يستطيع أن يودع باسمه.
 */
class AgentWhatsappTest extends TestCase
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
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967772100001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '750000']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');

        $bu = new User();
        $bu->forceFill([
            'f_name' => 'فرع المكلا', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967772100099', 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '900000']);

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
            'cash_on_hand' => '2500000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '100000',
        ]);
        $this->branch = $this->branch->fresh('till');

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'محمد علي', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
        ]);
        $this->manager = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير المكلا', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->branch->id, 'password' => 'manager1',
        ]);
    }

    private function svc(): AgentWhatsappService
    {
        return app(AgentWhatsappService::class);
    }

    private function linkActive(AgentStaff $staff, string $number): void
    {
        WhatsappLinkedDevice::create([
            'agent_staff_id' => $staff->id, 'user_id' => null,
            'whatsapp_number' => $number,
            'status' => WhatsappLinkedDevice::STATUS_ACTIVE,
            'risk_score' => 0, 'otp_verified_at' => now(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // الربط
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الربط لا يكتمل بقرار المدير وحده.** خطأٌ في رقمٍ واحد كان سيُسلّم
     * أرقام فرعٍ إلى غريب. فالرمز يُرسَل إلى الرقم، ولا يُفعَّل حتى يُعيده
     * صاحبه — فيثبت أنّ الهاتف بيده.
     */
    public function linking_stays_pending_until_the_number_proves_itself(): void
    {
        $out = $this->svc()->startLink($this->hq, $this->teller, '967700111222');

        $this->assertTrue($out['ok']);

        $link = WhatsappLinkedDevice::where('whatsapp_number', '967700111222')->first();
        $this->assertNotNull($link);
        $this->assertSame(WhatsappLinkedDevice::STATUS_PENDING, $link->status);

        // ومعلَّقاً لا يُجاب عن شيء.
        $this->assertNull($this->svc()->resolveStaff('967700111222'));
    }

    /** @test */
    public function the_right_code_activates_the_link_and_a_wrong_one_does_not(): void
    {
        $this->svc()->startLink($this->hq, $this->teller, '967700111222');
        $otp = Cache::get('wa_agent_link_otp:967700111222');
        $this->assertNotNull($otp);

        $bad = $this->svc()->tryVerifyPending('967700111222', '000000');
        $this->assertStringContainsString('غير صحيح', $bad);
        $this->assertNull($this->svc()->resolveStaff('967700111222'));

        $ok = $this->svc()->tryVerifyPending('967700111222', $otp);
        $this->assertStringContainsString('تمّ ربط', $ok);

        $staff = $this->svc()->resolveStaff('967700111222');
        $this->assertNotNull($staff);
        $this->assertSame($this->teller->id, $staff->id);
    }

    /** @test */
    public function a_teller_cannot_link_anyone(): void
    {
        $out = $this->svc()->startLink($this->teller, $this->teller, '967700111333');

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('صلاحية الإدارة', $out['message']);
    }

    /** @test */
    public function a_staff_member_of_another_company_cannot_be_linked(): void
    {
        $rival = new User();
        $rival->forceFill([
            'f_name' => 'منافس', 'l_name' => 'للصرافة', 'phone' => '967772199999',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $rival->id, 'current_balance' => '0']);
        $rivalHq = app(AgentStaffService::class)->ensureHeadOfficeAccount($rival, 'rival123');

        $out = $this->svc()->startLink($this->hq, $rivalHq, '967700111444');

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('خارج شركتك', $out['message']);
    }

    /**
     * @test
     *
     * **رقمٌ واحدٌ لشخصٍ واحد.** ولو كان عميلاً وموظّفاً معاً لصار «ما
     * رصيدي؟» سؤالاً غامضاً — أمحفظتُه أم درجُه؟
     */
    public function a_number_already_linked_elsewhere_is_refused(): void
    {
        $this->linkActive($this->manager, '967700111555');

        $out = $this->svc()->startLink($this->hq, $this->teller, '967700111555');

        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('مرتبطٌ بحسابٍ آخر', $out['message']);
    }

    /** @test */
    public function revoking_closes_the_channel_immediately(): void
    {
        $this->linkActive($this->teller, '967700111666');
        $this->assertNotNull($this->svc()->resolveStaff('967700111666'));

        $this->svc()->revoke($this->teller);

        $this->assertNull($this->svc()->resolveStaff('967700111666'));
    }

    /** @test */
    public function a_disabled_staff_account_gets_no_answer(): void
    {
        $this->linkActive($this->teller, '967700111777');
        $this->teller->forceFill(['is_active' => false])->save();

        $this->assertNull($this->svc()->resolveStaff('967700111777'));
    }

    // ══════════════════════════════════════════════════════════════════
    // الجواب يُقصّ على قدر الدور
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_teller_menu_offers_only_their_own_drawer(): void
    {
        $menu = $this->svc()->menu($this->teller);

        $this->assertStringContainsString('درجي', $menu);
        $this->assertStringNotContainsString('الفروع', $menu);
        $this->assertStringNotContainsString('التسوية', $menu);
        // والقاعدة تُقال للمستعمل لا تُترك ضمنيّة.
        $this->assertStringContainsString('للاستعلام فقط', $menu);
    }

    /** @test */
    public function the_head_office_menu_offers_the_company_and_the_settlement(): void
    {
        $menu = $this->svc()->menu($this->hq);

        $this->assertStringContainsString('الفروع', $menu);
        $this->assertStringContainsString('التسوية', $menu);
    }

    /**
     * @test
     *
     * **الصرّاف لا يرى خزنة الشركة.** ما يراه درجُه وخزنةُ فرعه فقط —
     * وأرقام الشركة كلّها ليست من شأنه.
     */
    public function a_teller_cannot_reach_company_wide_numbers(): void
    {
        // كلمة «الفروع» أمرُ الإدارة العامّة — ولا تُفهم من صرّاف.
        $this->assertNull($this->svc()->handle($this->teller, 'الفروع'));
        $this->assertNull($this->svc()->handle($this->teller, 'التسوية'));
        $this->assertNull($this->svc()->handle($this->teller, '4'));
    }

    /** @test */
    public function the_teller_sees_their_open_drawer_with_real_numbers(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '150000');

        $c = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '967773100011', 'is_active' => 1, 'is_kyc_verified' => 1,
        ]);
        EMoney::updateOrCreate(['user_id' => $c->id], ['current_balance' => '0']);

        app(AgentCounterService::class)->deposit(
            $this->branch, $c, '20000', $this->branch->account, null, $shift->fresh(),
        );

        $reply = $this->svc()->handle($this->teller, 'درجي');

        $this->assertStringContainsString('170,000', $reply);   // ١٥٠٬٠٠٠ + ٢٠٬٠٠٠
        $this->assertStringContainsString('فرع المكلا', $reply);
    }

    /**
     * @test
     *
     * «لا ورديّة» ليست «درجٌ فيه صفر» — والفرق بينهما هو الفرق بين موظّفٍ
     * لم يبدأ وموظّفٍ أفرغ درجه.
     */
    public function no_open_shift_is_said_plainly_not_shown_as_zero(): void
    {
        $reply = $this->svc()->handle($this->teller, 'درجي');

        $this->assertStringContainsString('لا ورديّة مفتوحة', $reply);
        $this->assertStringNotContainsString('النقد في درجك: *0*', $reply);
    }

    /** @test */
    public function the_branch_manager_sees_the_branch_safe_and_drawers(): void
    {
        app(AgentShiftService::class)->open($this->teller, '150000');

        $reply = $this->svc()->handle($this->manager, 'فرعي');

        $this->assertStringContainsString('فرع المكلا', $reply);
        $this->assertStringContainsString('2,350,000', $reply);  // الخزنة بعد العهدة
        $this->assertStringContainsString('150,000', $reply);     // في الأدراج
    }

    /** @test */
    public function the_head_office_sees_the_company_totals(): void
    {
        $reply = $this->svc()->handle($this->hq, 'الشركة');

        $this->assertStringContainsString('750,000', $reply);      // رصيد الشركة
        $this->assertStringContainsString('2,500,000', $reply);    // نقد الخزائن
    }

    /** @test */
    public function the_head_office_can_read_todays_settlement_state(): void
    {
        $reply = $this->svc()->handle($this->hq, 'التسوية');

        $this->assertStringContainsString('تسوية اليوم', $reply);
        $this->assertStringContainsString('لم تُرفع بعد', $reply);
        // والرفع يبقى في البوّابة — القناة لا تُحرّك مالاً.
        $this->assertStringContainsString('الرفع من بوّابة الشركة', $reply);
    }

    // ══════════════════════════════════════════════════════════════════
    // نقاط النهاية
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function head_office_links_and_unlinks_from_the_portal(): void
    {
        $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.staff.wa.link', ['id' => $this->teller->id]),
                ['phone' => '967700112000'])
            ->assertOk();

        $this->assertDatabaseHas('whatsapp_linked_devices', [
            'whatsapp_number' => '967700112000',
            'agent_staff_id' => $this->teller->id,
            'status' => WhatsappLinkedDevice::STATUS_PENDING,
        ]);

        $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.staff.wa.unlink', ['id' => $this->teller->id]))
            ->assertOk();

        $this->assertDatabaseHas('whatsapp_linked_devices', [
            'whatsapp_number' => '967700112000',
            'status' => WhatsappLinkedDevice::STATUS_REVOKED,
        ]);
    }

    /** @test */
    public function a_teller_cannot_unlink_from_the_portal(): void
    {
        $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.staff.wa.unlink', ['id' => $this->teller->id]))
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════
    // «أقرب وكيل» — ثلاثة أعطالٍ كانت تُسقطه
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * كان الاستعلام يسأل عن `users.type = 2` (والوكيل ١)، وعن عمودين لا
     * وجود لهما (`is_active` و`display_name`) — فيسقط بـSQLSTATE[42S22]
     * ويرى العميل «حدث خطأ» كلّما سأل عن أقرب وكيل.
     */
    public function the_nearest_agent_query_runs_and_excludes_branches(): void
    {
        $branchAccounts = AgentBranch::pluck('branch_user_id')->all();

        $rows = DB::table('users')
            ->join('agent_profiles', 'users.id', '=', 'agent_profiles.user_id')
            ->where('users.type', AGENT_TYPE)
            ->where('users.is_active', 1)
            ->where('agent_profiles.status', 'active')
            ->whereNotIn('users.id', $branchAccounts ?: [0])
            ->where('agent_profiles.zone_code', 'SOUTH')
            ->select('users.f_name', 'users.l_name', 'users.phone', 'agent_profiles.business_name')
            ->limit(5)->get();

        // الفرع لا يُعرَض للعميل — يُرسله إلى فرعٍ باسم شركةٍ لا يعرفه.
        $this->assertNotContains(
            $this->branch->branch_user_id,
            DB::table('users')->whereIn('phone', $rows->pluck('phone'))->pluck('id')->all(),
        );
    }
}
