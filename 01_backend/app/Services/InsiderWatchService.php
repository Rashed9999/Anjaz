<?php

namespace App\Services;

use App\Models\SecurityAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-INSIDER-001 — مراقبة سلوك الموظفين (التهديد الداخلي).
 *
 * 1) يسجّل كل "اطّلاع" موظف على بيانات عميل (وليس التعديلات فقط) في
 *    pii_access_logs — الموظف يعرف أن كل فتحة ملف مسجَّلة باسمه.
 * 2) بعد كل اطّلاع يقيّم العدّادات اليومية ضد العتبات ويرفع تنبيهاً
 *    لمسؤول الأمن عند الشذوذ (مرة واحدة لكل نوع/يوم — لا إغراق).
 *
 * العتبات قابلة للضبط من config/amial.php → insider_watch.
 */
class InsiderWatchService
{
    public function __construct(
        private readonly PiiAccessAuditService $piiAudit,
    ) {}

    /** يسجّل اطّلاع موظف على ملف عميل ثم يقيّم الشذوذ. */
    public function logView(
        int $adminId,
        int $customerId,
        string $what,           // profile_360 | transactions | search_results | statement
        ?string $context = null // رقم تذكرة/سبب إن وُجد
    ): void {
        $this->piiAudit->logAccess(
            actorUserId: $adminId,
            subjectType: 'user',
            subjectId: $customerId,
            fieldName: $what,
            accessType: 'view',
            accessReason: $context,
        );

        $this->assess($adminId);
    }

    /** يسجّل عملية بحث (استعلام واسع). */
    public function logSearch(int $adminId, string $query, int $resultsCount): void
    {
        $this->piiAudit->logAccess(
            actorUserId: $adminId,
            subjectType: 'user',
            subjectId: 0,
            fieldName: 'search',
            accessType: 'search',
            accessReason: mb_substr("q={$query} results={$resultsCount}", 0, 500),
        );

        $this->assess($adminId);
    }

    /** عدّادات اليوم لموظف. */
    public function todayCounters(int $adminId): array
    {
        $start = now()->startOfDay();

        $rows = DB::table('pii_access_logs')
            ->where('actor_user_id', $adminId)
            ->where('created_at', '>=', $start)
            ->get(['access_type', 'subject_id', 'created_at']);

        $views = $rows->where('access_type', 'view');

        return [
            'profile_views' => $views->count(),
            'distinct_customers' => $views->pluck('subject_id')->unique()->count(),
            'searches' => $rows->where('access_type', 'search')->count(),
            'after_hours' => $rows->filter(function ($r) {
                $h = (int) date('G', strtotime((string) $r->created_at));
                return $h >= (int) config('amial.insider_watch.night_start', 22)
                    || $h < (int) config('amial.insider_watch.night_end', 6);
            })->count(),
        ];
    }

    /** يقيّم العدّادات ضد العتبات ويرفع تنبيهات عند التجاوز. */
    public function assess(int $adminId): array
    {
        $c = $this->todayCounters($adminId);
        $raised = [];

        $checks = [
            [
                'type' => 'excessive_profile_views',
                'severity' => 'critical',
                'breached' => $c['profile_views'] > (int) config('amial.insider_watch.max_profile_views_per_day', 150),
            ],
            [
                'type' => 'excessive_searches',
                'severity' => 'warning',
                'breached' => $c['searches'] > (int) config('amial.insider_watch.max_searches_per_day', 200),
            ],
            [
                'type' => 'after_hours_access',
                'severity' => 'warning',
                'breached' => $c['after_hours'] > (int) config('amial.insider_watch.max_after_hours', 0),
            ],
        ];

        foreach ($checks as $check) {
            if (!$check['breached']) {
                continue;
            }
            if ($this->raiseOncePerDay($adminId, $check['type'], $check['severity'], $c)) {
                $raised[] = $check['type'];
            }
        }

        return $raised;
    }

    /** يرفع تنبيهاً واحداً لكل موظف/نوع/يوم. يعيد true إن أُنشئ الآن. */
    private function raiseOncePerDay(int $adminId, string $type, string $severity, array $counters): bool
    {
        try {
            SecurityAlert::create([
                'admin_id' => $adminId,
                'alert_type' => $type,
                'severity' => $severity,
                'details' => $counters,
                'status' => 'new',
                'alert_date' => now()->toDateString(),
            ]);

            Log::channel('stack')->warning('insider.alert', [
                'admin_id' => $adminId, 'type' => $type, 'counters' => $counters,
            ]);

            return true;
        } catch (\Throwable $e) {
            // قيد unique = تنبيه اليوم مرفوع سابقاً — صمت مقصود
            return false;
        }
    }

    /** نظرة عامة لمسؤول الأمن: نشاط كل موظف اليوم + التنبيهات المفتوحة. */
    public function overview(): array
    {
        $start = now()->startOfDay();

        $activity = DB::table('pii_access_logs')
            ->join('users', 'users.id', '=', 'pii_access_logs.actor_user_id')
            ->where('pii_access_logs.created_at', '>=', $start)
            ->groupBy('pii_access_logs.actor_user_id', 'users.f_name', 'users.l_name')
            ->selectRaw("
                pii_access_logs.actor_user_id AS admin_id,
                CONCAT(users.f_name, ' ', COALESCE(users.l_name, '')) AS admin_name,
                SUM(access_type = 'view') AS profile_views,
                COUNT(DISTINCT CASE WHEN access_type = 'view' THEN subject_id END) AS distinct_customers,
                SUM(access_type = 'search') AS searches,
                MAX(pii_access_logs.created_at) AS last_activity_at
            ")
            ->orderByDesc('profile_views')
            ->limit(50)
            ->get();

        $alerts = SecurityAlert::with('admin:id,f_name,l_name')
            ->where('status', 'new')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return [
            'activity_today' => $activity,
            'open_alerts' => $alerts,
        ];
    }
}
