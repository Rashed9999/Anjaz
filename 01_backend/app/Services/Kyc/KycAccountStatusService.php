<?php

namespace App\Services\Kyc;

use App\Models\User;

/** مصدر قرار KYC للحساب؛ لا تعتمد الواجهات على أعلام متفرقة. */
class KycAccountStatusService
{
    public function for(User $user): array
    {
        $verified = (int) ($user->is_kyc_verified ?? 0) === 1;
        $rejected = (int) ($user->is_kyc_verified ?? 0) === 2;
        $update = (int) ($user->kyc_update_required ?? 0) === 1;
        $tier = max(0, min(3, (int) ($user->kyc_tier ?? 0)));
        $state = $update ? 'update_required' : ($verified ? 'verified' : ($rejected ? 'rejected' : 'pending'));
        return ['state' => $state, 'is_verified' => $verified && !$update, 'tier' => $tier, 'update_required' => $update];
    }
}
