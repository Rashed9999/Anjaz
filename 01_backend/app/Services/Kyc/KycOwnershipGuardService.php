<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\EncryptionService;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-OWNERSHIP-002 — إثبات الملكية يتدرّج مع مستوى المخاطر.
 *
 * Tier 2: هاتف مملوك + وثيقة هوية معتمدة + رقم هوية أقرّه المراجع ويطابق
 * الرقم القانوني في الحساب. لا نفرض سيلفي على هذا المستوى.
 * Tier 3: نفس أساس الهوية، ثم إثبات أقوى لصاحبها (سيلفي مقيد أو Liveness
 * + Face Match حقيقي أو تحقق حضوري مخول).
 */
class KycOwnershipGuardService
{
    public function __construct(private readonly KycPrivacyService $privacy) {}

    /** @return array{ready:bool,method:string,blockers:array<int,string>,evidence:array<string,mixed>} */
    public function assess(User $user, int $targetTier = 2): array
    {
        $targetTier = max(2, min(3, $targetTier));
        $identity = $this->identityEvidence($user);

        if ($targetTier <= 2) {
            return [
                'ready' => $identity['blockers'] === [],
                'method' => 'identity_document_confirmed_id_phone',
                'blockers' => $identity['blockers'],
                'evidence' => $identity['evidence'] + ['target_tier' => 2],
            ];
        }

        $state = $this->privacy->forUser($user);
        $mode = (string) ($state['review_mode'] ?? KycPrivacyService::MODE_STANDARD);

        return match ($mode) {
            KycPrivacyService::MODE_AUTOMATED => $this->assessAutomated($state, $identity),
            KycPrivacyService::MODE_IN_PERSON => $this->assessInPerson($state, $identity),
            default => $this->assessManual($user, $mode, $identity),
        };
    }

    public function assertReady(User $user, int $targetTier = 2): array
    {
        $assessment = $this->assess($user, $targetTier);

        if (!$assessment['ready']) {
            throw new DomainException(
                'لا يمكن اعتماد الحساب قبل إثبات أن صاحب الحساب هو صاحب الهوية: '
                . implode(' · ', $assessment['blockers'])
                . ' [KYC_OWNERSHIP_NOT_PROVEN]'
            );
        }

        return $assessment;
    }

    /** @return array{blockers:array<int,string>,evidence:array<string,mixed>} */
    private function identityEvidence(User $user): array
    {
        $blockers = [];
        $confirmedId = $this->confirmedNationalId($user);
        $accountId = $this->normalizeIdentity((string) $user->identification_number);

        if (!(bool) ($user->is_phone_verified ?? false)) {
            $blockers[] = 'ملكية رقم الهاتف لم تُثبت بعد.';
        }
        if ($confirmedId === null) {
            $blockers[] = 'لم يُقِرّ المراجع رقم الهوية من وثيقة هوية معتمدة.';
        }
        if ($accountId === '') {
            $blockers[] = 'رقم الهوية القانوني غير محفوظ على الحساب.';
        }
        if ($confirmedId !== null && $accountId !== '' && !hash_equals($accountId, $confirmedId)) {
            $blockers[] = 'رقم الهوية الذي أقرّه المراجع لا يطابق رقم الهوية المحفوظ للحساب.';
        }

        return [
            'blockers' => $blockers,
            'evidence' => [
                'phone_owned' => (bool) ($user->is_phone_verified ?? false),
                'confirmed_identity_number' => $confirmedId !== null,
                'account_identity_number' => $accountId !== '',
                'identity_number_match' => $confirmedId !== null && $accountId !== ''
                    ? hash_equals($accountId, $confirmedId) : null,
            ],
        ];
    }

