<?php

namespace App\Services\Muwathaq;

use App\Models\User;

/**
 * قاعدة التفعيل التدريجي لميزات موثّق: off | canary | on.
 * في canary يُسمح لمعرّفات مستخدمين (`*_user_ids`) أو لأرقام حسابات/محافظ (`*_accounts`، 8 أرقام)
 * حتى لا يحتاج المشغّل إلى معرّف المستخدم الداخلي. لا يُسمح لغيرهم أبداً.
 */
final class MuwathaqCanary
{
    /** @param 'tier_upgrade'|'residence_review'|'profile_change'|'merchant_verification' $feature */
    public static function allows(string $feature, User $user): bool
    {
        $mode = (string) config("muwathaq.{$feature}_mode", 'off');
        if ($mode === 'on') {
            return true;
        }
        if ($mode !== 'canary') {
            return false;
        }
        if (in_array((int) $user->id, array_map('intval', (array) config("muwathaq.{$feature}_user_ids", [])), true)) {
            return true;
        }
        $account = trim((string) ($user->account_number ?? ''));

        return $account !== '' && in_array($account, array_map('strval', (array) config("muwathaq.{$feature}_accounts", [])), true);
    }
}
