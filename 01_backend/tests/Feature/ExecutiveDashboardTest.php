<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\SentinelEvent;
use App\Models\User;
use App\Services\ExecutiveDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-EXEC-DASHBOARD-001 — اختبارات اللوحة التنفيذية.
 *
 * يثبت أن الخدمة تجمّع المؤشّرات بشكل صحيح ولا تنكسر عند غياب جداول القاعدة
 * (fail-safe).
 */
class ExecutiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ExecutiveDashboardService
    {
        return app(ExecutiveDashboardService::class);
    }

    /** @test */
    public function summary_returns_all_expected_keys(): void
    {
        $summary = $this->service()->summary();

        foreach ([
            'wallets_total', 'payments_today', 'purchases_today', 'active_users_today',
            'new_users_today', 'security_alerts_today', 'suspended_accounts',
            'revenue', 'top_merchants', 'top_fuel_stations', 'system_status',
        ] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }
    }

    /** @test */
    public function wallets_total_sums_balances(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        EMoney::create(['user_id' => $u1->id, 'current_balance' => '1000.0000', 'charge_earned' => '0.0000', 'pending_balance' => '0.0000', 'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0]);
        EMoney::create(['user_id' => $u2->id, 'current_balance' => '500.5000', 'charge_earned' => '0.0000', 'pending_balance' => '0.0000', 'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0]);

        $summary = $this->service()->summary();

        $this->assertSame('1500.5', (string) (float) $summary['wallets_total']);
    }

    /** @test */
    public function counts_new_and_suspended_users(): void
    {
        User::factory()->count(3)->create();
        User::factory()->create(['security_hold_until' => now()->addDay()]);

        $summary = $this->service()->summary();

        $this->assertSame(4, $summary['new_users_today']);
        $this->assertSame(1, $summary['suspended_accounts']);
    }

    /** @test */
    public function security_alerts_count_sentinel_events(): void
    {
        SentinelEvent::create(['ip_address' => '1.1.1.1', 'severity' => 'critical', 'threat_score' => 90, 'action' => 'block', 'signatures' => []]);
        SentinelEvent::create(['ip_address' => '1.1.1.2', 'severity' => 'warning', 'threat_score' => 50, 'action' => 'monitor', 'signatures' => []]);
        SentinelEvent::create(['ip_address' => '1.1.1.3', 'severity' => 'info', 'threat_score' => 10, 'action' => 'monitor', 'signatures' => []]);

        $summary = $this->service()->summary();

        // info لا يُحتسب، warning + critical = 2
        $this->assertSame(2, $summary['security_alerts_today']['sentinel']);
    }

    /** @test */
    public function system_status_reports_database_up(): void
    {
        $summary = $this->service()->summary();

        $this->assertTrue($summary['system_status']['api']);
        $this->assertTrue($summary['system_status']['database']);
    }
}
