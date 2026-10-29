<?php

namespace App\Services\Kyc;

use App\Models\User;
use App\Services\ZoneAssignmentService;
use App\Support\YemenGovernorates;
use DomainException;

/**
 * AMIAL-RESIDENCE-ZONE-001 — المنطقة التشغيلية لا تُستنتج من محافظة الأصل.
 *
 * كل مسار قديم ما زال ينادي ZoneAssignmentService::assignFromKyc يمر من
 * هنا. لذلك حتى لو حاول Controller قديم fallback إلى origin_governorate،
 * فلن يستطيع تحويل الأصل إلى إقامة موثقة.
 */
class ResidenceAwareZoneAssignmentService extends ZoneAssignmentService
{
    public function assignFromKyc(User $user, string $declaredCity, ?int $adminId = null): string
    {
        $verified = YemenGovernorates::codeFromName(
            (string) ($user->verified_residence_governorate ?? '')
        );
        if ($verified === null || empty($user->residence_verified_at)) {
            throw new DomainException('RESIDENCE_NOT_VERIFIED_FOR_ZONE');
        }

        $requested = YemenGovernorates::codeFromName($declaredCity);
        if ($requested !== null && !hash_equals($verified, $requested)) {
            throw new DomainException('RESIDENCE_ZONE_SOURCE_MISMATCH');
        }

        return parent::assignFromKyc($user, $verified, $adminId);
    }

    /** حقيقة الوثيقة التشغيلية = الإقامة الموثقة، لا الحقل المصرح به. */
    public function zoneFromDocuments(User $user): ?string
    {
        $verified = YemenGovernorates::codeFromName(
            (string) ($user->verified_residence_governorate ?? '')
        );
        if ($verified === null || empty($user->residence_verified_at)) {
            return null;
        }

        $zone = $this->cityToZone($verified);
        return $zone === self::ZONE_UNKNOWN ? null : $zone;
    }
}
