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
