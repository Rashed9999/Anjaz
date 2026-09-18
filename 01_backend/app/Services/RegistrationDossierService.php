<?php

namespace App\Services;

use App\Models\RegistrationDossier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegistrationDossierService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /** @param array<string,mixed> $payload */
    public function create(User $actor, string $type, string $source, string $phone, array $payload, ?UploadedFile $paper = null): RegistrationDossier
    {
        $hash = hash('sha256', $phone);
        $file = $this->storePaper($paper);

        $dossier = DB::transaction(function () use ($actor, $type, $source, $hash, $payload, $file) {
            return RegistrationDossier::create([
                'reference' => (string) Str::ulid(),
                'subject_type' => $type,
                'source' => $source,
                'state' => RegistrationDossier::AWAITING_CONFIRMATION,
                'phone_hash' => $hash,
                'payload_encrypted' => $payload,
                'paper_form_encrypted_path' => $file['path'] ?? null,
                'paper_form_mime' => $file['mime'] ?? null,
                'paper_form_sha256' => $file['sha256'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);
        });

        $this->audit->record([
            'actor_type' => 'platform_user', 'actor_user_id' => $actor->id,
            'subject_type' => 'registration_dossier', 'subject_id' => $dossier->id,
            'action' => 'REGISTRATION_DOSSIER_CREATED', 'decision_code' => 'REG_DOSSIER_CREATED',
            'severity' => 'notice',
            'context' => ['reference' => $dossier->reference, 'type' => $type, 'source' => $source, 'paper_attached' => $paper !== null],
        ]);

        return $dossier;
    }

    /**
     * أرشفة لقطة فتح الحساب الذي أدخله موظف من شاشة الحساب نفسها.
     *
     * لا تستعمل هذه الدالة مسار "مسودة قبل التسجيل": الحساب موجود بالفعل
     * واللقطة مرتبطة به فوراً. لا نحدّثها لاحقاً كي تبقى النسخة المطبوعة
     * دليلاً مطابقاً لما قُدِّم يوم الفتح.
     *
     * @param array<string,mixed> $payload
     */
    public function archiveAssistedRegistration(User $actor, User $subject, string $type, string $phone, array $payload, ?UploadedFile $paper = null): RegistrationDossier
    {
        $file = $this->storePaper($paper);
        $dossier = DB::transaction(function () use ($actor, $subject, $type, $phone, $payload, $file) {
            return RegistrationDossier::create([
                'reference' => (string) Str::ulid(),
                'subject_type' => $type,
                'subject_user_id' => $subject->id,
                'source' => 'staff_assisted',
                'state' => RegistrationDossier::SUBMITTED,
                'phone_hash' => hash('sha256', $phone),
                'payload_encrypted' => $payload,
                'paper_form_encrypted_path' => $file['path'] ?? null,
                'paper_form_mime' => $file['mime'] ?? null,
                'paper_form_sha256' => $file['sha256'] ?? null,
                'created_by_user_id' => $actor->id,
                'confirmed_at' => now(),
            ]);
        });

        $this->audit->record([
            'actor_type' => 'platform_user', 'actor_user_id' => $actor->id,
            'subject_type' => 'registration_dossier', 'subject_id' => $dossier->id,
            'action' => 'ACCOUNT_OPENING_DOSSIER_ARCHIVED', 'decision_code' => 'OPENING_DOSSIER_ARCHIVED',
            'severity' => 'notice',
            'context' => ['reference' => $dossier->reference, 'type' => $type, 'subject_user_id' => $subject->id,
                'paper_attached' => $paper !== null],
        ]);

        return $dossier;
    }

    /** لا يملأ السجل المحمي حساباً ولا يتجاوز OTP؛ يربط فقط بعد تسجيل العميل بنفس الرقم. */
    public function claimForConfirmedRegistration(string $type, string $phone, User $subject): ?RegistrationDossier
    {
        return DB::transaction(function () use ($type, $phone, $subject) {
            $dossier = RegistrationDossier::query()
                ->where('subject_type', $type)
                ->where('phone_hash', hash('sha256', $phone))
                ->where('state', RegistrationDossier::AWAITING_CONFIRMATION)
                ->orderByDesc('id')->lockForUpdate()->first();
            if (!$dossier) return null;

            $dossier->update([
                'subject_user_id' => $subject->id,
                'state' => RegistrationDossier::SUBMITTED,
                'confirmed_at' => now(),
            ]);
            return $dossier;
        });
    }

    /**
     * لقطة ثابتة لطلب التوثيق الذي أكده العميل بنفسه.
     *
     * لا تُحدَّث هذه اللقطة لاحقاً؛ الغرض منها أن تكون النسخة المطبوعة
     * في الأرشيف مطابقة لما وافق عليه العميل وقت الإرسال.
     *
     * @param array<string,mixed> $payload
     */
    public function archiveVerificationSubmission(
        User $subject,
        int $targetTier,
        array $payload,
    ): RegistrationDossier {
        if (! in_array($targetTier, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('VERIFICATION_TIER_INVALID');
        }

        $source = match ($targetTier) {
            1 => RegistrationDossier::VERIFICATION_TIER_1,
            2 => RegistrationDossier::VERIFICATION_TIER_2,
            3 => RegistrationDossier::VERIFICATION_TIER_3,
        };

        $snapshot = array_merge([
            'verification_target_tier' => $targetTier,
            'verification_target_label' => match ($targetTier) {
                1 => 'عميل موثق جزئيا',
                2 => 'عميل موثق بهوية',
                3 => 'عميل موثق',
            },
            'full_name' => trim((string) ($subject->f_name.' '.$subject->l_name)),
            'account_number' => (string) ($subject->account_number ?? ''),
            'phone' => (string) ($subject->phone ?? ''),
            'email' => (string) ($subject->email ?? ''),
            'birth_governorate' => (string) ($subject->birth_governorate ?? ''),
            'residence_governorate' => (string) ($subject->residence_governorate ?? ''),
            'residence_district' => (string) ($subject->residence_district ?? ''),
            'residence_area' => (string) ($subject->residence_area ?? ''),
            'residence_landmark' => (string) ($subject->residence_landmark ?? ''),
            'identification_type' => (string) ($subject->identification_type ?? ''),
            'identification_number' => (string) ($subject->identification_number ?? ''),
            'identification_issue_date' => optional($subject->identification_issue_date)->format('Y-m-d')
                ?? (string) ($subject->identification_issue_date ?? ''),
            'identification_expiry_date' => optional($subject->identification_expiry_date)->format('Y-m-d')
                ?? (string) ($subject->identification_expiry_date ?? ''),
            'id_place_of_issue' => (string) ($subject->id_place_of_issue ?? ''),
            'date_of_birth' => optional($subject->date_of_birth)->format('Y-m-d')
                ?? (string) ($subject->date_of_birth ?? ''),
            'confirmed_by_customer_at' => now()->toIso8601String(),
        ], $payload);

        $dossier = RegistrationDossier::create([
            'reference' => (string) Str::ulid(),
            'subject_type' => RegistrationDossier::CUSTOMER,
            'subject_user_id' => $subject->id,
            'source' => $source,
            'state' => RegistrationDossier::SUBMITTED,
            'phone_hash' => hash('sha256', (string) $subject->phone),
            'payload_encrypted' => $snapshot,
            'created_by_user_id' => $subject->id,
            'confirmed_at' => now(),
        ]);

        $this->audit->record([
            'actor_type' => 'customer',
            'actor_user_id' => (int) $subject->id,
            'subject_type' => 'registration_dossier',
            'subject_id' => (string) $dossier->id,
            'action' => 'CUSTOMER_VERIFICATION_DOSSIER_ARCHIVED',
            'decision_code' => 'VERIFICATION_SUBMISSION_CONFIRMED',
            'severity' => 'notice',
            'context' => [
                'reference' => $dossier->reference,
                'target_tier' => $targetTier,
                'source' => $source,
            ],
        ]);

        return $dossier;
    }

    /** @param array<string,mixed> $payload */
    public function archiveSelfRegistration(string $type, string $phone, User $subject, array $payload): RegistrationDossier
    {
        return RegistrationDossier::create([
            'reference' => (string) Str::ulid(),
            'subject_type' => $type,
            'subject_user_id' => $subject->id,
            'source' => 'self_service',
            'state' => RegistrationDossier::SUBMITTED,
            'phone_hash' => hash('sha256', $phone),
            'payload_encrypted' => $payload,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * يعيد حقولاً ناقصة فقط لمسار التسجيل الذاتي. لا يكفي هذا وحده لإنشاء
     * حساب: RegisterController ما زال يطلب OTP وPIN من صاحب الرقم.
     *
     * @return array<string,mixed>
     */
    public function prefillForPhone(string $type, string $phone): array
    {
        $dossier = RegistrationDossier::query()
            ->where('subject_type', $type)
            ->where('phone_hash', hash('sha256', $phone))
            ->where('state', RegistrationDossier::AWAITING_CONFIRMATION)
            ->latest('id')->first();
        if (!$dossier) return [];

        $payload = (array) $dossier->payload_encrypted;
        $parts = preg_split('/\s+/u', trim((string) ($payload['full_name'] ?? ''))) ?: [];
        return array_filter([
            'f_name' => $parts[0] ?? null,
            'l_name' => count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null,
            'gender' => $payload['gender'] ?? null,
            'identification_number' => $payload['identification_number'] ?? null,
            'identification_type' => $payload['identification_type'] ?? null,
            'address' => $payload['address'] ?? null,
            'store_name' => $payload['business_name'] ?? null,
            'business_type' => $payload['business_type'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** @return array{path:string,mime:string,sha256:string}|null */
    private function storePaper(?UploadedFile $paper): ?array
    {
        if (!$paper) return null;
        $mime = (string) $paper->getMimeType();
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true) || $paper->getSize() > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('النموذج الورقي يجب أن يكون PDF أو صورة حتى 8MB');
        }
        return [
            // لا نُنشئ خدمة التشفير عند تسجيل ذاتي بلا مرفق: خطأ إعداد مفتاح
            // التشفير يجب ألا يعطّل OTP أو إنشاء الحساب، أما إرفاق ورقة فيفشل
            // بأمان بدلاً من حفظها مكشوفة.
            'path' => app(EncryptedFileStorage::class)->encryptAndStore($paper, 'registration-dossiers'),
            'mime' => $mime,
            'sha256' => hash_file('sha256', $paper->getRealPath()),
        ];
    }
}
