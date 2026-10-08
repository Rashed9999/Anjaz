<?php

namespace App\Services\Kyc;

use App\Models\User;
use App\Services\Kyc\Biometric\BiometricProviderManager;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-PRIVACY-001 — «كيف أثبتنا صاحب الهوية؟» مصدر واحد للحقيقة.
 *
 * لا تُستنتج الخصوصية من جنس المستخدم. صاحب الحساب يطلبها، والسياسة تحدد
 * من يراجع. ولا تُصنع نتيجة Liveness/Face Match من غياب مزود: الغياب
 * `not_configured` وليس صفراً ولا نجاحاً.
 */
class KycPrivacyService
{
    public const MODE_STANDARD = 'standard';
    public const MODE_RESTRICTED = 'restricted_review';
    public const MODE_AUTOMATED = 'automated';
    public const MODE_IN_PERSON = 'in_person';

    public const MODES = [
        self::MODE_STANDARD,
        self::MODE_RESTRICTED,
        self::MODE_AUTOMATED,
        self::MODE_IN_PERSON,
    ];

    public function biometricConfigured(): bool
    {
        // AMIAL-KYC-BIOMETRIC-RUNTIME-003 — كتابة alias في ENV وحدها لا
        // تعني أن مزوداً حقيقياً جاهز. يجب أن يكون مسجلاً، مفعلاً، وDriver
        // نفسه يؤكد توفر مفاتيح الاتصال والتحقق من التوقيع.
        return app(BiometricProviderManager::class)->configured();
    }

    /** @return array<string,mixed> */
    public function forUser(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        if (!Schema::hasTable('kyc_verification_cases')) {
            return $this->virtualDefault($userId, 'schema_unavailable');
        }

        $row = DB::table('kyc_verification_cases')->where('user_id', $userId)->first();
        if (!$row) {
            return $this->virtualDefault($userId, null);
        }

        return $this->serialize($row);
    }

    public function isRestricted(User|int $user): bool
    {
        return (bool) ($this->forUser($user)['restricted_review'] ?? false);
    }

    /**
     * بوابة المراجع المقيد.
     *
     * `kyc.view` يفتح طابور KYC العادي فقط. من اختار خصوصية إضافية لا تظهر
     * وثائقه ولا OCR ولا قراره لمن لا يحمل المفتاح المقيد. فصل view عن
     * decide مهم: محققٌ قد يحتاج رؤية القضية ولا يملك حق اعتمادها.
     */
    public function assertReviewerAccess(User|int $subject, User $reviewer, bool $decision = false): void
    {
        if (!$this->isRestricted($subject)) {
            return;
        }

        $permission = $decision
            ? 'platform.customers.kyc.restricted.decide'
            : 'platform.customers.kyc.restricted.view';

        if (!$reviewer->hasPlatformPermission($permission)) {
            throw new DomainException($decision
                ? 'هذه الحالة في طابور مراجعة مقيد وتتطلب صلاحية اعتماد مستقلة. [KYC_RESTRICTED_DECIDE_REQUIRED]'
                : 'هذه الحالة في طابور مراجعة مقيد ولا يحق لهذا الموظف عرض مستنداتها. [KYC_RESTRICTED_VIEW_REQUIRED]');
        }
    }

