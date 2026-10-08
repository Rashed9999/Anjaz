<?php

namespace App\Services\Reporting;

use App\Services\OpsAlertService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-REPORTING-CENTER-006 — التقارير التشغيلية من مصادر الرصد نفسها.
 *
 * لا نحول عينات health إلى SLA تعاقدي، ولا ندعي throughput للـ queue
 * لأن جدول Laravel القياسي يحتفظ بالمعلقة والفاشلة ولا يسجل كل المكتملة.
 * ما لا يمكن قياسه من المصدر يُعاد configured=false بدلاً من رقم مخترع.
 */
class P1ObservabilityReportService
{
    public function healthHistory(?string $from = null, ?string $to = null): array
    {
        [$fromDate, $toDate] = $this->period($from, $to, 7);
        if (! Schema::hasTable('system_health_checks')) {
            return $this->unavailable('system_health_checks');
        }

        $rows = DB::table('system_health_checks')
            ->whereBetween('checked_at', [$fromDate, $toDate])
            ->selectRaw('component, state, COUNT(*) as total')
            ->groupBy('component', 'state')
            ->orderBy('component')
            ->get();

        $components = [];
        foreach ($rows->groupBy('component') as $component => $states) {
            $stateCounts = ['up' => 0, 'degraded' => 0, 'down' => 0];
            foreach ($states as $row) {
                $stateCounts[(string) $row->state] = (int) $row->total;
            }
            $samples = array_sum($stateCounts);
            $upPct = $samples > 0
                ? bcdiv(bcmul((string) $stateCounts['up'], '100', 4), (string) $samples, 2)
                : null;

            $latest = DB::table('system_health_checks')
                ->where('component', $component)
                ->orderByDesc('checked_at')
                ->first(['state', 'latency_ms', 'detail', 'checked_at']);

            $components[] = [
                'component' => (string) $component,
                'samples' => $samples,
                'up' => $stateCounts['up'],
                'degraded' => $stateCounts['degraded'],
                'down' => $stateCounts['down'],
                'observed_up_ratio_pct' => $upPct,
                'latest_state' => $latest?->state,
                'latest_latency_ms' => $latest?->latency_ms !== null ? (int) $latest->latency_ms : null,
                'latest_detail' => $latest?->detail,
                'latest_checked_at' => $latest?->checked_at,
            ];
        }

        $lastBeat = DB::table('system_health_checks')->max('checked_at');
        $beatAgeMinutes = $lastBeat
            ? max(0, (int) Carbon::parse($lastBeat)->diffInMinutes(now()))
            : null;

        $errors = [
            'available' => Schema::hasTable('system_errors'),
            'open' => 0,
            'acknowledged' => 0,
            'resolved' => 0,
            'seen_in_period' => 0,
            'occurrences_in_period_rows' => 0,
            'by_exception' => [],
        ];

        if ($errors['available']) {
            $errors['open'] = DB::table('system_errors')->where('status_flag', 'open')->count();
            $errors['acknowledged'] = DB::table('system_errors')->where('status_flag', 'acknowledged')->count();
            $errors['resolved'] = DB::table('system_errors')->where('status_flag', 'resolved')->count();

            $periodErrors = DB::table('system_errors')
                ->whereBetween('last_seen_at', [$fromDate, $toDate]);
            $errors['seen_in_period'] = (clone $periodErrors)->count();
            $errors['occurrences_in_period_rows'] = (int) ((clone $periodErrors)->sum('occurrences') ?? 0);
            $errors['by_exception'] = (clone $periodErrors)
                ->selectRaw('exception as value, COUNT(*) as total')
                ->groupBy('exception')
                ->orderByDesc('total')
                ->limit(20)
                ->get()
                ->map(fn ($r) => ['value' => (string) $r->value, 'total' => (int) $r->total])
                ->all();
        }

        $sampleTotals = [
            'up' => (int) $rows->where('state', 'up')->sum('total'),
            'degraded' => (int) $rows->where('state', 'degraded')->sum('total'),
            'down' => (int) $rows->where('state', 'down')->sum('total'),
        ];

        return [
            'report' => 'system_health_history',
            'basis' => 'system_health_checks + system_errors + OpsAlertService channel readiness',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'retention_days' => 14,
            'heartbeat_expected_minutes' => 5,
            'last_heartbeat_at' => $lastBeat,
            'heartbeat_age_minutes' => $beatAgeMinutes,
            'heartbeat_stale' => $beatAgeMinutes === null || $beatAgeMinutes > 10,
            'external_alert_channel_configured' => OpsAlertService::hasExternalChannel(),
            'samples' => $sampleTotals,
            'components' => $components,
            'errors' => $errors,
            // سجل داخلي بأخذ عينة كل خمس دقائق، وليس مراقب uptime خارجي
            // يثبت التوافر التعاقدي بين العينات أو من خارج الشبكة.
            'formal_sla_available' => false,
            'formal_sla_reason' => 'internal sampled health history is not contractual end-to-end uptime evidence',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function queueOperations(?string $from = null, ?string $to = null): array
    {
        [$fromDate, $toDate] = $this->period($from, $to, 7);
        $driver = (string) config('queue.default');
        $jobsAvailable = Schema::hasTable('jobs');
        $failedAvailable = Schema::hasTable('failed_jobs');

        $pending = [
            'available' => $jobsAvailable,
            'total' => null,
            'ready' => null,
            'reserved' => null,
            'delayed' => null,
            'oldest_ready_wait_seconds' => null,
            'by_queue' => [],
        ];

        if ($jobsAvailable) {
            $nowEpoch = now()->timestamp;
            $pending['total'] = DB::table('jobs')->count();
            $pending['reserved'] = Schema::hasColumn('jobs', 'reserved_at')
                ? DB::table('jobs')->whereNotNull('reserved_at')->count()
                : null;
            if (Schema::hasColumn('jobs', 'available_at')) {
                $pending['ready'] = DB::table('jobs')
                    ->when(Schema::hasColumn('jobs', 'reserved_at'), fn ($q) => $q->whereNull('reserved_at'))
                    ->where('available_at', '<=', $nowEpoch)
                    ->count();
                $pending['delayed'] = DB::table('jobs')->where('available_at', '>', $nowEpoch)->count();
            }
            if (Schema::hasColumn('jobs', 'created_at')) {
                $oldest = DB::table('jobs')
                    ->when(Schema::hasColumn('jobs', 'reserved_at'), fn ($q) => $q->whereNull('reserved_at'))
                    ->when(Schema::hasColumn('jobs', 'available_at'), fn ($q) => $q->where('available_at', '<=', $nowEpoch))
                    ->min('created_at');
                if ($oldest !== null) {
                    $pending['oldest_ready_wait_seconds'] = max(0, $nowEpoch - (int) $oldest);
                }
            }
            if (Schema::hasColumn('jobs', 'queue')) {
                $pending['by_queue'] = DB::table('jobs')
                    ->selectRaw('queue as value, COUNT(*) as total')
                    ->groupBy('queue')->orderByDesc('total')->get()
                    ->map(fn ($r) => ['value' => (string) $r->value, 'total' => (int) $r->total])
                    ->all();
            }
        }

        $failed = [
            'available' => $failedAvailable,
            'total' => null,
            'in_period' => null,
            'by_queue' => [],
        ];

        if ($failedAvailable) {
            $failed['total'] = DB::table('failed_jobs')->count();
            $periodFailed = DB::table('failed_jobs');
            if (Schema::hasColumn('failed_jobs', 'failed_at')) {
                $periodFailed->whereBetween('failed_at', [$fromDate, $toDate]);
                $failed['in_period'] = (clone $periodFailed)->count();
            }
            if (Schema::hasColumn('failed_jobs', 'queue')) {
                $failed['by_queue'] = (clone $periodFailed)
                    ->selectRaw('queue as value, COUNT(*) as total')
                    ->groupBy('queue')->orderByDesc('total')->get()
                    ->map(fn ($r) => ['value' => (string) $r->value, 'total' => (int) $r->total])
                    ->all();
            }
        }

        $state = 'healthy';
        if (($failed['total'] ?? 0) > 50 || ($pending['total'] ?? 0) > 1000) {
            $state = 'warning';
        }

        return [
            'report' => 'jobs_queues',
            'basis' => 'Laravel queue jobs + failed_jobs; payloads are never exposed',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'driver' => $driver,
            'state' => $state,
            'pending' => $pending,
            'failed' => $failed,
            // Laravel's database queue deletes successful rows, لذلك لا توجد
            // عينة تاريخية سليمة لحساب throughput/latency للمكتملات.
            'completion_history_available' => false,
            'completion_history_reason' => 'successful standard Laravel jobs are deleted; no append-only completion telemetry exists',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function period(?string $from, ?string $to, int $defaultDays): array
    {
        return [
            Carbon::parse($from ?: now()->subDays($defaultDays - 1)->toDateString())->startOfDay(),
            Carbon::parse($to ?: now()->toDateString())->endOfDay(),
        ];
    }

    private function unavailable(string $source): array
    {
        return [
            'available' => false,
            'source' => $source,
            'reason' => 'source_table_missing',
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
