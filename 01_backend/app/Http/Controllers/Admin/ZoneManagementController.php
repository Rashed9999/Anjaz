<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ZonePolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-ZONE-001 (v0.8 — Admin Panel)
 *
 * إدارة zone_code للمستخدمين عبر web UI.
 * موازية لـ artisan command `amial:zone` (v0.7-A.1 hotfix).
 *
 * Routes:
 *   GET  /admin/amial/zones          — لوحة التحكم + قائمة المستخدمين مع filter
 *   POST /admin/amial/zones/update   — تحديث zone لمستخدم
 *   POST /admin/amial/zones/bulk     — تحديث جماعي مع filter
 */
class ZoneManagementController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * GET /admin/amial/zones
     */
    public function index(Request $request)
    {
        $zoneFilter = $request->query('zone', 'all');
        $typeFilter = $request->query('type', 'all');
        $search = trim((string)$request->query('search', ''));

        $query = User::query();

        if ($zoneFilter !== 'all') {
            $query->where('zone_code', strtoupper($zoneFilter));
        }
        if ($typeFilter !== 'all') {
            $query->where('type', (int)$typeFilter);
        }
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhere('f_name', 'like', "%{$search}%")
                  ->orWhere('l_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        // إحصاءات للـ dashboard cards
        $stats = User::selectRaw('zone_code, COUNT(*) as cnt')
            ->groupBy('zone_code')
            ->pluck('cnt', 'zone_code')
            ->toArray();

        $totalUsers = User::count();
        $southUsers = $stats['SOUTH'] ?? 0;
        $otherZones = $totalUsers - $southUsers;

        return view('admin-views.amial.zones.index', [
            'users' => $users,
            'stats' => $stats,
            'total_users' => $totalUsers,
            'south_users' => $southUsers,
            'other_users' => $otherZones,
            'valid_zones' => ZonePolicyService::VALID_ZONES,
            'zone_filter' => $zoneFilter,
            'type_filter' => $typeFilter,
            'search' => $search,
        ]);
    }

    /**
     * POST /admin/amial/zones/update
     */
    public function update(Request $request)
    {
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'zone' => 'required|string|in:' . implode(',', ZonePolicyService::VALID_ZONES),
            'reason' => 'sometimes|string|max:500',
        ]);
        if ($v->fails()) {
            return back()->withErrors($v)->withInput();
        }

        /** @var User $user */
        $user = User::find($request->user_id);
        $oldZone = $user->zone_code ?? 'UNKNOWN';
        $newZone = strtoupper($request->zone);

        if ($oldZone === $newZone) {
            return back()->with('warning', "User #{$user->id} is already in zone {$newZone}");
        }

        $user->zone_code = $newZone;
        $user->save();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => auth('admin')->id() ?? auth()->id() ?? 1,
            'subject_type' => 'user',
            'subject_id' => (string)$user->id,
            'action' => 'ZONE_CHANGED',
            'decision_code' => 'ZONE_SET',
            'reason' => $request->input('reason') ?: "Zone changed via admin panel: {$oldZone} → {$newZone}",
            'zone_code' => $newZone,
            'severity' => 'notice',
            'context' => [
                'old_zone' => $oldZone,
                'new_zone' => $newZone,
                'user_phone' => $user->phone,
            ],
        ]);

        return back()->with('success', "Zone updated: {$oldZone} → {$newZone}");
    }
}
