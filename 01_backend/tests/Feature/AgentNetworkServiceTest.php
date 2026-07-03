<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\AgentSettlement;
use App\Models\User;
use App\Services\AgentNetworkService;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-AGENT-NETWORK-001 (v2.3) — اختبارات شبكة الوكلاء.
 */
class AgentNetworkServiceTest extends TestCase
{
    use RefreshDatabase;

    private AgentNetworkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AgentNetworkService::class);
    }

    private function makeAgent(array $profileOverrides = []): User
    {
        $agent = User::factory()->create(['type' => 1, 'zone_code' => 'SOUTH']);
        AgentProfile::create(array_merge([
            'user_id' => $agent->id,
            'agent_level' => 'independent',
            'status' => 'active',
            'daily_cash_in_limit' => '100000',
            'single_transaction_limit' => '50000',
            'min_float_balance' => '5000',
            'commission_rate' => '0.50',
        ], $profileOverrides));
        return $agent;
    }

    /** @test */
    public function cash_in_within_limit_is_allowed()
    {
        $agent = $this->makeAgent();
        $this->service->assertCashInAllowed($agent->id, '10000');
        $this->assertTrue(true);
    }

    /** @test */
    public function cash_in_exceeding_single_limit_is_blocked()
    {
        $agent = $this->makeAgent(['single_transaction_limit' => '50000']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('حد العملية الواحدة');
        $this->service->assertCashInAllowed($agent->id, '60000');
    }

    /** @test */
    public function suspended_agent_cannot_cash_in()
    {
        $agent = $this->makeAgent(['status' => 'suspended']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('غير نشط');
        $this->service->assertCashInAllowed($agent->id, '1000');
    }

    /** @test */
    public function daily_limit_accumulates()
    {
        $agent = $this->makeAgent(['daily_cash_in_limit' => '15000', 'single_transaction_limit' => '20000']);

        // سجّل 10000 cash-in
        $this->service->recordFloatMovement($agent->id, 'cash_in', '10000');

        // محاولة 10000 أخرى → المجموع 20000 > 15000 → مرفوض
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('حدك اليومي');
        $this->service->assertCashInAllowed($agent->id, '10000');
    }

    /** @test */
    public function agent_without_profile_uses_safe_default_limit()
    {
        // إصلاح أمني: الوكيل بلا profile لم يعد بلا حدود (fail-open) — يخضع لحدّ افتراضي آمن
        $agent = User::factory()->create(['type' => 1, 'zone_code' => 'SOUTH']);
        // ضمن الحدّ الافتراضي → مسموح
        $this->service->assertCashInAllowed($agent->id, '50000');
        $this->assertTrue(true);
        // فوق الحدّ الافتراضي → محظور
        $this->expectException(\RuntimeException::class);
        $this->service->assertCashInAllowed($agent->id, '999999');
    }

    /** @test */
    public function float_movement_is_tracked()
    {
        $agent = $this->makeAgent();
        $this->service->recordFloatMovement($agent->id, 'cash_in', '5000', '25');

        $this->assertDatabaseHas('agent_float_logs', [
            'agent_user_id' => $agent->id,
            'cash_in_total' => '5000.0000',
            'commission_earned' => '25.0000',
            'transaction_count' => 1,
        ]);
    }

    /** @test */
    public function topup_request_creates_pending_settlement()
    {
        $agent = $this->makeAgent();
        $settlement = $this->service->requestTopup($agent, '50000', null, 'bank', 'TXN-123');

        $this->assertEquals('pending', $settlement->status);
        $this->assertEquals('topup', $settlement->settlement_type);
        $this->assertEquals('50000.0000', (string)$settlement->amount);
    }

    /** @test */
    public function approving_topup_credits_agent_and_posts_ledger()
    {
        $agent = $this->makeAgent();
        $admin = User::factory()->create(['type' => 0]);

        // أنشئ محفظة للوكيل
        \App\Models\EMoney::create(['user_id' => $agent->id, 'current_balance' => '0']);

        $settlement = $this->service->requestTopup($agent, '50000');
        $approved = $this->service->approveSettlement($settlement, $admin);

        $this->assertEquals('completed', $approved->status);
        $this->assertNotNull($approved->ledger_entry_ulid);

        // الرصيد أُضيف
        $wallet = \App\Models\EMoney::where('user_id', $agent->id)->first();
        $this->assertEquals('50000.0000', (string)$wallet->current_balance);

        // قيد ledger: CASH_RESERVE → agent wallet
        $agentLedger = app(LedgerService::class)->getOrCreateUserWallet($agent->id);
        $this->assertEquals('50000.0000', (string)$agentLedger->current_balance);
    }

    /** @test AMIAL-FIX(H1): مستخدم عادي/وكيل لا يعتمد تسوية شحن */
    public function non_admin_cannot_approve_topup()
    {
        $agent = $this->makeAgent();
        $intruder = User::factory()->create(['type' => 2]); // عميل عادي
        \App\Models\EMoney::create(['user_id' => $agent->id, 'current_balance' => '0']);

        $settlement = $this->service->requestTopup($agent, '10000');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا تملك صلاحية اعتماد');
        $this->service->approveSettlement($settlement, $intruder);
    }

    /** @test AMIAL-FIX(H2): مبلغ شحن يتجاوز الحدّ الأقصى يُرفَض */
    public function topup_above_max_is_rejected()
    {
        $agent = $this->makeAgent();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('الحدّ الأقصى');
        $this->service->requestTopup($agent, '999999999');
    }

    /** @test */
    public function cannot_approve_already_completed_settlement()
    {
        $agent = $this->makeAgent();
        $admin = User::factory()->create(['type' => 0]);
        \App\Models\EMoney::create(['user_id' => $agent->id, 'current_balance' => '0']);

        $settlement = $this->service->requestTopup($agent, '10000');
        $this->service->approveSettlement($settlement, $admin);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ليست قيد الانتظار');
        $this->service->approveSettlement($settlement->fresh(), $admin);
    }

    /** @test */
    public function float_dashboard_shows_remaining_limit()
    {
        $agent = $this->makeAgent(['daily_cash_in_limit' => '100000']);
        \App\Models\EMoney::create(['user_id' => $agent->id, 'current_balance' => '30000']);

        $this->service->recordFloatMovement($agent->id, 'cash_in', '20000');

        $dashboard = $this->service->getFloatDashboard($agent->id);
        $this->assertEquals('80000.0000', $dashboard['limits']['daily_cash_in_remaining']);
        $this->assertEquals(1, $dashboard['today']['transaction_count']);
    }

    /** @test */
    public function low_float_warning_triggers()
    {
        $agent = $this->makeAgent(['min_float_balance' => '10000']);
        \App\Models\EMoney::create(['user_id' => $agent->id, 'current_balance' => '5000']);

        $dashboard = $this->service->getFloatDashboard($agent->id);
        $this->assertTrue($dashboard['low_float_warning']);
    }

    /** @test */
    public function distributor_network_lists_sub_agents()
    {
        $distUser = User::factory()->create(['type' => 1]);
        $dist = AgentProfile::create([
            'user_id' => $distUser->id, 'agent_level' => 'distributor',
            'status' => 'active', 'daily_cash_in_limit' => '1000000',
            'single_transaction_limit' => '500000', 'min_float_balance' => '0',
            'commission_rate' => '0.50',
        ]);

        $subUser = User::factory()->create(['type' => 1]);
        AgentProfile::create([
            'user_id' => $subUser->id, 'parent_agent_id' => $dist->id,
            'agent_level' => 'sub_agent', 'status' => 'active',
            'business_name' => 'فرع 1', 'location_city' => 'عدن',
            'daily_cash_in_limit' => '100000', 'single_transaction_limit' => '50000',
            'min_float_balance' => '5000', 'commission_rate' => '0.30',
        ]);
        \App\Models\EMoney::create(['user_id' => $subUser->id, 'current_balance' => '15000']);

        $network = $this->service->getDistributorNetwork($distUser->id);
        $this->assertEquals(1, $network['sub_agents_count']);
        $this->assertEquals('فرع 1', $network['sub_agents'][0]['business_name']);
    }
}
