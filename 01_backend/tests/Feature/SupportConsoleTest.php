<?php

namespace Tests\Feature;

use App\Models\AuditDecision;
use App\Models\EMoney;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-OPS-CONSOLE-001 — منصة عمليات الموظفين (Customer Operations Console).
 *
 * يثبت: البوابة الإدارية، البحث الموحّد (هاتف/معرّف/عملية)، ملف العميل 360°،
 * فحص العملية مع الخط الزمني، إجراءات التحقّق (تجميد/PIN/جلسات/KYC) مع
 * التدقيق الإلزامي وسبب موثَّق، دورة حياة التذكرة كاملة، ولوحة المراقبة.
 */
class SupportConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['type' => 0, 'phone' => '967770000001']);
        // AMIAL-OPERATOR-RBAC-001: صار لكل مسار حسّاس صلاحيةٌ مطلوبة، وحساب
        // الإدارة بلا دور لا يملك شيئاً (الافتراض منعٌ لا سماح). وهذا المدير
        // يمثّل مالك اللوحة، فيُسند إليه دور مدير المنصّة كما تفعل الهجرة.
        $this->grantPlatformAdmin($this->admin);
        $this->customer = User::factory()->create([
            'type' => 2, 'phone' => '967771555001',
            'f_name' => 'سالم', 'l_name' => 'الحضرمي',
        ]);
        EMoney::create([
            'user_id' => $this->customer->id,
            'current_balance' => '25000.0000',
            'charge_earned' => '0',
            'pending_balance' => '0', 'held_balance' => '0',
            'zone_code' => 'SOUTH', 'version' => 1,
        ]);
    }

    private function actAsAdmin(): void
    {
        Passport::actingAs($this->admin, [], 'api');
    }

    // ==================== البوابة ====================

    public function test_non_admin_is_forbidden(): void
    {
        Passport::actingAs($this->customer, [], 'api');

        $this->getJson('/api/v1/amial/admin/support/search?q=777')
            // **الرمزُ صار `PERMISSION_DENIED`** موحَّداً مع بقيّة البوّابات —
            // و`FORBIDDEN` كان اسماً محلّيّاً لهذه النقطة وحدها.
            ->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->getJson('/api/v1/amial/admin/support/ops-dashboard')->assertStatus(403);
        $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/freeze",
            ['reason' => 'اختبار وصول'])->assertStatus(403);
    }

    // ==================== البحث ====================

    public function test_search_finds_customer_by_phone_any_format(): void
    {
        $this->actAsAdmin();

        foreach (['967771555001', '+967771555001', '00967771555001', '0771555001'] as $format) {
            $this->getJson('/api/v1/amial/admin/support/search?q=' . urlencode($format))
                ->assertOk()
                ->assertJsonPath('meta.users.0.id', $this->customer->id)
                ->assertJsonPath('meta.users.0.name', 'سالم الحضرمي');
        }
    }

    public function test_search_finds_transaction_by_ref(): void
    {
        $tx = Transaction::create([
            'user_id' => $this->customer->id,
            'transaction_id' => 'TRXSUPPORT000001',
            'transaction_type' => 'send_money',
            'debit' => '1000', 'credit' => '0', 'amount' => '1000',
            'balance' => '24000',
        ]);
        $this->actAsAdmin();

        $this->getJson('/api/v1/amial/admin/support/search?q=TRXSUPPORT000001')
            ->assertOk()
            ->assertJsonPath('meta.transactions.0.id', $tx->id);
    }

    // ==================== ملف العميل 360° ====================

    public function test_customer_360_returns_full_profile(): void
    {
        Transaction::create([
            'user_id' => $this->customer->id,
            'transaction_id' => 'TRX360TEST000001',
            'transaction_type' => 'cash_in',
            'debit' => '0', 'credit' => '5000', 'amount' => '5000',
            'balance' => '25000',
        ]);
        $this->actAsAdmin();

        $r = $this->getJson("/api/v1/amial/admin/support/customers/{$this->customer->id}")
            ->assertOk();

        $r->assertJsonPath('meta.profile.id', $this->customer->id)
            ->assertJsonPath('meta.wallet.current_balance', '25000.0000')
            ->assertJsonPath('meta.wallet.exists', true)
            ->assertJsonPath('meta.security.is_temp_blocked', false)
            ->assertJsonPath('meta.recent_transactions.0.transaction_id', 'TRX360TEST000001');

        $this->assertArrayHasKey('kyc', $r->json('meta'));
        $this->assertArrayHasKey('pin', $r->json('meta'));
        $this->assertArrayHasKey('open_tickets', $r->json('meta'));
    }

    // ==================== فحص عملية + الخط الزمني ====================

    public function test_transaction_inspection_builds_timeline(): void
    {
        $tx = Transaction::create([
            'user_id' => $this->customer->id,
            'transaction_id' => 'TRXTIMELINE00001',
            'transaction_type' => 'send_money',
            'debit' => '2000', 'credit' => '0', 'amount' => '2000',
            'balance' => '23000',
        ]);
        \App\Models\Receipt::create([
            'receipt_number' => 'RC-TL-0001',
            'verification_code' => 'TLAB2345CDEF6789',
            'receipt_type' => 'send_money',
            'user_id' => $this->customer->id,
            'reference_transaction_id' => 'TRXTIMELINE00001',
            'reference_type' => 'transaction', 'reference_id' => $tx->id,
            'amount' => '2000.0000', 'fee' => '0', 'net_amount' => '2000.0000',
            'direction' => 'debit', 'status' => 'pdf_generated',
            'zone_code' => 'SOUTH', 'issued_at' => now(),
        ]);
        $this->actAsAdmin();

        $r = $this->getJson('/api/v1/amial/admin/support/transactions/TRXTIMELINE00001')
            ->assertOk();

        $events = collect($r->json('meta.timeline'))->pluck('event');
        $this->assertTrue($events->contains('transaction_created'));
        $this->assertTrue($events->contains('receipt_issued'));
        $r->assertJsonPath('meta.receipt.receipt_number', 'RC-TL-0001');
    }

    // ==================== إجراءات التحقّق ====================

    public function test_action_without_reason_is_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/freeze", [])
            ->assertStatus(422)->assertJsonPath('code', 'REASON_REQUIRED');
    }

    /** المسار يحمل اسم customers؛ فلا يتحول إلى بابٍ خفيّ لمسار وكيل أو تاجر. */
    public function test_customer_security_actions_cannot_target_a_non_customer(): void
    {
        $agent = User::factory()->create(['type' => AGENT_TYPE, 'phone' => '967771555099']);
        $this->actAsAdmin();

        $this->postJson("/api/v1/amial/admin/support/customers/{$agent->id}/freeze", [
            'reason' => 'اختبار منع توجيه تجميد العميل إلى حساب وكيل',
        ])->assertNotFound();
    }

    public function test_freeze_is_immediate_and_audited(): void
    {
        $this->actAsAdmin();

        // التجميد (يقيّد) فوري — إيقاف احتيال لا ينتظر موافقة
        $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/freeze",
            ['reason' => 'اشتباه احتيال — بلاغ عميل'])
            ->assertOk()->assertJsonPath('meta.action', 'freeze');

        $this->assertTrue((bool) $this->customer->fresh()->is_temp_blocked);
        $this->assertDatabaseHas('audit_decisions', [
            // مركز الدعم ومركز العملاء لا ينفذان نسختين من التجميد.
            'action' => 'CUSTOMER_FREEZE',
            'actor_user_id' => $this->admin->id,
            'subject_id' => (string) $this->customer->id,
            'severity' => 'critical',
        ]);
    }

    public function test_unfreeze_requires_second_approver(): void
    {
        $this->customer->forceFill(['is_temp_blocked' => 1, 'temp_block_time' => now()])->save();
        $this->actAsAdmin();

        // AMIAL-INSIDER-001: فكّ التجميد (يعيد وصولاً) → طلب موافقة، لا تنفيذ فوري
        $r = $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/freeze",
            ['reason' => 'انتهى التحقيق — سليم', 'unfreeze' => true])
            ->assertStatus(202)
            ->assertJsonPath('code', 'APPROVAL_PENDING')
            ->assertJsonPath('meta.approval_required', true);

        // العميل ما زال مجمَّداً حتى يعتمد مشرف آخر
        $this->assertTrue((bool) $this->customer->fresh()->is_temp_blocked);
        $this->assertMatchesRegularExpression('/^APR-\d{6}$/', $r->json('meta.request_number'));
    }

    public function test_reset_pin_requires_second_approver(): void
    {
        $this->customer->forceFill([
            'transaction_pin' => bcrypt('1234'),
            'pin_failed_attempts' => 3,
        ])->save();
        $this->actAsAdmin();

        $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/reset-pin",
            ['reason' => 'العميل نسي الرمز — تحقق هاتفي'])
            ->assertStatus(202)
            ->assertJsonPath('code', 'APPROVAL_PENDING');

        // PIN لم يُمَس حتى الاعتماد
        $this->assertNotNull($this->customer->fresh()->transaction_pin);
    }

    public function test_revoke_sessions_kills_all_tokens(): void
    {
        DB::table('oauth_access_tokens')->insert([
            ['id' => 'tok_support_1', 'user_id' => $this->customer->id, 'client_id' => 1,
             'revoked' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'tok_support_2', 'user_id' => $this->customer->id, 'client_id' => 1,
             'revoked' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('oauth_refresh_tokens')->insert([
            ['id' => 'ref_support_1', 'access_token_id' => 'tok_support_1', 'revoked' => false],
            ['id' => 'ref_support_2', 'access_token_id' => 'tok_support_2', 'revoked' => false],
        ]);
        $this->actAsAdmin();

        $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/revoke-sessions",
            ['reason' => 'جهاز مفقود — طلب العميل'])
            ->assertOk()
            ->assertJsonPath('meta.action', 'revoke_sessions')
            ->assertJsonPath('meta.operation.revoked_access_tokens', 2)
            ->assertJsonPath('meta.operation.revoked_refresh_tokens', 2);

        $this->assertSame(0, DB::table('oauth_access_tokens')
            ->where('user_id', $this->customer->id)->where('revoked', false)->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', ['tok_support_1', 'tok_support_2'])
            ->where('revoked', false)->count());
    }

    public function test_require_kyc_resets_verification(): void
    {
        $this->customer->forceFill(['is_kyc_verified' => 1])->save();
        $this->actAsAdmin();

        $this->postJson("/api/v1/amial/admin/support/customers/{$this->customer->id}/require-kyc",
            ['reason' => 'وثيقة منتهية الصلاحية'])
            ->assertOk();

        $this->assertFalse((bool) $this->customer->fresh()->is_kyc_verified);
        $this->assertDatabaseHas('audit_decisions', ['action' => 'SUPPORT_REQUIRE_KYC']);
    }

    // ==================== التذاكر ====================

    public function test_full_ticket_lifecycle_with_timeline(): void
    {
        $this->actAsAdmin();
        $agent = User::factory()->create(['type' => 0, 'phone' => '967770000002']);

        // 1) فتح تذكرة
        $r = $this->postJson('/api/v1/amial/admin/support/tickets', [
            'user_id' => $this->customer->id,
            'subject' => 'حوّل ولم يصل المبلغ',
            'category' => 'missing_transfer',
            'priority' => 'high',
            'transaction_ref' => 'TRXMISSING000001',
            'description' => 'العميل يقول إنه حوّل 5000 ولم تصل للمستلم',
        ])->assertStatus(201);

        $ticketId = $r->json('meta.ticket.id');
        $this->assertMatchesRegularExpression('/^TKT-\d{6}$/', $r->json('meta.ticket.ticket_number'));

        // سجل التدقيق يُكتب فعلاً (كان يفشل بصمت قبل توسيع enum)
        $this->assertDatabaseHas('audit_decisions', [
            'action' => 'SUPPORT_TICKET_CREATED',
            'subject_type' => 'support_ticket',
            'subject_id' => (string) $ticketId,
        ]);

        // 2) إسناد لموظف + بدء التحقيق
        $this->postJson("/api/v1/amial/admin/support/tickets/{$ticketId}/update", [
            'assigned_admin_id' => $agent->id,
            'status' => 'investigating',
        ])->assertOk();

        // 3) ملاحظة داخلية
        $this->postJson("/api/v1/amial/admin/support/tickets/{$ticketId}/note", [
            'note' => 'تم التحقق من السجل — العملية معلّقة في الطابور',
        ])->assertOk();

        // 4) حلّ التذكرة
        $this->postJson("/api/v1/amial/admin/support/tickets/{$ticketId}/update", [
            'status' => 'resolved',
            'resolution_note' => 'أُعيدت معالجة العملية ووصل المبلغ',
        ])->assertOk();

        // 5) الخط الزمني الكامل
        $show = $this->getJson("/api/v1/amial/admin/support/tickets/{$ticketId}")->assertOk();
        $types = collect($show->json('meta.ticket.events'))->pluck('event_type');

        $this->assertTrue($types->contains('created'));
        $this->assertTrue($types->contains('assigned'));
        $this->assertTrue($types->contains('status_changed'));
        $this->assertTrue($types->contains('note'));
        $show->assertJsonPath('meta.ticket.status', 'resolved');
        $this->assertNotNull($show->json('meta.ticket.resolved_at'));
    }

    public function test_ticket_numbers_are_sequential(): void
    {
        $this->actAsAdmin();

        $n1 = $this->postJson('/api/v1/amial/admin/support/tickets', [
            'user_id' => $this->customer->id, 'subject' => 'بلاغ أول',
        ])->json('meta.ticket.ticket_number');

        $n2 = $this->postJson('/api/v1/amial/admin/support/tickets', [
            'user_id' => $this->customer->id, 'subject' => 'بلاغ ثانٍ',
        ])->json('meta.ticket.ticket_number');

        $this->assertSame(
            (int) substr($n1, 4) + 1,
            (int) substr($n2, 4),
        );
    }

    public function test_tickets_filter_by_status(): void
    {
        $this->actAsAdmin();
        SupportTicket::create([
            'ticket_number' => 'TKT-000900', 'user_id' => $this->customer->id,
            'status' => 'open', 'subject' => 'مفتوحة', 'category' => 'other', 'priority' => 'normal',
        ]);
        SupportTicket::create([
            'ticket_number' => 'TKT-000901', 'user_id' => $this->customer->id,
            'status' => 'closed', 'subject' => 'مغلقة', 'category' => 'other', 'priority' => 'normal',
        ]);

        $r = $this->getJson('/api/v1/amial/admin/support/tickets?status=open')->assertOk();
        $this->assertCount(1, $r->json('meta.tickets'));
        $this->assertSame('مفتوحة', $r->json('meta.tickets.0.subject'));
    }

    // ==================== لوحة المراقبة ====================

    public function test_ops_dashboard_returns_live_metrics(): void
    {
        Transaction::create([
            'user_id' => $this->customer->id,
            'transaction_id' => 'TRXOPSDASH000001',
            'transaction_type' => 'send_money',
            'debit' => '100', 'credit' => '0', 'amount' => '100', 'balance' => '0',
        ]);
        SupportTicket::create([
            'ticket_number' => 'TKT-000800', 'user_id' => $this->customer->id,
            'status' => 'open', 'subject' => 'x', 'category' => 'other', 'priority' => 'normal',
        ]);
        $this->actAsAdmin();

        $r = $this->getJson('/api/v1/amial/admin/support/ops-dashboard')->assertOk();

        $this->assertGreaterThanOrEqual(1, $r->json('meta.transactions.today'));
        $this->assertSame('up', $r->json('meta.health.database'));
        $this->assertArrayHasKey('pending_jobs', $r->json('meta.queues'));
        $this->assertArrayHasKey('failed_jobs', $r->json('meta.queues'));
        $this->assertSame(1, $r->json('meta.support.tickets_by_status.open'));
    }

    /** يُسند دور مدير المنصّة — كما تفعل الهجرة لحسابات الإدارة القائمة. */
    private function grantPlatformAdmin(User $user): void
    {
        $roleId = \Illuminate\Support\Facades\DB::table('roles')
            ->whereNull('merchant_user_id')->where('code', 'platform_admin')->value('id');

        \Illuminate\Support\Facades\DB::table('admin_user_roles')->updateOrInsert(
            ['user_id' => $user->id, 'role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()],
        );
    }
}
