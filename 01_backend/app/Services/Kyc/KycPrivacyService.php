<?php

namespace App\Services\Kyc;

use App\Models\User;
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
        $provider = trim((string) config('amial.kyc.biometric.provider', ''));
        return $provider !== '' && $provider !== 'none';
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
            ? trim((string) config('amial.kyc.biometric.provider'))
            : null;

        $now = now();
        DB::table('kyc_verification_cases')->updateOrInsert(
            ['user_id' => (int) $user->id],
            [
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
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return $this->forUser($user);
    }

    /**
     * ينشئ الحالة الافتراضية للحساب الجديد دون اختراع تحقق بيومتري.
     */
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
            'biometric_available' => $this->biometricConfigured(),
            'requested_at' => null,
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
                'in_person_pending' => 'تحقق حضوري — بانتظار اعتماد السياسة/المراجع',
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
            'biometric_available' => $this->biometricConfigured(),
            'requested_at' => $row->requested_at,
            'warning' => null,
        ];
    }
}
