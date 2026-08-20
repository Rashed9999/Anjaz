<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ZoneAssignmentService;
use App\Services\ZonePolicyService;
use Illuminate\Http\RedirectResponse;
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
        private readonly ZoneAssignmentService $zones,
    ) {}

    /**
     * المسار القديم يبقى لروابط الموظفين المحفوظة فقط. كانت هذه شاشةً ثانية
     * للسياسة نفسها وتنتج أرقاماً وتعريفات متعارضة؛ مركز المناطق هو المصدر
     * المرئي الوحيد الآن.
     */
    public function index(): RedirectResponse
    {
        return redirect()
            ->route('admin.amial.hub.zones.index')
            ->with('info', 'نُقلت إدارة المناطق إلى مركز سياسة المناطق الموحّد.');
    }

    /**
     * POST /admin/amial/zones/update
     */
    public function update(Request $request)
    {
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'zone' => 'required|string|in:' . implode(',', ZonePolicyService::VALID_ZONES),
            // إسناد المنطقة قرار تشغيلي يؤثر في السطح المسموح، فلا يقبل
            // «تحديثاً» بلا تفسير يُراجَع لاحقاً.
            'reason' => 'required|string|min:10|max:500',
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

        $actor = $request->user();
        if (!$actor) {
            abort(401);
        }

        // هذا هو مدخل الإسناد الوحيد: يسجل التاريخ وطريقة القرار ولا
        // يتجاوز قواعد الخدمة كما كانت تفعل الشاشة القديمة.
        $this->zones->assignByAdmin($user, $newZone, (int) $actor->id, (string) $request->input('reason'));

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'user',
            'subject_id' => (string)$user->id,
            'action' => 'ZONE_CHANGED',
            'decision_code' => 'ZONE_SET',
            'reason' => (string) $request->input('reason'),
            'zone_code' => $newZone,
            'severity' => 'notice',
            'context' => [
                'old_zone' => $oldZone,
                'new_zone' => $newZone,
                'assignment_method' => 'admin_decision',
            ],
        ]);

        return back()->with('success', "Zone updated: {$oldZone} → {$newZone}");
    }
}
