<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Support\Kyc\KycProfileFields;

/**
 * AMIAL-CUSTOMER-VERIFICATION-JOURNEY-001
 *
 * مصدر شروط رحلة العميل الفرد، لا سياسة التاجر أو الوكيل. وجودها هنا يمنع
 * أن تتحول شاشة أو حارس جانبي إلى تعريف جديد لـ Tier 1 أو Tier 2.
 *
 * Tier 1: هاتف + سكن معتمد، بلا وثيقة هوية.
 * Tier 2: Tier 1 + وجها وثيقة الهوية المعتمدان، بلا OCR إلزامي ولا سيلفي.
 * Tier 3: Tier 2 + ملف KYC والعنوان وإثبات ملكية معزز.
 */
class CustomerVerificationJourneyService
{
    public function __construct(private readonly ResidenceVerificationService $residence) {}

    public function tierOneReady(User $user): bool
    {
        return (bool) ($user->is_phone_verified ?? false)
            && ($this->residence->forUser($user)['status'] ?? null) === ResidenceVerificationService::STATUS_VERIFIED;
    }

    public function identityDocumentsReady(User $user): bool
    {
        return $this->hasUsableDocument($user, KycDocument::TYPE_ID_FRONT)
            && $this->hasUsableDocument($user, KycDocument::TYPE_ID_BACK);
    }

    /**
     * @param array<string,mixed>|null $ownership Tier 3 ownership assessment when available.
     * @return array<int,array{code:string,label:string,complete:bool}>
     */
    public function requirementsFor(User $user, int $tier, ?array $ownership = null): array
    {
        $tier = max(1, min(3, $tier));
        $tierOne = $this->tierOneReady($user);
        $identity = $this->identityDocumentsReady($user);

        return match ($tier) {
            1 => [
                ['code' => 'phone', 'label' => 'إثبات ملكية رقم الهاتف', 'complete' => (bool) ($user->is_phone_verified ?? false)],
                ['code' => 'residence', 'label' => 'إثبات محل الإقامة الحالي', 'complete' => $this->residenceVerified($user)],
            ],
            2 => [
                ['code' => 'tier1', 'label' => 'استكمال متطلبات عميل موثق جزئيا', 'complete' => $tierOne],
                ['code' => 'identity_document', 'label' => 'وجه وثيقة الهوية وظهرها — بلا سيلفي', 'complete' => $identity],
            ],
            3 => [
                ['code' => 'tier2', 'label' => 'استكمال متطلبات عميل موثق بهوية', 'complete' => $this->tierTwoGranted($user)],
                ['code' => 'profile', 'label' => 'إكمال بيانات اعرف عميلك التنظيمية', 'complete' => KycProfileFields::missingFor($user) === []],
                ['code' => 'address_proof', 'label' => 'دليل سكن/عنوان صالح ضمن ملف KYC', 'complete' => $this->hasUsableDocument($user, KycDocument::TYPE_ADDRESS_PROOF)],
                ['code' => 'ownership', 'label' => 'إثبات معزز لصاحب الهوية', 'complete' => (bool) ($ownership['ready'] ?? false)],
            ],
        };
    }

    private function tierTwoGranted(User $user): bool
    {
        return (int) ($user->kyc_tier ?? 0) >= 2
            && (int) ($user->is_kyc_verified ?? 0) === 1;
    }

    private function residenceVerified(User $user): bool
    {
        return ($this->residence->forUser($user)['status'] ?? null) === ResidenceVerificationService::STATUS_VERIFIED;
    }

    private function hasUsableDocument(User $user, string $type): bool
    {
        return KycDocument::query()
            ->where('user_id', $user->id)
            ->where('doc_type', $type)
            ->where('status', KycDocument::STATUS_APPROVED)
            ->get()
            ->contains(fn (KycDocument $document) => $document->isUsable());
    }
}
