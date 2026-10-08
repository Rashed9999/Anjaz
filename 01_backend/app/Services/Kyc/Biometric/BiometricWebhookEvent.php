<?php

namespace App\Services\Kyc\Biometric;

use InvalidArgumentException;

/**
 * نتيجة callback بعد التحقق من توقيعه وتحويله من صيغة المزود.
 * لا يحمل صوراً أو فيديو أو body خاماً.
 */
final class BiometricWebhookEvent
{
    public const LIVENESS = ['pending', 'passed', 'failed', 'manual_review'];
    public const FACE_MATCH = ['pending', 'matched', 'not_matched', 'manual_review'];

    public function __construct(
        public readonly string $eventId,
        public readonly string $providerReference,
        public readonly string $eventType,
        public readonly string $livenessStatus,
        public readonly ?string $livenessScore,
        public readonly string $faceMatchStatus,
        public readonly ?string $faceMatchScore,
        public readonly ?string $resultCode = null,
        public readonly ?string $occurredAt = null,
    ) {
        if (trim($providerReference) === '' || mb_strlen($providerReference) > 160) {
            throw new InvalidArgumentException('BIOMETRIC_PROVIDER_REFERENCE_INVALID');
        }
        if (!in_array($livenessStatus, self::LIVENESS, true)) {
            throw new InvalidArgumentException('BIOMETRIC_LIVENESS_STATUS_INVALID');
        }
        if (!in_array($faceMatchStatus, self::FACE_MATCH, true)) {
            throw new InvalidArgumentException('BIOMETRIC_FACE_MATCH_STATUS_INVALID');
        }

        $this->assertScore($livenessScore, 'BIOMETRIC_LIVENESS_SCORE_INVALID');
        $this->assertScore($faceMatchScore, 'BIOMETRIC_FACE_MATCH_SCORE_INVALID');
    }

    private function assertScore(?string $score, string $error): void
    {
        if ($score === null || $score === '') {
            return;
        }
        if (!is_numeric($score) || bccomp((string) $score, '0', 4) < 0 || bccomp((string) $score, '1', 4) > 0) {
            throw new InvalidArgumentException($error);
        }
    }
}
