<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OPERATOR-RBAC-003 — إسناد أدوار فريق المنصّة.
 *
 * **الفجوة التي يسدّها:** الهجرة تُسند دور مدير المنصّة لحسابات الإدارة
 * القائمة، فلا تُقفل اللوحة على مالكها. أمّا الحساب الذي يُنشأ **بعدها**
 * فيولد بلا دور — والافتراض منعٌ لا سماح، فلا يستطيع شيئاً.
 *
 * وذلك صحيحٌ أمنياً وقاتلٌ عملياً بلا هذه الصفحة: يُضاف موظّف دعم فلا يفتح
 * شيئاً، ولا سبيل إلى منحه إلا بكتابة صفٍّ في قاعدة البيانات يدوياً.
 *
 * **وإسناد الصلاحية فعلٌ أخطر من استعمالها:** من يمنح دور مدير المنصّة يمنح
 * كل شيء دفعةً واحدة. فلا يملكه إلا مدير المنصّة نفسه، وكل منح وسحب يُكتب
 * في سجلّ التدقيق باسم فاعله.
 */
class OperatorRolesController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index()
    {
        $roles = DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', 'like', 'platform_%')
            ->get(['id', 'code', 'label_ar']);

        $operators = User::where('type', 0)
            ->orderBy('id')
            ->get(['id', 'f_name', 'l_name', 'phone', 'email', 'is_active'])
            ->map(function ($u) {
                $u->role_ids = DB::table('admin_user_roles')
                    ->where('user_id', $u->id)->pluck('role_id')->all();
                return $u;
            });

        return view('admin-views.amial.ops.roles', [
            'roles' => $roles,
            'operators' => $operators,
        ]);
    }

    public function update(Request $request, int $userId): RedirectResponse
    {
        $data = $request->validate([
            'role_ids' => 'array',
            'role_ids.*' => 'integer|exists:roles,id',
        ]);

        $operator = User::where('type', 0)->find($userId);
        if (!$operator) {
            return back()->with('error', 'الحساب غير موجود أو ليس حساب إدارة');
        }

        $before = DB::table('admin_user_roles')->where('user_id', $userId)
            ->pluck('role_id')->all();
        $after = array_values(array_unique(array_map('intval', $data['role_ids'] ?? [])));

        // من يسحب دوره عن نفسه يُقفل الباب وهو داخله، ولا أحد يفتحه له.
        if ($operator->id === $request->user()->id
            && !$this->keepsAdminRole($after)) {
            return back()->with('error',
                'لا يمكنك سحب دور مدير المنصّة عن نفسك — اطلب من مدير آخر فعلها');
        }

        // ولا يبقى النظام بلا مدير واحد على الأقلّ.
        if ($this->wouldLeaveNoAdmin($operator->id, $after)) {
            return back()->with('error',
                'لا يمكن ترك المنصّة بلا مدير — أسنِد الدور إلى حساب آخر أوّلاً');
        }

        DB::transaction(function () use ($userId, $after, $request) {
            DB::table('admin_user_roles')->where('user_id', $userId)->delete();
            foreach ($after as $roleId) {
                DB::table('admin_user_roles')->insert([
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'granted_by_user_id' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'operator',
            'subject_id' => (string) $userId,
            'action' => 'roles_changed',
            'decision_code' => 'ROLES_CHANGED',
            'reason' => 'تعديل أدوار موظّف من لوحة الإدارة',
            'severity' => 'critical',
            'context' => [
                'before' => $before,
                'after' => $after,
                'labels' => DB::table('roles')->whereIn('id', $after)
                    ->pluck('label_ar')->all(),
            ],
        ]);

        return back()->with('success', 'حُدِّثت أدوار الموظّف');
    }

    private function adminRoleId(): ?int
    {
        return DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');
    }

    private function keepsAdminRole(array $roleIds): bool
    {
        return in_array($this->adminRoleId(), $roleIds, true);
    }

    /** هل يبقى مديرٌ واحد على الأقلّ بعد هذا التعديل؟ */
    private function wouldLeaveNoAdmin(int $userId, array $after): bool
    {
        $adminRoleId = $this->adminRoleId();
        if ($adminRoleId === null) {
            return false;
        }

        $others = DB::table('admin_user_roles')
            ->where('role_id', $adminRoleId)
            ->where('user_id', '!=', $userId)
            ->count();

        return $others === 0 && !in_array($adminRoleId, $after, true);
    }
}
