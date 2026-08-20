<?php

namespace Tests\Feature;

use App\Models\Agent\AgentAnnouncement;
use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentPanicAlert;
use App\Models\Agent\AgentStaff;
use App\Models\Agent\AgentTellerRequest;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use App\Services\AgentTellerRequestService;
use App\Services\AgentTellerRiskService;
use App\Services\AgentTellerWorkspaceService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-TELLER-WS-001 — مساحةُ عمل الصرّاف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما يُثبت هنا ليس أنّ الشاشة تعرض أرقاماً — بل أنّ ما تعرضه يمنع
 * قراراً خاطئاً، وأنّ ما تمنعه لا يُلتفّ عليه من باب آخر.**
 */
class AgentTellerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;
    private AgentBranch $branch;
    private AgentStaff $teller;
    private AgentStaff $manager;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = new User();
        $this->company->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967774100001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '750000']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');

        $bu = new User();
        $bu->forceFill([
            'f_name' => 'فرع المكلا', 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '967774100099', 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '5000000']);

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
            'cash_on_hand' => '3000000', 'max_cash_on_hand' => '9000000', 'min_cash_alert' => '100000',
        ]);
        $this->branch = $this->branch->fresh('till');

        $this->teller = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'محمد علي', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller12',
            'max_txn_amount' => '50000',
        ]);
        $this->manager = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'مدير المكلا', 'role' => AgentStaff::ROLE_BRANCH_MANAGER,
            'branch_id' => $this->branch->id, 'password' => 'manager1',
        ]);

        $this->customer = new User();
        $this->customer->forceFill([
            'f_name' => 'راشد', 'l_name' => 'معرابي', 'phone' => '967783545525',
            'type' => CUSTOMER_TYPE, 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();
        EMoney::create(['user_id' => $this->customer->id, 'current_balance' => '10000']);
    }

    private function ws(): AgentTellerWorkspaceService
    {
        return app(AgentTellerWorkspaceService::class);
    }

    // ══════════════════════════════════════════════════════════════════
    // ٦) الصلاحيات
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **لا أحد يملك تعديل رصيدٍ ولا إلغاء عمليّة — ولا الإدارة العامّة.**
     *
     * فتعديلُ رصيدٍ من بوّابة الوكيل يعني أنّه يستطيع أن يمنح نفسه مالاً
     * بلا قيدٍ مقابل، وإلغاءُ عمليّةٍ منفَّذة يعني محوَ أثرِ حركةٍ وقعت.
     * وكلاهما يمرّ بأميال لا بالوكيل.
     */
    public function no_role_in_the_agent_portal_can_adjust_a_balance_or_cancel_an_operation(): void
    {
        foreach ([$this->teller, $this->manager, $this->hq] as $s) {
            $this->assertFalse($s->hasCapability('adjust_balance'),
                "الدور {$s->role} يملك تعديل رصيد — وذلك بابٌ لمنح المال بلا قيد");
            $this->assertFalse($s->hasCapability('cancel_operation'),
                "الدور {$s->role} يملك إلغاء عمليّة — وذلك محوٌ لأثر حركةٍ وقعت");
        }
    }

    /** @test */
    public function a_teller_may_deposit_and_withdraw_but_not_override_limits(): void
    {
        $this->assertTrue($this->teller->hasCapability('deposit'));
        $this->assertTrue($this->teller->hasCapability('withdraw'));
        $this->assertFalse($this->teller->hasCapability('override_limit'));
        $this->assertFalse($this->teller->hasCapability('manage_staff'));
    }

    /**
     * @test
     *
     * **التخصيص يضيّق ولا يوسّع.** ولو وسّع لصار عمودُ `capabilities`
     * باباً خلفياً يمنح صرّافاً صلاحيات الإدارة بتعديل صفٍّ واحد.
     */
    public function custom_capabilities_can_only_narrow_never_widen(): void
    {
        $this->teller->forceFill(['capabilities' => [
            'deposit' => false,          // تضييق — يُطبَّق
            'manage_staff' => true,      // توسيع — يُتجاهل
            'adjust_balance' => true,    // توسيع — يُتجاهل
        ]])->save();

        $fresh = $this->teller->fresh();

        $this->assertFalse($fresh->hasCapability('deposit'), 'التضييق لم يُطبَّق');
        $this->assertFalse($fresh->hasCapability('manage_staff'), 'التوسيع طُبِّق — وهو بابٌ خلفيّ');
        $this->assertFalse($fresh->hasCapability('adjust_balance'), 'التوسيع طُبِّق على أخطر صلاحية');
    }

    // ══════════════════════════════════════════════════════════════════
    // ٣) الحدود
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **صفرٌ يعني «بلا حدّ خاصّ» لا «ممنوع».** وقلبُ القراءة يُقفل كلّ
     * شبّاكٍ في النظام دفعةً واحدة.
     */
    public function a_zero_limit_means_no_limit_not_a_ban(): void
    {
        $this->teller->forceFill(['max_txn_amount' => '0', 'daily_limit' => '0'])->save();

        $l = $this->ws()->limits($this->teller->fresh());

        $this->assertNull($l['per_operation'], 'صفرٌ قُرئ حدّاً — وذلك يُقفل الشبّاك');
        $this->assertNull($l['daily']);
        $this->assertNull($l['daily_remaining']);
    }

    /**
     * @test
     *
     * المستهلَك يُحسب من العمليّات نفسها — لا من عمودٍ تجميعيّ.
     */
    public function the_used_amount_is_computed_from_the_movements_themselves(): void
    {
        $this->teller->forceFill(['daily_limit' => '500000'])->save();

        $shift = app(AgentShiftService::class)->open($this->teller, '100000');

        app(AgentShiftService::class)->recordDrawer(
            $shift, 'in', 'customer_deposit', '30000',
            customerId: $this->customer->id, reference: 'T1',
        );
        app(AgentShiftService::class)->recordDrawer(
            $shift, 'in', 'customer_deposit', '20000',
            customerId: $this->customer->id, reference: 'T2',
        );

        $l = $this->ws()->limits($this->teller->fresh());

        $this->assertSame(0, bccomp('50000', $l['daily_used'], 4));
        $this->assertSame(0, bccomp('450000', $l['daily_remaining'], 4));
        $this->assertSame(2, $l['count_used']);
    }

    // ══════════════════════════════════════════════════════════════════
    // ١٨) المؤشّرات  ·  ٨) الأنظمة
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **«لا ورديّة» ليست «درجٌ فيه صفر»** — القاعدة السابعة. وصرّافٌ يقرأ
     * صفراً يظنّ درجه فارغاً وهو لم يفتحه بعد.
     */
    public function no_open_shift_reports_unknown_not_zero(): void
    {
        $m = $this->ws()->build($this->teller);

        $this->assertFalse($m['kpis']['drawer_known']);
        $this->assertNull($m['kpis']['drawer_cash']);
    }

    /** @test */
    public function an_open_shift_reports_the_drawer_and_the_branch_separately(): void
    {
        app(AgentShiftService::class)->open($this->teller, '100000');

        $m = $this->ws()->build($this->teller->fresh());

        $this->assertTrue($m['kpis']['drawer_known']);
        $this->assertSame(0, bccomp('100000', $m['kpis']['drawer_cash'], 4));
        // الخزنة نقصت بالعهدة — ودرجُه غيرُ خزنة فرعه.
        $this->assertSame(0, bccomp('2900000', $m['kpis']['branch_safe'], 4));
    }

    /**
     * @test
     *
     * **لا نقطةَ خضراء أمام جهازٍ لم نسأله.**
     *
     * فأخضرُ أمام «الطابعة» يعني أنّنا نقول للصرّاف «طابعتك تعمل» ونحن لا
     * نتّصل بها أصلاً — فيَعِد العميل بإيصالٍ لا يُطبَع.
     */
    public function unintegrated_devices_are_reported_as_unintegrated_not_healthy(): void
    {
        $systems = collect($this->ws()->systems())->keyBy('key');

        foreach (['printer', 'qr_scanner', 'fingerprint', 'ups'] as $device) {
            $this->assertSame('not_integrated', $systems[$device]['state'],
                "الجهاز {$device} يُعرَض بحالةٍ غير «غير مربوط» — وهي كذبةٌ يبني عليها الصرّاف وعداً");
        }

        // وما يُقاس فعلاً يُقاس: قاعدة البيانات نداءٌ حقيقيّ.
        $this->assertSame('ok', $systems['database']['state']);
    }

    // ══════════════════════════════════════════════════════════════════
    // ٤) إشارات الخطر
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_blacklisted_customer_is_blocked_not_merely_flagged(): void
    {
        // القائمة السوداء يقرّرها `CustomerStatusResolver` من ملفّ المخاطر
        // — لا من عمودٍ في `users`. والاختبار يسلك الطريق الحقيقيّ.
        \App\Models\Aml\AmlUserRiskProfile::create([
            'user_id' => $this->customer->id,
            'manual_override' => 'blacklist',
        ]);

        $out = app(AgentTellerRiskService::class)
            ->assess($this->customer->fresh(), '5000', 'deposit', $this->teller);

        $this->assertTrue($out['blocked'], 'عميلٌ محظورٌ مرّ — وهذا أخطر ما في الشاشة');
    }

    /** الإيقاف المؤقت لا يبقى شارة في لوحة الإدارة؛ يمنع المال عند الشباك. */
    public function a_suspended_customer_is_blocked_at_the_agent_counter(): void
    {
        $this->customer->forceFill(['lifecycle_state' => 'suspended'])->save();

        $out = app(AgentTellerRiskService::class)
            ->assess($this->customer->fresh(), '5000', 'deposit', $this->teller);

        $this->assertTrue($out['blocked']);
        $this->assertNotEmpty(array_filter($out['flags'], fn ($f) => $f['key'] === 'customer_status'));
    }

    /**
     * @test
     *
     * **الإشارة الصفراء تُنبّه ولا تمنع.** ولو منعت لصار كلّ عميلٍ أودع
     * ضعف معتاده ممنوعاً — فيتعطّل الفرع بلا سبب.
     */
    public function a_yellow_flag_warns_without_stopping_the_branch(): void
    {
        $this->teller->forceFill(['max_txn_amount' => '10000'])->save();

        $out = app(AgentTellerRiskService::class)
            ->assess($this->customer, '50000', 'deposit', $this->teller->fresh());

        $this->assertFalse($out['blocked']);
        $this->assertNotEmpty(array_filter($out['flags'], fn ($f) => $f['key'] === 'over_limit'));
    }

    /**
     * @test
     *
     * **وكلّ إشارةٍ تحمل نصيحةً.** إشارةٌ لا تقول ماذا يُفعل بها تُتجاهل
     * في الأسبوع الثاني.
     */
    public function every_flag_carries_advice_and_a_reason(): void
    {
        $this->teller->forceFill(['max_txn_amount' => '10000'])->save();

        $out = app(AgentTellerRiskService::class)
            ->assess($this->customer, '50000', 'deposit', $this->teller->fresh());

        foreach ($out['flags'] as $f) {
            $this->assertNotEmpty($f['title'], 'إشارةٌ بلا عنوان');
            $this->assertNotEmpty($f['advice'], "الإشارة {$f['key']} بلا نصيحة — ستُتجاهل");
        }
    }

    /**
     * @test
     *
     * **تاريخٌ قصيرٌ لا يُبنى عليه حكم.** عميلٌ بعمليّتين لا يُقاس عليه
     * متوسّط — وإنذارٌ من عيّنةٍ صغيرة إنذارٌ كاذب.
     */
    public function a_thin_history_produces_no_unusual_amount_alarm(): void
    {
        $shift = app(AgentShiftService::class)->open($this->teller, '100000');

        for ($i = 0; $i < 2; $i++) {
            app(AgentShiftService::class)->recordDrawer(
                $shift, 'in', 'customer_deposit', '1000',
                customerId: $this->customer->id, reference: 'H' . $i,
            );
        }

        $out = app(AgentTellerRiskService::class)
            ->assess($this->customer, '900000', 'deposit', $this->teller);

        $this->assertEmpty(array_filter($out['flags'], fn ($f) => $f['key'] === 'unusual_amount'));
    }

    // ══════════════════════════════════════════════════════════════════
    // ١٧) الموافقات
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_request_requires_a_written_reason(): void
    {
        $this->expectException(DomainException::class);

        app(AgentTellerRequestService::class)->submit($this->teller, [
            'amount' => '90000', 'reason' => 'مهم',
        ]);
    }

    /**
     * @test
     *
     * **لا يُوافق أحدٌ على طلب نفسه.**
     */
    public function nobody_approves_their_own_request(): void
    {
        // مديرٌ يطلب لنفسه (السيناريو يقع حين تتغيّر الأدوار).
        $row = app(AgentTellerRequestService::class)->submit($this->manager, [
            'amount' => '90000', 'reason' => 'سببٌ مكتوبٌ كافٍ للاختبار',
        ]);

        $this->expectException(DomainException::class);
        app(AgentTellerRequestService::class)->decide($this->manager, $row->id, true, 'موافق');
    }

    /** @test */
    public function a_teller_cannot_decide_a_request(): void
    {
        $row = app(AgentTellerRequestService::class)->submit($this->teller, [
            'amount' => '90000', 'reason' => 'سببٌ مكتوبٌ كافٍ للاختبار',
        ]);

        $other = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'صرّاف ثانٍ', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller34',
        ]);

        $this->expectException(DomainException::class);
        app(AgentTellerRequestService::class)->decide($other, $row->id, true, '');
    }

    /**
     * @test
     *
     * **الموافقة تُستهلك مرّةً واحدة.** ولو استُعملت مرّتين لصار مديرٌ
     * وافق على مليونٍ قد مرّ باسمه مليونان.
     */
    public function an_approval_is_consumed_exactly_once(): void
    {
        $row = app(AgentTellerRequestService::class)->submit($this->teller, [
            'amount' => '90000', 'operation' => 'deposit',
            'reason' => 'العميل تاجرٌ ومعه فاتورة موثّقة',
        ]);

        app(AgentTellerRequestService::class)->decide($this->manager, $row->id, true, 'موافق');

        $svc = app(AgentTellerRequestService::class);

        $this->assertNotNull($svc->consumeFor($this->teller, 'deposit', '90000'), 'لم تُستعمل الموافقة');
        $this->assertNull($svc->consumeFor($this->teller, 'deposit', '90000'), 'استُعملت الموافقة مرّتين');
    }

    /**
     * @test
     *
     * موافقةٌ على مليونٍ تُغطّي ما دونه، ولا تُغطّي ما فوقه.
     */
    public function an_approval_never_covers_a_larger_amount(): void
    {
        $row = app(AgentTellerRequestService::class)->submit($this->teller, [
            'amount' => '90000', 'operation' => 'deposit',
            'reason' => 'العميل تاجرٌ ومعه فاتورة موثّقة',
        ]);
        app(AgentTellerRequestService::class)->decide($this->manager, $row->id, true, 'موافق');

        $svc = app(AgentTellerRequestService::class);

        $this->assertNull($svc->consumeFor($this->teller, 'deposit', '200000'),
            'موافقةٌ على ٩٠ ألفاً غطّت ٢٠٠ ألف');
        $this->assertNotNull($svc->consumeFor($this->teller, 'deposit', '80000'));
    }

    /** @test */
    public function an_expired_request_cannot_be_approved(): void
    {
        $row = app(AgentTellerRequestService::class)->submit($this->teller, [
            'amount' => '90000', 'reason' => 'سببٌ مكتوبٌ كافٍ للاختبار',
        ]);

        $row->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->expectException(DomainException::class);
        app(AgentTellerRequestService::class)->decide($this->manager, $row->id, true, 'موافق');
    }

    /** @test */
    public function a_rejection_demands_a_written_reason(): void
    {
        $row = app(AgentTellerRequestService::class)->submit($this->teller, [
            'amount' => '90000', 'reason' => 'سببٌ مكتوبٌ كافٍ للاختبار',
        ]);

        $this->expectException(DomainException::class);
        app(AgentTellerRequestService::class)->decide($this->manager, $row->id, false, 'لا');
    }

    /**
     * @test
     *
     * **لقطةُ الحدّ تُحفظ لحظةَ الطلب.** فمن يقرأ الطلب بعد شهرٍ يجب أن
     * يرى ما مُنع فعلاً، لا ما يُحسب اليوم بحدودٍ تغيّرت.
     */
    public function the_limit_snapshot_is_frozen_at_request_time(): void
    {
        $row = app(AgentTellerRequestService::class)->submit($this->teller, [
            'amount' => '90000', 'reason' => 'سببٌ مكتوبٌ كافٍ للاختبار',
        ]);

        $this->teller->forceFill(['max_txn_amount' => '9000000'])->save();

        $this->assertSame(0, bccomp('50000', $row->fresh()->limit_snapshot['per_operation'], 4),
            'اللقطة تتبع الحدّ الحاليّ — فتاريخُ القرار يُعاد كتابته');
    }

    // ══════════════════════════════════════════════════════════════════
    // ١٣) الطوارئ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_panic_alert_records_what_arrived_and_names_what_did_not(): void
    {
        $alert = app(AgentTellerRequestService::class)->panic($this->teller, [
            'kind' => 'duress', 'geo_state' => 'insecure',
        ]);

        $this->assertSame('open', $alert->status);
        $this->assertSame('insecure', $alert->geo_state);
        // «غير متاح» ليس «رفض الموظّف» — ولكلٍّ سببُه المكتوب.
        $this->assertArrayHasKey('insecure', AgentPanicAlert::GEO_LABELS);
        $this->assertNull($alert->lat, 'حُفظ موقعٌ مع حالةٍ تقول إنّه لم يصل');
    }

    /**
     * @test
     *
     * **الإحداثيّات لا تُحفظ إلّا مع `ok`.** وحفظُها مع «مرفوض» يعني
     * موقعاً مختلَقاً يُرسَل إليه من يبحث عن موظّفٍ في خطر.
     */
    public function coordinates_are_stored_only_when_the_geo_state_says_ok(): void
    {
        $alert = app(AgentTellerRequestService::class)->panic($this->teller, [
            'kind' => 'robbery', 'geo_state' => 'denied', 'lat' => 14.5, 'lng' => 49.1,
        ]);

        $this->assertNull($alert->lat);
        $this->assertNull($alert->lng);

        $ok = app(AgentTellerRequestService::class)->panic($this->teller, [
            'kind' => 'robbery', 'geo_state' => 'ok', 'lat' => 14.5, 'lng' => 49.1,
        ]);

        $this->assertSame(0, bccomp('14.5', (string) $ok->lat, 4));
    }

    // ══════════════════════════════════════════════════════════════════
    // ١٢) التعاميم
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **تعميمُ شركةٍ لا يُقرأ في شركةٍ أخرى.**
     */
    public function a_companys_announcement_never_leaks_to_another_company(): void
    {
        $rival = new User();
        $rival->forceFill([
            'f_name' => 'منافس', 'l_name' => 'للصرافة', 'phone' => '967774100777',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ])->save();

        AgentAnnouncement::create([
            'agent_user_id' => $rival->id, 'audience' => 'all', 'severity' => 'critical',
            'title' => 'سرُّ المنافس', 'body' => 'لا يُقرأ عندنا', 'is_active' => true,
        ]);

        AgentAnnouncement::create([
            'agent_user_id' => null, 'audience' => 'all', 'severity' => 'info',
            'title' => 'تعميم أميال', 'body' => 'للجميع', 'is_active' => true,
        ]);

        $titles = collect($this->ws()->build($this->teller)['announcements'])->pluck('title');

        $this->assertContains('تعميم أميال', $titles);
        $this->assertNotContains('سرُّ المنافس', $titles, 'تسرّب تعميمُ شركةٍ إلى موظّف شركةٍ أخرى');
    }

    /** @test */
    public function an_expired_announcement_is_not_shown(): void
    {
        AgentAnnouncement::create([
            'agent_user_id' => null, 'audience' => 'all', 'severity' => 'info',
            'title' => 'قديم', 'body' => 'انتهى', 'is_active' => true,
            'ends_at' => now()->subDay(),
        ]);

        $this->assertEmpty($this->ws()->build($this->teller)['announcements']);
    }

    // ══════════════════════════════════════════════════════════════════
    // ١٠) العملاء الأخيرون
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **قائمةُ الصرّاف قائمتُه هو.** ولو ضمّت عملاء الفرع لعرف من خدمهم
     * زميلُه وبكم — وهي معلومةٌ لا يحتاجها لعمله.
     */
    public function the_recent_list_is_the_tellers_own_never_the_branchs(): void
    {
        $other = app(AgentStaffService::class)->hire($this->hq, [
            'name' => 'زميل', 'role' => AgentStaff::ROLE_TELLER,
            'branch_id' => $this->branch->id, 'password' => 'teller99',
        ]);

        $shift = app(AgentShiftService::class)->open($other, '50000');
        app(AgentShiftService::class)->recordDrawer(
            $shift, 'in', 'customer_deposit', '5000',
            customerId: $this->customer->id, reference: 'OTHER',
        );

        $rows = $this->ws()->build($this->teller)['recent_customers'];

        $this->assertEmpty($rows, 'رأى الصرّافُ عملاء زميله');
    }
}
