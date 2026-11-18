<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Models\WhatsappLinkedDevice;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AGT-GAP-015 / AGT-GAP-016 / AGT-GAP-017 — الأدوار المكملة، تعديل الفرع ونقل الموظف،
 * تنبيهات الطوارئ عبر واتساب، وصمود الواجهة بدون prompt() أو CDN.
 */
class AgentRolesUiAndPanicAlertsFixTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private AgentBranch $branch2;
    private AgentStaff $manager;
    private AgentStaff $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = new User();
        $this->company->email = 'fixture-' . bin2hex(random_bytes(8)) . '@example.test';
        $this->company->forceFill([
            'f_name' => 'شركة', 'l_name' => 'الاختبار', 'phone' => '967777400001',
            'type' => AGENT_TYPE, 'password' => Hash::make('company123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '500000']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hqpass123');

        $this->branch = $this->makeBranch('MKL', 'فرع المكلا', '967777400091');
        $this->branch2 = $this->makeBranch('SYH', 'فرع سيحوت', '967777400092');

        $this->manager = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير المكلا', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->branch->id, 'password' => 'mgr12345',
        ]);
        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صراف المكلا', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
        ]);
    }

    private function makeBranch(string $code, string $name, string $phone): AgentBranch
    {
        $bu = new User();
        $bu->email = 'fixture-' . bin2hex(random_bytes(8)) . '@example.test';
        $bu->forceFill([
            'f_name' => $name, 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => $phone, 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '5000000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 'sub_agent',
            'business_name' => $name, 'status' => 'active', 'zone_code' => 'SOUTH',
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

    /** @test */
    public function agt_gap_015_auditor_is_strictly_read_only_and_treasury_officer_manages_safe_only(): void
    {
        $auditor = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدقق الشركة',
            'role' => AgentStaff::ROLE_AUDITOR,
            'password' => 'audit123',
        ]);
        $treasury = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'أمين خزنة المكلا',
            'role' => AgentStaff::ROLE_TREASURY_OFFICER,
            'branch_id' => $this->branch->id,
            'password' => 'treas123',
        ]);

        // المدقق يقرأ التقارير والعمليات والفروع وملفات الموظفين.
        $this->actingAs($auditor, 'agent_staff');
        $this->getJson(route('agent.overview'))->assertOk();
        $this->getJson(route('agent.reports'))->assertOk();
        $this->getJson(route('agent.operations'))->assertOk();
        $this->getJson(route('agent.staff.index'))->assertOk()->assertJsonPath('can_manage', false);
        $this->getJson(route('agent.staff.shifts', ['branch_id' => $this->branch->id]))->assertOk();
        $this->getJson(route('agent.settlements'))->assertOk();
        $this->getJson(route('agent.settings'))->assertOk();

        // المدقق يُرفض بـ 403 على جميع عمليات الكتابة وتحريك المال.
        $this->postJson(route('agent.counter.shift.open'), ['opening_float' => 0])->assertStatus(403);
        $this->postJson(route('agent.branch.cash', ['id' => $this->branch->id]), [
            'direction' => 'in', 'amount' => 1000, 'note' => 'اختبار كتابة مدقق',
        ])->assertStatus(403);
        $this->postJson(route('agent.branch.count', ['id' => $this->branch->id]), [
            'counted_amount' => 1000, 'note' => 'اختبار جرد مدقق طويل',
        ])->assertStatus(403);
        $this->postJson(route('agent.branch.thresholds', ['id' => $this->branch->id]), [
            'min_cash_alert' => 1000, 'max_cash_on_hand' => 5000000,
        ])->assertStatus(403);
        $this->postJson(route('agent.branch.hours', ['id' => $this->branch->id]), ['hours' => []])->assertStatus(403);
        $this->postJson(route('agent.announcements.create'), ['title' => 'تعميم', 'body' => 'نص'])->assertStatus(403);
        $this->postJson(route('agent.staff.store'), [
            'name' => 'موظف جديد', 'password' => '123456', 'role' => 'teller', 'branch_id' => $this->branch->id,
        ])->assertStatus(403);
        $this->postJson(route('agent.teller.request'), ['amount' => 100, 'reason' => 'طلب من مدقق'])->assertStatus(403);

        // مسؤول الخزنة لا يفتح وردية شباك ولا يعين موظفين، لكنه يدير خزنة فرعه وجردها وحدودها.
        $this->actingAs($treasury, 'agent_staff');
        $this->postJson(route('agent.counter.shift.open'), ['opening_float' => 0])->assertStatus(403);
        $this->postJson(route('agent.staff.store'), [
            'name' => 'موظف جديد', 'password' => '123456', 'role' => 'teller', 'branch_id' => $this->branch->id,
        ])->assertStatus(403);
        $this->postJson(route('agent.branch.cash', ['id' => $this->branch->id]), [
            'direction' => 'in', 'amount' => 50000, 'note' => 'توريد نقدي للخزنة',
        ])->assertOk();
        $this->postJson(route('agent.branch.count', ['id' => $this->branch->id]), [
            'counted_amount' => 2050000, 'note' => 'جرد دوري مطابق للخزنة',
        ])->assertOk();
        $this->postJson(route('agent.branch.thresholds', ['id' => $this->branch->id]), [
            'min_cash_alert' => 200000, 'max_cash_on_hand' => 8000000,
        ])->assertOk();
    }

    /** @test */
    public function agt_gap_015_branch_edit_and_staff_transfer_enforce_open_shift_and_audit(): void
    {
        // تعديل الفرع محصور بالإدارة العامة.
        $this->actingAs($this->manager, 'agent_staff')
            ->postJson(route('agent.branch.update', ['id' => $this->branch->id]), [
                'name' => 'اسم جديد', 'phone' => '967777400095',
            ])->assertStatus(403);

        $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.branch.update', ['id' => $this->branch->id]), [
                'name' => 'فرع المكلا الرئيسي', 'phone' => '967777400095', 'city' => 'المكلا', 'address' => 'الشارع العام',
            ])->assertOk();

        $this->assertSame('فرع المكلا الرئيسي', $this->branch->fresh()->name);
        $this->assertDatabaseHas('audit_decisions', [
            'action' => 'AGENT_BRANCH_UPDATED',
            'subject_id' => (string) $this->branch->id,
        ]);

        // نقل موظف بوردية مفتوحة يُرفض بـ 422.
        $shift = app(AgentShiftService::class)->open($this->teller, '100000');
        $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.staff.transfer', ['id' => $this->teller->id]), [
                'branch_id' => $this->branch2->id, 'role' => AgentStaff::ROLE_TELLER,
            ])->assertStatus(422);

        app(AgentShiftService::class)->close($this->teller, $shift, '100000');

        // بعد إغلاق الوردية ينجح النقل ويُسجل في التدقيق.
        $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.staff.transfer', ['id' => $this->teller->id]), [
                'branch_id' => $this->branch2->id, 'role' => AgentStaff::ROLE_TREASURY_OFFICER,
            ])->assertOk();

        $moved = $this->teller->fresh();
        $this->assertSame((int) $this->branch2->id, (int) $moved->branch_id);
        $this->assertSame(AgentStaff::ROLE_TREASURY_OFFICER, $moved->role);
        $this->assertDatabaseHas('audit_decisions', [
            'action' => 'agent.staff.transfer',
            'subject_id' => (string) $this->teller->id,
        ]);
    }

    /** @test */
    public function agt_gap_016_panic_sends_immediate_whatsapp_to_managers_even_when_muted_and_survives_provider_outage(): void
    {
        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => 'ultramsg', 'settings_type' => 'whatsapp_config'],
            ['id' => 9901, 'live_values' => json_encode([
                'status' => 1, 'instance_id' => 'inst1', 'token' => 't', 'otp_template' => '{otp}',
            ]), 'mode' => 'live', 'is_active' => 1],
        );

        $fellowTeller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صراف زميل', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller22',
        ]);

        foreach ([
            [$this->manager, '967700111001', false], // كتم التنبيهات العادية — الطوارئ لا تُسكت
            [$this->hq, '967700111002', true],
            [$fellowTeller, '967700111003', true],
        ] as [$staff, $num, $alerts]) {
            WhatsappLinkedDevice::create([
                'agent_staff_id' => $staff->id, 'agent_user_id' => $staff->agent_user_id,
                'whatsapp_number' => $num, 'status' => WhatsappLinkedDevice::STATUS_ACTIVE,
                'alerts_enabled' => $alerts, 'linked_at' => now(),
            ]);
        }

        Http::fake(['*' => Http::response(['sent' => true], 200)]);

        $res = $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.teller.panic'), [
                'kind' => 'robbery', 'note' => 'حالة طوارئ فورية',
            ])->assertOk();

        $recipients = collect(Http::recorded())
            ->map(fn ($pair) => preg_replace('/\D+/', '', (string) ($pair[0]->data()['to'] ?? '')))
            ->filter()->values()->all();

        $this->assertContains('967700111001', $recipients);
        $this->assertContains('967700111002', $recipients);
        $this->assertNotContains('967700111003', $recipients);

        // عند انقطاع مزود واتساب لا يسقط بلاغ الطوارئ.
        Http::fake(['*' => Http::response('Gateway Down', 500)]);
        $res2 = $this->actingAs($this->teller, 'agent_staff')
            ->postJson(route('agent.teller.panic'), ['kind' => 'duress'])
            ->assertOk();

        $this->assertDatabaseHas('agent_panic_alerts', [
            'alert_number' => $res->json('meta.number'),
        ]);
        $this->assertDatabaseHas('agent_panic_alerts', [
            'alert_number' => $res2->json('meta.number'),
        ]);
    }

    /** @test */
    public function agt_gap_017_agent_views_have_no_raw_prompts_or_external_cdn_and_wire_working_hours(): void
    {
        $files = glob(resource_path('views/agent-views/*.blade.php')) ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $name = basename($file);
            $this->assertDoesNotMatchRegularExpression('/\bprompt\s*\(/', $src, "{$name} ما زال يستعمل prompt()");
            $this->assertDoesNotMatchRegularExpression('/\bconfirm\s*\(/', $src, "{$name} ما زال يستعمل confirm()");
            $this->assertStringNotContainsString('cdn.jsdelivr.net', $src, "{$name} ما زال يرتبط بـ CDN خارجي");
            $this->assertStringNotContainsString('fonts.googleapis.com', $src, "{$name} ما زال يرتبط بخطوط خارجية");
        }

        $settingsBlade = (string) file_get_contents(resource_path('views/agent-views/_settings.blade.php'));
        $this->assertStringContainsString('data-sg-hours', $settingsBlade);
        $this->assertStringContainsString('/hours', $settingsBlade);
    }
}
