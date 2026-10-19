<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use App\Services\Verification\VerificationCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedVerificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function final_identity_decision_is_projected_into_the_single_case_timeline(): void
    {
        $subject = User::factory()->create(['type' => CUSTOMER_TYPE]);
        $reviewer = User::factory()->create(['type' => ADMIN_TYPE]);

        app(VerificationCaseService::class)->recordAccountDecision(
            subject: $subject,
            reviewer: $reviewer,
            approved: true,
            targetLevel: 2,
        );

        $case = DB::table('kyc_verification_cases')->where('user_id', $subject->id)->first();
        $this->assertSame('approved', $case->workflow_status);
        $this->assertSame($reviewer->id, (int) $case->final_decided_by);
        $this->assertDatabaseHas('verification_case_steps', [
            'verification_case_id' => $case->id,
            'step_key' => 'final_activation',
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
        ]);
        $this->assertDatabaseHas('verification_case_events', [
            'verification_case_id' => $case->id,
            'actor_user_id' => $reviewer->id,
            'event_type' => 'risk_review_completed',
        ]);
        $this->assertDatabaseHas('verification_case_events', [
            'verification_case_id' => $case->id,
            'actor_user_id' => $reviewer->id,
            'event_type' => 'identity_case_approved',
        ]);
        $timeline = app(VerificationCaseService::class)->administrativeTimeline($subject);
        $this->assertSame('identity_case_approved', $timeline[0]['type'] ?? null);
    }

    /** @test */
    public function staff_security_case_never_uses_financial_activation_steps(): void
    {
        $staff = User::factory()->create([
            'type' => ADMIN_TYPE,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ]);

        $snapshot = app(VerificationCaseService::class)->snapshot($staff);

        $this->assertSame('admin_staff', $snapshot['subject_kind']);
        $this->assertSame(['staff_security'], array_column($snapshot['steps'], 'key'));
        $this->assertSame('action_required', $snapshot['status']);
    }

    /** @test */
    public function missing_policy_rows_fall_back_to_a_safe_customer_workflow(): void
    {
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'is_phone_verified' => false,
            'is_email_verified' => false,
        ]);

        $snapshot = app(VerificationCaseService::class)->snapshot($customer);

        $this->assertSame(2, (int) DB::table('kyc_verification_cases')
            ->where('user_id', $customer->id)->value('target_level'));
        $this->assertSame(
            ['contact', 'residence', 'identity', 'risk_review', 'final_activation'],
            array_column($snapshot['steps'], 'key'),
        );
        $this->assertSame('action_required', $snapshot['status']);
        $this->assertFalse($snapshot['ready_for_final_review']);
    }

    /** @test */
    public function agent_operating_approval_is_recorded_without_finalising_personal_kyc(): void
    {
        $agent = User::factory()->create([
            'type' => AGENT_TYPE,
            'agent_number' => 'AG-901',
            'zone_code' => 'SOUTH',
        ]);
        AgentProfile::create([
            'user_id' => $agent->id,
            'status' => 'active',
            'zone_code' => 'SOUTH',
        ]);
        $reviewer = User::factory()->create(['type' => ADMIN_TYPE]);

        app(VerificationCaseService::class)->recordAgentDecision($agent, $reviewer, true);

        $case = DB::table('kyc_verification_cases')->where('user_id', $agent->id)->first();
        $this->assertNotNull($case);
        $this->assertNotSame('approved', $case->workflow_status);
        $this->assertDatabaseHas('verification_case_steps', [
            'verification_case_id' => $case->id,
            'step_key' => 'agent_operating_profile',
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('verification_case_events', [
            'verification_case_id' => $case->id,
            'event_type' => 'agent_operating_approved',
        ]);
    }
}
