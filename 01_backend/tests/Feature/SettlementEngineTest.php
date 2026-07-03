<?php

namespace Tests\Feature;

use App\Models\Ledger\LedgerAccount;
use App\Models\Settlement;
use App\Models\SettlementAuditLog;
use App\Models\SettlementPartner;
use App\Models\User;
use App\Services\UniversalSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * USE-001 — اختبارات Universal Settlement Engine.
 *
 * ⚠️ AMIAL-FIX-USE-001: استورد LedgerAccount من App\Models\Ledger
 * (لا App\Models مباشرة) — هذا كان سيُسقط كل اختبار في هذا الملف فوراً
 * بخطأ "Class not found" لو لم يُصحَّح قبل التشغيل الفعلي.
 */
class SettlementEngineTest extends TestCase
{
    use RefreshDatabase;

    private UniversalSettlementService $svc;
    private User $admin;
    private User $approver;
    private LedgerAccount $revenueWallet;
    private SettlementPartner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc   = app(UniversalSettlementService::class);
        $this->admin = User::factory()->create(['type' => 0, 'role' => 'super_admin']);
        // AMIAL-FIX(C2): معتمِد منفصل عن المُنشئ (فصل المهام)
        $this->approver = User::factory()->create(['type' => 0, 'role' => 'super_admin']);

        $this->revenueWallet = LedgerAccount::create([
            'account_code'    => UniversalSettlementService::REVENUE_WALLET_CODE,
            'account_type'    => 'liability',
            'name_ar'         => 'محفظة إيرادات أميال',
            'owner_type'      => 'platform',
            'normal_balance'  => 'credit',
            'current_balance' => '10000000',
        ]);

