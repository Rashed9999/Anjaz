<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\KycTierService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-KYC-SEQUENCE-001
 *
 * مستوى التوثيق للعميل الفرد مسار حالة، لا رقم يمكن كتابته مباشرة:
 * 0 -> 1 -> 2 -> 3 فقط.
 */
class KycSequentialTierGuardTest extends TestCase
{
    use RefreshDatabase;

    private function customerAt(int $tier, bool $verified = false): User
    {
        $user = User::factory()->create([
            'type' => 2,
            'kyc_tier' => $tier,
            'is_kyc_verified' => $verified ? 1 : 0,
            'is_phone_verified' => $tier >= 1 ? 1 : 0,
            'residence_governorate' => $tier >= 1 ? 'YE-AD' : null,
            'verified_residence_governorate' => $tier >= 1 ? 'YE-AD' : null,
            'residence_verified_at' => $tier >= 1 ? now() : null,
        ]);

        if ($tier >= 1) {
            DB::table('residence_verifications')->insert([
                'user_id' => $user->id,
                'kyc_document_id' => null,
                'declared_governorate' => 'YE-AD',
                'evidence_type' => 'government_residence_document',
                'evidence_strength' => 'strong',
                'status' => 'verified',
                'submitted_at' => now()->subMinute(),
                'reviewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user->fresh();
    }

    public function test_tier_zero_cannot_jump_to_tier_two(): void
    {
        $user = $this->customerAt(0);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('KYC_TIER_SEQUENCE_VIOLATION');

        app(KycTierService::class)
            ->assertSequentialVerificationDecision($user, 2);
    }

    public function test_tier_zero_cannot_jump_to_tier_three(): void
    {
        $user = $this->customerAt(0);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('KYC_TIER_SEQUENCE_VIOLATION');

        app(KycTierService::class)
            ->assertSequentialVerificationDecision($user, 3);
    }

    public function test_tier_one_can_target_two_but_cannot_jump_to_three(): void
    {
        $user = $this->customerAt(1);

        app(KycTierService::class)
            ->assertSequentialVerificationDecision($user, 2);

        try {
            app(KycTierService::class)
                ->assertSequentialVerificationDecision($user, 3);
            $this->fail('Tier 1 قفز مباشرةً إلى Tier 3.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('KYC_TIER_SEQUENCE_VIOLATION', $e->getMessage());
        }
    }

    public function test_verified_tier_two_can_target_tier_three(): void
    {
        $user = $this->customerAt(2, true);

        app(KycTierService::class)
            ->assertSequentialVerificationDecision($user, 3);

        $this->assertTrue(true);
    }

    public function test_tier_three_reverification_is_allowed_only_for_a_real_previous_tier_three_account(): void
    {
        $user = $this->customerAt(3, true);
        $user->forceFill([
            'kyc_update_required' => 1,
            'kyc_update_previous_tier' => 3,
        ])->save();

        app(KycTierService::class)
            ->assertSequentialVerificationDecision($user->fresh(), 3);

        $fake = $this->customerAt(0);
        $fake->forceFill([
            'kyc_update_required' => 1,
            'kyc_update_previous_tier' => 3,
        ])->save();

        try {
            app(KycTierService::class)
                ->assertSequentialVerificationDecision($fake->fresh(), 3);
            $this->fail('حساب Tier 0 اصطَنَع إعادة توثيق Tier 3 وتجاوز التسلسل.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('KYC_TIER_SEQUENCE_VIOLATION', $e->getMessage());
        }
    }

    public function test_legacy_admin_kyc_decision_route_requires_approval_permission(): void
    {
        $route = Route::getRoutes()->getByName('admin.amial.hub.users.kyc')
            ?? Route::getRoutes()->getByName('amial.hub.users.kyc');

        $this->assertNotNull($route, 'مسار قرار KYC الإداري غير مسجل.');
        $this->assertContains(
            'platform:platform.approvals.decide',
            $route->gatherMiddleware(),
            'مسار قرار KYC الإداري يستطيع الاعتماد دون صلاحية approvals.decide.'
        );
    }

    public function test_direct_customer_tier_mutation_is_closed(): void
    {
        $user = $this->customerAt(0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('KYC_DIRECT_TIER_MUTATION_FORBIDDEN');

        app(KycTierService::class)->upgradeTier($user, 3);
    }
}
