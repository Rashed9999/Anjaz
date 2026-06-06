<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SentinelBlockedIp;
use App\Models\SentinelEvent;
use App\Services\Security\SecuritySentinelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-SENTINEL-001 (Admin) — لوحة تحكم الحارس المخفي.
 *
 * تعرض: إحصاءات النشاط المشبوه، أحدث الأحداث، أعلى IPs، تفصيل الخطورة،
 * والعناوين المحظورة — مع إمكانية الحظر/رفع الحظر اليدوي.
 */
class SentinelDashboardController extends Controller
{
    public function __construct(private readonly SecuritySentinelService $sentinel)
    {
    }

    public function index(Request $request)
    {
        $hours = (int) $request->query('hours', 24);
        $since = now()->subHours($hours);

        $base = SentinelEvent::where('created_at', '>=', $since);

        $stats = [
            'total' => (clone $base)->count(),
            'critical' => (clone $base)->where('severity', 'critical')->count(),
            'warning' => (clone $base)->where('severity', 'warning')->count(),
            'blocked_now' => SentinelBlockedIp::active()->count(),
        ];

        // تفصيل الخطورة
        $severityBreakdown = (clone $base)
            ->select('severity', DB::raw('COUNT(*) as cnt'))
            ->groupBy('severity')
            ->pluck('cnt', 'severity')
            ->all();

        // أعلى عناوين IP
        $topIps = (clone $base)
            ->select('ip_address', DB::raw('COUNT(*) as hits'), DB::raw('MAX(threat_score) as max_score'))
            ->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->orderByDesc('hits')
            ->limit(10)
            ->get();

        // أحدث الأحداث (مع فلاتر)
        $eventsQuery = SentinelEvent::query()->orderByDesc('id');
        if ($sev = $request->query('severity')) {
            $eventsQuery->where('severity', $sev);
        }
        if ($ip = $request->query('ip')) {
            $eventsQuery->where('ip_address', $ip);
        }
        $events = $eventsQuery->paginate(50)->withQueryString();

        // المحظورون الفعّالون
        $blocked = SentinelBlockedIp::active()->orderByDesc('updated_at')->limit(50)->get();

        return view('admin-views.amial.sentinel.index', [
            'hours' => $hours,
            'stats' => $stats,
            'severity_breakdown' => $severityBreakdown,
            'top_ips' => $topIps,
            'events' => $events,
            'blocked' => $blocked,
            'filters' => $request->only(['severity', 'ip']),
        ]);
    }

    public function block(Request $request)
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip'],
            'reason' => ['nullable', 'string', 'max:255'],
            'minutes' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->sentinel->blockIp(
            $data['ip_address'],
            $data['reason'] ?? 'manual block by admin',
            $data['minutes'] ?? null,
            'admin:' . (optional($request->user())->id ?? 'unknown'),
        );

        return back()->with('success', translate('IP blocked successfully'));
    }

    public function unblock(Request $request)
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip'],
        ]);

        $this->sentinel->unblockIp($data['ip_address']);

        return back()->with('success', translate('IP unblocked successfully'));
    }
}
