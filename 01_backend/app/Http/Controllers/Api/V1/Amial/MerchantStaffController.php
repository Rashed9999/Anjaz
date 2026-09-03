<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\PosUser;
use App\Models\User;
use App\Models\MerchantSale;
use App\Exceptions\UsageLimitExceededException;
use App\Services\FeatureAccessService;
use App\Services\UsageLimitService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-MERCHANT-STAFF-001 — إدارة الموظفين وحساباتهم من تطبيق التاجر.
 *
 * محميّة بميزة «الموظفين» (باقة الأعمال فأعلى). التاجر يُنشئ حساب موظف برمز
 * دخول + كلمة مرور، ويفعّل/يعطّل. الموظف يدخل: رقم التاجر + جوال التاجر +
 * رمز الموظف + كلمة مروره. رمز الموظف محفوظ في العمود التاريخي
 * `pos_number` حتى لا تنكسر المبيعات القديمة، لكنه ليس جهازاً ولا مقعد POS.
 *
 *   GET   /api/v1/amial/merchant/staff
 *   POST  /api/v1/amial/merchant/staff
 *   POST  /api/v1/amial/merchant/staff/{id}/toggle
 */
class MerchantStaffController extends Controller
{
    public function __construct(
        private FeatureAccessService $access,
        private UsageLimitService $usage,
    ) {}

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
                'employee_code' => $p->pos_number,
                'display_name' => $p->display_name,
                'is_active' => (bool) $p->is_active,
                'permissions' => $p->permissions ?? [],
                'is_operations_manager' => in_array('operations_manager', $p->permissions ?? [], true),
                'is_financial_manager' => in_array('financial_manager', $p->permissions ?? [], true),
                'last_login_at' => $p->last_login_at?->toIso8601String(),
            ]);

        return $this->ok(['staff' => $staff, 'count' => $staff->count()], 'OK', 'الموظفون وحساباتهم');
    }

    /**
     * AMIAL-STAFF-PERFORMANCE-001 — أداء الموظفين من المبيعات الفعلية.
     * GET /merchant/staff/performance?days=7|30|90
     * يجمّع merchant_sales حسب pos_user_id: عدد + إجمالي + متوسط الفاتورة + اليوم.
     */
    public function performance(Request $request): JsonResponse
    {
        $m = $this->guardMerchant($request);
        if ($m instanceof JsonResponse) return $m;

        $days = max(1, min(90, (int) $request->query('days', 7)));
        $from = now()->subDays($days - 1)->startOfDay();
        $todayFrom = now()->startOfDay();

        $staff = PosUser::where('merchant_user_id', $m->id)->orderBy('pos_number')->get();

        $agg = MerchantSale::where('merchant_user_id', $m->id)
            ->whereNotNull('pos_user_id')
            ->where('created_at', '>=', $from)
            ->selectRaw('pos_user_id, COUNT(*) as cnt, SUM(COALESCE(base_amount, total_amount)) as total')
            ->groupBy('pos_user_id')->get()->keyBy('pos_user_id');

        $todayAgg = MerchantSale::where('merchant_user_id', $m->id)
            ->whereNotNull('pos_user_id')
            ->where('created_at', '>=', $todayFrom)
            ->selectRaw('pos_user_id, COUNT(*) as cnt, SUM(COALESCE(base_amount, total_amount)) as total')
            ->groupBy('pos_user_id')->get()->keyBy('pos_user_id');

        $rows = $staff->map(function (PosUser $p) use ($agg, $todayAgg) {
            $a = $agg->get($p->id);
            $t = $todayAgg->get($p->id);
            $cnt = $a ? (int) $a->cnt : 0;
            $total = $a ? (string) $a->total : '0';
            $avg = $cnt > 0 ? bcdiv($total, (string) $cnt, 2) : '0';
            return [
                'id' => $p->id,
                'employee_code' => $p->pos_number,
                'display_name' => $p->display_name,
                'is_active' => (bool) $p->is_active,
                'sales_count' => $cnt,
                'sales_total' => $total,
                'avg_ticket' => $avg,
                'today_count' => $t ? (int) $t->cnt : 0,
                'today_total' => $t ? (string) $t->total : '0',
            ];
        })->sortByDesc(fn ($r) => (float) $r['sales_total'])->values();

        // مبيعات غير منسوبة لموظف (سجّلها التاجر نفسه) + الإجمالي العام
        $unattributed = (string) MerchantSale::where('merchant_user_id', $m->id)
            ->whereNull('pos_user_id')
            ->where('created_at', '>=', $from)->sum(\DB::raw('COALESCE(base_amount, total_amount)'));
        $grandTotal = (string) MerchantSale::where('merchant_user_id', $m->id)
            ->where('created_at', '>=', $from)->sum(\DB::raw('COALESCE(base_amount, total_amount)'));

        return $this->ok([
            'days' => $days,
            'staff' => $rows,
            'unattributed_total' => $unattributed,
            'grand_total' => $grandTotal,
        ], 'OK', 'أداء الموظفين');
    }

    public function store(Request $request): JsonResponse
    {
        $m = $this->guardMerchant($request);
        if ($m instanceof JsonResponse) return $m;

        $v = Validator::make($request->all(), [
            'employee_code' => 'required_without:pos_number|nullable|string|max:20',
            // توافق خلفي فقط؛ العقد الجديد اسمه employee_code.
            'pos_number' => 'sometimes|nullable|string|max:20',
            'display_name' => 'required|string|max:80',
            'password' => 'required|string|min:4|max:64',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|max:40',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        $employeeCode = trim((string) ($request->input('employee_code') ?? $request->input('pos_number')));
        if ($employeeCode === '') {
            return $this->error('VALIDATION', 'رمز الموظف مطلوب', 422);
        }
        $exists = PosUser::where('merchant_user_id', $m->id)
            ->where('pos_number', $employeeCode)->exists();
        if ($exists) {
            return $this->error('EMPLOYEE_CODE_TAKEN', 'رمز الموظف مستخدم مسبقاً', 422);
        }

        try {
            $pos = DB::transaction(function () use ($request, $m, $employeeCode) {
                // القفل والحد هنا، لا في التطبيق: الموظف حساب وباقته مورد مستقل.
                DB::table('users')->where('id', $m->id)->lockForUpdate()->first();
                $this->usage->assertCanAddEmployee($m);

                // حساب دخول الموظف (الهاتف الاصطناعي داخلي؛ الدخول برمز الموظف).
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
                    'pos_number' => $employeeCode,
                    'display_name' => $request->input('display_name'),
                    'is_active' => true,
                    'permissions' => $request->input('permissions', []),
                ]);
            });
        } catch (UsageLimitExceededException $e) {
            return $e->toJsonResponse();
        }

        return $this->ok([
            'id' => $pos->id,
            'employee_code' => $pos->pos_number,
            'login_hint' => "دخول الموظف: رقم التاجر + جوال التاجر + رمز الموظف {$pos->pos_number} + كلمة مروره",
        ], 'STAFF_CREATED', 'تم إنشاء الموظف', 201);
    }

    /** مجموعة صلاحيات مدير العمليات — إشراف تشغيلي واسع (بلا إعدادات النشاط/الاشتراك). */
    private const OPS_MANAGER_PERMISSIONS = [
        'operations_manager', 'sell', 'refund', 'products', 'reports',
        'credit', 'employees', 'customers', 'suppliers', 'branches',
    ];

    /** مجموعة صلاحيات المدير المالي — مالية بحتة (بلا بيع/مخزون). */
    private const FINANCE_MANAGER_PERMISSIONS = [
        'financial_manager', 'reports', 'profit_reports', 'excel_export',
        'credit', 'customers', 'audit_log',
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

    /**
     * تعيين/إلغاء «مدير مالي» لموظف (الباقة المؤسسية).
     * صلاحيات مالية بحتة: التقارير والأرباح والتصدير والتحصيلات وسجلّ التدقيق.
     */
    public function setFinancialManager(Request $request, int $id): JsonResponse
    {
        $m = $this->guardMerchant($request);
        if ($m instanceof JsonResponse) return $m;

        if (!$this->access->hasFeature($m, A::F_FINANCIAL_MANAGER)) {
            return $this->error('FEATURE_LOCKED', 'المدير المالي متاح في الباقة المؤسسية', 402);
        }

        $v = Validator::make($request->all(), ['enabled' => 'required|boolean']);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        $pos = PosUser::where('id', $id)->where('merchant_user_id', $m->id)->first();
        if (!$pos) return $this->error('NOT_FOUND', 'الموظف غير موجود', 404);

        $pos->permissions = $request->boolean('enabled') ? self::FINANCE_MANAGER_PERMISSIONS : ['sell'];
        $pos->save();

        return $this->ok([
            'id' => $pos->id,
            'is_financial_manager' => $request->boolean('enabled'),
            'permissions' => $pos->permissions,
        ], 'FIN_MANAGER_SET', $request->boolean('enabled') ? 'تم تعيين المدير المالي' : 'تم الإلغاء');
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $m = $this->guardMerchant($request);
        if ($m instanceof JsonResponse) return $m;

        $pos = PosUser::where('id', $id)->where('merchant_user_id', $m->id)->first();
        if (!$pos) return $this->error('NOT_FOUND', 'الموظف غير موجود', 404);

        $pos->is_active = !$pos->is_active;
        $pos->save();

        // ══════════════════════════════════════════════════════════════
        // AMIAL-STAFF-REVOKE-001 — **القطعُ كان يقع، ولا يُقال.**
        //
        // ظننتُ أوّلاً أنّ الرمزَ يبقى حيّاً بعد التعطيل فبنيتُ إبطالاً
        // هنا. **وقياسٌ نقض الدعوى**: `User::boot()`
        // (‏`app/Models/User.php:281`) يُبطل كلَّ الرموز حين يتّسخ
        // `is_active` ويصير صفراً — فحفظُ الحسابِ أدناه **يقطع الجلسات
        // في نفس اللحظة منذ زمن**. فحُذف الإبطالُ المكرَّر.
        //
        // **والعطلُ الباقي هو الصمت**: الرسالةُ «تم التعطيل» واحدةٌ
        // لحالتين — «ولا جلسةَ له» و«وقُطعت ثلاثٌ وهو يبيع الآن».
        // فالتاجرُ لا يعرف أنّ موظّفَه كان على الجهاز في تلك اللحظة.
        //
        // **ويُعدُّ قبل الحفظ لا بعده** — فالخُطّاف يكون قد أبطلها،
        // فيقرأ العدَّ صفراً ويُطبَع «ولا جلسةَ مفتوحةٌ له» **وهو كذب**.
        // (وهذا بعينه ما وقع أوّلَ مرّةٍ هنا وأمسكه الحارس.)
        // ══════════════════════════════════════════════════════════════
        $revoked = 0;
        if (! $pos->is_active && $pos->user) {
            $revoked = $pos->user->tokens()->where('revoked', false)->count();
        }

        // عطّل حساب الدخول أيضاً — وبه يقع القطعُ عبر خُطّاف `User`.
        if ($pos->user) {
            $pos->user->is_active = $pos->is_active ? 1 : 0;
            $pos->user->save();
        }

        // **درءُ الانحراف — ومعه شبكةُ أمانٍ للباب كلِّه.**
        //
        // الحالةُ المقصودة: عضويّةٌ تُعطَّل وحسابُها مُعطَّلٌ سلفاً، فلا
        // يتّسخ `is_active`، **فلا يُطلَق الخُطّاف**، فتبقى الرموزُ حيّة.
        //
        // **وقِيس بالتجربة العكسيّة أنّه أوسعُ من ذلك**: بنزع الخُطّاف من
        // `User` بقيت الحالاتُ الستُّ كلُّها خضراء — أي أنّ هذا السطرَ
        // يقطع وحدَه إن ذهب الخُطّاف. فالبابُ الذي يضغطه التاجرُ مكتفٍ
        // بنفسِه ولا يتّكل على خُطّافٍ في نموذجٍ بعيد.
        if (! $pos->is_active && $pos->user
            && $pos->user->tokens()->where('revoked', false)->exists()) {
            $pos->user->tokens()->where('revoked', false)->get()
                ->each(fn ($t) => $t->revoke());
        }

        return $this->ok([
            'id' => $pos->id,
            'is_active' => (bool) $pos->is_active,
            // **ويُقال كم جلسةً قُطعت** — فصفرٌ صامتٌ لا يفرّق بين
            // «لم يكن يعمل» و«لم يُقطَع شيء». (القاعدة السابعة.)
            'revoked_sessions' => $revoked,
        ], 'STAFF_TOGGLED', $pos->is_active
            ? 'تم التفعيل'
            : ($revoked > 0
                ? "تم التعطيل — وقُطعت {$revoked} جلسة عمل فوراً"
                : 'تم التعطيل — ولا جلسةَ مفتوحةٌ له'));
    }

    private function uniqueSyntheticPhone(int $merchantId): string
    {
        do {
            // بادئة اصطناعية 9009 لتفادي التصادم مع أرقام حقيقية
            $phone = '9009' . str_pad((string) $merchantId, 5, '0', STR_PAD_LEFT) . random_int(1000, 9999);
        } while (User::whereIn('phone', \App\Support\Phone::variants($phone))->exists());
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
