<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentCashTill;
use App\Models\MerchantProfile;
use App\Models\MerchantVerificationRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Reporting\P1ControlReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class P1ControlReportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function merchant_portfolio_is_aggregated_from_profiles_without_money_math(): void
    {
        $a = User::factory()->create(['type' => MERCHANT_TYPE]);
        $b = User::factory()->create(['type' => MERCHANT_TYPE]);

        MerchantProfile::create([
            'user_id' => $a->id,
            'business_type' => 'pharmacy',
            'verification_status' => 'verified',
            'risk_category' => 'low',
            'subscription_plan' => 'business',
        ]);
        MerchantProfile::create([
            'user_id' => $b->id,
            'business_type' => 'retail',
            'verification_status' => 'pending_review',
            'risk_category' => 'standard',
            'subscription_plan' => 'free',
        ]);

        $report = app(P1ControlReportService::class)->merchantPortfolio();

        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['verified']);
        $this->assertSame(2, collect($report['by_business_type'])->sum('total'));
        $this->assertSame(2, collect($report['by_plan'])->sum('total'));
    }

    /** @test */
    public function kyc_pipeline_combines_customer_truth_and_merchant_review_queue(): void
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE]);
        $customer->forceFill(['is_kyc_verified' => 1, 'kyc_tier' => 2, 'kyc_update_required' => 0])->save();

        $merchant = User::factory()->create(['type' => MERCHANT_TYPE]);
        MerchantVerificationRequest::create([
            'request_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'business_name' => 'متجر اختبار',
            'status' => 'pending_review',
            'zone_code' => 'SOUTH',
        ]);

        $report = app(P1ControlReportService::class)
            ->kycPipeline(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertGreaterThanOrEqual(1, $report['customers']['verified']);
        $this->assertSame(1, $report['merchants']['submitted_in_period']);
        $this->assertSame(1, $report['merchants']['pending_backlog']);
    }

    /** @test */
    public function agent_liquidity_compares_append_only_movements_with_till_without_creating_a_till(): void
    {
        $agent = User::factory()->create();
        $branchUser = User::factory()->create();
        $branch = AgentBranch::create([
            'agent_user_id' => $agent->id,
            'branch_user_id' => $branchUser->id,
            'name' => 'فرع الاختبار',
            'code' => 'BR-TST',
            'is_active' => 1,
        ]);
        AgentCashTill::create([
            'branch_id' => $branch->id,
            'cash_on_hand' => '130.0000',
            'max_cash_on_hand' => '1000.0000',
            'min_cash_alert' => '20.0000',
            'last_counted_at' => now(),
            'last_counted_amount' => '130.0000',
        ]);
        AgentCashMovement::create([
            'branch_id' => $branch->id,
            'direction' => 'in',
            'reason' => 'customer_deposit',
            'amount' => '30.0000',
            'balance_before' => '100.0000',
            'balance_after' => '130.0000',
            'reference' => 'R-1',
            'actor_user_id' => $agent->id,
            'created_at' => now(),
        ]);

        $before = AgentCashTill::count();
        $report = app(P1ControlReportService::class)->agentLiquidity(now()->toDateString());

        $this->assertSame($before, AgentCashTill::count(), 'التقرير لا يجوز أن ينشئ خزنة أثناء القراءة');
        $this->assertSame('130.0000', $report['summary']['cash_on_hand']);
        $this->assertSame('130.0000', $report['summary']['expected_cash']);
        $this->assertSame('0.0000', $report['summary']['difference']);
        $this->assertSame(0, $report['summary']['unreconciled']);
    }

    /** @test */
    public function audit_and_rbac_reports_read_the_append_only_audit_chain_without_exposing_context(): void
    {
        $audit = app(AuditService::class);
        $audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => 10,
            'subject_type' => 'staff',
            'subject_id' => '22',
            'action' => 'PERMISSION_GRANTED',
            'decision_code' => 'ALLOW',
            'severity' => 'warning',
            'context' => ['secret_note' => 'must not be returned'],
        ]);
        $audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => 10,
            'subject_type' => 'wallet',
            'subject_id' => '31',
            'action' => 'WALLET_FREEZE_REQUESTED',
            'decision_code' => 'ALLOW',
            'severity' => 'warning',
        ]);

        $service = app(P1ControlReportService::class);
        $auditReport = $service->auditSensitiveActions(now()->toDateString(), now()->toDateString());
        $rbacReport = $service->rbacChanges(now()->toDateString(), now()->toDateString());

        $this->assertGreaterThanOrEqual(2, $auditReport['sensitive_events']);
        $this->assertGreaterThanOrEqual(1, $rbacReport['total_changes']);
        $this->assertArrayNotHasKey('context', $auditReport['recent'][0]);
        $this->assertArrayNotHasKey('context', $rbacReport['recent'][0]);
    }
}
