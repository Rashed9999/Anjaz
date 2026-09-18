<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\RegistrationDossier;
use App\Services\Kyc\KycAccountStatusService;
use App\Services\Kyc\KycOwnershipGuardService;
use App\Services\Kyc\KycPrivacyService;
use App\Services\Kyc\ResidenceVerificationService;
use App\Services\KycTierService;
use App\Support\Kyc\KycProfileFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-VERIFICATION-CENTER-001 — مصدر واحد لما يراه العميل الفرد.
 *
 * لا نعيد مسار ملف، reviewer id، OCR خاماً أو بيانات موظفين. الواجهة تحتاج
 * قراراً تشغيلياً فقط: حدود الاستخدام، مستوى التوثيق، ما الذي اكتمل، وما
 * الذي ينقص. هذا المركز لا يخص التاجر/الوكيل/POS/الإدارة.
 */
class VerificationStatusController extends Controller
{
    public function show(
        Request $request,
        KycTierService $tiers,
        KycAccountStatusService $accounts,
        ResidenceVerificationService $residence,
        KycPrivacyService $privacy,
        KycOwnershipGuardService $ownership,
    ): JsonResponse {
        $user = $request->user();
        if (!$user || (int) $user->type !== 2) {
            return response()->json([
                'success' => false,
                'code' => 'INDIVIDUAL_CUSTOMER_REQUIRED',
                'message' => 'مركز توثيق الأفراد متاح لحساب العميل فقط.',
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
                'description' => 'مطلوب للانتقال إلى حالة عميل موثق جزئيا.',
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
                    'description' => 'بعد اعتماد وجه الوثيقة وظهرها يقرر المراجع ترقية الحساب.',
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
                    'title' => 'وثّق هويتك وارفع حدودك',
                    'description' => 'حالة عميل موثق بهوية تحتاج بيانات الهوية وتواريخها + وجه الوثيقة + ظهرها، بلا سيلفي.',
                ],
            };
        }

        if ((int) $tier['current_tier'] === 2) {
            $actions[] = [
                'code' => 'upgrade_full_kyc',
                'priority' => 4,
                'title' => 'الترقية إلى عميل موثق',
                'description' => 'أكمل بيانات اعرف عميلك والتقط صورة سيلفي حديثة لإثبات صاحب الهوية.',
            ];
        }

        usort($actions, fn (array $a, array $b) => $a['priority'] <=> $b['priority']);

        $levels = $this->verificationLevels(
            $user,
            $tiers,
            $tier,
            $identity,
            $phoneVerified,
            $residenceVerified,
            $ownership,
            $docs,
        );

        return response()->json([
            'success' => true,
            'code' => 'VERIFICATION_STATUS_OK',
            'data' => [
                'audience' => 'individual_customer',
                'tier' => [
                    'current' => (int) $tier['current_tier'],
                    'stored' => (int) $tier['stored_tier'],
                    'name' => (string) $tier['tier_name'],
                    'limits' => $this->limitView($tier['limits']),
                    'usage' => [
                        'today' => (string) $tier['today_used'],
                        'month' => (string) $tier['month_used'],
                    ],
                ],
                'usage_bar' => [
                    'used' => (string) $tier['month_used'],
                    'limit' => (string) $tier['limits']['max_monthly_total'],
                    'period' => 'month',
                    'currency' => 'YER',
                    'label' => 'استخدامك الشهري',
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
                'verification_levels' => $levels,
                'next_actions' => $actions,
                'verification_documents' => $this->verificationDocuments((int) $user->id),
            ],
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function verificationLevels(
        $user,
        KycTierService $tiers,
        array $tierInfo,
        array $identity,
        bool $phoneVerified,
        bool $residenceVerified,
        KycOwnershipGuardService $ownership,
        array $docs,
    ): array {
        $current = (int) $tierInfo['current_tier'];
        $profileMissing = KycProfileFields::missingFor($user);
        $ownership3 = $ownership->assess($user, 3);
        $addressDoc = $docs[KycDocument::TYPE_ADDRESS_PROOF] ?? null;

        $requirements = [
            1 => [
                ['code' => 'phone', 'label' => 'إثبات ملكية رقم الهاتف', 'complete' => $phoneVerified],
                ['code' => 'residence', 'label' => 'إثبات محل الإقامة الحالي', 'complete' => $residenceVerified],
            ],
            2 => [
                ['code' => 'tier1', 'label' => 'استكمال متطلبات عميل موثق جزئيا', 'complete' => $current >= 1],
                ['code' => 'identity_number', 'label' => 'بيانات الهوية القانونية وتواريخها', 'complete' =>
                    trim((string) ($user->identification_number ?? '')) !== ''
                    && trim((string) ($user->date_of_birth ?? '')) !== ''
                    && trim((string) ($user->id_place_of_issue ?? '')) !== ''
                    && trim((string) ($user->identification_issue_date ?? '')) !== ''
                    && trim((string) ($user->identification_expiry_date ?? '')) !== ''
                ],
                ['code' => 'identity_document', 'label' => 'وجه وثيقة الهوية وظهرها — بلا سيلفي', 'complete' => ($identity['status'] ?? '') === 'verified'],
            ],
            3 => [
                ['code' => 'tier2', 'label' => 'استكمال متطلبات عميل موثق بهوية', 'complete' => $current >= 2],
                ['code' => 'profile', 'label' => 'إكمال بيانات اعرف عميلك التنظيمية', 'complete' => $profileMissing === []],
                ['code' => 'address_proof', 'label' => 'دليل سكن/عنوان صالح ضمن ملف KYC', 'complete' => (bool) ($addressDoc['usable'] ?? false)],
                ['code' => 'ownership', 'label' => 'صورة سيلفي حديثة لإثبات صاحب الهوية', 'complete' => (bool) ($ownership3['ready'] ?? false)],
            ],
        ];

        $descriptions = [
            1 => 'توثيق جزئي يفتح الاستخدام المالي الأساسي ضمن الحدود المعتمدة.',
            2 => 'هوية قانونية موثقة تفتح حدوداً أعلى ومزايا مالية إضافية.',
            3 => 'توثيق مكتمل للوصول إلى أعلى حدود ومزايا الحساب الفردي.',
        ];

        $out = [];
        foreach ([1, 2, 3] as $level) {
            $limits = $tiers->getLimits($level);
            $missing = array_values(array_map(
                fn (array $r) => $r['label'],
                array_filter($requirements[$level], fn (array $r) => !$r['complete'])
            ));

            if ($level === 3 && $profileMissing !== []) {
                $missing = array_values(array_unique(array_merge($missing, $profileMissing)));
            }

            $out[] = [
                'tier' => $level,
                'name' => (string) $limits['name_ar'],
                'description' => $descriptions[$level],
                'status' => $current >= $level ? 'completed' : 'incomplete',
                'current' => $current === $level,
                'requirements' => $requirements[$level],
                'missing' => $missing,
                'benefits' => $this->featureLabels($limits['allowed_features']),
                'limits' => $this->limitView($limits),
                'action' => $current >= $level ? null : [
                    'code' => 'complete_account',
                    'label' => 'إكمال حسابي',
                    'target_tier' => $level,
                ],
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function verificationDocuments(int $userId): array
    {
        return RegistrationDossier::query()
            ->where('subject_user_id', $userId)
            ->whereIn('source', RegistrationDossier::VERIFICATION_SOURCES)
            ->orderByDesc('id')
            ->limit(12)
            ->get()
            ->map(function (RegistrationDossier $dossier): array {
                $payload = (array) $dossier->payload_encrypted;

                return [
                    'reference' => (string) $dossier->reference,
                    'label' => (string) ($payload['verification_target_label'] ?? 'طلب توثيق'),
                    'status' => (string) $dossier->state,
                    'confirmed_at' => optional($dossier->confirmed_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int,string> */
    private function featureLabels(array $features): array
    {
        if (in_array('*', $features, true)) {
            return ['جميع مزايا المحفظة المتاحة للعميل الفرد'];
        }

        $labels = [
            'send_money' => 'تحويل الأموال',
            'receive_money' => 'استقبال الأموال',
            'bill_pay' => 'سداد الخدمات والفواتير',
            'cash_out' => 'السحب النقدي',
            'merchant_pay' => 'الدفع للتاجر',
            'safe_payment' => 'الدفع الآمن',
            'donations' => 'التبرعات',
            'family_fund' => 'الصندوق العائلي',
        ];

        return array_values(array_map(
            fn (string $feature) => $labels[$feature] ?? $feature,
            array_values(array_unique($features))
        ));
    }

    /** @return array<string,mixed> */
    private function limitView(array $limits): array
    {
        return [
            'max_balance' => (string) ($limits['max_balance'] ?? '0'),
            'max_single_transaction' => (string) ($limits['max_single_transaction'] ?? '0'),
            'max_daily_total' => (string) ($limits['max_daily_total'] ?? '0'),
            'max_monthly_total' => (string) ($limits['max_monthly_total'] ?? '0'),
            'allowed_features' => array_values($limits['allowed_features'] ?? []),
        ];
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
