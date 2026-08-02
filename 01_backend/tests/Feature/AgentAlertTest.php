<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentDailySettlement;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Models\WhatsappLinkedDevice;
use App\Services\AgentDailySettlementService;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use App\Services\AgentTillService;
use App\Services\Whatsapp\AgentAlertService;
use App\Services\Whatsapp\AgentWhatsappService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AMIAL-WA-AGENT-002 — التنبيهات الصادرة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةٌ تُثبت هنا، وسقوطُ أيّها يعني عطلاً حقيقيّاً:**
 *
 * ١. **النطاق:** الصرّاف يُبلَّغ بفرق ورديّته ولا يُبلَّغ برقم خزنة الفرع.
 *    ولو سرّبها التنبيه لصار البابُ الذي أُغلق في الاستعلام مفتوحاً من
 *    الجهة الأخرى — والصرّاف الذي يعرف كم في الخزنة يقرّر على أساسه.
 *
 * ٢. **العمليّة لا تسقط بفشل الرسالة:** انقطاعُ مزوّد واتساب لا يجوز أن
 *    يمنع صرّافاً من إغلاق درجه. فالتنبيه بعد المعاملة، وفشلُه مبتلَع.
 *
 * ٣. **الإسكات يعمل ويُبقي الاستعلام:** ومن لا يجد كيف يُسكت يحظر الرقم
 *    فيقطع القناة كلّها ولا تعرف إدارتُه أنّها قُطعت.
 */
