<?php

namespace Tests\Feature;

use App\Aml\Rules\AgentVelocityRule;
use App\Aml\Rules\CircularTransferRule;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-AML-002 (v2.5) — اختبارات القواعد السلوكية + Shadow Mode.
 */
class AmlBehavioralRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // MERGE-FIX: اختبارات قواعد AML تستخدم IDs اصطناعية ثابتة — نعطّل فحص FK
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    }

    private function makeRule(string $type, array $params, string $action = 'hold', float $score = 50, bool $shadow = false): AmlRule
    {
        return AmlRule::create([
            'code' => strtoupper($type) . '_TEST',
            'name_ar' => "قاعدة {$type}",
            'description_ar' => 'اختبار',
            'rule_type' => $type,
            'applies_to' => 'send_money,agent_cash_in',
            'parameters' => $params,
            'action_on_match' => $action,
            'risk_score_contribution' => $score,
            'priority' => 50,
            'is_active' => true,
            'shadow_mode' => $shadow,
        ]);
    }

    private function ctx(int $actor, ?int $counterparty, string $type, string $amount): TransactionContext
    {
        return new TransactionContext(
            actorUserId: $actor,
            counterpartyUserId: $counterparty,
            transactionType: $type,
            amount: $amount,
            timestamp: Carbon::now(),
        );
    }

    // ==================== Circular Transfer ====================

    /** @test */
    public function circular_transfer_detects_direct_cycle()
    {
        // B حوّل للـ A سابقاً
        DB::table('transactions')->insert([
            'transaction_id' => 'TX1',
            'user_id' => 2, 'from_user_id' => 2, 'to_user_id' => 1,
            'transaction_type' => defined('SEND_MONEY') ? SEND_MONEY : 1,
            'amount' => 10000, 'debit' => 0, 'credit' => 10000, 'balance' => 0,
            'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);

        $rule = $this->makeRule('circular_transfer', ['window_hours' => 24, 'min_cycle_amount' => '5000']);
        // الآن A يحوّل لـ B → دورة
        $result = (new CircularTransferRule())->evaluate($this->ctx(1, 2, 'send_money', '8000'), $rule);

        $this->assertTrue($result->matched);
        $this->assertEquals('direct_cycle', $result->context['pattern']);
    }

    /** @test */
    public function circular_transfer_ignores_small_amounts()
    {
        $rule = $this->makeRule('circular_transfer', ['min_cycle_amount' => '5000']);
        // مبلغ تحت العتبة
        $result = (new CircularTransferRule())->evaluate($this->ctx(1, 2, 'send_money', '1000'), $rule);
        $this->assertFalse($result->matched);
    }

    /** @test */
    public function circular_transfer_no_match_without_reverse()
    {
        $rule = $this->makeRule('circular_transfer', ['min_cycle_amount' => '5000']);
        // لا تحويل عكسي سابق
        $result = (new CircularTransferRule())->evaluate($this->ctx(1, 99, 'send_money', '8000'), $rule);
        $this->assertFalse($result->matched);
    }

    // ==================== Agent Velocity ====================

    /** @test */
    public function agent_velocity_detects_identical_amounts()
    {
        $agentId = 5;
        // 5 عمليات cash-in سابقة بنفس المبلغ
        for ($i = 0; $i < 5; $i++) {
            DB::table('transactions')->insert([
                'transaction_id' => "AG{$i}",
                'user_id' => $agentId, 'from_user_id' => $agentId, 'to_user_id' => 100 + $i,
                'transaction_type' => defined('CASH_OUT') ? CASH_OUT : 3,
                'amount' => 10000, 'debit' => 10000, 'credit' => 0, 'balance' => 0,
                'created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3),
            ]);
        }

        $rule = $this->makeRule('agent_velocity',
            ['window_minutes' => 10, 'max_identical_count' => 5], 'flag', 50);

        // العملية السادسة بنفس المبلغ → تتجاوز الحد
        $result = (new AgentVelocityRule())->evaluate(
            $this->ctx($agentId, 200, 'agent_cash_in', '10000'), $rule);

        $this->assertTrue($result->matched);
        $this->assertEquals(6, $result->context['count']);
    }

    /** @test */
    public function agent_velocity_ignores_non_agent_transactions()
    {
        $rule = $this->makeRule('agent_velocity', ['max_identical_count' => 5]);
        // نوع ليس agent_cash_in
        $result = (new AgentVelocityRule())->evaluate(
            $this->ctx(5, 200, 'send_money', '10000'), $rule);
        $this->assertFalse($result->matched);
    }

    /** @test */
    public function agent_velocity_allows_normal_activity()
    {
        $agentId = 7;
        // عمليتان فقط
        for ($i = 0; $i < 2; $i++) {
            DB::table('transactions')->insert([
                'transaction_id' => "NORM{$i}",
                'user_id' => $agentId, 'from_user_id' => $agentId, 'to_user_id' => 100 + $i,
                'transaction_type' => defined('CASH_OUT') ? CASH_OUT : 3,
                'amount' => 5000, 'debit' => 5000, 'credit' => 0, 'balance' => 0,
                'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2),
            ]);
        }
        $rule = $this->makeRule('agent_velocity',
            ['window_minutes' => 10, 'max_identical_count' => 5, 'max_distinct_customers' => 15]);
        $result = (new AgentVelocityRule())->evaluate(
            $this->ctx($agentId, 200, 'agent_cash_in', '5000'), $rule);
        $this->assertFalse($result->matched);
    }

    // ==================== Shadow Mode ====================

    /** @test */
    public function shadow_rule_does_not_block_but_logs()
    {
        // قاعدة block في shadow mode
        $this->makeRule('max_single_transaction',
            ['threshold_amount' => '1000'], 'block', 80, shadow: true);

        $service = app(\App\Services\AmlScreeningService::class);
        $decision = $service->screen($this->ctx(1, 2, 'send_money', '50000'));

        // القرار الفعلي = allow (لأن القاعدة shadow)
        $this->assertEquals('allow', $decision->finalAction);

        // لكن سُجّل في shadow_decisions أن النظام كان سيـ block
        $this->assertDatabaseHas('aml_shadow_decisions', [
            'user_id' => 1,
            'would_be_action' => 'block',
            'actual_action' => 'allow',
        ]);
    }

    /** @test */
    public function non_shadow_rule_blocks_normally()
    {
        // نفس القاعدة لكن غير shadow
        $this->makeRule('max_single_transaction',
            ['threshold_amount' => '1000'], 'block', 80, shadow: false);

        $service = app(\App\Services\AmlScreeningService::class);
        $decision = $service->screen($this->ctx(1, 2, 'send_money', '50000'));

        // القرار الفعلي = block
        $this->assertEquals('block', $decision->finalAction);
    }

    /** @test */
    public function shadow_decision_only_logged_when_differs_from_actual()
    {
        // قاعدة flag فعلية (غير shadow) — القرار allow→flag، لا فرق shadow
        $this->makeRule('max_single_transaction',
            ['threshold_amount' => '1000'], 'flag', 30, shadow: false);

        $service = app(\App\Services\AmlScreeningService::class);
        $service->screen($this->ctx(1, 2, 'send_money', '50000'));

        // لا shadow decision لأن لا قاعدة shadow أطلقت
        $this->assertEquals(0, DB::table('aml_shadow_decisions')->count());
    }
}
