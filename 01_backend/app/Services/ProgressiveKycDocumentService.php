<?php

namespace App\Services;

use App\Models\KycDocument;
use App\Models\User;

/**
 * AMIAL-PROGRESSIVE-KYC-DOCS-001
 *
 * يحافظ على خدمة KYC الأصلية لكل ما يخص التشفير والمراجعة والتدقيق،
 * ويغيّر فقط معنى اكتمال Tier 2 وفق السياسة التدريجية المعتمدة:
 *
 * Tier 2 = رقم هوية مؤكد + وجه الوثيقة + ظهر الوثيقة.
 * Tier 3 = KYC كامل وإثبات أقوى لصاحب الهوية، وفيه السيلفي/العنوان.
 *
 * الفصل هنا متعمد حتى لا نضع سيلفي وهمياً أو نكرر منطق المستندات.
 */
class ProgressiveKycDocumentService extends KycDocumentService
{
    public function completenessFor(User $user, int $targetTier): array
    {
        $state = parent::completenessFor($user, $targetTier);

        if ($targetTier !== 2) {
            return $state;
        }

        $required = [
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
        ];
        $approved = array_values(array_unique($state['approved'] ?? []));
        $missing = array_values(array_diff($required, $approved));

        $state['required'] = $required;
        $state['missing'] = $missing;
        $state['complete'] = $missing === [];

        return $state;
    }
}
