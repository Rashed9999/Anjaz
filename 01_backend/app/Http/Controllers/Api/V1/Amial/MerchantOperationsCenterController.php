<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Merchant\MerchantRole;
use App\Models\Merchant\PosDevice;
use App\Models\Merchant\PosDeviceSession;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Access\AccessConstants as A;
use App\Support\Merchant\MerchantPermissions as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * مركزُ التشغيل ليس محركَ صلاحياتٍ ثانياً. هو نافذةُ المالك الواحدة على
 * مصادر الحقيقة القائمة: MerchantRole وPosUser وPosDevice وCashierShift.
 */
class MerchantOperationsCenterController extends AmialApiController
{
    public function __construct(private readonly AuditService $audit) {}

    public function summary(Request $request): JsonResponse
    {
        $merchant = $this->owner($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $branches = Branch::where('merchant_user_id', $merchant->id);
        $staff = PosUser::where('merchant_user_id', $merchant->id);
        $roles = MerchantRole::where('merchant_user_id', $merchant->id)->where('is_active', true);
        $devices = PosDevice::where('merchant_user_id', $merchant->id)
            ->whereNull('revoked_at')->where('is_active', true);
        $openShifts = CashierShift::where('merchant_user_id', $merchant->id)
            ->where('status', 'open')->orderByDesc('opened_at')->limit(12)->get();

        $staffById = PosUser::with('branch:id,name')->whereIn(
            'id', $openShifts->pluck('pos_user_id')->filter()->unique(),
        )->get()->keyBy('id');

        $openShiftRows = $openShifts->map(function (CashierShift $shift) use ($staffById): array {
            $employee = $staffById->get($shift->pos_user_id);

            return [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at?->toIso8601String(),
                'opened_by_name' => $shift->opened_by_name ?: $employee?->display_name,
                'employee_code' => $employee?->pos_number,
                'branch_name' => $employee?->branch?->name,
            ];
        })->values();

        $activeDeviceIds = PosDeviceSession::where('merchant_user_id', $merchant->id)
            ->whereNull('ended_at')->pluck('pos_device_id')->filter()->unique();

        return $this->ok([
            'merchant' => [
                'id' => $merchant->id,
                'business_name' => MerchantProfile::where('user_id', $merchant->id)
                    ->value('business_name'),
            ],
            'counts' => [
                'branches' => (clone $branches)->count(),
                'active_branches' => (clone $branches)->where('is_active', true)->count(),
                'roles' => (clone $roles)->count(),
                'employees' => (clone $staff)->count(),
                'active_employees' => (clone $staff)->where('is_active', true)->count(),
                'devices' => (clone $devices)->count(),
                'active_device_sessions' => $activeDeviceIds->count(),
                'open_shifts' => $openShiftRows->count(),
            ],
            'open_shifts' => $openShiftRows,
            'setup' => [
                'has_branch' => (clone $branches)->exists(),
                'has_role' => (clone $roles)->exists(),
                'has_employee' => (clone $staff)->exists(),
                'has_device' => (clone $devices)->exists(),
            ],
        ], 'OK', 'ملخص مركز تشغيل المنشأة');
    }

    public function roles(Request $request): JsonResponse
    {
        $merchant = $this->owner($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $roles = MerchantRole::where('merchant_user_id', $merchant->id)
            ->withCount(['permissions', 'assignments'])
            ->with('permissions:id,merchant_role_id,permission_code')
            ->orderByDesc('is_system')->orderBy('name_ar')->get()
            ->map(fn (MerchantRole $role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name_ar' => $role->name_ar,
                'description_ar' => $role->description_ar,
                'is_system' => (bool) $role->is_system,
                'is_active' => (bool) $role->is_active,
                'permissions_count' => $role->permissions_count,
                'assignments_count' => $role->assignments_count,
                'permissions' => $role->permissions->pluck('permission_code')->values(),
            ])->values();

        $catalogue = collect(P::catalogue())
            ->only($this->permissionCodesFor($merchant))
            ->map(
            fn (array $item, string $code) => ['code' => $code, ...$item],
        )->values();

        return $this->ok(['roles' => $roles, 'permission_catalogue' => $catalogue]);
    }

    public function createRole(Request $request): JsonResponse
    {
        $merchant = $this->owner($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $v = Validator::make($request->all(), [
            'name_ar' => 'required|string|max:80',
            'description_ar' => 'sometimes|nullable|string|max:240',
            'permissions' => 'required|array|min:1|max:80',
            'permissions.*' => 'string|max:80',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        $permissions = array_values(array_unique($request->input('permissions', [])));
        $known = $this->permissionCodesFor($merchant);
        $unknown = array_values(array_diff($permissions, $known));
        if ($unknown !== []) {
            return $this->error('UNKNOWN_PERMISSION', 'تتضمن القائمة صلاحية غير معروفة', 422);
        }

        $role = DB::transaction(function () use ($merchant, $request, $permissions): MerchantRole {
            $role = MerchantRole::create([
                'merchant_user_id' => $merchant->id,
                'code' => 'custom_' . Str::lower(Str::random(10)),
                'name_ar' => trim($request->input('name_ar')),
                'description_ar' => $request->input('description_ar'),
                'is_system' => false,
                'is_active' => true,
            ]);

            foreach ($permissions as $permission) {
                $role->permissions()->create([
                    'permission_code' => $permission,
                    'scope_type' => 'merchant',
                    'approval' => 'none',
                ]);
            }

            return $role;
        });

        $this->audit->record([
            'actor_type' => 'merchant', 'actor_user_id' => $merchant->id,
            'subject_type' => 'merchant_role', 'subject_id' => (string) $role->id,
            'action' => 'MERCHANT_ROLE_CREATED', 'decision_code' => 'COMPLETED',
            'reason' => 'أنشأ المالك دوراً تشغيلياً',
            'context' => [
                'merchant_user_id' => $merchant->id,
                'role_id' => $role->id,
                'role_name' => $role->name_ar,
                'permission_codes' => $permissions,
            ],
        ]);

        return $this->ok([
            'role' => [
                'id' => $role->id,
                'code' => $role->code,
                'name_ar' => $role->name_ar,
                'permissions' => $permissions,
            ],
        ], 'ROLE_CREATED', 'تم إنشاء الدور');
    }

    private function owner(Request $request): User|JsonResponse
    {
        $user = $request->user();
        if (!$user) return $this->error('UNAUTHENTICATED', 'يجب تسجيل الدخول', 401);

        if ($user->role !== A::ROLE_MERCHANT
            || !MerchantProfile::where('user_id', $user->id)->exists()) {
            return $this->error('OWNER_ONLY', 'مركز التشغيل متاح لمالك المنشأة فقط', 403);
        }

        return $user;
    }

    /**
     * لا تظهر لصاحب الصيدلية «إدارة مضخات» ولا لصاحب المحطة «ملف مريض».
     * النشاط يحدد مجال الفعل قبل أن يختاره المالك، أمّا الباقة فتحرس
     * الشاشة ونقطة النهاية عند الاستعمال — فلا يتحول الدور إلى التفافٍ.
     *
     * @return array<int,string>
     */
    private function permissionCodesFor(User $merchant): array
    {
        $businessType = MerchantProfile::where('user_id', $merchant->id)->value('business_type');
        $prefix = match ($businessType) {
            A::BIZ_RETAIL => 'retail.',
            A::BIZ_FUEL => 'fuel.',
            A::BIZ_PHARMACY => 'pharmacy.',
            A::BIZ_WHOLESALE => 'wholesale.',
            A::BIZ_RESTAURANT => 'restaurant.',
            default => null,
        };

        return array_values(array_filter(P::all(), static function (string $code) use ($prefix): bool {
            $isVertical = str_starts_with($code, 'retail.')
                || str_starts_with($code, 'fuel.')
                || str_starts_with($code, 'pharmacy.')
                || str_starts_with($code, 'wholesale.')
                || str_starts_with($code, 'restaurant.');

            return ! $isVertical || ($prefix !== null && str_starts_with($code, $prefix));
        }));
    }
}
