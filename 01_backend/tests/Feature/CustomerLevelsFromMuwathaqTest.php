<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\KycTierService;
use App\Services\Muwathaq\MuwathaqProvisioningService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * مستويات العميل بعد اعتماد موثّق، حسب الجنس:
 *  - الذكر: مسار قياسي = هويةٌ مُراجَعة ⇒ الحساب موثَّق بالكامل (المستوى 3).
 *  - الأنثى: مسار تدريجي = المستوى 1 (هاتف + إقامة) أو 2 (+ هوية)؛ والثالث (+ ضمان ملكية) غير متاح بعد.
 * لا مستوى بلا إثباته في قرار موثّق، والحدُّ الفعليّ (effectiveTier) هو ما تقرؤه حدود المال.
 */
class CustomerLevelsFromMuwathaqTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $applicant = [], string $kind = 'customer'): array
    {
        $ulid = (string) Str::ulid();

        return [
            'event_version' => 1, 'event_type' => 'account.approved', 'application_ulid' => $ulid,
            'decision_reference' => 'MWT-'.strtoupper(substr(md5($ulid), 0, 12)),
            'subject_kind' => $kind, 'verification_path' => 'standard_customer', 'business_type' => null, 'requested_tier' => null,
            'applicant' => $applicant + ['f_name' => 'محمد', 'l_name' => 'القحطاني', 'phone' => '+9677'.random_int(10000000, 99999999),
                'dial_country_code' => '+967', 'email' => 'welcome-'.strtolower(Str::random(8)).'@example.test', 'gender' => 'male'],
            'subject' => [],
            'decision_context' => ['policy_version' => 'standard-customer-v1', 'verified_phone' => true, 'verified_residence_governorate' => 'YE-AD', 'verified_identity' => true],
        ];
    }

    private function provision(array $event): User
    {
        $r = app(MuwathaqProvisioningService::class)->provisionApproved($event);

        return User::query()->findOrFail((int) $r['user_id']);
    }

    private function approve(string $gender, ?int $tier, array $context): User
    {
        Queue::fake();
        $event = $this->event(['gender' => $gender]);
        $event['verification_path'] = $gender === 'female' ? 'women_progressive' : 'standard_customer';
        $event['requested_tier'] = $tier;
        $event['decision_context'] = array_replace($event['decision_context'], ['verified_identity' => false], $context);
        $r = app(MuwathaqProvisioningService::class)->provisionApproved($event);

        return User::query()->findOrFail((int) $r['user_id']);
    }

    private function effective(User $u): int
    {
        return app(KycTierService::class)->effectiveTier($u->fresh());
    }

    /** @test */
    public function a_man_with_a_reviewed_identity_is_fully_verified(): void
    {
        $u = $this->approve('male', null, ['verified_identity' => true]);
        $this->assertSame(1, (int) $u->fresh()->is_kyc_verified);
        $this->assertSame(3, $this->effective($u));
    }

    /** @test */
    public function a_man_without_a_reviewed_identity_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MUWATHAQ_IDENTITY_REQUIRED');
        $this->approve('male', null, []);
    }

    /** @test */
    public function a_woman_at_level_one_needs_phone_and_residence_only(): void
    {
        $u = $this->approve('female', 1, []);
        $this->assertSame(1, (int) $u->fresh()->kyc_tier);
        $this->assertSame(0, (int) $u->fresh()->is_kyc_verified);
        $this->assertSame(1, $this->effective($u));
    }

    /** @test */
    public function a_woman_at_level_two_gets_level_two_limits_because_her_identity_was_reviewed(): void
    {
        $u = $this->approve('female', 2, ['verified_identity' => true]);
        $this->assertSame(2, (int) $u->fresh()->kyc_tier);
        $this->assertSame(1, (int) $u->fresh()->is_kyc_verified);
        $this->assertSame(2, $this->effective($u), 'كانت تبقى عند المستوى 1 رغم مراجعة هويتها');
    }

    /** @test */
    public function a_woman_cannot_reach_level_two_without_the_identity_proof_in_the_decision(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('MUWATHAQ_IDENTITY_REQUIRED');
        $this->approve('female', 2, []);
    }

    /** @test */
    public function level_three_for_a_woman_requires_the_ownership_assurance_proof(): void
    {
        try {
            $this->approve('female', 3, ['verified_identity' => true]);
            $this->fail('expected refusal');
        } catch (DomainException $e) {
            $this->assertSame('MUWATHAQ_OWNERSHIP_ASSURANCE_REQUIRED', $e->getMessage());
        }
        $u = $this->approve('female', 3, ['verified_identity' => true, 'verified_ownership_assurance' => true]);
        $this->assertSame(3, $this->effective($u));
    }
}
