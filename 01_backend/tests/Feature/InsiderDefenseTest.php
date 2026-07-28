<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\SecurityAlert;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InsiderWatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-INSIDER-001 — دفاعات التهديد الداخلي.
 *
 * يثبت الطبقات الأربع:
 *   1. Maker-Checker: لا تنفيذ بموظف واحد، لا اعتماد ذاتي، تنفيذ ذرّي عند الاعتماد.
 *   2. تسجيل الاطّلاع: فتح ملف عميل/بحث = سجل باسم الموظف.
 *   3. كشف الشذوذ: تجاوز العتبات يرفع تنبيهاً واحداً/نوع/يوم.
 *   4. سلسلة التجزئة: أي تعديل/حذف في سجل التدقيق يُكشف بالتحقق.
 */
class InsiderDefenseTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;
    private User $checker;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maker = User::factory()->create(['type' => 0, 'phone' => '967770001001']);
        $this->checker = User::factory()->create(['type' => 0, 'phone' => '967770001002']);

        // AMIAL-OPERATOR-RBAC-001: صار لكل مسار حسّاس صلاحيةٌ مطلوبة، وحساب
        // الإدارة بلا دور لا يملك شيئاً. وهذان يمثّلان مشرفَين حقيقيَّين،
        // فيُسند إليهما دور مدير المنصّة كما تفعل الهجرة للحسابات القائمة.
        foreach ([$this->maker, $this->checker] as $admin) {
            \Illuminate\Support\Facades\DB::table('admin_user_roles')->updateOrInsert(
                [
                    'user_id' => $admin->id,
                    'role_id' => \Illuminate\Support\Facades\DB::table('roles')
                        ->whereNull('merchant_user_id')
                        ->where('code', 'platform_admin')->value('id'),
                ],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
        $this->customer = User::factory()->create([
            'type' => 2, 'phone' => '967771666001',
            'is_temp_blocked' => 1, 'temp_block_time' => now(),
        ]);
    }

    // ==================== 1) Maker-Checker ====================

    public function test_full_maker_checker_cycle_executes_on_approval(): void
    {
        // المُقدِّم يطلب فكّ التجميد
        Passport::actingAs($this->maker, [], 'api');
        $r = $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/freeze",
            ['reason' => 'اكتمل التحقيق ولا شبهة', 'unfreeze' => true])
            ->assertStatus(202);
        $reqId = $r->json('meta.request_id');

        $this->assertTrue((bool) $this->customer->fresh()->is_temp_blocked, 'لم يُنفَّذ قبل الاعتماد');

        // مشرف مختلف يعتمد → التنفيذ يحدث
        Passport::actingAs($this->checker, [], 'api');
        $this->postJson("/api/v1/amial/admin/support/approvals/{$reqId}/approve",
            ['note' => 'راجعت الأدلة — موافق'])
            ->assertOk()
            ->assertJsonPath('meta.approval.status', 'approved');

        $fresh = $this->customer->fresh();
        $this->assertFalse((bool) $fresh->is_temp_blocked, 'نُفِّذ بعد الاعتماد');

        // الدورة كاملة في سجل التدقيق: طلب + اعتماد
        $this->assertDatabaseHas('audit_decisions', ['action' => 'APPROVAL_REQUESTED', 'actor_user_id' => $this->maker->id]);
        $this->assertDatabaseHas('audit_decisions', ['action' => 'APPROVAL_GRANTED', 'actor_user_id' => $this->checker->id]);
    }

    public function test_self_approval_is_forbidden_even_for_maker(): void
    {
        Passport::actingAs($this->maker, [], 'api');
        $reqId = $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/freeze",
            ['reason' => 'محاولة اعتماد ذاتي', 'unfreeze' => true])->json('meta.request_id');

        // نفس الموظف يحاول اعتماد طلبه — مرفوض قطعاً
        $this->postJson("/api/v1/amial/admin/support/approvals/{$reqId}/approve", [])
            ->assertStatus(403)
            ->assertJsonPath('code', 'SELF_APPROVAL_FORBIDDEN');

        $this->assertTrue((bool) $this->customer->fresh()->is_temp_blocked, 'لم يُنفَّذ شيء');
    }

    public function test_rejection_blocks_execution(): void
    {
        Passport::actingAs($this->maker, [], 'api');
        $reqId = $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/freeze",
            ['reason' => 'طلب سيُرفض', 'unfreeze' => true])->json('meta.request_id');

        Passport::actingAs($this->checker, [], 'api');
        $this->postJson("/api/v1/amial/admin/support/approvals/{$reqId}/reject",
            ['note' => 'الأدلة غير كافية — يبقى مجمَّداً'])
            ->assertOk()
            ->assertJsonPath('meta.approval.status', 'rejected');

        $this->assertTrue((bool) $this->customer->fresh()->is_temp_blocked);

        // الطلب المرفوض لا يُعتمد لاحقاً
        $this->postJson("/api/v1/amial/admin/support/approvals/{$reqId}/approve", [])
            ->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING');
    }

    public function test_expired_request_cannot_be_approved(): void
    {
        Passport::actingAs($this->maker, [], 'api');
        $reqId = $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/freeze",
            ['reason' => 'سينتهي قبل الاعتماد', 'unfreeze' => true])->json('meta.request_id');

        ApprovalRequest::where('id', $reqId)->update(['expires_at' => now()->subMinute()]);

        Passport::actingAs($this->checker, [], 'api');
        $this->postJson("/api/v1/amial/admin/support/approvals/{$reqId}/approve", [])
            ->assertStatus(410)->assertJsonPath('code', 'EXPIRED');

        $this->assertSame('expired', ApprovalRequest::find($reqId)->status);
        $this->assertTrue((bool) $this->customer->fresh()->is_temp_blocked);
    }

    public function test_reset_pin_executes_only_after_approval(): void
    {
        $this->customer->forceFill(['transaction_pin' => bcrypt('9999'), 'pin_failed_attempts' => 5])->save();

        Passport::actingAs($this->maker, [], 'api');
        $reqId = $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/reset-pin",
            ['reason' => 'عميل نسي الرمز'])->json('meta.request_id');

        $this->assertNotNull($this->customer->fresh()->transaction_pin);

        Passport::actingAs($this->checker, [], 'api');
        $this->postJson("/api/v1/amial/admin/support/approvals/{$reqId}/approve", [])->assertOk();

        $fresh = $this->customer->fresh();
        $this->assertNull($fresh->transaction_pin);
        $this->assertSame(0, (int) $fresh->pin_failed_attempts);
    }

    public function test_pending_approvals_visible_in_queue(): void
    {
        Passport::actingAs($this->maker, [], 'api');
        $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/freeze",
            ['reason' => 'طلب للقائمة', 'unfreeze' => true]);

        Passport::actingAs($this->checker, [], 'api');
        $r = $this->getJson('/api/v1/amial/admin/support/approvals?status=pending')->assertOk();

        $this->assertGreaterThanOrEqual(1, count($r->json('meta.approvals')));
        $this->assertSame('unfreeze_wallet', $r->json('meta.approvals.0.action_type'));
    }

    // ==================== 2) تسجيل الاطّلاع ====================

    public function test_viewing_customer_profile_is_logged(): void
    {
        Passport::actingAs($this->maker, [], 'api');

        $this->getJson("/api/v1/amial/admin/support/customers/{$this->customer->id}")->assertOk();

        $this->assertDatabaseHas('pii_access_logs', [
            'actor_user_id' => $this->maker->id,
            'subject_id' => $this->customer->id,
            'field_name' => 'profile_360',
            'access_type' => 'view',
        ]);
    }

    public function test_search_is_logged(): void
    {
        Passport::actingAs($this->maker, [], 'api');

        $this->getJson('/api/v1/amial/admin/support/search?q=967771666001')->assertOk();

        $this->assertDatabaseHas('pii_access_logs', [
            'actor_user_id' => $this->maker->id,
            'access_type' => 'search',
        ]);
    }

    // ==================== 3) كشف الشذوذ ====================

    public function test_excessive_views_raise_single_alert_per_day(): void
    {
        config(['amial.insider_watch.max_profile_views_per_day' => 5]);
        $svc = app(InsiderWatchService::class);

        // 7 اطّلاعات تتجاوز عتبة 5
        for ($i = 0; $i < 7; $i++) {
            $svc->logView($this->maker->id, $this->customer->id, 'profile_360');
        }

        $alerts = SecurityAlert::where('admin_id', $this->maker->id)
            ->where('alert_type', 'excessive_profile_views')->get();

        // تنبيه واحد فقط رغم تكرار التجاوز (لا إغراق)
        $this->assertCount(1, $alerts);
        $this->assertSame('critical', $alerts->first()->severity);
        $this->assertGreaterThan(5, $alerts->first()->details['profile_views']);
    }

    public function test_alert_can_be_acknowledged(): void
    {
        SecurityAlert::create([
            'admin_id' => $this->maker->id,
            'alert_type' => 'after_hours_access',
            'severity' => 'warning',
            'details' => ['after_hours' => 3],
            'status' => 'new',
            'alert_date' => now()->toDateString(),
        ]);
        $alert = SecurityAlert::first();

        Passport::actingAs($this->checker, [], 'api');
        $this->postJson("/api/v1/amial/admin/support/insider/alerts/{$alert->id}/ack")
            ->assertOk()
            ->assertJsonPath('meta.alert.status', 'acknowledged');
    }

    public function test_insider_overview_shows_activity_and_alerts(): void
    {
        $svc = app(InsiderWatchService::class);
        $svc->logView($this->maker->id, $this->customer->id, 'profile_360');
        $svc->logSearch($this->maker->id, '7716', 3);

        Passport::actingAs($this->checker, [], 'api');
        $r = $this->getJson('/api/v1/amial/admin/support/insider/overview')->assertOk();

        $activity = collect($r->json('meta.activity_today'));
        $row = $activity->firstWhere('admin_id', $this->maker->id);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['profile_views']);
        $this->assertSame(1, (int) $row['searches']);
    }

    // ==================== 4) سلسلة التجزئة ====================

    public function test_audit_chain_verifies_clean(): void
    {
        $audit = app(AuditService::class);
        for ($i = 1; $i <= 5; $i++) {
            $audit->record([
                'actor_type' => 'admin', 'actor_user_id' => $this->maker->id,
                'subject_type' => 'user', 'subject_id' => (string) $this->customer->id,
                'action' => "CHAIN_TEST_{$i}", 'decision_code' => 'OK',
            ]);
        }

        $this->assertSame(0, Artisan::call('amial:audit-verify'));
        $this->assertStringContainsString('السلسلة سليمة', Artisan::output());
    }

    public function test_tampering_with_audit_record_is_detected(): void
    {
        $audit = app(AuditService::class);
        $audit->record(['actor_type' => 'admin', 'actor_user_id' => $this->maker->id,
            'subject_type' => 'user', 'subject_id' => '1', 'action' => 'ORIGINAL', 'decision_code' => 'OK']);
        $audit->record(['actor_type' => 'admin', 'actor_user_id' => $this->maker->id,
            'subject_type' => 'user', 'subject_id' => '1', 'action' => 'SECOND', 'decision_code' => 'OK']);

        // موظف تقني محتال يعدّل سجلاً قديماً مباشرة في قاعدة البيانات
        DB::table('audit_decisions')->where('action', 'ORIGINAL')
            ->update(['reason' => 'أثر مُزوَّر بعد الحادثة']);

        $this->assertSame(1, Artisan::call('amial:audit-verify'));
        $this->assertStringContainsString('عُدِّل بعد كتابته', Artisan::output());
    }

    public function test_deleting_audit_record_breaks_the_chain(): void
    {
        $audit = app(AuditService::class);
        $ids = [];
        foreach (['A', 'B', 'C'] as $x) {
            $audit->record(['actor_type' => 'admin', 'actor_user_id' => $this->maker->id,
                'subject_type' => 'user', 'subject_id' => '1', 'action' => "DEL_{$x}", 'decision_code' => 'OK']);
        }

        // محو الأثر: حذف السجل الأوسط
        DB::table('audit_decisions')->where('action', 'DEL_B')->delete();

        $this->assertSame(1, Artisan::call('amial:audit-verify'));
        $this->assertStringContainsString('مكسور', Artisan::output());
    }
}
