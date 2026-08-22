<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MerchantProfile;
use App\Models\Permission;
use App\Models\PosUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * P1-RBAC — Endpoints إدارة الأدوار والصلاحيات.
 *
 * /merchant/rbac/permissions          — قائمة صلاحيات التاجر (للـ UI)
 * /merchant/rbac/roles                — قائمة الأدوار (system + الخاصّة)
 * /merchant/rbac/pos-users/{id}/roles — أدوار موظّف
 * /merchant/rbac/pos-users/{id}/assign-role — تعيين دور
 * /merchant/rbac/pos-users/{id}/revoke-role — إزالة دور
 *
 * ══════════════════════════════════════════════════════════════════════
 * AMIAL-RBAC-SPLIT-001 — **«نظاميّ» كانت تعني اثنين، والشرطُ يقرأ أحدَهما.**
 *
 * جداولُ `roles` و`permissions` و`role_permissions` **مشتركةٌ بين
 * محرّكين**: أدوارُ المنصّة (`platform_admin` · `platform_finance` ·
 * `platform_security`…) وأدوارُ التاجر القديمة (`cashier` ·
 * `branch_manager`…). **وكلا الصنفين `is_system = 1` و
 * `merchant_user_id = null`** — فهما لا يفترقان بشكلٍ في الجدول.
 *
 * وكان الشرطُ هنا `where('is_system', true)`، فيصدق على الصنفين معاً:
 *
 *   · `GET /merchant/rbac/permissions` **يُخرج كلَّ صفٍّ في `permissions`**
 *     — ومنها `platform.money.move` و`platform.aml.decide` و
 *     `platform.security.act`. أي **مصفوفةُ صلاحيّات المنصّة كاملةً لكلّ
 *     تاجر**، بأسمائها العربيّة وتصنيفها.
 *
 *   · `GET /merchant/rbac/roles` يُخرج أدوارَ المنصّة ومعها صلاحيّاتُها.
 *
 *   · و`assign-role` **تقبل معرِّفَ دورِ منصّةٍ** فتكتب صفّاً في
 *     `pos_user_roles`.
 *
 * **والأخيرةُ لا تُصعّد اليوم** — قِيس: الإنفاذُ في
 * `PlatformPermissionMiddleware` يقرأ `admin_user_roles` لا
 * `pos_user_roles`، و`PosPermission` (التي تقرأ الأخير) **مسجَّلةٌ في
 * `bootstrap/app.php` وتحرس صفرَ مسار**.
 *
 * **وهذا لغمٌ لا أمان**: أوّلُ من يُلبس تلك الوسيطةَ مساراً يجعل صفَّ
 * `pos_user_roles` نافذاً، فيصير تاجرٌ يملك `platform_admin`. والحمايةُ
 * القائمةُ اليوم **مصادفةُ موتِ محرّك**، لا حاجزٌ قُصد.
 *
 * فيُقطع الأمران معاً: **يُقصَر هذا المتحكّم على أدوار التاجر وصلاحيّاته**
 * — والقائمةُ موجبةٌ (`Role::ALL_SYSTEM_ROLES`) لا نفيَ بادئة: قائمةُ
 * منعٍ تُنسى عند إضافة دورٍ جديد، وقائمةُ سماحٍ تُوقفه.
 */
class RbacController extends AmialApiController // AMIAL-FIX-007
{
    /**
     * **صلاحيّاتُ المنصّة ليست من شأن التاجر.**
     *
     * وتُميَّز بالبادئة لأنّ عمود `category` تسميةٌ لا إنفاذ — وقد سبق أن
     * أخطأ مِقياسٌ بُني عليه (انظر `ReadOnlyAuditorTest`). والبادئةُ
     * `platform.` يفرضها كلُّ ما في `PlatformPermissionMiddleware`.
     */
    private const PLATFORM_PERMISSION_PREFIX = 'platform.';