    /**
     * صاحب الحساب يختار مستوى الخصوصية. لا يستطيع من هذا الباب إعلان نفسه
     * «متحققاً»، ولا كتابة درجات حيوية أو مطابقة.
     *
     * @return array<string,mixed>
     */
    public function choose(User $user, string $mode): array
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new DomainException('KYC_PRIVACY_MODE_INVALID');
        }

        if ($mode === self::MODE_AUTOMATED && !$this->biometricConfigured()) {
            throw new DomainException('KYC_BIOMETRIC_PROVIDER_NOT_CONFIGURED');
        }

        if (!Schema::hasTable('kyc_verification_cases')) {
            throw new DomainException('KYC_PRIVACY_SCHEMA_UNAVAILABLE');
        }

        $restricted = $mode === self::MODE_RESTRICTED;
        $provider = $this->biometricConfigured()
            ? app(BiometricProviderManager::class)->selectedAlias()
            : null;

        $now = now();
        $values = [
            'review_mode' => $mode,
            'ownership_method' => $mode === self::MODE_AUTOMATED
                ? 'biometric_liveness_face_match'
                : ($mode === self::MODE_IN_PERSON ? 'in_person_pending' : 'legacy_selfie_review'),
            'status' => $mode === self::MODE_IN_PERSON ? 'manual_review' : 'collecting',
            'liveness_status' => $mode === self::MODE_AUTOMATED ? 'pending' : 'not_configured',
            'liveness_score' => null,
            'face_match_status' => $mode === self::MODE_AUTOMATED ? 'pending' : 'not_configured',
            'face_match_score' => null,
            'biometric_provider' => $mode === self::MODE_AUTOMATED ? $provider : null,
            'provider_reference' => null,
            'restricted_review' => $restricted,
            'requested_at' => $now,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'decision_reason' => null,
            'updated_at' => $now,
        ];

        // updateOrInsert مع created_at داخل قيم التحديث كان يعيد كتابة تاريخ
        // إنشاء القضية عند كل تغيير للخصوصية. تاريخ الطلب يتغير؛ تاريخ إنشاء
        // السجل لا. لذلك نفصل الإنشاء عن التحديث.
        $exists = DB::table('kyc_verification_cases')->where('user_id', $user->id)->exists();
        if ($exists) {
            DB::table('kyc_verification_cases')->where('user_id', $user->id)->update($values);
        } else {
            DB::table('kyc_verification_cases')->insert($values + [
                'user_id' => (int) $user->id,
                'created_at' => $now,
            ]);
        }

        return $this->forUser($user);
    }

    /** ينشئ الحالة الافتراضية للحساب الجديد دون اختراع تحقق بيومتري. */
    public function ensure(User $user): array
    {
        if (!Schema::hasTable('kyc_verification_cases')) {
            return $this->virtualDefault((int) $user->id, 'schema_unavailable');
        }

        if (!DB::table('kyc_verification_cases')->where('user_id', $user->id)->exists()) {
            $now = now();
            DB::table('kyc_verification_cases')->insert([
                'user_id' => (int) $user->id,
                'review_mode' => self::MODE_STANDARD,
                'ownership_method' => 'legacy_selfie_review',
                'status' => 'collecting',
                'liveness_status' => 'not_configured',
                'liveness_score' => null,
                'face_match_status' => 'not_configured',
                'face_match_score' => null,
                'biometric_provider' => null,
                'provider_reference' => null,
                'restricted_review' => false,
                'requested_at' => $now,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'decision_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->forUser($user);
    }

    /**
     * يُكتب أثر القرار بعد نجاح القرار المالي/الحسابي نفسه، لا قبله.
     * يلفّه GuardedKycDocumentService في المعاملة نفسها حتى لا يصبح الحساب
     * موثقاً وحالة KYC تقول collecting أو العكس.
     */
    public function markAccountDecision(
        User $user,
        User $reviewer,
        bool $approved,
        ?string $reason = null,
        ?string $ownershipMethod = null,
    ): void {
        if (!Schema::hasTable('kyc_verification_cases')) {
            return;
        }

        $this->ensure($user);

        $update = [
            'status' => $approved ? 'verified' : 'rejected',
            'reviewed_by' => (int) $reviewer->id,
            'reviewed_at' => now(),
            'decision_reason' => $approved ? null : mb_substr(trim((string) $reason), 0, 500),
            'updated_at' => now(),
        ];

        if ($approved && $ownershipMethod) {
            $update['ownership_method'] = $ownershipMethod;
        }

        DB::table('kyc_verification_cases')->where('user_id', $user->id)->update($update);
    }

    /**
     * التحقق الحضوري لا يعني «الموظف رآه»؛ يحتاج سبباً وأثراً ومراجعاً
     * يحمل مفتاح القرار المقيد. وبعده فقط يستطيع حارس الملكية اعتباره دليلاً.
     */
    public function verifyInPerson(User $user, User $reviewer, string $reason): array
    {
        // in_person ليس بالضرورة restricted_review=true، لذلك لا يجوز
        // الاعتماد على assertReviewerAccess وحدها: ذلك الحارس يتعمّد عدم
        // التدخل في الحالات العادية. هنا الفعل نفسه حساس دائماً.
        if (!$reviewer->hasPlatformPermission('platform.customers.kyc.restricted.decide')) {
            throw new DomainException('KYC_RESTRICTED_DECIDE_REQUIRED');
        }

        $state = $this->forUser($user);

        if (($state['review_mode'] ?? null) !== self::MODE_IN_PERSON) {
            throw new DomainException('KYC_IN_PERSON_MODE_REQUIRED');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException('سبب/مرجع التحقق الحضوري مطلوب.');
        }
        if (!Schema::hasTable('kyc_verification_cases')) {
            throw new DomainException('KYC_PRIVACY_SCHEMA_UNAVAILABLE');
        }

        DB::table('kyc_verification_cases')->where('user_id', $user->id)->update([
            'ownership_method' => 'in_person_verified',
            'status' => 'verified',
            'reviewed_by' => (int) $reviewer->id,
            'reviewed_at' => now(),
            'decision_reason' => mb_substr(trim($reason), 0, 500),
            'updated_at' => now(),
        ]);

        return $this->forUser($user);
    }

    /** @return array<string,mixed> */
    private function virtualDefault(int $userId, ?string $warning): array
    {
        return [
            'user_id' => $userId,
            'review_mode' => self::MODE_STANDARD,
            'review_mode_label' => 'مراجعة عادية',
            'ownership_method' => 'legacy_selfie_review',
            'ownership_method_label' => 'صورة شخصية + مراجعة بشرية (النظام الحالي)',
            'status' => 'collecting',
            'restricted_review' => false,
            'liveness' => ['status' => 'not_configured', 'score' => null],
            'face_match' => ['status' => 'not_configured', 'score' => null],
            'biometric_provider' => null,
            'provider_reference' => null,
            'biometric_available' => $this->biometricConfigured(),
            'requested_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'decision_reason' => null,
            'warning' => $warning,
        ];
    }

    /** @return array<string,mixed> */
    private function serialize(object $row): array
    {
        return [
            'user_id' => (int) $row->user_id,
            'review_mode' => (string) $row->review_mode,
            'review_mode_label' => match ((string) $row->review_mode) {
                self::MODE_RESTRICTED => 'خصوصية إضافية — مراجعة مقيدة',
                self::MODE_AUTOMATED => 'تحقق آلي خاص',
                self::MODE_IN_PERSON => 'تحقق حضوري',
                default => 'مراجعة عادية',
            },
            'ownership_method' => (string) $row->ownership_method,
            'ownership_method_label' => match ((string) $row->ownership_method) {
                'biometric_liveness_face_match' => 'Liveness + Face Match',
                'in_person_pending' => 'تحقق حضوري — بانتظار المراجع',
                'in_person_verified' => 'تحقق حضوري معتمد',
                'manual_selfie_confirmed_id' => 'صورة شخصية + رقم هوية أقرّه المراجع',
                'restricted_manual_selfie_confirmed_id' => 'مراجعة مقيدة + صورة شخصية + رقم هوية مُقَر',
                default => 'صورة شخصية + مراجعة بشرية (النظام الحالي)',
            },
            'status' => (string) $row->status,
            'restricted_review' => (bool) $row->restricted_review,
            'liveness' => [
                'status' => (string) $row->liveness_status,
                'score' => $row->liveness_score === null ? null : (string) $row->liveness_score,
            ],
            'face_match' => [
                'status' => (string) $row->face_match_status,
                'score' => $row->face_match_score === null ? null : (string) $row->face_match_score,
            ],
            'biometric_provider' => $row->biometric_provider,
            'provider_reference' => $row->provider_reference,
            'biometric_available' => $this->biometricConfigured(),
            'requested_at' => $row->requested_at,
            'reviewed_by' => $row->reviewed_by === null ? null : (int) $row->reviewed_by,
            'reviewed_at' => $row->reviewed_at,
            'decision_reason' => $row->decision_reason,
            'warning' => null,
        ];
    }
}