class AgentAlertTest extends TestCase
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
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967773100001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '750000']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');

        $bu = new User();
        $bu->forceFill([
            'f_name' => 'فرع المكلا', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967773100099', 'password' => Hash::make('secret123'),
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
            'cash_on_hand' => '500000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '100000',
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

        $this->enableProvider();
    }

    /**
     * مزوّدٌ حقيقيّ الشكل مُلتقَطُ الطلبات.
     *
     * ولا تُزيَّف `WhatsappModule` نفسها: الطريق من الخدمة إلى الشبكة هو
     * ما يُختبَر، ولو زُيِّف لمرّت الاختبارات وقناةُ الإرسال مقطوعة.
     */
    private function enableProvider(): void
    {
        // `addon_settings.id` ليس تلقائيّاً في هذا المخطّط — يُملأ صراحةً.
        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => 'ultramsg', 'settings_type' => 'whatsapp_config'],
            ['id' => 9901, 'live_values' => json_encode([
                'status' => 1, 'instance_id' => 'inst1', 'token' => 't', 'otp_template' => '{otp}',
            ]), 'mode' => 'live', 'is_active' => 1],
        );

        Http::fake(['*' => Http::response(['sent' => true], 200)]);
    }

    /** كلّ ما أُرسل فعلاً: [الرقم => نصّ الرسالة]. */
    private function sent(): array
    {
        $out = [];

        foreach (Http::recorded() as [$request]) {
            $d = $request->data();
            if (isset($d['to'])) {
                // المزوّد يُوحّد الرقم بإضافة «+» — والمقارنة بالأرقام وحدها.
                $out[] = [
                    'to' => preg_replace('/\D+/', '', (string) $d['to']),
                    'body' => (string) ($d['body'] ?? ''),
                ];
            }
        }

        return $out;
    }

    private function bodyTo(string $number): ?string
    {
        foreach ($this->sent() as $m) {
            if ($m['to'] === $number) {
                return $m['body'];
            }
        }

        return null;
    }

    private function linkActive(AgentStaff $staff, string $number, bool $alerts = true): void
    {
        WhatsappLinkedDevice::create([
            'agent_staff_id' => $staff->id, 'user_id' => null,
            'whatsapp_number' => $number, 'alerts_enabled' => $alerts,
            'status' => WhatsappLinkedDevice::STATUS_ACTIVE,
            'risk_score' => 0, 'otp_verified_at' => now(),
        ]);
    }

    private function admin(string $phone): User
    {
        $admin = new User();
        $admin->forceFill([
            'f_name' => 'مشرف', 'l_name' => 'أميال', 'phone' => $phone,
            'type' => ADMIN_TYPE, 'role' => 'super_admin',
            'password' => Hash::make('secret123'), 'is_active' => 1,
        ])->save();

        return $admin;
    }

    /**
     * تسويةٌ تُرفع بالمسار الحقيقيّ داخل النافذة.
     *
     * ولا يُبنى الصفّ بيد: صفٌّ مصنوعٌ يُثبت أنّ الحقول التي كتبناها
     * موجودة، لا أنّ الرفع يعمل.
     */
    private function submitToday(): AgentDailySettlement
    {
        $this->travelTo(now()->startOfDay()->addHours(23));

        return app(AgentDailySettlementService::class)
            ->submit($this->company, $this->hq, now()->toDateString());
    }

    /** ورديّةٌ تُفتح وتُغلق بفرقٍ مقصود. */
    private function closeWithShortage(string $float = '50000', string $counted = '45000'): void
    {
        $svc = app(AgentShiftService::class);
        $shift = $svc->open($this->teller, $float);
        $svc->close($this->teller, $shift, $counted, 'فرقٌ في العدّ يُراجَع غداً');
    }

    // ══════════════════════════════════════════════════════════════════
    // النطاق
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * الصرّاف يُبلَّغ بفرقه، والإدارة تُبلَّغ به منسوباً إليه.
     */
    public function a_variance_reaches_both_the_teller_and_the_management(): void
    {
        $this->linkActive($this->teller, '967700000001');
        $this->linkActive($this->manager, '967700000002');
        $this->linkActive($this->hq, '967700000003');

        $this->closeWithShortage();

        $this->assertNotNull($this->bodyTo('967700000001'), 'الصرّاف لم يُبلَّغ بفرق ورديّته');
        $this->assertNotNull($this->bodyTo('967700000002'), 'مدير الفرع لم يُبلَّغ');
        $this->assertNotNull($this->bodyTo('967700000003'), 'الإدارة العامّة لم تُبلَّغ');

        // الفرق نفسه في الرسالتين: ٥٬٠٠٠.
        $this->assertStringContainsString('5,000', $this->bodyTo('967700000001'));
        $this->assertStringContainsString('5,000', $this->bodyTo('967700000002'));

        // ونسبةُ الفرق إلى صاحبه هي ما تحتاجه الإدارة للقرار.
        $this->assertStringContainsString('محمد علي', $this->bodyTo('967700000002'));
    }

    /**
     * @test
     *
     * **الصرّاف لا يُعطى رقم خزنة فرعه في تنبيه.** وهو نفس الحدّ الذي
     * يفرضه الاستعلام — ولو خُرق هنا لصار إغلاقه هناك زينة.
     */
    public function the_tellers_alert_never_carries_branch_numbers(): void
    {
        $this->linkActive($this->teller, '967700000001');

        $this->closeWithShortage();

        $body = $this->bodyTo('967700000001');

        $this->assertNotNull($body);
        $this->assertStringNotContainsString('الخزنة', $body);
        $this->assertStringNotContainsString('مدير المكلا', $body);
        // ولا اسمُ زميلٍ ولا رصيدُ فرع.
        $this->assertStringNotContainsString('500,000', $body);
    }

    /**
     * @test
     *
     * صرّافُ فرعٍ آخر لا يُبلَّغ بفرقٍ ليس في فرعه — ومديرُه كذلك.
     */
    public function another_branchs_manager_is_not_told(): void
    {
        $other = new User();
        $other->forceFill([
            'f_name' => 'فرع سيحوت', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967773100088', 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $other->id, 'current_balance' => '10000']);

        $b2 = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $other->id,
            'name' => 'فرع سيحوت', 'code' => 'SHT', 'city' => 'المهرة',
            'phone' => $other->phone, 'is_active' => true,
        ]);
        $b2->till()->create(['cash_on_hand' => '0', 'max_cash_on_hand' => '0', 'min_cash_alert' => '0']);

        $m2 = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير سيحوت', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $b2->id, 'password' => 'manager2',
        ]);

        $this->linkActive($m2, '967700000009');

        $this->closeWithShortage();

        $this->assertNull($this->bodyTo('967700000009'), 'مديرُ فرعٍ آخر بُلِّغ بفرقٍ ليس في فرعه');
    }

    /**
     * @test
     *
     * **الورديّة المطابِقة لا تُنبَّه.** وتنبيهٌ عن لا شيء يُعلّم الناس
     * تجاهل التنبيهات، فيُتجاهل الذي يهمّ حين يأتي.
     */
    public function a_balanced_shift_sends_nothing(): void
    {
        $this->linkActive($this->teller, '967700000001');
        $this->linkActive($this->manager, '967700000002');

        $svc = app(AgentShiftService::class);
        $shift = $svc->open($this->teller, '50000');
        $svc->close($this->teller, $shift, '50000');

        $this->assertNull($this->bodyTo('967700000001'));
        $this->assertNull($this->bodyTo('967700000002'));
    }

    // ══════════════════════════════════════════════════════════════════
    // نقد الفرع
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function low_branch_cash_alerts_the_management_not_the_teller(): void
    {
        $this->linkActive($this->teller, '967700000001');
        $this->linkActive($this->manager, '967700000002');

        // الحدّ ١٠٠٬٠٠٠ والخزنة ٥٠٠٬٠٠٠ — يُخرَج منها ٤٥٠٬٠٠٠ فتنزل تحته.
        app(AgentTillService::class)->record(
            $this->branch, 'out', 'treasury_out', '450000', $this->company,
            note: 'توريدٌ إلى فرعٍ آخر',
        );

        $this->assertNotNull($this->bodyTo('967700000002'), 'مدير الفرع لم يُنبَّه بنقدٍ منخفض');
        $this->assertStringContainsString('نقد الفرع منخفض', $this->bodyTo('967700000002'));

        // والصرّاف لا شأن له بأرقام الخزنة.
        $this->assertNull($this->bodyTo('967700000001'), 'الصرّاف نُبّه برقم خزنة الفرع');
    }

    /**
     * @test
     *
     * **لا تُرسَل مع كلّ عمليّة.** فرعٌ تحت الحدّ يبقى تحته عشرات
     * العمليّات، ورسالةٌ مع كلٍّ منها تجعل المدير يكتم البوت — فيفقد
     * التنبيه الذي يهمّ.
     */
    public function the_low_cash_alert_does_not_repeat_on_every_movement(): void
    {
        $this->linkActive($this->manager, '967700000002');

        $till = app(AgentTillService::class);

        $till->record($this->branch, 'out', 'treasury_out', '450000', $this->company, note: 'أولى');
        $till->record($this->branch, 'out', 'treasury_out', '1000', $this->company, note: 'ثانية');
        $till->record($this->branch, 'out', 'treasury_out', '1000', $this->company, note: 'ثالثة');

        $count = count(array_filter(
            $this->sent(),
            fn ($m) => $m['to'] === '967700000002' && str_contains($m['body'], 'نقد الفرع منخفض'),
        ));

        $this->assertSame(1, $count, "أُرسل التنبيه {$count} مرّة بدل مرّة واحدة");
    }

    /** @test */
    public function a_branch_above_the_threshold_is_never_alerted(): void
    {
        $this->linkActive($this->manager, '967700000002');

        app(AgentTillService::class)->record(
            $this->branch, 'out', 'treasury_out', '1000', $this->company, note: 'صغيرة',
        );

        $this->assertNull($this->bodyTo('967700000002'));
    }

    /**
     * @test
     *
     * الكتم يُنسى بالتعافي: فرعٌ دُعم ثمّ نزل مرّةً أخرى يُنبَّه من جديد.
     */
    public function recovery_clears_the_mute_so_the_next_dip_alerts_again(): void
    {
        $this->linkActive($this->manager, '967700000002');
        $till = app(AgentTillService::class);

        $till->record($this->branch, 'out', 'treasury_out', '450000', $this->company, note: 'نزول أوّل');
        $till->record($this->branch, 'in', 'treasury_in', '450000', $this->company, note: 'دعمٌ للفرع');
        $till->record($this->branch, 'out', 'treasury_out', '450000', $this->company, note: 'نزول ثانٍ');

        $count = count(array_filter(
            $this->sent(),
            fn ($m) => $m['to'] === '967700000002' && str_contains($m['body'], 'نقد الفرع منخفض'),
        ));

        $this->assertSame(2, $count, 'التعافي لم يُنسِ الكتم — فالنزول الثاني مرّ صامتاً');
    }

    // ══════════════════════════════════════════════════════════════════
    // التسوية
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_settlement_decision_reaches_the_head_office_only(): void
    {
        $this->linkActive($this->hq, '967700000003');
        $this->linkActive($this->manager, '967700000002');
        $this->linkActive($this->teller, '967700000001');

        $row = $this->submitToday();
        app(AgentDailySettlementService::class)->accept($row, $this->admin('967773100777'), 'مطابِقة');

        $this->assertNotNull($this->bodyTo('967700000003'), 'الإدارة العامّة لم تُبلَّغ بالقرار');
        $this->assertStringContainsString('قُبلت', $this->bodyTo('967700000003'));

        // القرار مع أميال شأنُ إدارة الشركة، لا شأنُ صرّافٍ ولا مدير فرع.
        $this->assertNull($this->bodyTo('967700000002'));
        $this->assertNull($this->bodyTo('967700000001'));
    }

    /** @test */
    public function a_rejection_carries_its_reason(): void
    {
        $this->linkActive($this->hq, '967700000003');

        $row = $this->submitToday();
        app(AgentDailySettlementService::class)
            ->reject($row, $this->admin('967773100778'), 'العجز غير مفسَّر في فرع المكلا');

        $body = $this->bodyTo('967700000003');

        $this->assertNotNull($body);
        $this->assertStringContainsString('رُفضت', $body);
        // **السبب في الرسالة نفسها.** ورفضٌ بلا سبب يُنتج مكالمةً لا معالجة.
        $this->assertStringContainsString('غير مفسَّر', $body);
    }

    /** @test */
    public function the_window_reminder_only_goes_to_those_who_have_not_filed(): void
    {
        $this->linkActive($this->hq, '967700000003');

        // داخل النافذة: ٢٣:٠٠ من يوم التسوية.
        $this->travelTo(now()->startOfDay()->addHours(23));

        $this->artisan('amial:agent-settlement-reminder')->assertSuccessful();

        $this->assertNotNull($this->bodyTo('967700000003'), 'لم يُذكَّر وكيلٌ لم يرفع');
        $this->assertStringContainsString('تُغلق', $this->bodyTo('967700000003'));
    }

    /** @test */
    public function no_reminder_is_sent_outside_the_window(): void
    {
        $this->linkActive($this->hq, '967700000003');

        // الظهر: قبل فتح النافذة بساعات.
        $this->travelTo(now()->startOfDay()->addHours(12));

        $this->artisan('amial:agent-settlement-reminder')->assertSuccessful();

        $this->assertNull($this->bodyTo('967700000003'), 'ذُكِّر خارج النافذة');
    }

    // ══════════════════════════════════════════════════════════════════
    // الإسكات والصلابة
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الإسكات يوقف الصادر ويُبقي الوارد.** ومن لا يجد كيف يُسكت يحظر
     * الرقم فيقطع الاستعلام معه، ولا تعرف إدارتُه أنّ قناته انقطعت.
     */
    public function silencing_stops_alerts_but_keeps_the_bot_answering(): void
    {
        $this->linkActive($this->teller, '967700000001');

        $reply = app(AgentWhatsappService::class)->handle($this->teller, 'إيقاف التنبيهات');
        $this->assertStringContainsString('أُوقفت', (string) $reply);

        $this->closeWithShortage();
        $this->assertNull($this->bodyTo('967700000001'), 'وصل تنبيهٌ بعد الإسكات');

        // والاستعلام يعمل كما هو.
        $answer = app(AgentWhatsappService::class)->handle($this->teller, 'درجي');
        $this->assertNotNull($answer);
        $this->assertStringContainsString('درجك', (string) $answer);
    }

    /** @test */
    public function alerts_can_be_turned_back_on(): void
    {
        $this->linkActive($this->teller, '967700000001', alerts: false);

        $reply = app(AgentWhatsappService::class)->handle($this->teller, 'تشغيل التنبيهات');
        $this->assertStringContainsString('عادت', (string) $reply);

        $this->closeWithShortage();

        $this->assertNotNull($this->bodyTo('967700000001'));
    }

    /** @test */
    public function an_unlinked_or_revoked_staff_receives_nothing(): void
    {
        // معلَّقٌ لم يُثبت الرقمَ صاحبُه بعد.
        WhatsappLinkedDevice::create([
            'agent_staff_id' => $this->teller->id, 'user_id' => null,
            'whatsapp_number' => '967700000001',
            'status' => WhatsappLinkedDevice::STATUS_PENDING, 'risk_score' => 0,
        ]);

        $this->closeWithShortage();

        $this->assertNull($this->bodyTo('967700000001'));
    }

    /**
     * @test
     *
     * **التنبيه خارج المعاملة — وهذا هو الخطر الحقيقيّ في الميزة كلّها.**
     *
     * لو أُطلق داخل `DB::transaction` لصار انفجارُه تراجعاً: ورديّةٌ عُدّ
     * درجُها وسُجّل فرقُها وعاد نقدُها إلى الخزنة، ثمّ يُمحى ذلك كلّه لأنّ
     * رسالةً لم تُرسَل.
     *
     * فيُزرَع تنبيهٌ ينفجر حتماً — ولا يُقاس «هل ظهر خطأ» بل **هل بقي
     * الإغلاق مُثبتاً في القاعدة بعده**.
     */
    public function the_alert_runs_after_the_commit_not_inside_it(): void
    {
        $this->app->bind(AgentAlertService::class, fn () => new class extends AgentAlertService {
            public function shiftClosedWithVariance(\App\Models\Agent\AgentShift $shift): void
            {
                throw new \RuntimeException('انفجارٌ مزروع في التنبيه');
            }
        });

        $svc = app(AgentShiftService::class);
        $shift = $svc->open($this->teller, '50000');

        try {
            $svc->close($this->teller, $shift, '45000', 'فرقٌ في العدّ يُراجَع غداً');
        } catch (\RuntimeException) {
            // متوقَّع: الانفجار المزروع.
        }

        $fresh = $shift->fresh();

        $this->assertSame(\App\Models\Agent\AgentShift::STATUS_CLOSED, $fresh->status,
            'الإغلاق تراجع بانفجار تنبيه — أي أنّ التنبيه داخل المعاملة');
        $this->assertSame(0, bccomp('-5000', (string) $fresh->variance, 4));
    }

    /** @test */
    public function a_cash_movement_survives_an_exploding_alert(): void
    {
        $this->app->bind(AgentAlertService::class, fn () => new class extends AgentAlertService {
            public function tillLow(AgentBranch $branch): void
            {
                throw new \RuntimeException('انفجارٌ مزروع في التنبيه');
            }
        });

        try {
            app(AgentTillService::class)->record(
                $this->branch, 'out', 'treasury_out', '450000', $this->company, note: 'توريد',
            );
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, bccomp('50000', (string) $this->branch->fresh('till')->till->cash_on_hand, 4),
            'حركة النقد تراجعت بانفجار تنبيه');
    }

    /**
     * @test
     *
     * ومزوّدٌ ميّتٌ لا يُخرج استثناءً أصلاً: يُبتلع ويُسجَّل.
     */
    public function a_dead_whatsapp_provider_never_blocks_the_shift_close(): void
    {
        $this->linkActive($this->teller, '967700000001');

        Http::fake(['*' => fn () => throw new \RuntimeException('الشبكة مقطوعة')]);

        $svc = app(AgentShiftService::class);
        $shift = $svc->open($this->teller, '50000');
        $closed = $svc->close($this->teller, $shift, '45000', 'فرقٌ في العدّ يُراجَع غداً');

        $this->assertSame(\App\Models\Agent\AgentShift::STATUS_CLOSED, $closed->status);
        $this->assertSame(0, bccomp('-5000', (string) $closed->variance, 4));
    }

    /**
     * @test
     *
     * الموظّف المعطَّل لا يُنبَّه: تعطيلُه في اللوحة يجب أن يُغلق كلّ أبوابه.
     */
    public function a_disabled_staff_member_gets_no_alerts(): void
    {
        $this->linkActive($this->manager, '967700000002');

        $this->manager->forceFill(['is_active' => false])->save();

        app(AgentTillService::class)->record(
            $this->branch, 'out', 'treasury_out', '450000', $this->company, note: 'توريد',
        );

        $this->assertNull($this->bodyTo('967700000002'));
    }

    /** @test */
    public function the_alert_service_writes_nothing_to_money(): void
    {
        $this->linkActive($this->manager, '967700000002');

        $before = DB::table('ledger_journal_entries')->count();
        $balanceBefore = (string) EMoney::where('user_id', $this->company->id)->value('current_balance');

        app(AgentAlertService::class)->tillLow($this->branch);
        app(AgentAlertService::class)->settlementWindowClosing(
            $this->company->id, now()->toDateString(), '00:00', 60,
        );

        $this->assertSame($before, DB::table('ledger_journal_entries')->count());
        $this->assertSame($balanceBefore, (string) EMoney::where('user_id', $this->company->id)->value('current_balance'));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
