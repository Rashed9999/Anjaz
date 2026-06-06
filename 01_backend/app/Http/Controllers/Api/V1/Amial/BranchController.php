<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Exceptions\UsageLimitExceededException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\BranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * P1-BRANCHES — Endpoints الفروع.
 *
 * للتاجر:
 *   GET    /merchant/branches              — قائمة فروعه
 *   POST   /merchant/branches              — إنشاء فرع
 *   GET    /merchant/branches/{id}         — تفاصيل فرع
 *   PUT    /merchant/branches/{id}         — تحديث
 *   DELETE /merchant/branches/{id}         — حذف (soft)
 *   POST   /merchant/branches/{id}/default — جعله الافتراضي
 */
class BranchController extends Controller
{
    public function __construct(private readonly BranchService $svc) {}

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->resolveMerchant($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $activeOnly = $request->boolean('active_only', false);
        $branches = $this->svc->listForMerchant($merchant, $activeOnly);

        return $this->ok([
            'branches' => $branches,
            'count' => $branches->count(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $merchant = $this->resolveMerchant($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $branch = Branch::with('manager')
            ->where('id', $id)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$branch) return $this->error('NOT_FOUND', 'الفرع غير موجود', 404);

        return $this->ok(['branch' => $branch]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->resolveMerchant($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'code' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:500',
            'city' => 'sometimes|nullable|string|max:80',
            'phone' => 'sometimes|nullable|string|max:32',
            'manager_pos_user_id' => 'sometimes|nullable|integer',
            'settings' => 'sometimes|nullable|array',
        ]);
        if ($v->fails()) return $this->validationError($v);

        // تحقّق من المدير المُختار
        if ($request->filled('manager_pos_user_id')) {
            $valid = PosUser::where('id', $request->input('manager_pos_user_id'))
                ->where('merchant_user_id', $merchant->id)->exists();
            if (!$valid) return $this->error('INVALID_MANAGER', 'المدير غير صالح', 422);
        }

        try {
            $branch = $this->svc->create($merchant, $request->only([
                'name', 'code', 'address', 'city', 'phone',
                'manager_pos_user_id', 'settings',
            ]));
        } catch (UsageLimitExceededException $e) {
            return $e->toJsonResponse();
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }

        return $this->ok(['branch' => $branch], 'CREATED', 'تمّ إنشاء الفرع');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $merchant = $this->resolveMerchant($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $branch = Branch::where('id', $id)
            ->where('merchant_user_id', $merchant->id)->first();
        if (!$branch) return $this->error('NOT_FOUND', 'الفرع غير موجود', 404);

        $v = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:500',
            'city' => 'sometimes|nullable|string|max:80',
            'phone' => 'sometimes|nullable|string|max:32',
            'manager_pos_user_id' => 'sometimes|nullable|integer',
            'is_active' => 'sometimes|boolean',
            'settings' => 'sometimes|nullable|array',
        ]);
        if ($v->fails()) return $this->validationError($v);

        // تحقّق من المدير
        if ($request->filled('manager_pos_user_id')) {
            $valid = PosUser::where('id', $request->input('manager_pos_user_id'))
                ->where('merchant_user_id', $merchant->id)->exists();
            if (!$valid) return $this->error('INVALID_MANAGER', 'المدير غير صالح', 422);
        }

        try {
            $branch = $this->svc->update($branch, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['branch' => $branch], 'UPDATED', 'تمّ التحديث');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $merchant = $this->resolveMerchant($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $branch = Branch::where('id', $id)
            ->where('merchant_user_id', $merchant->id)->first();
        if (!$branch) return $this->error('NOT_FOUND', 'الفرع غير موجود', 404);

        try {
            $this->svc->delete($branch);
        } catch (\LogicException $e) {
            return $this->error('LOCKED', $e->getMessage(), 422);
        }
        return $this->ok([], 'DELETED', 'تمّ الحذف');
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        $merchant = $this->resolveMerchant($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $branch = Branch::where('id', $id)
            ->where('merchant_user_id', $merchant->id)->first();
        if (!$branch) return $this->error('NOT_FOUND', 'الفرع غير موجود', 404);

        try {
            $branch = $this->svc->setAsDefault($branch);
        } catch (\LogicException $e) {
            return $this->error('INVALID_STATE', $e->getMessage(), 422);
        }
        return $this->ok(['branch' => $branch], 'DEFAULT_SET', 'تمّ تعيينه افتراضياً');
    }

    /**
     * P1-BRANCHES — تقرير سريع لكل فرع.
     * يجمع إجماليات من كل الجداول التي بها branch_id.
     */
    public function report(Request $request, int $id): JsonResponse
    {
        $merchant = $this->resolveMerchant($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $branch = Branch::where('id', $id)
            ->where('merchant_user_id', $merchant->id)->first();
        if (!$branch) return $this->error('NOT_FOUND', 'الفرع غير موجود', 404);

        // نطاق التاريخ (افتراضي: الشهر الحالي)
        $from = $request->query('from')
            ? \Carbon\Carbon::parse($request->query('from'))->startOfDay()
            : now()->startOfMonth();
        $to = $request->query('to')
            ? \Carbon\Carbon::parse($request->query('to'))->endOfDay()
            : now()->endOfDay();

        $report = [
            'branch' => $branch,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'sections' => [],
        ];

        // Wholesale
        if (\Schema::hasTable('wholesale_invoices')) {
            $wInvoices = \DB::table('wholesale_invoices')
                ->where('branch_id', $id)
                ->whereBetween('created_at', [$from, $to])
                ->where('status', '!=', 'voided');
            $report['sections']['wholesale'] = [
                'invoice_count' => (clone $wInvoices)->count(),
                'total_amount' => (float)(clone $wInvoices)->sum('total_amount'),
                'paid_amount' => (float)(clone $wInvoices)->sum('paid_amount'),
                'balance_due' => (float)(clone $wInvoices)->sum('balance_due'),
            ];
        }

        // POS Users count لهذا الفرع
        $report['sections']['employees'] = [
            'active_count' => PosUser::where('branch_id', $id)
                ->where('is_active', true)->count(),
        ];

        return $this->ok($report);
    }

    // ============ Helpers ============

    /**
     * يُعيد التاجر المالك (إن كان user مباشرة) أو merchant_user_id (إن كان POS user).
     */
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
