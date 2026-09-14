<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Kyc\KycPrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** AMIAL-KYC-PRIVACY-ADMIN-001 — رؤية مسار التحقق بلا اختراع نتيجة. */
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
            ->map(function ($row) use ($privacy) {
                $state = $privacy->forUser((int) $row->user_id);
                return [
                    'user_id' => (int) $row->user_id,
                    'name' => trim((string) ($row->f_name . ' ' . $row->l_name)) ?: '—',
                    'phone' => (string) ($row->phone ?? '—'),
                ] + $state;
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'cases' => $rows,
                'biometric_provider_configured' => $privacy->biometricConfigured(),
            ],
        ]);
    }

    public function show(Request $request, int $userId, KycPrivacyService $privacy): JsonResponse
    {
        $user = User::findOrFail($userId);

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => (int) $user->id,
                'name' => trim((string) ($user->f_name . ' ' . $user->l_name)) ?: '—',
                'state' => $privacy->forUser($user),
            ],
        ]);
    }
}
