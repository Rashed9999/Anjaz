<?php

namespace Tests\Feature;

use App\Models\Aml\AmlInvestigation;
use App\Models\Aml\AmlUserRiskProfile;
use App\Models\EMoney;
use App\Models\KycDocument;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\CustomerActionService;
use App\Services\CustomerStatusResolver;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-CENTER-001 — الفصل ٠٢: مركز العملاء.
 *
 * **الشرط الذي يفرضه الفصل حرفياً:** «يجب أن يستطيع موظف خدمة العملاء إدارة
 * العميل **بالكامل من شاشة واحدة** دون الحاجة للانتقال بين أكثر من لوحة».
 *
 * ويشترط أيضاً — وهذه شروطٌ تُختبَر لا تُدَّعى:
 *   • **سجلّ تلقائيّ** لكلّ بحث وكلّ فتح ملفّ وكلّ إجراء.
 *   • **أداء**: فتح الملفّ ≤ ثانية، والبحث ≤ ٥٠٠ms.
 */
class CustomerCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = $this->platformAdmin('967770003301');
        $this->customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '770003302',
            'f_name' => 'أحمد', 'l_name' => 'صالح', 'zone_code' => 'SOUTH',
        ]);
        EMoney::updateOrCreate(['user_id' => $this->customer->id],
            ['current_balance' => '150000', 'held_balance' => '20000', 'zone_code' => 'SOUTH']);
    }

    private function platformAdmin(string $phone): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => $phone]);
        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');
        DB::table('admin_user_roles')->insert([
            'user_id' => $u->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u->fresh();
    }

    // ── شرط الوثيقة: شاشة واحدة ─────────────────────────────────────────

    /** @test */
    public function all_ten_tabs_answer_from_the_one_screen(): void
    {
        // الشرط الحرفيّ في الفصل. ولو سقط تبويبٌ واحد لعاد الموظّف يقفز بين
        // اللوحات — وهو ما بُنيت هذه الشاشة لإنهائه.
        foreach ([
            'overview', 'wallets', 'transactions', 'devices', 'authentication',
            'kyc', 'risk', 'support', 'notifications', 'audit',
        ] as $tab) {
            $this->actingAs($this->staff, 'user')
                ->getJson("/admin/amial/customer/{$this->customer->id}/tab/{$tab}")
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    /** طلب إعادة الهوية يجب أن يُعرض ولا ينهار بسبب تاريخٍ نصّيّ من قاعدة البيانات. */
    public function kyc_tab_renders_an_active_kyc_update_request(): void
    {
        $this->customer->forceFill([
            'kyc_update_required' => 1,
            'kyc_update_requested_at' => now(),
        ])->save();

        $response = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/kyc")
            ->assertOk()
            ->assertJsonPath('meta.update_required', true)
            ->assertJsonPath('meta.reconciliation.state', 'update_required');

        $this->assertNotEmpty($response->json('meta.update_requested_at'));
    }

    /** @test */
    public function support_can_trace_money_requests_from_the_customer_file(): void
    {
        $other = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '770003399',
            'f_name' => 'سالم', 'l_name' => 'المقطري', 'zone_code' => 'SOUTH',
        ]);
        $request = PaymentRequest::create([
            'request_ulid' => (string) Str::ulid(),
            'short_code' => 'ABC234',
            'requester_user_id' => $this->customer->id,
            'recipient_user_id' => $other->id,
            'recipient_phone' => $other->phone,
            'amount' => '7500.0000',
            'share_method' => PaymentRequest::SHARE_DIRECT,
            'status' => 'paid',
            'paid_by_user_id' => $other->id,
            'paid_transaction_id' => (string) Str::ulid(),
            'paid_at' => now(),
            'expires_at' => now()->addDay(),
            'zone_code' => 'SOUTH',
        ]);

        $row = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/support")
            ->assertOk()->json('meta.payment_requests.0');

        $this->assertSame($request->request_ulid, $row['request_ulid']);
        $this->assertSame('outgoing', $row['direction']);
        $this->assertSame('سالم المقطري', $row['counterparty']);
        $this->assertSame($request->paid_transaction_id, $row['paid_transaction_id']);
    }

    /** @test */
    public function the_available_balance_is_computed_not_left_to_the_operator(): void
    {
        // من يقرأ «الرصيد ١٥٠٠٠٠» ولا يرى «محجوز ٢٠٠٠٠» يعِد العميل بما لا
        // يستطيع أن يعطيه.
        $w = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/overview")
            ->json('meta.wallet');

        $this->assertSame(0, bccomp($w['available_balance'], '130000', 4),
            'المتاح لم يُحسب — عُرض الرصيد كاملاً وفيه محجوز');
    }

    // ── الحالة تُحسب ولا تُخزَّن ────────────────────────────────────────

    /** @test */
    public function the_status_follows_the_facts_not_a_stored_column(): void
    {
        $resolver = app(CustomerStatusResolver::class);

        // تجميدٌ من أيّ مسارٍ آخر — بلا لمس أيّ عمود حالة.
        $this->customer->forceFill(['is_temp_blocked' => 1])->save();

        $this->assertSame(CustomerStatusResolver::FROZEN,
            $resolver->resolve($this->customer->fresh())['status'],
            'الحالة لا تتبع الحقيقة — عمودٌ مخزَّن ينحرف عن الواقع');
    }

    /** @test */
    public function the_most_restrictive_status_wins_not_the_latest(): void
    {
        // عميلٌ مدرَجٌ في القائمة السوداء **وهويّته معلّقة** يجب أن يُعرَض
        // «مدرَج»، لا «هويّة معلّقة»: الأخيرة تدعو الموظّف إلى أن يطلب منه
        // مستنداً ويكمل معه، والأولى تقول له قف.
        // القيمة تُضبط صراحةً لا تُترك للافتراضيّ: اختبارٌ يعتمد على
        // افتراضيّ قاعدة البيانات يسقط يوم يتغيّر، ولا يقول لماذا.
        $this->customer->forceFill(['is_kyc_verified' => 0])->save();

        AmlUserRiskProfile::create([
            'user_id' => $this->customer->id, 'current_risk_score' => 90,
            'risk_level' => 'critical', 'manual_override' => 'blacklist',
        ]);

        $out = app(CustomerStatusResolver::class)->resolve($this->customer->fresh());

        $this->assertSame(CustomerStatusResolver::BLACKLISTED, $out['status']);

        // وكلّ الأسباب تُجمع لا الفائز وحده: من يرفع القائمة السوداء ولا
        // يعرف أنّ الهويّة معلّقة أيضاً سيتفاجأ بأنّ الحساب ما زال محدوداً.
        $this->assertGreaterThan(1, count($out['reasons']),
            'عُرض سببٌ واحد — فتبقى بقيّة القيود مخفيّة');
    }

    /**
     * @test
     *
     * AMIAL-KYC-FLAG-001 — علامة التوثيق تُقرأ كما يقرؤها الكود الذي يمنع.
     *
     * **عطلٌ قائم كُشف أثناء بناء هذه الشاشة، وأثرُه يوميّ:**
     *
     * العمود `is_kyc_verified` من نوع `tinyint` وقيمته الافتراضية **٣** لا
     * صفر. والكود الذي يحرس المال يقرؤه `!= 1` — فالتحويل والنزاعات والسحب
     * **ممنوعة** على كلّ عميلٍ جديد.
     *
     * أمّا شاشات الموظّفين فكانت تقرؤه `(bool)`، و٣ صادقةٌ منطقياً — فتعرض
     * «موثَّق ✓» لعميلٍ يمنعه النظام. فيرى موظّف الدعم «موثَّق» ويقول للعميل
     * إنّ مشكلته في مكانٍ آخر، والمانعُ هو الهويّة بعينها.
     */
    public function the_verified_badge_matches_what_the_money_code_enforces(): void
    {
        // القيمة الافتراضية — لم تُلمَس.
        $fresh = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '770003310']);

        $this->assertNotSame(1, (int) $fresh->is_kyc_verified,
            'تغيّرت القيمة الافتراضية — راجع هذا الاختبار');

        // الحالة تقول «معلّقة» لا «نشط».
        $out = app(CustomerStatusResolver::class)->resolve($fresh);
        $this->assertContains(CustomerStatusResolver::KYC_PENDING,
            [$out['status']], 'عميلٌ يمنعه النظام يُعرَض غير معلّق الهويّة');

        // ومركز الدعم يقول الشيء نفسه — لا «موثَّق ✓».
        $profile = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/support-center/customers/{$fresh->id}")
            ->assertOk()->json('meta.kyc.is_verified');

        $this->assertFalse($profile,
            'مركز الدعم يعرض «موثَّق» لعميلٍ يمنعه الكود من التحويل');

        // وبالقيمة الحقيقية للتوثيق يصير موثَّقاً في الشاشتين.
        $fresh->forceFill(['is_kyc_verified' => 1])->save();
        $this->assertSame(CustomerStatusResolver::ACTIVE,
            app(CustomerStatusResolver::class)->resolve($fresh->fresh())['status']);
    }

    /** @test */
    public function the_customer_centre_never_calls_a_non_approved_kyc_value_verified(): void
    {
        // القيمة 3 هي «لم يقدّم بعد» في قاعدة البيانات القديمة، وليست موافقة.
        $this->customer->forceFill(['is_kyc_verified' => 3])->save();

        $overview = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/overview")
            ->assertOk()->json('meta.kyc');
        $kyc = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/kyc")
            ->assertOk()->json('meta');

        $this->assertFalse($overview['is_verified']);
        $this->assertFalse($kyc['is_verified']);
        $this->assertSame('not_submitted', $kyc['account_state']);
    }

    /** @test */
    public function kyc_reconciliation_exposes_a_legacy_verified_account_without_documents(): void
    {
        $this->customer->forceFill(['is_kyc_verified' => 1, 'kyc_tier' => 2])->save();

        $kyc = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/kyc")
            ->assertOk()->json('meta');

        $this->assertTrue($kyc['is_verified']);
        $this->assertSame('verified_without_document_record', $kyc['reconciliation']['state']);
        $this->assertSame('warning', $kyc['reconciliation']['severity']);
        $this->assertFalse($kyc['completeness']['complete']);
    }

    /** @test */
    public function kyc_reconciliation_never_treats_complete_documents_as_an_account_approval(): void
    {
        $this->customer->forceFill(['is_kyc_verified' => 0, 'kyc_tier' => 0])->save();
        foreach ([
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE,
        ] as $type) {
            KycDocument::create([
                'user_id' => $this->customer->id,
                'doc_type' => $type,
                'encrypted_path' => 'tests/kyc/' . $type,
                'status' => KycDocument::STATUS_APPROVED,
            ]);
        }

        $kyc = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/kyc")
            ->assertOk()->json('meta');

        $this->assertFalse($kyc['is_verified']);
        $this->assertTrue($kyc['completeness']['complete']);
        $this->assertSame('documents_complete_pending_account_decision', $kyc['reconciliation']['state']);
    }

    /** @test */
    public function an_unreconciled_wallet_is_exposed_as_a_financial_exception_not_a_green_balance(): void
    {
        // رصيد المحفظة يُحدَّث مباشرةً هنا عمداً من دون تمرير قيد جديد،
        // فنصنع فرقاً واقعياً بين مصدر التشغيل والدفتر. إخفاؤه خلف بطاقة
        // «الرصيد الحالي» يخلق طمأنينة مالية كاذبة.
        DB::table('e_money')->where('user_id', $this->customer->id)
            ->update(['current_balance' => '140000']);

        $financial = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/overview")
            ->assertOk()->json('meta.financial_truth');

        $this->assertSame('mismatch', $financial['state']);
        $this->assertSame('140000', $financial['operational_balance']);
        $this->assertSame('150000.0000', $financial['ledger_balance']);
        $this->assertSame('-10000.0000', $financial['gap']);
    }

    /** غياب تقييم المخاطر ليس درجة صفرية مطمئنة للموظف. */
    public function an_unassessed_risk_profile_is_not_presented_as_zero_risk(): void
    {
        $overview = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/overview")
            ->assertOk()->json('meta.risk');

        $this->assertSame('unassessed', $overview['state']);
        $this->assertNull($overview['score']);
        $this->assertSame('unassessed', $overview['level']);
    }

    /** @test */
    public function a_closed_account_outranks_everything_else(): void
    {
        $this->customer->forceFill([
            'lifecycle_state' => 'closed', 'is_temp_blocked' => 1,
        ])->save();

        $this->assertSame(CustomerStatusResolver::CLOSED,
            app(CustomerStatusResolver::class)->resolve($this->customer->fresh())['status']);
    }

    // ── شرط الوثيقة: السجلّ التلقائيّ ───────────────────────────────────

    /** @test */
    public function opening_a_tab_is_logged_automatically(): void
    {
        // الوثيقة: «يسجل النظام تلقائياً: فتح صفحة العميل». ويُسجَّل لكلّ
        // تبويب لا للصفحة وحدها — من يفتح «المخاطر» يطّلع على ما هو أشدّ
        // حساسيةً ممّن يقرأ الرصيد.
        $before = DB::table('pii_access_logs')->count();

        $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/risk")->assertOk();

        $log = DB::table('pii_access_logs')->orderByDesc('id')->first();

        $this->assertGreaterThan($before, DB::table('pii_access_logs')->count(),
            'فُتح تبويب المخاطر بلا تسجيل — والوثيقة تشترطه');
        $this->assertSame($this->staff->id, (int) $log->actor_user_id);
        $this->assertStringContainsString('risk', (string) $log->field_name);
    }

    /** @test */
    public function every_search_is_logged(): void
    {
        // بحثُ موظّفٍ عن أسماء لا علاقة لها بعمله أوّل ما يظهر في مراجعة
        // الأمن الداخليّ — ولا يظهر إن لم يُسجَّل.
        $before = DB::table('pii_access_logs')->where('field_name', 'customer_search')->count();

        $this->actingAs($this->staff, 'user')
            ->getJson('/admin/amial/customer/search?q=أحمد')->assertOk();

        $this->assertSame($before + 1,
            DB::table('pii_access_logs')->where('field_name', 'customer_search')->count(),
            'بحثٌ لم يُسجَّل');
    }

    /** @test */
    public function search_audit_never_keeps_the_raw_phone_and_search_never_returns_non_customers(): void
    {
        $agent = User::factory()->create([
            'type' => AGENT_TYPE, 'phone' => '770003399', 'f_name' => 'عميل مزيف',
        ]);

        $this->actingAs($this->staff, 'user')
            ->getJson('/admin/amial/customer/search?q=770003399')
            ->assertOk()
            ->assertJsonPath('meta.items', []);

        $log = DB::table('pii_access_logs')->where('field_name', 'customer_search')->latest('id')->first();
        $this->assertStringNotContainsString($agent->phone, (string) $log->access_reason);
        $this->assertStringContainsString('fingerprint:', (string) $log->access_reason);
    }

    /** @test */
    public function a_support_operator_cannot_open_customer_tabs_outside_their_capability(): void
    {
        $support = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'admin', 'phone' => '967770003399',
        ]);
        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_support')->value('id');
        DB::table('admin_user_roles')->insert([
            'user_id' => $support->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($support->fresh(), 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/overview")
            ->assertOk();
        $this->actingAs($support->fresh(), 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/risk")
            ->assertForbidden();
    }

    /** القراءة لا تفتح تحقيقات أو تعدّل حدوداً أو تطلب KYC. */
    public function customer_read_permission_never_authorizes_customer_mutations(): void
    {
        $reader = User::factory()->create(['type' => ADMIN_TYPE, 'phone' => '967770003398']);
        $roleId = DB::table('roles')->whereNull('merchant_user_id')->where('code', 'platform_support')->value('id');
        DB::table('admin_user_roles')->insert(['user_id' => $reader->id, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);

        foreach (['escalate_risk', 'update_limits', 'require_kyc'] as $action) {
            $this->actingAs($reader->fresh(), 'user')
                ->postJson("/admin/amial/customer/{$this->customer->id}/action", [
                    'action' => $action, 'reason' => 'سبب اختباري مكتوب لمنع تنفيذ تعديل بلا صلاحية',
                ])->assertStatus(422);
        }

        // كتابة ملاحظة صلاحية دعمٍ منفصلة وصريحة، وليست أثراً عرضياً لقراءة
        // الملف. هذا يثبت أن المصفوفة لا تمنع الدعم من عمله المشروع.
        $this->actingAs($reader->fresh(), 'user')
            ->postJson("/admin/amial/customer/{$this->customer->id}/action", [
                'action' => 'add_note', 'reason' => 'ملاحظة دعم موثقة بعد تواصل العميل مع المركز',
            ])->assertOk();
        $this->assertDatabaseHas('customer_notes', ['user_id' => $this->customer->id, 'author_id' => $reader->id]);
    }

    // ── شرط الوثيقة: الأداء ─────────────────────────────────────────────

    /** @test */
    public function opening_the_profile_stays_under_one_second(): void
    {
        // شرطٌ صريح في الفصل. ويتحقّق بأنّ التبويبات تُحمَّل عند الضغط لا
        // عند الفتح — فزمنُ الفتح ليس مجموع أبطئها.
        //
        // (والقياس هنا تقريبيّ بطبيعته — قاعدة اختبارٍ لا إنتاج — لكنّه يمسك
        // الانحدار الفادح: استعلامٌ يمرّ على كلّ العملاء بدل واحد.)
        $start = microtime(true);

        $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/overview")->assertOk();

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed,
            sprintf('فتح الملفّ استغرق %.2f ثانية — والوثيقة تشترط ≤ ١', $elapsed));
    }

    /** @test */
    public function search_stays_under_half_a_second(): void
    {
        User::factory()->count(40)->create(['type' => CUSTOMER_TYPE]);

        $start = microtime(true);
        $this->actingAs($this->staff, 'user')
            ->getJson('/admin/amial/customer/search?q=77000')->assertOk();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.5, $elapsed,
            sprintf('البحث استغرق %.3f ثانية — والوثيقة تشترط ≤ ٠٫٥', $elapsed));
    }

    // ── الإجراءات ───────────────────────────────────────────────────────

    /** @test */
    public function no_action_runs_without_a_written_reason(): void
    {
        // إجراءاتٌ تُتّخذ تحت ضغط مكالمة ويُراجعها بعد شهرٍ من لم يحضرها.
        $this->expectException(DomainException::class);
        app(CustomerActionService::class)->run($this->customer, $this->staff, 'freeze', 'لا');
    }

    /** @test */
    public function a_staff_member_cannot_act_on_their_own_account(): void
    {
        // موظّفو المنصّة عملاء فيها. ومن يرفع تجميداً عن نفسه أو يوسّع حدوده
        // بيده يُبطل كلّ ضابطٍ فوقه.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/FOUR_EYES_VIOLATION/');

        app(CustomerActionService::class)->run(
            $this->staff, $this->staff, 'freeze', 'محاولة تجميد حسابي الشخصيّ للاختبار',
        );
    }

    /** @test */
    public function unfreezing_creates_a_second_person_approval_and_does_not_restore_access_immediately(): void
    {
        $this->customer->forceFill(['is_temp_blocked' => 1])->save();

        $out = app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'unfreeze',
            'اكتمل فحص البلاغ ونحتاج اعتماد مشرف مختلف لفك التجميد',
        );

        $this->assertTrue($out['approval_required']);
        $this->assertSame(1, (int) $this->customer->fresh()->is_temp_blocked);
        $this->assertDatabaseHas('approval_requests', [
            'subject_user_id' => $this->customer->id,
            'action_type' => 'unfreeze_wallet',
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function the_customer_action_endpoint_cannot_target_an_agent_or_employee(): void
    {
        $agent = User::factory()->create(['type' => AGENT_TYPE, 'phone' => '770003411']);

        $this->actingAs($this->staff, 'user')
            ->postJson("/admin/amial/customer/{$agent->id}/action", [
                'action' => 'freeze',
                'reason' => 'اختبار منع توجيه إجراء العميل نحو وكيل',
            ])->assertNotFound();
    }

    /** @test */
    public function escalating_to_risk_opens_a_real_case_not_a_log_line(): void
    {
        // زرٌّ يكتب «صُعِّد» ولا يُنشئ شيئاً يجعل الموظّف يظنّ أنّه سلّم
        // المسألة وهي لم تصل أحداً.
        $out = app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'escalate_risk',
            'نمط تحويلات غير معتاد أبلغ عنه فريق الدعم',
        );

        $case = AmlInvestigation::where('subject_user_id', $this->customer->id)->first();

        $this->assertNotNull($case, 'صُعِّد إلى المخاطر ولم تُفتح قضيّة');
        $this->assertSame('high', $case->priority);
        $this->assertStringContainsString($case->case_number, $out['message']);

        // والسبب يصير دليلاً في الخطّ الزمنيّ لا سطراً في سجلٍّ منفصل.
        $this->assertGreaterThanOrEqual(2, $case->events()->count(),
            'لم يُضَف سبب التصعيد إلى ملفّ القضية');
    }

    /** @test */
    public function marking_deceased_requires_a_second_person_before_freezing_the_account(): void
    {
        // تعليمٌ بلا مراجعة مستقلة يترك موظفاً واحداً قادراً على خلق حالة
        // قانونية نهائية. maker يفتح معاملة فقط؛ checker هو من يثبتها ويجمد.
        $out = app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'mark_deceased',
            'وردت شهادة وفاة من ذوي العميل عبر فرع عدن',
        );

        $fresh = $this->customer->fresh();
        $this->assertTrue($out['approval_required']);
        $this->assertNotSame('deceased', $fresh->lifecycle_state);
        $this->assertSame(0, (int) $fresh->is_temp_blocked);
    }

    /** @test */
    public function a_closed_account_is_not_reopened_from_this_screen(): void
    {
        EMoney::where('user_id', $this->customer->id)->update([
            'current_balance' => '0', 'held_balance' => '0', 'pending_balance' => '0',
        ]);
        $out = app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'close', 'طلب العميل إغلاق حسابه نهائياً',
        );

        $this->assertTrue($out['approval_required']);
        $this->assertDatabaseHas('approval_requests', [
            'subject_user_id' => $this->customer->id,
            'action_type' => 'close_customer', 'status' => 'pending',
        ]);

        // لا يُغلق عند إنشاء الطلب؛ تنفيذه حصراً في ApprovalService بعد
        // اعتماد موظف مختلف.
        $this->assertNotSame('closed', $this->customer->fresh()->lifecycle_state);
        $this->customer->forceFill(['lifecycle_state' => 'closed'])->save();

        $this->expectException(DomainException::class);
        app(CustomerActionService::class)->run(
            $this->customer->fresh(), $this->staff, 'activate', 'محاولة إعادة الفتح',
        );
    }

    /** الرصيد أو الحجز أو تحقيق AML مفتوح يمنع إغلاق الحساب قبل الاعتماد. */
    public function closing_a_customer_with_unsettled_money_fails_preflight(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/CLOSURE_PREFLIGHT_FAILED/');

        app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'close', 'طلب إغلاق لكن المحفظة لم تدخل مرحلة التسوية بعد',
        );
    }

    /** تعليم الوفاة لا يغير حالة العميل عند ضغط maker وحده. */
    public function deceased_marking_creates_a_reviewable_case_and_second_person_request(): void
    {
        $out = app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'mark_deceased',
            'استلمنا شهادة وفاة أصلية من الورثة وتحتاج مراجعة مستقلة',
        );

        $this->assertTrue($out['approval_required']);
        $request = DB::table('approval_requests')->where('subject_user_id', $this->customer->id)
            ->where('action_type', 'mark_customer_deceased')->latest('id')->first();
        $this->assertNotNull($request);
        $this->assertDatabaseHas('customer_death_cases', [
            'approval_request_id' => $request->id, 'status' => 'pending_review',
        ]);
        $this->assertNotSame('deceased', $this->customer->fresh()->lifecycle_state);
    }

    /** القضية المفتوحة تستقبل دليل التصعيد الثاني بدلاً من إنشاء قضايا مكررة. */
    public function repeated_risk_escalation_links_the_existing_open_case(): void
    {
        app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'escalate_risk', 'نمط تحويلات غير معتاد أبلغ عنه فريق الدعم',
        );
        app(CustomerActionService::class)->run(
            $this->customer->fresh(), $this->staff, 'escalate_risk', 'ورد بلاغ إضافي يثبت استمرار النمط نفسه',
        );

        $this->assertSame(1, AmlInvestigation::where('subject_user_id', $this->customer->id)->open()->count());
    }

    /** الإيقاف المؤقت حالة صريحة لا اسم آخر للخمول. */
    public function suspending_a_customer_uses_the_suspended_state(): void
    {
        app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'suspend',
            'إيقاف مؤقت حتى تنتهي مراجعة البلاغ التشغيلي',
        );

        $fresh = $this->customer->fresh();
        $this->assertSame('suspended', $fresh->lifecycle_state);
        $this->assertSame(CustomerStatusResolver::SUSPENDED,
            app(CustomerStatusResolver::class)->resolve($fresh)['status']);
    }

    /** @test */
    public function a_daily_limit_above_the_monthly_one_is_refused(): void
    {
        // يمرّ في الحفظ ويُربك في التنفيذ — فيُمنع عند المصدر.
        $this->expectException(DomainException::class);

        app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'update_limits',
            'رفع حدود العميل بناءً على نشاطه التجاريّ',
            ['max_daily_total' => '900000', 'max_monthly_total' => '500000'],
        );
    }

    /** @test */
    public function a_customer_limit_override_does_not_touch_the_tier(): void
    {
        // تعديلُ حدّ الفئة يغيّر حدود كلّ من فيها. ومن أراد استثناء عميلٍ
        // واحد فسيغيّر حدود الآلاف بلا أن ينتبه.
        app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'update_limits',
            'استثناء لعميل تاجر بحجم نشاط مرتفع',
            ['max_single_transaction' => '900000'],
        );

        $limits = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/overview")
            ->json('meta.limits');

        $this->assertTrue($limits['has_override']);
        $this->assertSame('900000', $limits['max_single_transaction']);

        // وعميلٌ آخر في الفئة نفسها لم يتأثّر.
        $other = User::factory()->create(['type' => CUSTOMER_TYPE, 'phone' => '770003399']);
        $otherLimits = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$other->id}/tab/overview")
            ->json('meta.limits');

        $this->assertFalse($otherLimits['has_override'], 'تسرّب الاستثناء إلى عميل آخر');
    }

    /** @test */
    public function changing_one_limit_keeps_the_customer_other_explicit_limits(): void
    {
        $this->customer->forceFill(['limit_override' => [
            'max_daily_total' => '500000',
            'max_monthly_total' => '3000000',
        ]])->save();

        app(CustomerActionService::class)->run(
            $this->customer->fresh(), $this->staff, 'update_limits',
            'تعديل سقف العملية فقط بعد مراجعة النشاط',
            ['max_single_transaction' => '900000'],
        );

        $override = $this->customer->fresh()->limit_override;
        $this->assertSame('900000', $override['max_single_transaction']);
        $this->assertSame('500000', $override['max_daily_total']);
        $this->assertSame('3000000', $override['max_monthly_total']);
    }

    /** @test */
    public function every_action_lands_in_the_audit_trail_as_critical(): void
    {
        app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'freeze', 'اشتباه في نشاط الحساب بعد بلاغ',
        );

        $row = DB::table('audit_decisions')
            ->where('action', 'CUSTOMER_FREEZE')->orderByDesc('id')->first();

        $this->assertNotNull($row, 'إجراءٌ على حساب عميل بلا أثر في التدقيق');
        $this->assertSame('critical', (string) $row->severity,
            'مسٌّ بحساب عميل سُجّل بدرجةٍ أقلّ من حرجة');
    }

    // ── التدقيق لكلّ عميل ───────────────────────────────────────────────

    /** @test */
    public function the_audit_tab_merges_three_sources_into_one_timeline(): void
    {
        // القصّة لا تُقرأ إن وُزّعت على ثلاثة جداول يقلّبها الموظّف بيده.
        app(CustomerActionService::class)->run(
            $this->customer, $this->staff, 'freeze', 'تجميد بعد بلاغ من العميل نفسه',
        );

        $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/overview")->assertOk();

        $items = $this->actingAs($this->staff, 'user')
            ->getJson("/admin/amial/customer/{$this->customer->id}/tab/audit")
            ->json('meta.items');

        $sources = array_unique(array_column($items, 'source'));

        $this->assertContains('قرار', $sources, 'قرارات الموظّفين غائبة عن الخطّ الزمنيّ');
        $this->assertContains('اطّلاع', $sources, 'سجلّ من اطّلع على الملفّ غائب');
    }

    // ── الصلاحية ────────────────────────────────────────────────────────

    /** @test */
    public function the_centre_requires_the_customers_view_permission(): void
    {
        $plain = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770003398',
        ]);

        $this->actingAs($plain, 'user')->get('/admin/amial/customer')->assertStatus(403);
    }

    /** @test */
    public function the_page_opens_and_is_linked_from_the_sidebar(): void
    {
        $this->actingAs($this->staff, 'user')->get('/admin/amial/customer')->assertOk();

        $this->assertStringContainsString(
            "route('admin.amial.customer.page')",
            file_get_contents(resource_path('views/admin-views/amial/partials/_sidebar.blade.php')),
            'الشاشة تعمل ولا رابط لها',
        );
    }
}
