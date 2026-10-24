<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use App\Services\Muwathaq\MuwathaqProvisioningService;
use Illuminate\Support\Str;

/**
 * Produces an Amial operational account only from an approved Muwathaq event.
 *
 * It intentionally never calls a legacy Amial registration endpoint: those
 * endpoints must remain retired (410) while operational tests still exercise
 * the real downstream provisioning contract.
 */
trait ProvisionsAccountsFromMuwathaq
{
    protected function provisionCustomerFromMuwathaq(string $phone, array $overrides = []): User
    {
        return $this->provisionApprovedFromMuwathaq('customer', $phone, null, $overrides);
    }

    protected function provisionMerchantFromMuwathaq(string $businessType, string $phone, array $overrides = []): User
    {
        return $this->provisionApprovedFromMuwathaq('merchant', $phone, $businessType, $overrides);
    }

    protected function provisionAgentFromMuwathaq(string $phone, array $overrides = []): User
    {
        return $this->provisionApprovedFromMuwathaq('agent', $phone, null, $overrides);
    }

    private function provisionApprovedFromMuwathaq(
        string $kind, string $phone, ?string $businessType, array $overrides,
    ): User {
        $ulid = (string) Str::ulid();
        $suffix = substr(preg_replace('/\\D/', '', $phone) ?: '000000', -6);
        $firstName = (string) ($overrides['f_name'] ?? 'اختبار');
        $lastName = (string) ($overrides['l_name'] ?? 'موثّق');
        $event = [
            'event_version' => 1,
            'event_type' => 'account.approved',
            'application_ulid' => $ulid,
            'decision_reference' => 'MUW_TEST_'.str_replace('-', '', $ulid),
            'subject_kind' => $kind,
            'verification_path' => match ($kind) {
                'merchant' => MuwathaqAccountOpeningService::PATH_MERCHANT_SECTOR,
                'agent' => MuwathaqAccountOpeningService::PATH_AGENT_OPERATION,
                default => (($overrides['gender'] ?? 'male') === 'female'
                    ? MuwathaqAccountOpeningService::PATH_WOMEN_PROGRESSIVE
                    : MuwathaqAccountOpeningService::PATH_STANDARD_CUSTOMER),
            },
            'applicant' => [
                'f_name' => $firstName,
                'l_name' => $lastName,
                'phone' => $phone,
                'dial_country_code' => '+967',
                'email' => $overrides['email'] ?? 'muwathaq-'.$suffix.'-'.strtolower(Str::random(8)).'@example.test',
                'gender' => $overrides['gender'] ?? 'male',
            ],
            'subject' => [
                'store_name' => $overrides['store_name'] ?? ($kind === 'agent' ? 'وكيل الاختبار' : 'منشأة الاختبار'),
                'operating_address' => $overrides['operating_address'] ?? 'عدن — مقر الاختبار',
            ],
            'business_type' => $businessType,
            'requested_tier' => $overrides['requested_tier'] ?? null,
            'decision_context' => [
                'policy_version' => 'muwathaq-v1',
                'verified_phone' => true,
                'verified_residence_governorate' => $overrides['governorate'] ?? 'YE-AD',
                'verified_identity' => true,
                'verified_owner_identity' => true,
                'verified_business_requirements' => true,
                'admin_provisioned' => true,
            ],
        ];

        $result = app(MuwathaqProvisioningService::class)->provisionApproved($event);

        return User::query()->findOrFail((int) $result['user_id']);
    }
}
