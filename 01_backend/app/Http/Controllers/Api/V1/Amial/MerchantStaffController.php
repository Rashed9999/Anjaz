<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\PosUser;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-MERCHANT-STAFF-001 — إدارة موظفي نقاط البيع من تطبيق التاجر.
 *
 * محميّة بميزة «الموظفين» (باقة الأعمال فأعلى). التاجر يُنشئ موظفاً برقم نقطة
 * بيع + كلمة مرور، ويفعّل/يعطّل. الموظف يدخل: رقم التاجر + جوال التاجر +
 * رقم نقطة البيع + كلمة مروره.
 *
 *   GET   /api/v1/amial/merchant/staff
 *   POST  /api/v1/amial/merchant/staff
 *   POST  /api/v1/amial/merchant/staff/{id}/toggle
 */
class MerchantStaffController extends Controller
{
    public function __construct(private FeatureAccessService $access) {}

    /** يتأكّد أن الطالب تاجر يملك ميزة الموظفين. يعيد التاجر أو رداً بالخطأ. */
    private function guardMerchant(Request $request): User|JsonResponse
    {
        $user = $request->user();
        if (!$user || $user->role !== A::ROLE_MERCHANT) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($user, A::F_EMPLOYEES)) {
            return $this->error('FEATURE_LOCKED', 'إدارة الموظفين متاحة في باقة الأعمال فأعلى', 402);
        }
        return $user;
    }

    public function index(Request $request): JsonResponse
    {
        $m = $this->guardMerchant($request);
        if ($m instanceof JsonResponse) return $m;

        $staff = PosUser::where('merchant_user_id', $m->id)
            ->orderBy('pos_number')
            ->get()
            ->map(fn (PosUser $p) => [
                'id' => $p->id,
                'pos_number' => $p->pos_number,
                'display_name' => $p->display_name,
                'is_active' => (bool) $p->is_active,
                'permissions' => $p->permissions ?? [],
                'is_operations_manager' => in_array('operations_manager', $p->permissions ?? [], true),
                'last_login_at' => $p->last_login_at?->toIso8601String(),
            ]);

        return $this->ok(['staff' => $staff, 'count' => $staff->count()], 'OK', 'موظفو نقاط البيع');
    }

    public function store(Request $request): JsonResponse
    {
        $m = $this->guardMerchant($request);
        if ($m instanceof JsonResponse) return $m;

        $v = Validator::make($request->all(), [
            'pos_number' => 'required|string|max:20',
            'display_name' => 'required|string|max:80',
            'password' => 'required|string|min:4|max:64',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|max:40',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        $posNumber = trim($request->input('pos_number'));
        $exists = PosUser::where('merchant_user_id', $m->id)
            ->where('pos_number', $posNumber)->exists();
        if ($exists) {
            return $this->error('POS_TAKEN', 'رقم نقطة البيع مستخدم مسبقاً', 422);
        }

        $pos = DB::transaction(function () use ($request, $m, $posNumber) {
            // مستخدم الدخول للموظف (هاتف اصطناعي فريد — لا يُستخدم لدخوله فعلياً)
            $staffUser = new User();
            $staffUser->f_name = $request->input('display_name');
            $staffUser->l_name = '';
            $staffUser->phone = $this->uniqueSyntheticPhone($m->id);
            $staffUser->password = Hash::make($request->input('password'));
            $staffUser->type = 4; // POS staff
            $staffUser->role = 'pos';
            $staffUser->is_active = 1;
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'zone_code')) {
                $staffUser->zone_code = $m->zone_code ?? 'SOUTH';
            }
            $staffUser->save();

            return PosUser::create([
                'user_id' => $staffUser->id,
                'merchant_user_id' => $m->id,
                'pos_number' => $posNumber,
                'display_name' => $request->input('display_name'),
                'is_active' => true,
                'permissions' => $request->input('permissions', []),
            ]);
        });

        return $this->ok([
            'id' => $pos->id,
            'pos_number' => $pos->pos_number,
            'login_hint' => "دخول الموظف: رقم التاجر + جوال التاجر + رقم نقطة البيع {$pos->pos_number} + كلمة مروره",
        ], 'STAFF_CREATED', 'تم إنشاء الموظف', 201);
    }

    /** مجموعة صلاحيات مدير العمليات — إشراف تشغيلي واسع (بلا إعدادات النشاط/الاشتراك). */
    private const OPS_MANAGER_PERMISSIONS = [
        'operations_manager', 'sell', 'refund', 'products', 'reports',
        'credit', 'employees', 'customers', 'suppliers', 'branches',
    ];

    /**
     * تعيين/إلغاء «مدير عمليات» لموظف (الباقة المؤسسية).
     * مدير العمليات يحصل على مجموعة صلاحيات إشرافية واسعة.
     */
    public function setOperationsManager(Request $request, int $id): JsonResponse
    {
        $m = $this->guardMerchant($request);
        if ($m instanceof JsonResponse) return $m;

        if (!$this->access->hasFeature($m, A::F_OPERATIONS_MANAGER)) {
            return $this->error('FEATURE_LOCKED', 'مدير العمليات متاح في الباقة المؤسسية', 402);
        }

        $v = Validator::make($request->all(), ['enabled' => 'required|boolean']);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        $pos = PosUser::where('id', $id)->where('merchant_user_id', $m->id)->first();
        if (!$pos) return $this->error('NOT_FOUND', 'الموظف غير موجود', 404);

        if ($request->boolean('enabled')) {
            $pos->permissions = self::OPS_MANAGER_PERMISSIONS;
        } else {
            // إلغاء الترقية → يعود لصلاحيات بيع أساسية
            $pos->permissions = ['sell'];
        }
        $pos->save();

        return $this->ok([
            'id' => $pos->id,
            'is_operations_manager' => $request->boolean('enabled'),
            'permissions' => $pos->permissions,
        ], 'OPS_MANAGER_SET', $request->boolean('enabled') ? 'تم تعيين مدير العمليات' : 'تم الإلغاء');
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $m = $this->guardMerchant($request);
        if ($m instanceof JsonResponse) return $m;

        $pos = PosUser::where('id', $id)->where('merchant_user_id', $m->id)->first();
        if (!$pos) return $this->error('NOT_FOUND', 'الموظف غير موجود', 404);

        $pos->is_active = !$pos->is_active;
        $pos->save();
        // عطّل حساب الدخول أيضاً
        if ($pos->user) {
            $pos->user->is_active = $pos->is_active ? 1 : 0;
            $pos->user->save();
        }

        return $this->ok(['id' => $pos->id, 'is_active' => (bool) $pos->is_active],
            'STAFF_TOGGLED', $pos->is_active ? 'تم التفعيل' : 'تم التعطيل');
    }

    private function uniqueSyntheticPhone(int $merchantId): string
    {
        do {
            // بادئة اصطناعية 9009 لتفادي التصادم مع أرقام حقيقية
            $phone = '9009' . str_pad((string) $merchantId, 5, '0', STR_PAD_LEFT) . random_int(1000, 9999);
        } while (User::where('phone', $phone)->exists());
        return $phone;
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }
}
