<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Services\Kyc\KycAccountStatusService;
use App\Services\Kyc\KycPrivacyService;
use App\Services\Kyc\ResidenceVerificationService;
use App\Services\KycTierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-VERIFICATION-CENTER-001 — مصدر واحد لما يراه صاحب الحساب.
 *
 * لا نعيد مسار ملف، reviewer id، OCR خاماً أو بيانات موظفين. الواجهة تحتاج
 * قراراً تشغيلياً فقط: ما الذي تحقق؟ ما الذي ينتظر؟ وما الخطوة التالية؟
 */
class VerificationStatusController extends Controller
{
    public function show(
        Request $request,
        KycTierService $tiers,
        KycAccountStatusService $accounts,
        ResidenceVerificationService $residence,
        KycPrivacyService $privacy,
    ): JsonResponse {
        $user = $request->user();
        if (!$user || (int) $user->type !== 2) {
            return response()->json([
                'success' => false,
                'code' => 'CUSTOMER_REQUIRED',
                'message' => 'مركز التحقق متاح لحساب العميل.',
            ], 403);
        }

        $tier = $tiers->getUserTierInfo($user);
        $account = $accounts->for($user);
        $residenceState = $residence->forUser($user);
        $privacyState = $privacy->forUser($user);
        $docs = $this->documentStates((int) $user->id);
        $identity = $this->identityState($tier, $account, $docs);

        $phoneVerified = (bool) ($user->is_phone_verified ?? false);
        $emailVerified = (bool) ($user->is_email_verified ?? false);
        $residenceVerified = ($residenceState['status'] ?? null) === ResidenceVerificationService::STATUS_VERIFIED;
        $operationalResidence = $residenceVerified && (bool) ($residenceState['operational'] ?? false);
        $financialActive = (int) $tier['current_tier'] >= 1
            && $phoneVerified
            && $operationalResidence;

        $actions = [];
        if (!$phoneVerified) {
            $actions[] = [
                'code' => 'verify_phone',
                'priority' => 1,
                'title' => 'أثبت ملكية رقم الهاتف',
                'description' => 'مطلوب للوصول إلى Tier 1.',
            ];
        }

        $residenceStatus = (string) ($residenceState['status'] ?? 'not_submitted');
        if (!$residenceVerified) {
            $actions[] = match ($residenceStatus) {
                ResidenceVerificationService::STATUS_PENDING => [
                    'code' => 'wait_residence_review',
                    'priority' => 2,
                    'title' => 'إثبات السكن قيد المراجعة',
                    'description' => 'لا ترفع دليلاً جديداً إلا إذا طُلب منك.',
                ],
                ResidenceVerificationService::STATUS_NEEDS_MORE => [
                    'code' => 'submit_residence',
                    'priority' => 2,
                    'title' => 'أرسل دليلاً أقوى للسكن',
                    'description' => (string) ($residenceState['decision_reason'] ?? 'المراجع طلب دليلاً إضافياً.'),
                ],
                ResidenceVerificationService::STATUS_REJECTED => [
                    'code' => 'submit_residence',
                    'priority' => 2,
                    'title' => 'أعد إثبات محل الإقامة',
                    'description' => (string) ($residenceState['decision_reason'] ?? 'الدليل السابق لم يُعتمد.'),
                ],
                default => [
                    'code' => 'submit_residence',
                    'priority' => 2,
                    'title' => 'أثبت محل إقامتك الحالي',
                    'description' => 'الإقامة الموثقة هي التي تحدد نطاق تشغيل المحفظة.',
                ],
            };
        } elseif (!$operationalResidence) {
            $actions[] = [
                'code' => 'residence_outside_area',
                'priority' => 2,
                'title' => 'الإقامة خارج نطاق التشغيل الحالي',
                'description' => 'الحساب يبقى موجوداً، لكن الحركة المالية لا تُفتح في هذه المنطقة حالياً.',
            ];
        }

        if ((int) $tier['current_tier'] < 2) {
            $actions[] = match ($identity['status']) {
                'pending_review', 'ready_for_account_review' => [
                    'code' => 'wait_identity_review',
                    'priority' => 3,
                    'title' => 'الهوية قيد المراجعة',
                    'description' => 'بعد اعتماد الوجه والظهر يقرر المراجع ترقية الحساب إلى Tier 2.',
                ],
                'rejected' => [
                    'code' => 'upgrade_identity',
                    'priority' => 3,
                    'title' => 'أعد رفع وثيقة الهوية',
                    'description' => $identity['reason'] ?? 'إحدى صور الهوية رُفضت وتحتاج تصحيحاً.',
                ],
                default => [
                    'code' => 'upgrade_identity',
                    'priority' => 3,
                    'title' => 'ارفع حدودك بتوثيق الهوية',
                    'description' => 'Tier 2 يحتاج رقم الهوية + وجه الوثيقة + ظهرها فقط، بلا سيلفي.',
                ],
            };
        }

        if ((int) $tier['current_tier'] === 2) {
            $actions[] = [
                'code' => 'upgrade_full_kyc',
                'priority' => 4,
                'title' => 'الترقية إلى Tier 3',
                'description' => 'تتطلب ملف KYC الكامل وإثباتاً أقوى لملكية الهوية.',
            ];
        }

        usort($actions, fn (array $a, array $b) => $a['priority'] <=> $b['priority']);

        return response()->json([
            'success' => true,
            'code' => 'VERIFICATION_STATUS_OK',
            'data' => [
                'tier' => [
                    'current' => (int) $tier['current_tier'],
                    'stored' => (int) $tier['stored_tier'],
                    'name' => (string) $tier['tier_name'],
                    'limits' => [
                        'max_balance' => (string) $tier['limits']['max_balance'],
                        'max_single_transaction' => (string) $tier['limits']['max_single_transaction'],
                        'max_daily_total' => (string) $tier['limits']['max_daily_total'],
                        'max_monthly_total' => (string) $tier['limits']['max_monthly_total'],
                        'allowed_features' => $tier['limits']['allowed_features'],
                    ],
                    'usage' => [
                        'today' => (string) $tier['today_used'],
                        'month' => (string) $tier['month_used'],
                    ],
                ],
                'contact' => [
                    'email_verified' => $emailVerified,
                    'phone_verified' => $phoneVerified,
                ],
                'residence' => $residenceState,
                'identity' => $identity,
                'privacy' => [
                    'review_mode' => $privacyState['review_mode'] ?? 'standard',
                    'review_mode_label' => $privacyState['review_mode_label'] ?? 'مراجعة عادية',
                    'biometric_available' => (bool) ($privacyState['biometric_available'] ?? false),
                    'liveness_status' => $privacyState['liveness']['status'] ?? 'not_configured',
                    'face_match_status' => $privacyState['face_match']['status'] ?? 'not_configured',
                ],
                'financial' => [
                    'active' => $financialActive,
                    'blocker_code' => $this->financialBlocker(
                        $phoneVerified,
                        $residenceStatus,
                        $operationalResidence,
                    ),
                ],
                'account_kyc_state' => $account['state'],
                'next_actions' => $actions,
            ],
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    private function documentStates(int $userId): array
    {
        $wanted = [
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE,
            KycDocument::TYPE_ADDRESS_PROOF,
        ];

        $rows = KycDocument::query()
            ->where('user_id', $userId)
            ->whereIn('doc_type', $wanted)
            ->orderByDesc('id')
            ->get()
            ->unique('doc_type');

        $result = [];
        foreach ($rows as $doc) {
            $result[(string) $doc->doc_type] = [
                'status' => (string) $doc->status,
                'usable' => (bool) $doc->isUsable(),
                'reason' => $doc->status === KycDocument::STATUS_REJECTED
                    ? (string) ($doc->rejection_reason ?? '')
                    : null,
                'submitted_at' => $doc->created_at?->toIso8601String(),
                'reviewed_at' => $doc->reviewed_at?->toIso8601String(),
            ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function identityState(array $tier, array $account, array $docs): array
    {
        $front = $docs[KycDocument::TYPE_ID_FRONT] ?? null;
        $back = $docs[KycDocument::TYPE_ID_BACK] ?? null;

        if ((int) $tier['current_tier'] >= 2 && ($account['is_verified'] ?? false)) {
            $status = 'verified';
        } elseif (($front['status'] ?? null) === KycDocument::STATUS_REJECTED
            || ($back['status'] ?? null) === KycDocument::STATUS_REJECTED) {
            $status = 'rejected';
        } elseif (($front['usable'] ?? false) && ($back['usable'] ?? false)) {
            $status = 'ready_for_account_review';
        } elseif (($front['status'] ?? null) === KycDocument::STATUS_PENDING
            || ($back['status'] ?? null) === KycDocument::STATUS_PENDING) {
            $status = 'pending_review';
        } else {
            $status = 'not_submitted';
        }

        $reason = null;
        foreach ([$front, $back] as $doc) {
            if (($doc['status'] ?? null) === KycDocument::STATUS_REJECTED
                && trim((string) ($doc['reason'] ?? '')) !== '') {
                $reason = $doc['reason'];
                break;
            }
        }

        return [
            'status' => $status,
            'reason' => $reason,
            'selfie_required_for_tier_2' => false,
            'documents' => [
                'front' => $front,
                'back' => $back,
            ],
        ];
    }

    private function financialBlocker(
        bool $phoneVerified,
        string $residenceStatus,
        bool $operationalResidence,
    ): ?string {
        if (!$phoneVerified) return 'PHONE_NOT_VERIFIED';
        if ($residenceStatus !== ResidenceVerificationService::STATUS_VERIFIED) {
            return 'RESIDENCE_NOT_VERIFIED';
        }
        if (!$operationalResidence) return 'RESIDENCE_OUTSIDE_OPERATIONAL_AREA';
        return null;
    }
}
