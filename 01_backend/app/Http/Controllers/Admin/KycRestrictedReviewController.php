<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Kyc\GuardedKycDocumentService;
use App\Services\Kyc\KycOwnershipGuardService;
use App\Services\Kyc\KycPrivacyService;
use App\Services\KycDocumentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-RESTRICTED-QUEUE-001 — طابورٌ منفصل لا فلتر تجميلي.
 *
 * الحالة التي يختار صاحبها «خصوصية إضافية» لا تدخل الطابور العام أصلاً.
 * والتحقق الحضوري يُدار هنا أيضاً لأنه إثبات ملكية عالي الحساسية، لا لأنه
 * مرتبط بجنس صاحب الحساب. التعيين للموظفين يتم بالصلاحيات فقط.
 */
class KycRestrictedReviewController extends Controller
{
    public function queue(
        Request $request,
        KycDocumentService $documents,
        KycPrivacyService $privacy,
        KycOwnershipGuardService $ownership,
    ): JsonResponse {
        $reviewer = $request->user();

        if (!$documents instanceof GuardedKycDocumentService) {
            return response()->json([
                'success' => false,
                'code' => 'KYC_GUARD_NOT_BOUND',
                'message' => 'حارس KYC المركزي غير مربوط بالخدمة — أُوقف الطابور المقيد احتياطياً.',
            ], 503);
        }

        $pending = $documents->restrictedPendingQueue($reviewer);
        $ready = $documents->restrictedActivationQueue($reviewer);

        $decorate = function (array $row) use ($privacy, $ownership): array {
            $user = User::find($row['user_id']);
            if (!$user) {
                return $row + ['privacy' => null, 'ownership' => null];
            }

            return $row + [
                'privacy' => $privacy->forUser($user),
                'ownership' => $ownership->assess($user),
            ];
        };

        $inPerson = [];
        if (Schema::hasTable('kyc_verification_cases')) {
            $ids = DB::table('kyc_verification_cases')
                ->where('review_mode', KycPrivacyService::MODE_IN_PERSON)
                ->whereIn('status', ['collecting', 'manual_review'])
                ->orderBy('requested_at')
                ->limit(100)
                ->pluck('user_id');

            $inPerson = User::query()
                ->whereIn('id', $ids)
                ->get(['id', 'f_name', 'l_name', 'phone'])
                ->map(function (User $user) use ($privacy, $ownership) {
                    return [
                        'user_id' => (int) $user->id,
                        'customer_name' => trim((string) ($user->f_name . ' ' . $user->l_name)) ?: '—',
                        'customer_phone' => (string) ($user->phone ?? '—'),
                        'privacy' => $privacy->forUser($user),
                        'ownership' => $ownership->assess($user),
                    ];
                })
                ->values()
                ->all();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'documents' => array_map($decorate, $pending),
                'ready_for_account_decision' => array_map($decorate, $ready),
                'in_person_requests' => $inPerson,
            ],
        ]);
    }

    public function show(
        Request $request,
        int $userId,
        KycPrivacyService $privacy,
        KycOwnershipGuardService $ownership,
    ): JsonResponse {
        $user = User::findOrFail($userId);
        $state = $privacy->forUser($user);

        // التحقق الحضوري يستخدم نفس فريق الخصوصية المقيد حتى إن لم يحمل
        // restricted_review=true؛ route نفسه يتطلب restricted.view.
        if (($state['review_mode'] ?? null) !== KycPrivacyService::MODE_IN_PERSON) {
            $privacy->assertReviewerAccess($user, $request->user(), false);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => (int) $user->id,
                'name' => trim((string) ($user->f_name . ' ' . $user->l_name)) ?: '—',
                'privacy' => $state,
                'ownership' => $ownership->assess($user),
            ],
        ]);
    }

    /**
     * يثبت مقابلة حضورية كدليل ملكية فقط. لا يرفع is_kyc_verified ولا tier؛
     * يبقى القرار النهائي في KycDocumentService مع كل حرّاسه الآخرين.
     */
    public function verifyInPerson(
        Request $request,
        int $userId,
        KycPrivacyService $privacy,
        AuditService $audit,
    ): JsonResponse {
        $request->validate(['reason' => 'required|string|min:5|max:500']);

        $user = User::findOrFail($userId);

        try {
            $state = $privacy->verifyInPerson(
                $user,
                $request->user(),
                (string) $request->input('reason'),
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 422);
        }

        $audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'KYC_IN_PERSON_OWNERSHIP_VERIFIED',
            'decision_code' => 'KYC_IN_PERSON_PROOF',
            'reason' => mb_substr((string) $request->input('reason'), 0, 500),
            'severity' => 'critical',
            'context' => ['account_verification_changed' => false],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'سُجّل إثبات الملكية الحضوري. لم يُعتمد الحساب بعد؛ القرار النهائي مستقل.',
            'data' => $state,
        ]);
    }
}
