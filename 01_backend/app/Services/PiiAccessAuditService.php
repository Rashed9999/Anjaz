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
            // ══════════════════════════════════════════════════════════
            // AMIAL-PII-BURST-001 — **كاشفٌ مبنيٌّ ولا يناديه أحد.**
            //
            // `checkSuspiciousActivity` تكشف نمطَ التسريب — أكثرَ من ٥٠
            // سجلّاً في ساعة، أو ٣٠ عميلاً مختلفاً — **وقِيس أنّ لا
            // مُنادِيَ لها في المشروع كلِّه**.
            //
            // **وليست تكراراً لـ`InsiderWatchService`**: تلك حدودٌ
            // **يوميّة** (١٥٠ مشاهدة/يوم)، وهذه **ساعيّة**. وحدٌّ يوميٌّ
            // لا يمسك من سحب خمسين سجلّاً في عشر دقائق ثمّ توقّف —
            // **وتلك بصمةُ التسريب لا الاستعمال**.
            //
            // وموضعُها هنا لأنّ هذه هي نقطةُ الكتابة الوحيدة: كلُّ قراءةِ
            // بياناتٍ شخصيّةٍ تمرّ بها، فلا بابَ ثانٍ يُنسى.
            //
            // **ولا يُغرَق مركزُ الأعطال بصفٍّ لكلّ قراءة**: المفتاحُ
            // يحمل الفاعلَ والساعة، و`note` تُحدّث الصفَّ القائم ولا
            // تُنشئ آخر.
            //
            // **والرصدُ لا يُسقط القراءة.** رميٌ هنا يمنع موظّفاً من
            // خدمة عميل — وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى.
            // ══════════════════════════════════════════════════════════
            $burst = $this->checkSuspiciousActivity($actorUserId);

            if ($burst['suspicious']) {
                app(\App\Services\OpsAlertService::class)->note(
                    'pii.burst.' . $actorUserId . '.' . now()->format('YmdH'),
                    'قراءةٌ مكثّفةٌ لبياناتٍ شخصيّة',
                    sprintf(
                        'الموظّف #%d قرأ %d سجلّاً لبياناتٍ شخصيّة عن %d شخصاً '
                        . 'مختلفاً في الساعة الماضية (الحدّ: %s). '
                        . 'وهذا نمطُ سحبٍ لا استعمال — يُراجَع في '
                        . '«الامتثال والمخاطر ← مراقبة الداخل».',
                        $actorUserId,
                        $burst['access_count_last_hour'],
                        $burst['unique_subjects_last_hour'],
                        $burst['threshold'],
                    ),
                );
            }
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
