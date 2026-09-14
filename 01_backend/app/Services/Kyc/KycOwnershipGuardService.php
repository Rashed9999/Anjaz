<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\EncryptionService;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-OWNERSHIP-001 — المستندات لا تكفي وحدها لإثبات صاحبها.
 *
 * هذا الحارس يجيب سؤالاً واحداً فقط: هل لدينا دليلٌ صالح يربط صاحب الحساب
 * بالهوية المعتمدة؟ ولا يقرر اكتمال KYC ولا انتهاء الوثيقة ولا التكرار؛
 * تلك تبقى في KycDocumentService. فصل السؤالين يمنع أن تصبح صورةٌ موجودة
 * مرادفاً خاطئاً لملكية الهوية.
 *
 * القواعد الحالية:
 *  - standard / restricted_review: رقم هوية أقرّه مراجع على وثيقة معتمدة
 *    ويطابق الرقم القانوني المحفوظ للحساب + سيلفي معتمد وصالح.
 *  - automated: مزود حقيقي مضبوط + Liveness passed + Face Match matched
 *    + مرجع مزود. لا توجد نتيجة افتراضية أو درجة مصطنعة.
 *  - in_person: لا ينجح إلا بعد أن تسجل لجنة مخولة حالة verified وطريقة
 *    in_person_verified. مجرد اختيار العميل للمسار الحضوري لا يثبت شيئاً.
 */
class KycOwnershipGuardService
{
    public function __construct(private readonly KycPrivacyService $privacy) {}

    /** @return array{ready:bool,method:string,blockers:array<int,string>,evidence:array<string,mixed>} */
    public function assess(User $user): array
    {
        $state = $this->privacy->forUser($user);
        $mode = (string) ($state['review_mode'] ?? KycPrivacyService::MODE_STANDARD);

        return match ($mode) {
            KycPrivacyService::MODE_AUTOMATED => $this->assessAutomated($state),
            KycPrivacyService::MODE_IN_PERSON => $this->assessInPerson($state),
            default => $this->assessManual($user, $mode),
        };
    }

    public function assertReady(User $user): array
    {
        $assessment = $this->assess($user);

        if (!$assessment['ready']) {
            throw new DomainException(
                'لا يمكن اعتماد الحساب قبل إثبات أن صاحب الحساب هو صاحب الهوية: '
                . implode(' · ', $assessment['blockers'])
                . ' [KYC_OWNERSHIP_NOT_PROVEN]'
            );
        }

        return $assessment;
    }

    /** @return array{ready:bool,method:string,blockers:array<int,string>,evidence:array<string,mixed>} */
    private function assessManual(User $user, string $mode): array
    {
        $blockers = [];
        $confirmedId = $this->confirmedNationalId($user);
        $accountId = $this->normalizeIdentity((string) $user->identification_number);

        if ($confirmedId === null) {
            $blockers[] = 'لم يُقِرّ المراجع رقم الهوية من وثيقة هوية معتمدة.';
        }

        if ($accountId === '') {
            $blockers[] = 'رقم الهوية القانوني غير محفوظ على الحساب.';
        }

        if ($confirmedId !== null && $accountId !== '' && !hash_equals($accountId, $confirmedId)) {
            $blockers[] = 'رقم الهوية الذي أقرّه المراجع لا يطابق رقم الهوية المحفوظ للحساب.';
        }

        $selfie = KycDocument::query()
            ->where('user_id', $user->id)
            ->where('doc_type', KycDocument::TYPE_SELFIE)
            ->where('status', KycDocument::STATUS_APPROVED)
            ->orderByDesc('id')
            ->get()
            ->first(fn (KycDocument $doc) => $doc->isUsable());

        if (!$selfie) {
            $blockers[] = 'لا توجد صورة شخصية معتمدة وصالحة تربط صاحب الحساب بملف الهوية.';
        }

        return [
            'ready' => $blockers === [],
            'method' => $mode === KycPrivacyService::MODE_RESTRICTED
                ? 'restricted_manual_selfie_confirmed_id'
                : 'manual_selfie_confirmed_id',
            'blockers' => $blockers,
            // لا نعيد الرقم الخام حتى لهذه الخدمة؛ يكفي أن نعرف وجود التطابق.
            'evidence' => [
                'confirmed_identity_number' => $confirmedId !== null,
                'account_identity_number' => $accountId !== '',
                'identity_number_match' => $confirmedId !== null && $accountId !== ''
                    ? hash_equals($accountId, $confirmedId) : null,
                'approved_selfie_document_id' => $selfie?->id,
            ],
        ];
    }

    /** @return array{ready:bool,method:string,blockers:array<int,string>,evidence:array<string,mixed>} */
    private function assessAutomated(array $state): array
    {
        $blockers = [];

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
            'evidence' => [
                'provider' => $state['biometric_provider'] ?? null,
                'provider_reference_present' => trim((string) ($state['provider_reference'] ?? '')) !== '',
                'liveness_status' => $state['liveness']['status'] ?? 'not_configured',
                'face_match_status' => $state['face_match']['status'] ?? 'not_configured',
            ],
        ];
    }

    /** @return array{ready:bool,method:string,blockers:array<int,string>,evidence:array<string,mixed>} */
    private function assessInPerson(array $state): array
    {
        $ready = ($state['status'] ?? null) === 'verified'
            && ($state['ownership_method'] ?? null) === 'in_person_verified'
            && !empty($state['reviewed_by'])
            && !empty($state['reviewed_at']);

        return [
            'ready' => $ready,
            'method' => 'in_person_verified',
            'blockers' => $ready ? [] : [
                'طلب التحقق الحضوري لم يُحسم بعد من مراجع مخول؛ اختيار المسار وحده لا يثبت الملكية.',
            ],
            'evidence' => [
                'status' => $state['status'] ?? 'collecting',
                'reviewed' => !empty($state['reviewed_by']) && !empty($state['reviewed_at']),
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
