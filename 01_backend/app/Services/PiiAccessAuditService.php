<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-PII-ENCRYPTION-001 (v1.3)
 *
 * PiiAccessAuditService — يسجل كل admin access للـ PII.
 *
 * استخدام:
 *   $audit->logAccess(
 *     actorAdmin: auth()->user(),
 *     subjectType: 'user', subjectId: $user->id,
 *     fieldName: 'phone',
 *     accessReason: 'Customer support ticket #12345',
 *     accessType: 'view',
 *   );
 *
 * في كل admin endpoint يعرض PII، استدعِ هذا قبل الـ response.
 */
class PiiAccessAuditService
{
    public function logAccess(
        int $actorUserId,
        string $subjectType,
        int $subjectId,
        string $fieldName,
        string $accessType = 'view',
        ?string $accessReason = null,
    ): void {
        try {
            DB::table('pii_access_logs')->insert([
                'actor_user_id' => $actorUserId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'field_name' => $fieldName,
                'access_type' => $accessType,
                'access_reason' => $accessReason ? mb_substr($accessReason, 0, 500) : null,
                'ip_address' => request()?->ip(),
                'user_agent' => mb_substr(request()?->userAgent() ?? '', 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('PII audit log failed', [
                'error' => $e->getMessage(),
                'actor' => $actorUserId,
                'field' => $fieldName,
            ]);
            // لا نرمي exception — الـ access نفسه لا يجب أن يفشل
        }
    }

    /**
     * تنبيه عند نمط مشبوه (admin قرأ > 50 PII record خلال ساعة).
     */
    public function checkSuspiciousActivity(int $adminId): array
    {
        $hourAgo = now()->subHour();

        $count = DB::table('pii_access_logs')
            ->where('actor_user_id', $adminId)
            ->where('created_at', '>=', $hourAgo)
            ->count();

        $uniqueSubjects = DB::table('pii_access_logs')
            ->where('actor_user_id', $adminId)
            ->where('created_at', '>=', $hourAgo)
            ->distinct('subject_id')
            ->count('subject_id');

        $suspicious = $count > 50 || $uniqueSubjects > 30;

        return [
            'suspicious' => $suspicious,
            'access_count_last_hour' => $count,
            'unique_subjects_last_hour' => $uniqueSubjects,
            'threshold' => 'count>50 OR subjects>30',
        ];
    }
}