        $this->partner = SettlementPartner::create([
            'code' => 'TEST_EXCHANGE', 'name_ar' => 'صرافة تجريبية',
            'type' => SettlementPartner::TYPE_EXCHANGE, 'currency' => 'YER', 'is_active' => true,
        ]);
    }

    /** @test */
    public function can_create_settlement_in_draft(): void
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '2000000', $this->admin);
        $this->assertEquals(Settlement::STATUS_DRAFT, $s->status);
        $this->assertStringStartsWith('SET-', $s->reference_number);
        $this->assertNotNull($s->ulid);
    }

    /** @test */
    public function reference_number_is_sequential(): void
    {
        $s1 = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $s2 = $this->svc->create($this->revenueWallet->id, $this->partner->id, '200000', $this->admin);
        $this->assertNotEquals($s1->reference_number, $s2->reference_number);
    }

    /** @test */
    public function cannot_create_with_zero_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create($this->revenueWallet->id, $this->partner->id, '0', $this->admin);
    }

    /** @test */
    public function cannot_create_exceeding_balance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create($this->revenueWallet->id, $this->partner->id, '99999999', $this->admin);
    }

    /** @test */
    public function cannot_create_with_inactive_partner(): void
    {
        $this->partner->update(['is_active' => false]);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
    }

    /** @test */
    public function full_workflow_draft_to_completed(): void
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '2000000', $this->admin);
        $this->assertEquals(Settlement::STATUS_DRAFT, $s->status);

        $s = $this->svc->submit($s, $this->admin);
        $this->assertEquals(Settlement::STATUS_PENDING_APPROVAL, $s->status);

        $s = $this->svc->approve($s, $this->approver, 'معتمد');
        $this->assertEquals(Settlement::STATUS_APPROVED, $s->status);
        $this->assertNotNull($s->approved_at);

        $s = $this->svc->startProcessing($s, $this->admin);
        $this->assertEquals(Settlement::STATUS_PROCESSING, $s->status);
        $this->assertNotNull($s->debit_journal_entry_id);

        // تحقّق فعلي أنّ القيد المحاسبي صحيح (وليس فقط أنّ الحالة تغيّرت)
        $walletAfterProcessing = $this->revenueWallet->fresh();
        $this->assertEquals('8000000.0000', $walletAfterProcessing->current_balance);

        $s = $this->svc->complete($s, $this->admin, 'HAW-2026-001', 'BNK-001');
        $this->assertEquals(Settlement::STATUS_COMPLETED, $s->status);
        $this->assertEquals('HAW-2026-001', $s->external_reference);
        $this->assertNotNull($s->completed_at);
    }

    /** @test */
    public function processing_actually_debits_ledger_account(): void
    {
        // اختبار حاسم: يتحقّق من القيد المحاسبي الفعلي، لا فقط الحالة
        $balanceBefore = (string) $this->revenueWallet->current_balance;

        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '1500000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);
        $s = $this->svc->approve($s, $this->approver);
        $s = $this->svc->startProcessing($s, $this->admin);

        $balanceAfter = (string) $this->revenueWallet->fresh()->current_balance;
        $this->assertEquals(
            bcsub($balanceBefore, '1500000', 4),
            $balanceAfter
        );

        // وحساب التعليق (SETTLEMENT_SUSPENSE) يجب أن يحوي المبلغ الآن
        $suspense = LedgerAccount::where('account_code', 'SETTLEMENT_SUSPENSE')->first();
        $this->assertNotNull($suspense);
        $this->assertEquals('1500000.0000', $suspense->current_balance);
    }

    /** @test */
    public function audit_log_records_every_step(): void
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '500000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);
        $s = $this->svc->approve($s, $this->approver);

        $logs = SettlementAuditLog::where('settlement_id', $s->id)->get();
        $this->assertGreaterThanOrEqual(3, $logs->count());

        $actions = $logs->pluck('action')->toArray();
        $this->assertContains(SettlementAuditLog::ACTION_CREATED, $actions);
        $this->assertContains(SettlementAuditLog::ACTION_SUBMITTED, $actions);
        $this->assertContains(SettlementAuditLog::ACTION_APPROVED, $actions);
    }

    /** @test */
    public function cannot_modify_completed_settlement(): void
    {
        $s = $this->createCompleted();
        $this->expectException(\LogicException::class);
        $this->svc->submit($s, $this->admin);
    }

    /** @test */
    public function cannot_modify_rejected_settlement(): void
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);
        $s = $this->svc->reject($s, $this->admin, 'رفض لأسباب إدارية');

        $this->expectException(\LogicException::class);
        $this->svc->approve($s, $this->approver);
    }

    /** @test */
    public function invalid_transition_throws_exception(): void
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $this->expectException(\LogicException::class);
        $this->svc->approve($s, $this->approver); // draft → approved مباشرة (غير مسموح)
    }

    /** @test */
    public function cancelling_processing_creates_reverse_entry_and_restores_balance(): void
    {
        $balanceBefore = (string) $this->revenueWallet->current_balance;

        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '1000000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);
        $s = $this->svc->approve($s, $this->approver);
        $s = $this->svc->startProcessing($s, $this->admin);

        $debitEntryId = $s->debit_journal_entry_id;
        $this->assertNotNull($debitEntryId);

        $s = $this->svc->cancel($s, $this->admin, 'إلغاء بسبب مشكلة في شركة الصرافة');

        $s->refresh();
        $this->assertEquals(Settlement::STATUS_CANCELLED, $s->status);
        $this->assertNotNull($s->reverse_journal_entry_id);
        $this->assertNotEquals($debitEntryId, $s->reverse_journal_entry_id);

        // تحقّق حاسم: الرصيد عاد فعلياً لما كان عليه (لا فقط الحالة تغيّرت)
        $balanceAfterCancel = (string) $this->revenueWallet->fresh()->current_balance;
        $this->assertEquals($balanceBefore, $balanceAfterCancel);
    }

    /** @test */
    public function cancelling_draft_does_not_create_reverse_entry(): void
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $s = $this->svc->cancel($s, $this->admin, 'إلغاء مبكّر');

        $this->assertEquals(Settlement::STATUS_CANCELLED, $s->status);
        $this->assertNull($s->reverse_journal_entry_id);
    }

    /** @test */
    public function dashboard_returns_correct_structure(): void
    {
        $this->svc->create($this->revenueWallet->id, $this->partner->id, '500000', $this->admin);
        $data = $this->svc->dashboard();

        $this->assertArrayHasKey('revenue_wallet', $data);
        $this->assertArrayHasKey('counts', $data);
        $this->assertArrayHasKey('amounts', $data);
        $this->assertArrayHasKey('total_revenue_30d', $data);
        $this->assertGreaterThan(0, $data['revenue_wallet']['balance']);
    }

    /** @test */
    public function reject_requires_reason(): void
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->reject($s, $this->admin, '');
    }

    /** @test */
    public function complete_requires_external_reference(): void
    {
        $s = $this->createInProcessing();
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->complete($s, $this->admin, '');
    }

    /** @test */
    public function api_dashboard_returns_200_for_admin(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/amial/admin/settlements/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'meta' => ['revenue_wallet', 'counts']]);
    }

    /** @test */
    public function api_create_settlement_works(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/v1/amial/admin/settlements', [
                'source_account_id' => $this->revenueWallet->id,
                'partner_id'        => $this->partner->id,
                'amount'            => 500000,
                'notes'             => 'تسوية شهرية',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.settlement.status', Settlement::STATUS_DRAFT);
    }

    /** @test */
    public function api_non_admin_cannot_access(): void
    {
        $user = User::factory()->create(['type' => 3]);
        $this->actingAs($user, 'api')
            ->getJson('/api/v1/amial/admin/settlements/dashboard')
            ->assertStatus(403);
    }

    /** @test AMIAL-FIX(C1): الوكيل (type=1) ليس أدمن ولا يعتمد التسويات */
    public function agent_cannot_approve_settlement(): void
    {
        $agent = User::factory()->create(['type' => 1, 'role' => 'agent']);
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('صلاحية المدير المالي مطلوبة');
        $this->svc->approve($s, $agent);
    }

    /** @test AMIAL-FIX(C2): المُنشئ لا يعتمد تسويته بنفسه (فصل المهام) */
    public function creator_cannot_approve_own_settlement(): void
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('فصل المهام');
        $this->svc->approve($s, $this->admin); // نفس مُنشئ التسوية
    }

    /** @test AMIAL-FIX(C3): لا يمكن اعتماد تسوية مرّتين (لا اعتماد/تنفيذ مزدوج) */
    public function cannot_approve_the_same_settlement_twice(): void
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);
        $s = $this->svc->approve($s, $this->approver);

        // محاولة ثانية على نفس النسخة القديمة → الحالة صارت APPROVED → انتقال غير صالح
        $this->expectException(\LogicException::class);
        $this->svc->approve($s, $this->approver);
    }

    /** @test AMIAL-FIX(H1): بدء التنفيذ يتطلّب صلاحية المدير المالي */
    public function agent_cannot_start_processing(): void
    {
        $agent = User::factory()->create(['type' => 1, 'role' => 'agent']);
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);
        $s = $this->svc->approve($s, $this->approver);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('صلاحية المدير المالي مطلوبة');
        $this->svc->startProcessing($s, $agent);
    }

    private function createCompleted(): Settlement
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);
        $s = $this->svc->approve($s, $this->approver);
        $s = $this->svc->startProcessing($s, $this->admin);
        return $this->svc->complete($s, $this->admin, 'REF-001');
    }

    private function createInProcessing(): Settlement
    {
        $s = $this->svc->create($this->revenueWallet->id, $this->partner->id, '100000', $this->admin);
        $s = $this->svc->submit($s, $this->admin);
        $s = $this->svc->approve($s, $this->approver);
        return $this->svc->startProcessing($s, $this->admin);
    }
}
