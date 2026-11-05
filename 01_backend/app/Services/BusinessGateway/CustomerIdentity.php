<?php

namespace App\Services\BusinessGateway;

use App\Models\User;

/**
 * ما يُكشف لأميال أعمال عن صاحب رقمٍ في أميال باي: اسمٌ مقنّع يكفي الكاشير
 * ليتأكد أنه الشخص الصحيح («محمد ع*** ص***»)، ولا شيء غيره — لا رقم داخلي
 * ولا رصيد ولا حالة توثيق تفصيلية.
 */
final class CustomerIdentity
{
    public static function maskedName(?User $user): ?string
    {
        if (!$user) {
            return null;
        }

        $full = trim(((string) $user->f_name).' '.((string) $user->l_name));
        $parts = preg_split('/\s+/u', $full, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '***';
        }

        $first = array_shift($parts);
        $rest = array_map(fn (string $p) => mb_substr($p, 0, 1).'***', $parts);

        return trim($first.' '.implode(' ', $rest));
    }
}
