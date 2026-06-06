<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * CRITICAL-001 — Access Controller.
 *
 * Endpoints:
 *   GET  /api/v1/amial/me/access                          ← الجوهر
 *   PUT  /api/v1/amial/merchant/business-type             (للتاجر — يختار نوع نشاطه)
 *
 *   Admin:
 *   POST /api/v1/amial/admin/merchants/{id}/plan          ← تغيير خطّة
 *   POST /api/v1/amial/admin/merchants/{id}/business-type
 *   POST /api/v1/amial/admin/merchants/{id}/extra-feature
 *   GET  /api/v1/amial/admin/access-catalog               ← قائمة كل القيم الممكنة
 */
class AccessController extends Controller
{
    public function __construct(
        private readonly FeatureAccessService $svc,
    ) {}

    // ============ /me/access ============

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return $this->error('UNAUTHENTICATED', 'يجب تسجيل الدخول', 401);

        $access = $this->svc->accessFor($user);

        // أضف plan_info إن كان تاجراً
        $planInfo = null;
        $profile = MerchantProfile::where('user_id', $user->id)->first();
        if ($profile) {
            $plan = $profile->subscription_plan ?? A::PLAN_FREE;
            $expiresAt = $profile->subscription_expires_at;

            $isExpired = $expiresAt !== null && $expiresAt->isPast() && $plan !== A::PLAN_FREE;
            $effectivePlan = $isExpired ? A::PLAN_FREE : $plan;

            $planInfo = [
                'code' => $effectivePlan,
                'label' => A::PLAN_LABELS[$effectivePlan] ?? $effectivePlan,
                'subscription_expires_at' => $expiresAt?->toIso8601String(),
                'is_expired' => $isExpired,
                'limits' => AccessPresets::planLimits($effectivePlan),
                'price_monthly_sar' => A::PLAN_PRICES_SAR[$effectivePlan] ?? 0,
            ];
        }

