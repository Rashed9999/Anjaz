<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ZoneAssignmentService;
use App\Support\YemenGovernorates;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-RESIDENCE-001 — إثبات الإقامة حقيقة مستقلة عن أصل العميل.
 *
 * لا تُعد فاتورة المتجر دليلاً لمجرد أن عنوان المتجر ظاهر فيها. الفواتير
 * المقبولة من فئة delivery_* يجب أن تكون وثيقة يراجعها الموظف ويرى فيها
 * اسم العميل وعنوان التسليم/الإقامة. GPS وإفادة المالك إشارات مساعدة فقط.
 */
class ResidenceVerificationService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_NEEDS_MORE = 'needs_more_evidence';
    public const STATUS_REJECTED = 'rejected';

    /** @var array<string,array{label:string,strength:string,description:string}> */
    public const EVIDENCE_TYPES = [
        'lease_contract' => [
            'label' => 'عقد إيجار باسم صاحب الحساب', 'strength' => 'strong',
            'description' => 'عقد يحدد صاحب الحساب ومحل السكن الحالي.',
        ],
        'home_internet_contract' => [
            'label' => 'عقد إنترنت/خدمة منزلية', 'strength' => 'strong',
            'description' => 'عقد خدمة منزلية باسم العميل وعنوان المنزل.',
        ],
        'employer_residence_letter' => [
            'label' => 'خطاب جهة عمل يثبت السكن', 'strength' => 'strong',
            'description' => 'خطاب حديث من جهة العمل يتضمن عنوان الإقامة.',
        ],
        'education_residence_letter' => [
            'label' => 'خطاب جامعة/جهة تعليمية', 'strength' => 'strong',
            'description' => 'خطاب حديث يتضمن اسم الطالب وعنوان إقامته.',
        ],
        'government_residence_document' => [
            'label' => 'مستند حكومي/محلي يثبت السكن', 'strength' => 'strong',
            'description' => 'وثيقة رسمية تربط صاحب الحساب بعنوان الإقامة.',
        ],
        'delivery_purchase_invoice' => [
            'label' => 'فاتورة شراء مع عنوان التسليم', 'strength' => 'medium',
            'description' => 'يجب أن يظهر اسم العميل وعنوان التسليم؛ عنوان المتجر وحده لا يكفي.',
        ],
        'shipping_waybill' => [
            'label' => 'بوليصة شحن/توصيل', 'strength' => 'medium',
            'description' => 'بوليصة حديثة باسم العميل وعنوان التسليم.',
        ],
        'residential_service_contract' => [
            'label' => 'عقد خدمة مرتبط بالسكن', 'strength' => 'medium',
            'description' => 'خدمة موثوقة باسم العميل ومحل تقديم الخدمة.',
        ],
        'landlord_attestation' => [
            'label' => 'إفادة مالك السكن', 'strength' => 'supporting',
            'description' => 'دليل مساعد فقط؛ لا يُعتمد منفرداً.',
        ],
        'other_supporting' => [
            'label' => 'دليل سكن آخر', 'strength' => 'supporting',
            'description' => 'يحتاج دليلاً إضافياً أو تحققاً حضوريّاً قبل الاعتماد.',
        ],
    ];

    public function __construct(private readonly AuditService $audit) {}

    /** @return array<int,array<string,string>> */
    public function evidenceOptions(): array
    {
        $out = [];
        foreach (self::EVIDENCE_TYPES as $code => $meta) {
            $out[] = ['code' => $code] + $meta;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function forUser(User $user): array
    {
        if (!Schema::hasTable('residence_verifications')) {
            return [
                'status' => 'schema_unavailable',
                'declared_governorate' => $user->residence_governorate,
                'verified_governorate' => null,
                'operational' => false,
                'verification_id' => null,
            ];
        }

        $latest = DB::table('residence_verifications')
            ->where('user_id', $user->id)->latest('id')->first();
        $verified = YemenGovernorates::codeFromName(
            (string) ($user->verified_residence_governorate ?? '')
        );

        return [
            'status' => $latest?->status ?? 'not_submitted',
            'birth_governorate' => $user->birth_governorate ?? null,
            'birth_governorate_name' => YemenGovernorates::name($user->birth_governorate ?? null),
            'declared_governorate' => $latest?->declared_governorate ?? $user->residence_governorate,
            'declared_governorate_name' => YemenGovernorates::name(
                $latest?->declared_governorate ?? $user->residence_governorate
            ),
            'verified_governorate' => $verified,
            'verified_governorate_name' => YemenGovernorates::name($verified),
            'operational' => $verified !== null && YemenGovernorates::isOperational($verified),
            'verification_id' => $latest?->id,
            'residence_district' => $user->residence_district ?? null,
            'residence_area' => $user->residence_area ?? null,
            'residence_landmark' => $user->residence_landmark ?? null,
            'evidence_type' => $latest?->evidence_type,
            'evidence_strength' => $latest?->evidence_strength,
            'evidence_date' => $latest?->evidence_date,
            'decision_reason' => $latest?->decision_reason,
            'reviewed_at' => $latest?->reviewed_at,
        ];
    }

    /** @return array<string,mixed> */
    public function submit(
        User $user,
        string $birthGovernorate,
        string $governorate,
        string $district,
        ?string $area,
        ?string $landmark,
        string $evidenceType,
        KycDocument $document,
        ?string $evidenceDate = null,
    ): array {
        if (!Schema::hasTable('residence_verifications')) {
            throw new DomainException('RESIDENCE_VERIFICATION_SCHEMA_UNAVAILABLE');
        }

        $birthCode = YemenGovernorates::codeFromName($birthGovernorate);
        if ($birthCode === null) {
            throw new DomainException('BIRTH_GOVERNORATE_INVALID');
        }

        $code = YemenGovernorates::codeFromName($governorate);
        if ($code === null) {
            throw new DomainException('RESIDENCE_GOVERNORATE_INVALID');
        }
        if (!isset(self::EVIDENCE_TYPES[$evidenceType])) {
            throw new DomainException('RESIDENCE_EVIDENCE_TYPE_INVALID');
        }
        if ((int) $document->user_id !== (int) $user->id
            || $document->doc_type !== KycDocument::TYPE_ADDRESS_PROOF) {
            throw new DomainException('RESIDENCE_EVIDENCE_DOCUMENT_INVALID');
        }

        $id = DB::transaction(function () use (
            $user,
            $birthCode,
            $code,
            $district,
            $area,
            $landmark,
            $evidenceType,
            $document,
            $evidenceDate,
        ) {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            if (Schema::hasColumn('users', 'birth_governorate')) {
                $account->birth_governorate = $birthCode;
            }
            $account->residence_governorate = $code; // تصريح العميل، لا يعني «موثق».
            if (Schema::hasColumn('users', 'residence_district')) {
                $account->residence_district = trim($district);
            }
            if (Schema::hasColumn('users', 'residence_area')) {
                $account->residence_area = $area ? trim($area) : null;
            }
            if (Schema::hasColumn('users', 'residence_landmark')) {
                $account->residence_landmark = $landmark ? trim($landmark) : null;
            }
            $account->save();

            return DB::table('residence_verifications')->insertGetId([
                'user_id' => (int) $account->id,
                'kyc_document_id' => (int) $document->id,
                'declared_governorate' => $code,
                'evidence_type' => $evidenceType,
                'evidence_strength' => self::EVIDENCE_TYPES[$evidenceType]['strength'],
                'evidence_date' => $evidenceDate,
                'status' => self::STATUS_PENDING,
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->audit->record([
            'actor_type' => 'customer', 'actor_user_id' => (int) $user->id,
            'subject_type' => 'residence_verification', 'subject_id' => (string) $id,
            'action' => 'RESIDENCE_EVIDENCE_SUBMITTED', 'decision_code' => 'RESIDENCE_PENDING',
            'severity' => 'info',
            'context' => [
                'governorate' => $code,
                'evidence_type' => $evidenceType,
                'evidence_strength' => self::EVIDENCE_TYPES[$evidenceType]['strength'],
                'kyc_document_id' => (int) $document->id,
            ],
        ]);

        return $this->forUser($user->fresh());
    }

    /** @return array<string,mixed> */
    public function decide(int $verificationId, User $reviewer, string $status, ?string $reason = null): array
    {
        if (!in_array($status, [self::STATUS_VERIFIED, self::STATUS_NEEDS_MORE, self::STATUS_REJECTED], true)) {
            throw new DomainException('RESIDENCE_DECISION_INVALID');
        }
        if (in_array($status, [self::STATUS_NEEDS_MORE, self::STATUS_REJECTED], true)
            && mb_strlen(trim((string) $reason)) < 5) {
            throw new DomainException('RESIDENCE_DECISION_REASON_REQUIRED');
        }

        $row = DB::table('residence_verifications')->where('id', $verificationId)->first();
        if (!$row) {
            throw new DomainException('RESIDENCE_VERIFICATION_NOT_FOUND');
        }
        if ((int) $row->user_id === (int) $reviewer->id) {
            throw new DomainException('FOUR_EYES_VIOLATION');
        }
        if ((string) $row->status !== self::STATUS_PENDING) {
            throw new DomainException('RESIDENCE_ALREADY_REVIEWED');
        }
        if ($status === self::STATUS_VERIFIED && (string) $row->evidence_strength === 'supporting') {
            throw new DomainException('RESIDENCE_STRONGER_EVIDENCE_REQUIRED');
        }

        $document = $row->kyc_document_id
            ? KycDocument::find((int) $row->kyc_document_id) : null;
        if ($status === self::STATUS_VERIFIED
            && (!$document || $document->status !== KycDocument::STATUS_APPROVED || !$document->isUsable())) {
            throw new DomainException('RESIDENCE_DOCUMENT_MUST_BE_APPROVED');
        }

        DB::transaction(function () use ($row, $reviewer, $status, $reason) {
            $current = DB::table('residence_verifications')
                ->where('id', $row->id)->lockForUpdate()->first();
            if (!$current || $current->status !== self::STATUS_PENDING) {
                throw new DomainException('RESIDENCE_ALREADY_REVIEWED');
            }

            DB::table('residence_verifications')->where('id', $row->id)->update([
                'status' => $status,
                'reviewed_by' => (int) $reviewer->id,
                'reviewed_at' => now(),
                'decision_reason' => $reason ? mb_substr(trim($reason), 0, 1000) : null,
                'updated_at' => now(),
            ]);

            if ($status === self::STATUS_VERIFIED) {
                $account = User::query()->lockForUpdate()->findOrFail($row->user_id);
                $account->verified_residence_governorate = $row->declared_governorate;
                $account->residence_verified_at = now();
                $account->residence_verification_id = (int) $row->id;

                // الانتقال من 🟤 إلى 🟠 يعتمد على التوثيق فقط:
                // هاتف مثبت + سكن معتمد. نطاق التشغيل سياسة خدمة مستقلة
                // وقد تكون محافظة العميل غير مدعومة حالياً دون أن يفقد
                // حقه في التسجيل أو التوثيق.
                if ((bool) ($account->is_phone_verified ?? false)
                    && Schema::hasColumn('users', 'kyc_tier')) {
                    $account->kyc_tier = max(1, (int) ($account->kyc_tier ?? 0));
                    if (Schema::hasColumn('users', 'kyc_tier_updated_at')) {
                        $account->kyc_tier_updated_at = now();
                    }
                }

                $account->save();

                // المصدر هنا هو الإقامة الموثقة نفسها، لا الأصل ولا GPS.
                app(ZoneAssignmentService::class)->assignFromKyc(
                    $account,
                    (string) $row->declared_governorate,
                    (int) $reviewer->id,
                );
            }
        });

        $this->audit->record([
            'actor_type' => 'admin', 'actor_user_id' => (int) $reviewer->id,
            'subject_type' => 'residence_verification', 'subject_id' => (string) $verificationId,
            'action' => 'RESIDENCE_VERIFICATION_DECIDED',
            'decision_code' => match ($status) {
                self::STATUS_VERIFIED => 'RESIDENCE_VERIFIED',
                self::STATUS_NEEDS_MORE => 'RESIDENCE_MORE_EVIDENCE',
                default => 'RESIDENCE_REJECTED',
            },
            'reason' => $reason,
            'severity' => $status === self::STATUS_VERIFIED ? 'critical' : 'warning',
            'context' => ['governorate' => $row->declared_governorate, 'status' => $status],
        ]);

        return $this->forUser(User::findOrFail($row->user_id));
    }

    public function verifiedGovernorate(User $user): ?string
    {
        if (!Schema::hasColumn('users', 'verified_residence_governorate')) {
            return null;
        }
        return YemenGovernorates::codeFromName((string) $user->verified_residence_governorate);
    }

    public function assertVerified(User $user): string
    {
        $code = $this->verifiedGovernorate($user);
        if ($code === null || empty($user->residence_verified_at)) {
            throw new DomainException(
                'لا يمكن رفع هذا المستوى قبل إثبات محل الإقامة الحالي. [RESIDENCE_NOT_VERIFIED]'
            );
        }
        return $code;
    }

    /** @return array<int,array<string,mixed>> */
    public function pendingQueue(int $limit = 100): array
    {
        if (!Schema::hasTable('residence_verifications')) {
            return [];
        }

        return DB::table('residence_verifications as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.status', self::STATUS_PENDING)
            ->orderBy('r.submitted_at')
            ->limit($limit)
            ->get([
                'r.id', 'r.user_id', 'r.kyc_document_id', 'r.declared_governorate',
                'r.evidence_type', 'r.evidence_strength', 'r.evidence_date', 'r.submitted_at',
                'u.f_name', 'u.l_name', 'u.phone',
                'u.birth_governorate', 'u.residence_district',
                'u.residence_area', 'u.residence_landmark',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'name' => trim((string) ($row->f_name . ' ' . $row->l_name)) ?: '—',
                'phone' => (string) ($row->phone ?? '—'),
                'kyc_document_id' => $row->kyc_document_id ? (int) $row->kyc_document_id : null,
                'birth_governorate' => (string) ($row->birth_governorate ?? ''),
                'birth_governorate_name' => YemenGovernorates::name($row->birth_governorate ?? null),
                'governorate' => (string) $row->declared_governorate,
                'governorate_name' => YemenGovernorates::name($row->declared_governorate),
                'residence_district' => (string) ($row->residence_district ?? ''),
                'residence_area' => (string) ($row->residence_area ?? ''),
                'residence_landmark' => (string) ($row->residence_landmark ?? ''),
                'operational' => YemenGovernorates::isOperational($row->declared_governorate),
                'evidence_type' => (string) $row->evidence_type,
                'evidence_label' => self::EVIDENCE_TYPES[$row->evidence_type]['label'] ?? $row->evidence_type,
                'strength' => (string) $row->evidence_strength,
                'evidence_date' => $row->evidence_date,
                'submitted_at' => $row->submitted_at,
            ])->all();
    }
}
