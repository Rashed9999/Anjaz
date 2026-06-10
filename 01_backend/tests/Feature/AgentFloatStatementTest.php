<?php

namespace Tests\Feature;

use App\Models\AgentFloatLog;
use App\Models\AgentProfile;
use App\Models\EMoney;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-AGENT-PORTAL-001 — كشف حركة الرصيد (السيولة) للوكيل.
 *
 * يثبت: الـ endpoint يعيد صفوف الحركة اليومية + إجماليات الفترة، يحترم نطاق
 * التاريخ، ويعزل كل وكيل عن غيره.
 */
class AgentFloatStatementTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): User
    {
        $agent = User::factory()->create(['type' => 1, 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $agent->id, 'current_balance' => '30000']);
        AgentProfile::create([
            'user_id' => $agent->id, 'agent_level' => 'independent', 'status' => 'active',
            'daily_cash_in_limit' => '100000', 'daily_cash_out_limit' => '100000',
            'single_transaction_limit' => '50000', 'min_float_balance' => '5000',
            'commission_rate' => '0.50',
        ]);
        return $agent;
    }

    private function log(int $userId, string $date, array $o = []): void
    {
        AgentFloatLog::create(array_merge([
            'agent_user_id' => $userId, 'log_date' => $date,
            'opening_float' => '10000', 'cash_in_total' => '2000', 'cash_out_total' => '1500',
            'topup_total' => '5000', 'commission_earned' => '50', 'closing_float' => '13550',
            'transaction_count' => 7,
        ], $o));
    }

    public function test_statement_returns_rows_and_totals(): void
    {
        $agent = $this->agent();
        $this->log($agent->id, Carbon::now()->subDays(1)->toDateString());
        $this->log($agent->id, Carbon::now()->toDateString(), ['cash_in_total' => '3000', 'transaction_count' => 5]);
        Passport::actingAs($agent);

        $r = $this->getJson('/api/v1/amial/agent/float-statement')->assertOk();

        $this->assertCount(2, $r->json('meta.rows'));
        // أحدث أولاً
        $this->assertSame(Carbon::now()->toDateString(), $r->json('meta.rows.0.date'));
        // الإجماليات: 2000 + 3000 = 5000 إيداعات، عدد العمليات 7+5=12
        $this->assertSame('5000.0000', $r->json('meta.totals.cash_in_total'));
        $this->assertSame(12, $r->json('meta.totals.transaction_count'));
    }

    public function test_statement_respects_date_range(): void
    {
        $agent = $this->agent();
        $this->log($agent->id, Carbon::now()->subDays(40)->toDateString()); // خارج النطاق الافتراضي
        $this->log($agent->id, Carbon::now()->toDateString());
        Passport::actingAs($agent);

        // الافتراضي آخر 30 يوماً → صفّ واحد فقط
        $this->getJson('/api/v1/amial/agent/float-statement')->assertOk()
            ->assertJsonCount(1, 'meta.rows');

        // نطاق صريح يشمل القديم → صفّان
        $from = Carbon::now()->subDays(60)->toDateString();
        $this->getJson("/api/v1/amial/agent/float-statement?from={$from}")->assertOk()
            ->assertJsonCount(2, 'meta.rows');
    }

    public function test_statement_is_isolated_per_agent(): void
    {
        $a = $this->agent();
        $b = $this->agent();
        $this->log($a->id, Carbon::now()->toDateString());
        Passport::actingAs($b);

        $this->getJson('/api/v1/amial/agent/float-statement')->assertOk()
            ->assertJsonCount(0, 'meta.rows');
    }
}
