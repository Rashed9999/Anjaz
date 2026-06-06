<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountSecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * AMIAL-PIN-SECURITY-001 (v0.8 — Admin)
 *
 * عرض account_security_events للأدمن — رصد أنشطة PIN/phone/login suspicious.
 *
 * Filters:
 *   - user_id
 *   - event_type (PIN_FAILED, PIN_LOCKED, PHONE_CHANGED, ...)
 *   - severity
 */
class SecurityEventsController extends Controller
{
    public function index(Request $request)
    {
        $query = AccountSecurityEvent::query()->with('user')->orderByDesc('id');

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', (int)$userId);
        }
        if ($type = $request->query('event_type')) {
            $query->where('event_type', 'like', "%{$type}%");
        }
        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }

        $events = $query->paginate(50)->withQueryString();

        // top users بمحاولات PIN فاشلة في آخر ساعة (للكشف عن brute-force)
        $suspiciousUsers = AccountSecurityEvent::where('event_type', 'PIN_FAILED')
            ->where('created_at', '>=', now()->subHour())
            ->selectRaw('user_id, COUNT(*) as failed_count')
            ->groupBy('user_id')
            ->orderByDesc('failed_count')
            ->having('failed_count', '>', 3)
            ->limit(10)
            ->get();

        return view('admin-views.amial.security_events.index', [
            'events' => $events,
            'suspicious_users' => $suspiciousUsers,
            'filters' => $request->only(['user_id', 'event_type', 'severity']),
        ]);
    }
}
