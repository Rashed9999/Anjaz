<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Kyc\ResidenceVerificationService;
use App\Services\KycTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-RESIDENCE-LEGACY-001
 *
 * الحسابات التي اعتمد سكنها قبل جدول residence_verifications لا يجوز أن
 * تنقلب إلى Tier 0 لمجرد أن الجدول لم يكن موجوداً عند اعتمادها.
 */
class ResidenceVerificationLegacyStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_verified_columns_remain_a_verified_residence_when_no_newer_record_exists(): void
    {
        $user = User::factory()->create([
            'residence_governorate' => 'YE-AD',
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now()->subDay(),
        ]);

        $state = app(ResidenceVerificationService::class)->forUser($user);

        $this->assertSame(ResidenceVerificationService::STATUS_VERIFIED, $state['status']);
        $this->assertSame('YE-AD', $state['verified_governorate']);
        $this->assertTrue($state['operational']);
    }

    public function test_a_newer_pending_record_is_never_overridden_by_legacy_columns(): void
    {
        $user = User::factory()->create([
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now()->subDay(),
        ]);

        DB::table('residence_verifications')->insert([
            'user_id' => $user->id,
            'kyc_document_id' => null,
            'declared_governorate' => 'YE-AD',
            'evidence_type' => 'government_residence_document',
            'evidence_strength' => 'strong',
            'status' => ResidenceVerificationService::STATUS_PENDING,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $state = app(ResidenceVerificationService::class)->forUser($user->fresh());

        $this->assertSame(ResidenceVerificationService::STATUS_PENDING, $state['status']);
    }

    public function test_a_governorate_code_without_an_approval_timestamp_is_not_trusted(): void
    {
        $user = User::factory()->create([
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => null,
        ]);

        $state = app(ResidenceVerificationService::class)->forUser($user);

        $this->assertSame('not_submitted', $state['status']);
    }

    public function test_legacy_residence_evidence_keeps_a_pending_tier_two_customer_at_tier_one(): void
    {
        $user = User::factory()->create([
            'is_kyc_verified' => 0,
            'kyc_tier' => 2,
            'is_phone_verified' => 1,
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now()->subDay(),
        ]);

        $this->assertSame(
            1,
            app(KycTierService::class)->effectiveTier($user),
            'سجل السكن القديم الموثق يجب أن يحفظ أهلية Tier 1 إلى أن يكتمل طلب Tier 2.'
        );
    }
}