        return $this->ok([
            'access' => $access,
            'plan_info' => $planInfo,
            'user' => [
                'id' => $user->id,
                'phone' => $user->phone ?? null,
                'name' => trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')),
                'zone_code' => $user->zone_code ?? 'SOUTH',
            ],
        ]);
    }

    // ============ التاجر يختار نوع نشاطه ============

    public function updateMyBusinessType(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'business_type' => 'required|in:' . implode(',', A::ALL_BUSINESS_TYPES),
        ]);
        if ($v->fails()) return $this->validationError($v);

        $user = $request->user();
        if ($user->role !== A::ROLE_MERCHANT) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }

        $merchant = MerchantProfile::where('user_id', $user->id)->first();
        if (!$merchant) {
            return $this->error('NO_PROFILE', 'لا يوجد ملفّ تاجر', 404);
        }

        $updated = $this->svc->updateBusinessType($merchant, $request->input('business_type'));
        return $this->ok([
            'merchant_profile' => $updated,
            'access' => $this->svc->accessFor($user->fresh()),
        ], 'BUSINESS_TYPE_UPDATED', 'تم تحديث نوع النشاط');
    }

    /**
     * snapshot الاستخدام الحالي (لشاشة "خطّتي").
     * متاح للتاجر فقط.
     */
    public function myUsage(Request $request): JsonResponse
    {
        $user = $request->user();

        // ابحث عن التاجر (إن كان user مباشرة أو موظف POS)
        $merchant = $user;
        $pos = \App\Models\PosUser::where('user_id', $user->id)->where('is_active', true)->first();
        if ($pos) {
            $merchant = \App\Models\User::find($pos->merchant_user_id);
        }

        $isMerchant = MerchantProfile::where('user_id', $merchant->id)->exists();
        if (!$isMerchant) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجار فقط', 403);
        }

        $usageSvc = app(\App\Services\UsageLimitService::class);
        return $this->ok([
            'usage' => $usageSvc->usageSnapshot($merchant),
        ]);
    }

    // ============ Public endpoints ============

    /**
     * يعرض الـ Plans Catalog الكامل (للعرض في شاشة "خطّتي + الترقية").
     * متاح لكل مستخدم مُسجّل دخول — لا يحتاج صلاحيات إدارية.
     */
    public function plansCatalog(Request $request): JsonResponse
    {
        $plans = [];
        foreach (A::ALL_PLANS as $code) {
            $features = AccessPresets::planFeatures($code);
            $limits = AccessPresets::planLimits($code);

            $plans[] = [
                'code' => $code,
                'label' => A::PLAN_LABELS[$code] ?? $code,
                'price_monthly_sar' => A::PLAN_PRICES_SAR[$code] ?? 0,
                'price_annual_sar' => A::PLAN_PRICES_SAR_ANNUAL[$code] ?? 0,
                'duration_days' => $code === A::PLAN_FREE ? null : 30, // FREE دائم
                'features' => $features,
                'limits' => $limits,
                'is_free' => $code === A::PLAN_FREE,
            ];
        }

        // خطّة المستخدم الحالية (إن كان تاجراً)
        $currentPlan = null;
        $profile = \App\Models\MerchantProfile::where('user_id', $request->user()->id)->first();
        if ($profile) {
            $currentPlan = [
                'code' => $profile->subscription_plan ?? A::PLAN_FREE,
                'expires_at' => $profile->subscription_expires_at?->toIso8601String(),
                'extra_features' => $profile->extra_features ?? [],
            ];
        }

        return $this->ok([
            'plans' => $plans,
            'current_plan' => $currentPlan,
            'currency' => 'SAR',
            'note' => 'الأسعار مرجعية بالريال السعودي. التفعيل يتم يدوياً عبر خدمة العملاء.',
        ]);
    }

    // ============ Admin endpoints ============

    public function adminCatalog(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }
        return $this->ok([
            'roles' => A::ALL_ROLES,
            'verification_levels' => A::ALL_VERIFICATION_LEVELS,
            'business_types' => array_map(fn($t) => [
                'value' => $t, 'label' => A::BUSINESS_TYPE_LABELS[$t] ?? $t,
            ], A::ALL_BUSINESS_TYPES),
            'plans' => array_map(fn($p) => [
                'value' => $p,
                'label' => A::PLAN_LABELS[$p] ?? $p,
                'price_sar' => A::PLAN_PRICES_SAR[$p] ?? 0,
                'price_annual_sar' => A::PLAN_PRICES_SAR_ANNUAL[$p] ?? 0,
            ], A::ALL_PLANS),
        ]);
    }

    public function adminUpdatePlan(Request $request, int $merchantId): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $v = Validator::make($request->all(), [
            'plan' => 'required|in:' . implode(',', A::ALL_PLANS),
            'expires_at' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string|max:1000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $merchant = MerchantProfile::find($merchantId);
        if (!$merchant) return $this->error('NOT_FOUND', 'التاجر غير موجود', 404);

        try {
            $expires = $request->filled('expires_at')
                ? new \DateTimeImmutable($request->input('expires_at'))
                : null;

            // CRITICAL-001-SUBS — مرّ عبر SubscriptionService للـ audit log
            $merchantUser = \App\Models\User::find($merchant->user_id);
            if (!$merchantUser) return $this->error('NOT_FOUND', 'التاجر غير موجود', 404);

            $change = app(\App\Services\SubscriptionService::class)->changePlan(
                $merchantUser,
                $request->input('plan'),
                $request->user(), // actor = الأدمن الذي نفّذ
                [
                    'expires_at' => $expires,
                    'notes' => $request->input('notes'),
                    'price_paid_sar' => $request->input('price_paid_sar'),
                    'payment_method' => $request->input('payment_method'),
                    'payment_reference' => $request->input('payment_reference'),
                ],
            );
            $updated = MerchantProfile::find($merchantId)->fresh();
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('CHANGE_FAILED', $e->getMessage(), 500);
        }
        return $this->ok([
            'merchant_profile' => $updated,
            'change_id' => $change->id,
        ], 'PLAN_UPDATED', 'تم تحديث الخطّة');
    }

    public function adminUpdateBusinessType(Request $request, int $merchantId): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }
        $v = Validator::make($request->all(), [
            'business_type' => 'nullable|in:' . implode(',', A::ALL_BUSINESS_TYPES),
        ]);
        if ($v->fails()) return $this->validationError($v);

        $merchant = MerchantProfile::find($merchantId);
        if (!$merchant) return $this->error('NOT_FOUND', 'التاجر غير موجود', 404);

        $updated = $this->svc->updateBusinessType($merchant, $request->input('business_type'));
        return $this->ok(['merchant_profile' => $updated], 'UPDATED', 'تم التحديث');
    }

    public function adminAddExtraFeature(Request $request, int $merchantId): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }
        $v = Validator::make($request->all(), [
            'feature' => 'required|string|max:64',
            'action' => 'sometimes|in:add,remove',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $merchant = MerchantProfile::find($merchantId);
        if (!$merchant) return $this->error('NOT_FOUND', 'التاجر غير موجود', 404);

        $action = $request->input('action', 'add');
        $updated = $action === 'remove'
            ? $this->svc->removeExtraFeature($merchant, $request->input('feature'))
            : $this->svc->addExtraFeature($merchant, $request->input('feature'));

        return $this->ok(['merchant_profile' => $updated], 'UPDATED', 'تم التحديث');
    }

    // ============ Helpers ============

    private function isAdmin(?User $user): bool
    {
        if (!$user) return false;
        return $user->role === A::ROLE_ADMIN || $user->type === 1;
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
