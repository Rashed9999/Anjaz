<?php

namespace App\Services\Muwathaq;

use App\Models\EMoney;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\MuwathaqOpeningEvidence;
use App\Services\EncryptedFileStorage;
use App\Services\Otp\OtpPolicy;
use App\Support\YemenGovernorates;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * بوابة متابعة طلب فتح الحساب قبل تفعيل المستخدم.
 *
 * لا تستعمل Auth token للمستخدم غير المعتمد. token المتابعة مقيد بطلب واحد،
 * مخزن على شكل hash، قصير العمر، ولا يسمح بأي قراءة لمحفظة أو ملف مستخدم.
 */
class MuwathaqOpeningProgressService
{
    public const ACCESS_TTL_HOURS = 24;

    public function issueAccessToken(MuwathaqAccountOpeningApplication $application): string
    {
        if ($application->isFinal()) {
            throw new DomainException('لا تصدر جلسة إكمال لطلب صدر فيه قرار نهائي.');
        }
        $plain = Str::random(80);

        $application->forceFill([
            'opening_access_token_hash' => hash('sha256', $plain),
            'opening_access_expires_at' => now()->addHours(self::ACCESS_TTL_HOURS),
        ])->save();

        return $plain;
    }

    public function authorize(string $applicationUlid, ?string $plain): MuwathaqAccountOpeningApplication
    {
        $application = MuwathaqAccountOpeningApplication::query()
            ->where('application_ulid', $applicationUlid)
            ->first();

        if ($application === null || $plain === null || $plain === ''
            || $application->opening_access_expires_at === null
            || $application->opening_access_expires_at->isPast()
            || !hash_equals((string) $application->opening_access_token_hash, hash('sha256', $plain))) {
            throw new DomainException('جلسة إكمال فتح الحساب غير صالحة أو انتهت. سجّل الدخول لإعادة فتح الطلب.');
        }

        return $application;
    }

    /** @return array<string,mixed> */
    public function status(MuwathaqAccountOpeningApplication $application): array
    {
        $evidence = MuwathaqOpeningEvidence::query()
            ->where('application_id', $application->id)
            ->orderBy('kind')
            ->get()
            ->map(fn (MuwathaqOpeningEvidence $row) => $row->publicSummary())
            ->values()
            ->all();

        return [
            'application_ulid' => $application->application_ulid,
            'status' => $application->status,
            'verification_path' => $application->verification_path,
            'requested_tier' => $application->requested_tier,
            'next_steps' => $this->nextSteps($application, $evidence),
            'evidence' => $evidence,
            'wallet_created' => EMoney::query()->where('user_id', $application->user_id)->exists(),
            'decision_reason' => $application->status === MuwathaqAccountOpeningApplication::STATUS_NEEDS_MORE_INFO
                ? $application->decision_reason : null,
        ];
    }

    public function confirmPilotPhone(MuwathaqAccountOpeningApplication $application, string $code): MuwathaqOpeningEvidence
    {
        $this->assertEvidenceCollectionOpen($application);
        $pilotCode = app(OtpPolicy::class)->pilotCustomerPhoneCode();
        if ($pilotCode === null) {
            throw new DomainException('إثبات الهاتف متوقف حتى يُضبط مزود رسائل حقيقي.');
        }
        if (!hash_equals($pilotCode, trim($code))) {
            throw new DomainException('رمز الهاتف غير صحيح.');
        }

        return DB::transaction(function () use ($application) {
            $evidence = MuwathaqOpeningEvidence::query()->updateOrCreate(
                ['application_id' => $application->id, 'kind' => MuwathaqOpeningEvidence::PHONE],
                [
                    'state' => MuwathaqOpeningEvidence::STATE_SUBMITTED,
                    'payload_encrypted' => Crypt::encryptString(json_encode([
                        'mode' => 'pilot',
                        'confirmed_at' => now()->toIso8601String(),
                    ], JSON_THROW_ON_ERROR)),
                    'submitted_at' => now(),
                    'verified_at' => null,
                ],
            );

            $this->markReadyForReview($application);

            return $evidence;
        });
    }

