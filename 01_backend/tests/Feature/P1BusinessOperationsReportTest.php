<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\SubscriptionChange;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reporting\P1BusinessOperationsReportService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class P1BusinessOperationsReportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function subscription_report_keeps_sar_money_as_decimal_strings_and_labels_mrr_correctly(): void
    {
        foreach ([A::PLAN_BUSINESS, A::PLAN_BUSINESS, A::PLAN_ENTERPRISE] as $plan) {
            $merchant = User::factory()->create(['type' => MERCHANT_TYPE]);
            MerchantProfile::create([
                'user_id' => $merchant->id,
                'verification_status' => 'verified',
                'business_type' => 'retail',
                'subscription_plan' => $plan,
                'subscription_expires_at' => now()->addDays(20),
            ]);
        }

        $merchant = User::factory()->create(['type' => MERCHANT_TYPE]);
        SubscriptionChange::create([
            'merchant_user_id' => $merchant->id,
            'actor_role' => SubscriptionChange::ACTOR_SYSTEM,
            'action' => SubscriptionChange::ACTION_RENEW,
            'old_plan' => A::PLAN_BUSINESS,
            'new_plan' => A::PLAN_BUSINESS,
            'price_paid_sar' => '35.25',
        ]);

        $report = app(P1BusinessOperationsReportService::class)
            ->subscriptions(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame('169.00', $report['mrr_contract_value_sar']);
        $this->assertSame('2028.00', $report['arr_contract_value_sar']);
        $this->assertSame('35.25', $report['collected_in_period_sar']);
        $this->assertFalse($report['mrr_is_accounting_revenue']);
        $this->assertSame('SAR', $report['currency']);
        $this->assertIsString($report['mrr_contract_value_sar']);
    }

    /** @test */
    public function customer_activity_uses_all_server_rows_not_a_client_side_page_limit(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'created_at' => now()->subYear()]);

        for ($i = 0; $i < 520; $i++) {
            Transaction::create([
                'user_id' => $customer->id,
                'transaction_id' => (string) Str::ulid(),
                'transaction_type' => SEND_MONEY,
                'debit' => '1.0000',
                'credit' => '0.0000',
                'charge' => '0.0000',
                'amount' => '1.0000',
                'balance' => '0.0000',
                'from_user_id' => $customer->id,
                'to_user_id' => null,
                'decision_code' => 'TX_OK',
                'zone_code' => 'SOUTH',
            ]);
        }

        $report = app(P1BusinessOperationsReportService::class)
            ->customerActivity(now()->subDay()->toDateString(), now()->toDateString());

        $this->assertSame(520, $report['transaction_rows_in_period']);
        $this->assertSame(1, $report['transacting_customers_in_period']);
        $this->assertSame(0, $report['dormant_90_days']);
    }

    /** @test */
    public function support_report_refuses_to_invent_an_sla_target(): void
    {
        config()->set('amial_reporting.support_sla.resolution_minutes', [
            'urgent' => null, 'high' => null, 'normal' => null, 'low' => null,
        ]);

        $customer = User::factory()->create(['type' => CUSTOMER_TYPE]);
        $admin = User::factory()->create();
        SupportTicket::create([
            'ticket_number' => 'TKT-900001',
            'user_id' => $customer->id,
            'opened_by_admin_id' => $admin->id,
            'assigned_admin_id' => null,
            'category' => 'balance_issue',
            'priority' => 'urgent',
            'status' => 'open',
            'subject' => 'اختبار',
            'description' => 'اختبار تقرير الدعم',
        ]);

        $report = app(P1BusinessOperationsReportService::class)
            ->supportOperations(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(1, $report['open_backlog']);
        $this->assertSame(1, $report['unassigned_backlog']);
        $this->assertSame(1, $report['urgent_backlog']);
        $this->assertFalse($report['sla_target_configured']);
        $this->assertSameCanonicalizing(['urgent', 'high', 'normal', 'low'], $report['sla_missing_priorities']);
        $this->assertSame(0, $report['sla_evaluated_tickets']);
        $this->assertNull($report['sla_breaches']);
        $this->assertNull($report['sla_breach_rate']);
    }

    /** @test */
    public function support_report_measures_resolution_sla_only_after_all_priority_targets_are_configured(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 12:00:00'));
        config()->set('amial_reporting.support_sla.resolution_minutes', [
            'urgent' => 60,
            'high' => 240,
            'normal' => 480,
            'low' => 1440,
        ]);

        $customer = User::factory()->create(['type' => CUSTOMER_TYPE]);
        $admin = User::factory()->create();

        $breached = SupportTicket::create([
            'ticket_number' => 'TKT-900002',
            'user_id' => $customer->id,
            'opened_by_admin_id' => $admin->id,
            'category' => 'balance_issue',
            'priority' => 'urgent',
            'status' => 'resolved',
            'subject' => 'متجاوز',
            'description' => 'اختبار SLA متجاوز',
        ]);
        SupportTicket::query()->whereKey($breached->id)->update([
            'created_at' => now()->subMinutes(120),
            'updated_at' => now()->subMinutes(20),
            'resolved_at' => now()->subMinutes(20),
        ]);

        $within = SupportTicket::create([
            'ticket_number' => 'TKT-900003',
            'user_id' => $customer->id,
            'opened_by_admin_id' => $admin->id,
            'category' => 'account_access',
            'priority' => 'normal',
            'status' => 'resolved',
            'subject' => 'ضمن الهدف',
            'description' => 'اختبار SLA ضمن الهدف',
        ]);
        SupportTicket::query()->whereKey($within->id)->update([
            'created_at' => now()->subMinutes(90),
            'updated_at' => now()->subMinutes(30),
            'resolved_at' => now()->subMinutes(30),
        ]);

        $report = app(P1BusinessOperationsReportService::class)
            ->supportOperations(now()->subDay()->toDateString(), now()->toDateString());

        $this->assertTrue($report['sla_target_configured']);
        $this->assertSame([], $report['sla_missing_priorities']);
        $this->assertSame(2, $report['sla_evaluated_tickets']);
        $this->assertSame(1, $report['sla_breaches']);
        $this->assertSame('50.00', $report['sla_breach_rate_pct']);
        $this->assertSame(2, $report['resolved_in_period']);
        $this->assertSame(80, $report['average_resolution_minutes']);

        $byPriority = collect($report['sla_by_priority'])->keyBy('priority');
        $this->assertSame(60, $byPriority['urgent']['target_minutes']);
        $this->assertSame(1, $byPriority['urgent']['breached']);
        $this->assertSame('100.00', $byPriority['urgent']['breach_rate_pct']);
        $this->assertSame(0, $byPriority['normal']['breached']);
        $this->assertSame('0.00', $byPriority['normal']['breach_rate_pct']);
    }
}
