<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Kyc\KycPrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** AMIAL-KYC-PRIVACY-ADMIN-001 — رؤية مسار التحقق بلا اختراع نتيجة أو تسريبها. */
class KycPrivacyAdminController extends Controller
{
    public function cases(Request $request, KycPrivacyService $privacy): JsonResponse
    {
        if (!Schema::hasTable('kyc_verification_cases')) {
            return response()->json([
                'success' => false,
                'code' => 'KYC_PRIVACY_SCHEMA_UNAVAILABLE',
                'message' => 'جدول حالات خصوصية التحقق لم يُرحّل على هذا الخادم بعد.',
            ], 503);
        }

        $actor = $request->user();
        $canRestricted = (bool) $actor?->hasPlatformPermission('platform.customers.kyc.restricted.view');
        $canBiometric = (bool) $actor?->hasPlatformPermission('platform.customers.kyc.biometric.view');

        $rows = DB::table('kyc_verification_cases as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->select([
                'c.user_id', 'c.review_mode', 'c.ownership_method', 'c.status',
                'c.restricted_review', 'c.liveness_status', 'c.liveness_score',
                'c.face_match_status', 'c.face_match_score', 'c.biometric_provider',
                'c.requested_at', 'c.reviewed_at', 'u.f_name', 'u.l_name', 'u.phone',
            ])
            ->orderByDesc('c.restricted_review')
            ->orderBy('c.requested_at')
            ->limit(200)
            ->get()
            ->map(function ($row) use ($privacy, $canRestricted, $canBiometric) {
                $restricted = (bool) $row->restricted_review;
                $state = $this->redactBiometric($privacy->forUser((int) $row->user_id), $canBiometric);

                // موظف التدقيق العام يعرف أن «هناك حالة مقيدة» ليكتمل أثر
                // التشغيل، لكنه لا يحصل من هذه الشاشة على هوية صاحبها.
                if ($restricted && !$canRestricted) {
                    return [
                        'user_id' => null,
                        'name' => 'حالة KYC مقيدة',
                        'phone' => 'محجوب',
                        'details_allowed' => false,
                    ] + $state;
                }

                return [
                    'user_id' => (int) $row->user_id,
                    'name' => trim((string) ($row->f_name . ' ' . $row->l_name)) ?: '—',
                    'phone' => (string) ($row->phone ?? '—'),
                    'details_allowed' => true,
                ] + $state;
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'cases' => $rows,
                'biometric_provider_configured' => $privacy->biometricConfigured(),
                'biometric_details_allowed' => $canBiometric,
                'restricted_details_allowed' => $canRestricted,
            ],
        ]);
    }

    public function show(Request $request, int $userId, KycPrivacyService $privacy): JsonResponse
    {
        $user = User::findOrFail($userId);
        $actor = $request->user();

        // لا يكفي أن القائمة أخفت الاسم؛ رابط مباشر إلى /cases/{id} يجب أن
        // يخضع للبوابة نفسها.
        $privacy->assertReviewerAccess($user, $actor, false);

        $canBiometric = (bool) $actor?->hasPlatformPermission('platform.customers.kyc.biometric.view');

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => (int) $user->id,
                'name' => trim((string) ($user->f_name . ' ' . $user->l_name)) ?: '—',
                'state' => $this->redactBiometric($privacy->forUser($user), $canBiometric),
            ],
        ]);
    }

    /**
     * حالة/درجة بيومترية بيانات حساسة مشتقة من الوجه، وليست «معلومة تدقيق»
     * عامة. من لا يملك المفتاح يرى أنها محجوبة لا صفراً ولا فشلاً.
     */
    private function redactBiometric(array $state, bool $allowed): array
    {
        if ($allowed) {
            return $state;
        }

        $state['liveness'] = ['status' => 'redacted', 'score' => null];
        $state['face_match'] = ['status' => 'redacted', 'score' => null];
        $state['biometric_provider'] = null;
        $state['provider_reference'] = null;

        return $state;
    }
}
