<?php

namespace App\Services\Merchant;

use App\Models\{MuwathaqAccountOpeningApplication, User};
use Illuminate\Support\Carbon;

/** Shared authority for web login and commercial access projection. */
final class MerchantWorkspaceAccess
{
    public function latestOpening(User $owner): ?MuwathaqAccountOpeningApplication
    {
        return MuwathaqAccountOpeningApplication::where('user_id', $owner->id)
            ->where('subject_kind', 'merchant')->latest('id')->first();
    }

    public function operational(User $owner): bool
    {
        return (int) $owner->is_active === 1 && (int) $owner->is_temp_blocked !== 1
            && (! $owner->security_hold_until || ! Carbon::parse($owner->security_hold_until)->isFuture());
    }

    public function approved(?MuwathaqAccountOpeningApplication $opening): bool
    {
        return $opening !== null && $opening->status === 'approved' && $opening->muwathaq_decided_at !== null;
    }

    public function permitsLogin(User $owner, bool $allowLegacy = false): bool
    {
        $opening = $this->latestOpening($owner);
        return $this->operational($owner) && (($allowLegacy && $opening === null) || $this->approved($opening));
    }
}
