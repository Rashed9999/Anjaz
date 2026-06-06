<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\SubscriptionChange;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * CRITICAL-001-SUBS — Admin endpoints لإدارة الاشتراكات.
 *
 * Endpoints:
 *   GET  /admin/subscriptions/summary         — MRR + KPIs
 *   GET  /admin/subscriptions/expiring        — المنتهية قريباً
 *   GET  /admin/subscriptions/log             — audit log (paginated)
 *   GET  /admin/subscriptions/merchant/{id}/history — تاريخ تاجر معيّن
 *   POST /admin/subscriptions/merchant/{id}/renew   — تجديد 30 يوم
 *   POST /admin/subscriptions/merchant/{id}/extend  — تمديد بأيام
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $svc,
    ) {}

    // ============ Analytics ============

    public function summary(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }
        return $this->ok($this->svc->summary());
    }

    // ============ Expiring Soon ============

    public function expiring(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }
        $days = max(1, min(90, (int)$request->query('days', 7)));
        $items = $this->svc->expiringSoon($days);

        $payload = $items->map(function (MerchantProfile $p) {
            $user = $p->user;
            $daysLeft = $p->subscription_expires_at
                ? (int)now()->diffInDays($p->subscription_expires_at, false)
                : null;
            return [
                'merchant_profile_id' => $p->id,
                'merchant_user_id' => $p->user_id,
                'name' => trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')),
                'phone' => $user->phone ?? null,
                'business_type' => $p->business_type,
                'current_plan' => $p->subscription_plan,
                'current_plan_label' => A::PLAN_LABELS[$p->subscription_plan] ?? $p->subscription_plan,
                'expires_at' => $p->subscription_expires_at?->toIso8601String(),
                'days_left' => $daysLeft,
            ];
        });

        return $this->ok([
            'expiring' => $payload,
            'count' => $payload->count(),
            'days_window' => $days,
        ]);
    }

    // ============ Audit Log ============

    public function log(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $q = SubscriptionChange::with(['merchant:id,phone,f_name,l_name', 'actor:id,phone,f_name,l_name'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('action')) $q->where('action', $request->query('action'));
        if ($request->filled('merchant_id')) $q->where('merchant_user_id', $request->query('merchant_id'));
        if ($request->filled('from')) $q->where('created_at', '>=', $request->query('from'));
        if ($request->filled('to')) $q->where('created_at', '<=', $request->query('to'));

        $perPage = max(10, min(100, (int)$request->query('per_page', 20)));
        $paginated = $q->paginate($perPage);

        return $this->ok([
            'items' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
            ],
        ]);
    }

    // ============ Merchant History ============

    public function merchantHistory(Request $request, int $merchantId): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $merchant = User::find($merchantId);
        if (!$merchant) return $this->error('NOT_FOUND', 'التاجر غير موجود', 404);

        $changes = SubscriptionChange::with('actor:id,phone,f_name,l_name')
            ->where('merchant_user_id', $merchantId)
            ->orderBy('created_at', 'desc')
            ->get();

        $profile = MerchantProfile::where('user_id', $merchantId)->first();

        return $this->ok([
            'merchant' => [
                'id' => $merchant->id,
                'name' => trim(($merchant->f_name ?? '') . ' ' . ($merchant->l_name ?? '')),
                'phone' => $merchant->phone,
                'current_plan' => $profile?->subscription_plan,
                'current_plan_label' => $profile
                    ? (A::PLAN_LABELS[$profile->subscription_plan] ?? $profile->subscription_plan)
                    : null,
                'expires_at' => $profile?->subscription_expires_at?->toIso8601String(),
            ],
            'history' => $changes,
            'total_changes' => $changes->count(),
            'total_paid_sar' => round((float)$changes->sum('price_paid_sar'), 2),
        ]);
    }

    // ============ Renew (30 days same plan) ============

    public function renew(Request $request, int $merchantId): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $merchant = User::find($merchantId);
        if (!$merchant) return $this->error('NOT_FOUND', 'التاجر غير موجود', 404);

        $v = Validator::make($request->all(), [
            'price_paid_sar' => 'sometimes|nullable|numeric|min:0|max:100000',
            'payment_method' => 'sometimes|nullable|string|max:24',
            'payment_reference' => 'sometimes|nullable|string|max:64',
            'notes' => 'sometimes|nullable|string|max:1000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $change = $this->svc->renew($merchant, $request->user(), $request->only([
                'price_paid_sar', 'payment_method', 'payment_reference', 'notes',
            ]));
        } catch (\Throwable $e) {
            return $this->error('RENEW_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'change' => $change,
            'new_expires_at' => $change->new_expires_at?->toIso8601String(),
        ], 'RENEWED', 'تمّ التجديد');
    }

    // ============ Extend (custom days) ============

    public function extend(Request $request, int $merchantId): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $merchant = User::find($merchantId);
        if (!$merchant) return $this->error('NOT_FOUND', 'التاجر غير موجود', 404);

        $v = Validator::make($request->all(), [
            'days' => 'required|integer|min:1|max:365',
            'price_paid_sar' => 'sometimes|nullable|numeric|min:0|max:100000',
            'payment_method' => 'sometimes|nullable|string|max:24',
            'payment_reference' => 'sometimes|nullable|string|max:64',
            'notes' => 'sometimes|nullable|string|max:1000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $change = $this->svc->extend(
                $merchant, (int)$request->input('days'), $request->user(),
                $request->only(['price_paid_sar', 'payment_method', 'payment_reference', 'notes']),
            );
        } catch (\Throwable $e) {
            return $this->error('EXTEND_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'change' => $change,
            'new_expires_at' => $change->new_expires_at?->toIso8601String(),
        ], 'EXTENDED', 'تمّ التمديد');
    }

    // ============ Process Expired (cron / manual trigger) ============

    public function processExpired(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }
        $count = $this->svc->processExpired();
        return $this->ok(['processed' => $count],
            'PROCESSED', "تمّت معالجة {$count} اشتراك منتهي");
    }

    // ============ Private Helpers ============

    private function isAdmin(?User $user): bool
    {
        if (!$user) return false;
        return ($user->type ?? null) === 1 || ($user->is_admin ?? false);
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
