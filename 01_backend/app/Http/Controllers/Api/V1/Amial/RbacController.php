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
 * /merchant/rbac/permissions          — قائمة كل الصلاحيات (للـ UI)
 * /merchant/rbac/roles                — قائمة الأدوار (system + الخاصّة)
 * /merchant/rbac/pos-users/{id}/roles — أدوار موظّف
 * /merchant/rbac/pos-users/{id}/assign-role — تعيين دور
 * /merchant/rbac/pos-users/{id}/revoke-role — إزالة دور
 */
class RbacController extends Controller
{
    public function permissions(Request $request): JsonResponse
    {
        $merchant = $this->resolveMerchant($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        // تجميع حسب category لسهولة العرض
        $all = Permission::orderBy('category')->orderBy('code')->get();
        $byCategory = $all->groupBy('category')->map(fn($items) => $items->values())->toArray();

        return $this->ok([
            'permissions' => $all,
            'by_category' => $byCategory,
            'total' => $all->count(),
        ]);
    }

    public function roles(Request $request): JsonResponse
    {
        $merchant = $this->resolveMerchant($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        // الأدوار النظامية + الأدوار الخاصّة بهذا التاجر
        $roles = Role::query()
            ->where(function ($q) use ($merchant) {
                $q->where('is_system', true)
                  ->orWhere('merchant_user_id', $merchant->id);
            })
            ->with(['permissions' => function ($q) { $q->select('permissions.id', 'code', 'label_ar', 'category'); }])
            ->get();

        return $this->ok(['roles' => $roles]);
    }

    public function posUserRoles(Request $request, int $posUserId): JsonResponse
    {
        $merchant = $this->resolveMerchant($request);
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
        $merchant = $this->resolveMerchant($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $v = Validator::make($request->all(), [
            'role_id' => 'required|integer',
            'branch_scope_id' => 'sometimes|nullable|integer',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $pos = PosUser::where('id', $posUserId)
            ->where('merchant_user_id', $merchant->id)->first();
        if (!$pos) return $this->error('NOT_FOUND', 'الموظّف غير موجود', 404);

        // تحقّق من الدور (إمّا system، أو خاصّ بنفس التاجر)
        $role = Role::where('id', $request->input('role_id'))
            ->where(function ($q) use ($merchant) {
                $q->where('is_system', true)
                  ->orWhere('merchant_user_id', $merchant->id);
            })->first();
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
        $merchant = $this->resolveMerchant($request);
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

    private function resolveMerchant(Request $request)
    {
        $authUser = $request->user();
        if (!$authUser) return $this->error('UNAUTHENTICATED', 'يجب تسجيل الدخول', 401);

        $pos = PosUser::where('user_id', $authUser->id)->where('is_active', true)->first();
        if ($pos) return User::find($pos->merchant_user_id);

        $hasProfile = MerchantProfile::where('user_id', $authUser->id)->exists();
        if (!$hasProfile) return $this->error('NOT_A_MERCHANT', 'متاح للتجار فقط', 403);
        return $authUser;
    }

    private function ok(array $meta = [], string $code = 'OK', string $message = ''): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ]);
    }

    private function error(string $code, string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION', 'message' => 'بيانات غير صالحة',
            'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