    /** @return array{ready:bool,method:string,blockers:array<int,string>,evidence:array<string,mixed>} */
    private function assessManual(User $user, string $mode, array $identity): array
    {
        $blockers = $identity['blockers'];
        $selfie = KycDocument::query()
            ->where('user_id', $user->id)
            ->where('doc_type', KycDocument::TYPE_SELFIE)
            ->where('status', KycDocument::STATUS_APPROVED)
            ->orderByDesc('id')
            ->get()
            ->first(fn (KycDocument $doc) => $doc->isUsable());

        if (!$selfie) {
            $blockers[] = 'لا توجد صورة شخصية معتمدة وصالحة لإثبات صاحب الهوية في المستوى الكامل.';
        }

        return [
            'ready' => $blockers === [],
            'method' => $mode === KycPrivacyService::MODE_RESTRICTED
                ? 'restricted_manual_selfie_confirmed_id'
                : 'manual_selfie_confirmed_id',
            'blockers' => $blockers,
            'evidence' => $identity['evidence'] + [
                'approved_selfie_document_id' => $selfie?->id,
                'target_tier' => 3,
            ],
        ];
    }

    /** @return array{ready:bool,method:string,blockers:array<int,string>,evidence:array<string,mixed>} */
    private function assessAutomated(array $state, array $identity): array
    {
        $blockers = $identity['blockers'];

        if (!$this->privacy->biometricConfigured()) {
            $blockers[] = 'مزود التحقق البيومتري غير مضبوط.';
        }
        if (($state['liveness']['status'] ?? null) !== 'passed') {
            $blockers[] = 'فحص Liveness لم ينجح لدى مزود حقيقي.';
        }
        if (($state['face_match']['status'] ?? null) !== 'matched') {
            $blockers[] = 'Face Match لم يثبت تطابق الوجه مع الهوية.';
        }
        if (trim((string) ($state['provider_reference'] ?? '')) === '') {
            $blockers[] = 'لا يوجد مرجع تحقق صادر من المزود البيومتري.';
        }

        return [
            'ready' => $blockers === [],
            'method' => 'biometric_liveness_face_match',
            'blockers' => $blockers,
            'evidence' => $identity['evidence'] + [
                'provider' => $state['biometric_provider'] ?? null,
                'provider_reference_present' => trim((string) ($state['provider_reference'] ?? '')) !== '',
                'liveness_status' => $state['liveness']['status'] ?? 'not_configured',
                'face_match_status' => $state['face_match']['status'] ?? 'not_configured',
                'target_tier' => 3,
            ],
        ];
    }

    /** @return array{ready:bool,method:string,blockers:array<int,string>,evidence:array<string,mixed>} */
    private function assessInPerson(array $state, array $identity): array
    {
        $reviewed = ($state['status'] ?? null) === 'verified'
            && ($state['ownership_method'] ?? null) === 'in_person_verified'
            && !empty($state['reviewed_by'])
            && !empty($state['reviewed_at']);
        $blockers = $identity['blockers'];
        if (!$reviewed) {
            $blockers[] = 'طلب التحقق الحضوري لم يُحسم بعد من مراجع مخول؛ اختيار المسار وحده لا يثبت الملكية.';
        }

        return [
            'ready' => $blockers === [],
            'method' => 'in_person_verified',
            'blockers' => $blockers,
            'evidence' => $identity['evidence'] + [
                'status' => $state['status'] ?? 'collecting',
                'reviewed' => $reviewed,
                'target_tier' => 3,
            ],
        ];
    }

    private function confirmedNationalId(User $user): ?string
    {
        if (!Schema::hasColumn('kyc_documents', 'verified_fields')) {
            return null;
        }

        $docs = KycDocument::query()
            ->where('user_id', $user->id)
            ->where('status', KycDocument::STATUS_APPROVED)
            ->whereIn('doc_type', [
                KycDocument::TYPE_ID_FRONT,
                KycDocument::TYPE_ID_BACK,
                KycDocument::TYPE_PASSPORT,
            ])
            ->orderByDesc('id')
            ->get();

        foreach ($docs as $doc) {
            if (!$doc->isUsable() || !$doc->verified_fields) {
                continue;
            }

            try {
                $fields = json_decode(Crypt::decryptString($doc->verified_fields), true) ?: [];
            } catch (\Throwable) {
                continue;
            }

            $id = $this->normalizeIdentity((string) ($fields['national_id'] ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }

    private function normalizeIdentity(string $value): string
    {
        $value = EncryptionService::foldDigits(trim($value));
        $value = mb_strtoupper($value, 'UTF-8');

        return preg_replace('/[^\p{L}\p{N}]/u', '', $value) ?? '';
    }
}
