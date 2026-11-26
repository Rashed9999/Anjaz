<?php

namespace App\Support\Agent;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AGT-GAP-006 — مصدرُ الحقيقة الوحيد لحالة تشغيل شركة الصرافة.
 *
 * كان فحصُ الإيقاف مبعثراً بين `users.is_active` و`users.is_temp_blocked`
 * و`agent_profiles.status`: كلُّ بابٍ يفحص عموداً ويغفل الآخرين.
 * هنا تُفحص الثلاثة معاً في الدخول، والوسيط، والشباك.
 */
final class AgentCompanyStatus
{
    /** @return array{operable: bool, reason: ?string} */
    public static function inspect(int $companyUserId): array
    {
        if ($companyUserId <= 0) {
            return ['operable' => false, 'reason' => 'حساب الشركة غير صالح'];
        }

        $company = User::find($companyUserId);

        if (!$company || (int) $company->is_active !== 1 || (int) ($company->is_temp_blocked ?? 0) === 1) {
            return ['operable' => false, 'reason' => 'حساب الشركة موقوف — راجع إدارة أميال'];
        }

        $profileStatus = DB::table('agent_profiles')
            ->where('user_id', $companyUserId)
            ->value('status');

        if ($profileStatus !== null && (string) $profileStatus !== 'active') {
            $reason = (string) $profileStatus === 'pending_approval'
                ? 'حساب الشركة بانتظار اعتماد إدارة أميال'
                : 'حساب الشركة موقوف — راجع إدارة أميال';

            return ['operable' => false, 'reason' => $reason];
        }

        return ['operable' => true, 'reason' => null];
    }

    public static function assertOperable(int $companyUserId): void
    {
        $status = self::inspect($companyUserId);

        if (!$status['operable']) {
            throw new DomainException((string) $status['reason']);
        }
    }
}