    public function permissions(Request $request): JsonResponse
    {
        $merchant = $this->resolveMerchantPos($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $all = Permission::where('code', 'not like', self::PLATFORM_PERMISSION_PREFIX . '%')
            ->orderBy('category')->orderBy('code')->get();

        // تجميع حسب category لسهولة العرض
        $byCategory = $all->groupBy('category')->map(fn($items) => $items->values())->toArray();

        return $this->ok([
            'permissions' => $all,
            'by_category' => $byCategory,
            'total' => $all->count(),
        ]);
    }

    public function roles(Request $request): JsonResponse
    {
        $merchant = $this->resolveMerchantPos($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        // أدوارُ التاجر النظاميّة + أدوارُه الخاصّة — **لا أدوارَ المنصّة**.
        $roles = $this->assignableRoles($merchant)
            ->with(['permissions' => function ($q) { $q->select('permissions.id', 'code', 'label_ar', 'category'); }])
            ->get();

        return $this->ok(['roles' => $roles]);
    }

    /**
     * الأدوارُ التي يجوز لهذا التاجر رؤيتُها وإسنادُها — **مصدرٌ واحد**.
     *
     * فشرطان متطابقان في موضعين ينحرفان عند أوّل تعديل: يُضيَّق العرضُ
     * ويبقى الإسنادُ واسعاً، أو العكس. وهو نمطُ «بابان لفعلٍ واحد»
     * (القاعدة الرابعة).
     */
    private function assignableRoles(User $merchant)
    {
        return Role::query()->where(function ($q) use ($merchant) {
            $q->where(function ($w) {
                $w->where('is_system', true)
                  ->whereIn('code', Role::ALL_SYSTEM_ROLES);
            })->orWhere('merchant_user_id', $merchant->id);
        });
    }

    public function posUserRoles(Request $request, int $posUserId): JsonResponse
    {
        $merchant = $this->resolveMerchantPos($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $pos = PosUser::where('id', $posUserId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (!$pos) return $this->error('NOT_FOUND', 'الموظّف غير موجود', 404);

        $assigned = $pos->roles()
            ->with(['permissions' => function ($q) { $q->select('permissions.id', 'code', 'label_ar'); }])
            ->get()
            ->map(function ($role) {
                return [
                    'id' => $role->id,
                    'code' => $role->code,
                    'label_ar' => $role->label_ar,
                    'branch_scope_id' => $role->pivot->branch_scope_id,
                    'granted_by_user_id' => $role->pivot->granted_by_user_id,
                    'permissions' => $role->permissions,
                ];
            });

        return $this->ok([
            'pos_user' => $pos->only(['id', 'display_name', 'branch_id', 'is_active']),
            'roles' => $assigned,
        ]);
    }

    public function assignRole(Request $request, int $posUserId): JsonResponse
    {
        $merchant = $this->resolveMerchantPos($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $v = Validator::make($request->all(), [
            'role_id' => 'required|integer',
            'branch_scope_id' => 'sometimes|nullable|integer',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $pos = PosUser::where('id', $posUserId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (!$pos) return $this->error('NOT_FOUND', 'الموظّف غير موجود', 404);

        // تحقّق من الدور — **من المصدر نفسِه الذي يُعرَض منه** (`roles()`)،
        // فلا يُسنَد ما لا يُعرَض ولا يُعرَض ما لا يُسنَد. **ودورُ المنصّة
        // مردودٌ هنا** ولو حمل `is_system`.
        $role = $this->assignableRoles($merchant)
            ->where('id', $request->input('role_id'))->first();
        if (!$role) return $this->error('INVALID_ROLE', 'الدور غير صالح', 422);

        // تحقّق من الفرع (إن وُجد)
        $branchScopeId = $request->input('branch_scope_id');
        if ($branchScopeId) {
            $branchValid = Branch::where('id', $branchScopeId)
                ->where('merchant_user_id', $merchant->id)->exists();
            if (!$branchValid) return $this->error('INVALID_BRANCH', 'الفرع غير صالح', 422);
        }

        // attach
        DB::table('pos_user_roles')->updateOrInsert(
            [
                'pos_user_id' => $pos->id,
                'role_id' => $role->id,
                'branch_scope_id' => $branchScopeId,
            ],
            [
                'granted_by_user_id' => $request->user()?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $this->ok([
            'pos_user_id' => $pos->id,
            'role' => $role->only(['id', 'code', 'label_ar']),
            'branch_scope_id' => $branchScopeId,
        ], 'ROLE_ASSIGNED', 'تمّ تعيين الدور');
    }

    public function revokeRole(Request $request, int $posUserId): JsonResponse
    {
        $merchant = $this->resolveMerchantPos($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $v = Validator::make($request->all(), [
            'role_id' => 'required|integer',
            'branch_scope_id' => 'sometimes|nullable|integer',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $pos = PosUser::where('id', $posUserId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (!$pos) return $this->error('NOT_FOUND', 'الموظّف غير موجود', 404);

        $deleted = DB::table('pos_user_roles')
            ->where('pos_user_id', $pos->id)
            ->where('role_id', $request->input('role_id'))
            ->where('branch_scope_id', $request->input('branch_scope_id'))
            ->delete();

        return $this->ok(['deleted' => $deleted], 'ROLE_REVOKED', 'تمّت الإزالة');
    }

    // ============ Helpers ============

    private function resolveMerchantPos(Request $request)
    {
        $authUser = $request->user();
        if (!$authUser) return $this->error('UNAUTHENTICATED', 'يجب تسجيل الدخول', 401);

        $pos = PosUser::where('user_id', $authUser->id)->where('is_active', true)->first();
        if ($pos) return User::find($pos->merchant_user_id);

        $hasProfile = MerchantProfile::where('user_id', $authUser->id)->exists();
        if (!$hasProfile) return $this->error('NOT_A_MERCHANT', 'متاح للتجار فقط', 403);
        return $authUser;
    }
}
