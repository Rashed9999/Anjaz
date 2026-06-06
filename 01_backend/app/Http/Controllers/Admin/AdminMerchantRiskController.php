<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\MerchantRiskProfile;
use App\Models\User;
use App\Services\MerchantRiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-MERCHANT-RISK-001 (v2.10) — إدارة مخاطر وتصنيف التجار.
 */
class AdminMerchantRiskController extends Controller
{
    public function __construct(
        private readonly MerchantRiskService $service,
    ) {}

    /** GET /admin/amial/merchants/high-risk — التجار عالو المخاطر (أولوية المراجعة) */
    public function highRisk(): JsonResponse
    {
        $merchants = MerchantRiskProfile::whereIn('risk_level', ['high', 'critical'])
            ->orderByDesc('current_risk_score')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                $profile = MerchantProfile::where('user_id', $r->merchant_user_id)->first();
                $user = User::find($r->merchant_user_id);
                return [
                    'merchant_user_id' => $r->merchant_user_id,
                    'name' => trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')),
                    'risk_score' => (string)$r->current_risk_score,
                    'risk_level' => $r->risk_level,
                    'tier' => $profile?->tier,
                    'pass_through_ratio' => round($r->passThroughRatio() * 100, 1),
                    'aml_flags_count' => $r->aml_flags_count,
                    'last_flagged_at' => $r->last_flagged_at,
                ];
            });

        return $this->ok(['merchants' => $merchants]);
    }

    /** GET /admin/amial/merchants/{userId}/risk — لوحة مخاطر تاجر */
    public function riskDashboard(int $userId): JsonResponse
    {
        $dashboard = $this->service->getRiskDashboard($userId);
        return $this->ok($dashboard);
    }

    /** PUT /admin/amial/merchants/{userId}/tier — تغيير التصنيف */
    public function setTier(Request $request, int $userId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'tier' => 'required|in:micro,small,medium,large',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $merchant = User::find($userId);
        if (!$merchant) return $this->error('NOT_FOUND', 'التاجر غير موجود', 404);

        try {
            $profile = $this->service->setTier($merchant, $request->input('tier'), $request->user());
        } catch (\RuntimeException $e) {
            return $this->error('TIER_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'tier' => $profile->tier,
            'daily_receive_limit' => (string)$profile->daily_receive_limit,
            'single_receive_limit' => (string)$profile->single_receive_limit,
        ], 'TIER_UPDATED', 'تم تحديث تصنيف التاجر');
    }

    /** POST /admin/amial/merchants/{userId}/verify — توثيق التاجر */
    public function verify(Request $request, int $userId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'status' => 'required|in:verified,rejected,resubmission_required,verification_suspended',
            'reason' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $profile = MerchantProfile::where('user_id', $userId)->first();
        if (!$profile) return $this->error('NOT_FOUND', 'ملف التاجر غير موجود', 404);

        $profile->update([
            'verification_status' => $request->input('status'),
            'verified_by_admin_id' => $request->user()->id,
            'verified_at' => now(),
        ]);

        return $this->ok([
            'user_id' => $userId,
            'verification_status' => $profile->verification_status,
        ], 'VERIFICATION_UPDATED', 'تم تحديث حالة التوثيق');
    }

    /** GET /admin/amial/merchants/risk-stats — إحصاءات عامة */
    public function riskStats(): JsonResponse
    {
        $byLevel = MerchantRiskProfile::select('risk_level', DB::raw('count(*) as total'))
            ->groupBy('risk_level')->get();
        $byTier = MerchantProfile::select('tier', DB::raw('count(*) as total'))
            ->groupBy('tier')->get();
        $byVerification = MerchantProfile::select('verification_status', DB::raw('count(*) as total'))
            ->groupBy('verification_status')->get();

        return $this->ok([
            'by_risk_level' => $byLevel,
            'by_tier' => $byTier,
            'by_verification' => $byVerification,
            'critical_count' => MerchantRiskProfile::where('risk_level', 'critical')->count(),
        ]);
    }

    // ============================================================
    private function ok(array $meta, string $code = 'OK', string $message = 'OK'): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ]);
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