    public function submitResidence(
        MuwathaqAccountOpeningApplication $application,
        array $data,
        ?UploadedFile $document,
    ): MuwathaqOpeningEvidence {
        $this->assertEvidenceCollectionOpen($application);
        $governorate = YemenGovernorates::codeFromName((string) ($data['governorate'] ?? ''));
        if ($governorate === null) {
            throw new DomainException('محافظة الإقامة غير صحيحة.');
        }

        $path = null;
        $mime = null;
        $sha256 = null;
        if ($document !== null) {
            if (!$document->isValid()) {
                throw new DomainException('تعذر قراءة مرفق إثبات السكن.');
            }
            $path = app(EncryptedFileStorage::class)->encryptAndStore($document, 'muwathaq-opening-residence');
            $mime = (string) $document->getMimeType();
            $sha256 = hash_file('sha256', (string) $document->getRealPath());
        }

        return DB::transaction(function () use ($application, $data, $governorate, $path, $mime, $sha256) {
            $evidence = MuwathaqOpeningEvidence::query()->updateOrCreate(
                ['application_id' => $application->id, 'kind' => MuwathaqOpeningEvidence::RESIDENCE],
                [
                    'state' => MuwathaqOpeningEvidence::STATE_SUBMITTED,
                    'payload_encrypted' => Crypt::encryptString(json_encode([
                        'governorate' => $governorate,
                        'district' => trim((string) ($data['district'] ?? '')),
                        'area' => trim((string) ($data['area'] ?? '')),
                        'landmark' => trim((string) ($data['landmark'] ?? '')),
                        'evidence_type' => trim((string) ($data['evidence_type'] ?? '')),
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                    'file_encrypted_path' => $path,
                    'file_mime' => $mime,
                    'file_sha256' => $sha256,
                    'submitted_at' => now(),
                    'verified_at' => null,
                ],
            );

            $this->markReadyForReview($application);

            return $evidence;
        });
    }

    /** @param array<int,array<string,mixed>> $evidence */
    private function nextSteps(MuwathaqAccountOpeningApplication $application, array $evidence): array
    {
        if ($application->status === MuwathaqAccountOpeningApplication::STATUS_NEEDS_MORE_INFO) {
            return ['resolve_muwathaq_request'];
        }

        if (in_array($application->status, [
            MuwathaqAccountOpeningApplication::STATUS_APPROVED,
            MuwathaqAccountOpeningApplication::STATUS_REJECTED,
        ], true)) {
            return [];
        }

        $states = collect($evidence)->keyBy('kind');
        $steps = [];
        if (!isset($states[MuwathaqOpeningEvidence::PHONE])) $steps[] = 'confirm_phone';
        if (!isset($states[MuwathaqOpeningEvidence::RESIDENCE])) $steps[] = 'submit_residence';
        if ($steps === []) $steps[] = 'await_muwathaq_review';

        return $steps;
    }

    private function assertEvidenceCollectionOpen(MuwathaqAccountOpeningApplication $application): void
    {
        if ($application->isFinal()) {
            throw new DomainException('صدر قرار نهائي لهذا الطلب؛ لا يمكن تعديل أدلته.');
        }
    }

    private function markReadyForReview(MuwathaqAccountOpeningApplication $application): void
    {
        $required = [MuwathaqOpeningEvidence::PHONE, MuwathaqOpeningEvidence::RESIDENCE];
        $present = MuwathaqOpeningEvidence::query()
            ->where('application_id', $application->id)
            ->whereIn('kind', $required)
            ->pluck('kind')
            ->all();

        if (count(array_intersect($required, $present)) === count($required)) {
            $application->forceFill([
                'status' => MuwathaqAccountOpeningApplication::STATUS_UNDER_REVIEW,
                'evidence_completed_at' => now(),
            ])->save();
        }
    }
}
