<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Kyc\KycPrivacyService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class KycPrivacyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_case_never_invents_biometric_scores(): void
    {
        Config::set('amial_kyc.biometric.enabled', false);
        Config::set('amial_kyc.biometric.provider', 'none');

        $user = User::factory()->create();
        $state = app(KycPrivacyService::class)->ensure($user);

        $this->assertSame('standard', $state['review_mode']);
        $this->assertSame('legacy_selfie_review', $state['ownership_method']);
        $this->assertSame('not_configured', $state['liveness']['status']);
        $this->assertNull($state['liveness']['score']);
        $this->assertSame('not_configured', $state['face_match']['status']);
        $this->assertNull($state['face_match']['score']);
    }

    public function test_customer_can_request_restricted_review_without_being_classified_by_gender(): void
    {
        $user = User::factory()->create(['gender' => 'male']);
        $state = app(KycPrivacyService::class)->choose($user, KycPrivacyService::MODE_RESTRICTED);

        $this->assertTrue($state['restricted_review']);
        $this->assertSame('restricted_review', $state['review_mode']);
        $this->assertSame('not_configured', $state['liveness']['status']);

        // الخصوصية ليست «وضع نساء»: نفس الاختيار يعمل لأي صاحب حساب.
        $other = User::factory()->create(['gender' => 'female']);
        $otherState = app(KycPrivacyService::class)->choose($other, KycPrivacyService::MODE_RESTRICTED);
        $this->assertTrue($otherState['restricted_review']);
    }

    public function test_automated_mode_is_refused_until_a_real_provider_is_enabled(): void
    {
        Config::set('amial_kyc.biometric.enabled', false);
        Config::set('amial_kyc.biometric.provider', 'none');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('KYC_BIOMETRIC_PROVIDER_NOT_CONFIGURED');

        app(KycPrivacyService::class)->choose(
            User::factory()->create(),
            KycPrivacyService::MODE_AUTOMATED,
        );
    }

    public function test_in_person_request_does_not_mark_identity_verified(): void
    {
        $user = User::factory()->create(['is_kyc_verified' => 0]);
        $state = app(KycPrivacyService::class)->choose($user, KycPrivacyService::MODE_IN_PERSON);

        $this->assertSame('manual_review', $state['status']);
        $this->assertSame('in_person_pending', $state['ownership_method']);
        $this->assertSame(0, (int) $user->fresh()->is_kyc_verified,
            'طلب تحقق حضوري تجاوز قرار KYC النهائي.');
    }
}
