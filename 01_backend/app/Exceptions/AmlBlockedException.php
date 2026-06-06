<?php

namespace App\Exceptions;

use App\Aml\AmlDecision;
use RuntimeException;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * Exceptions تُرمى عند block أو hold المعاملة.
 */
class AmlBlockedException extends RuntimeException
{
    public function __construct(
        public readonly AmlDecision $decision,
        ?string $message = null,
    ) {
        parent::__construct($message ?? 'Transaction blocked due to security policy');
    }

    public function toApiArray(): array
    {
        return [
            'success' => false,
            'code' => 'AML_BLOCKED',
            'message' => 'تم رفض العملية لأسباب أمنية. للمساعدة، تواصل مع خدمة العملاء.',
            'errors' => (object)[],
            'meta' => [
                // لا نكشف القواعد للـ user (security through obscurity)
                'reference' => substr(md5($this->decision->reasonSummary ?? ''), 0, 8),
            ],
        ];
    }
}

class AmlHeldException extends RuntimeException
{
    public function __construct(
        public readonly AmlDecision $decision,
        public readonly ?int $flaggedId = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? 'Transaction held for review');
    }

    public function toApiArray(): array
    {
        return [
            'success' => false,
            'code' => 'AML_HELD',
            'message' => 'تم تعليق العملية للمراجعة. سيتم إشعارك خلال 24 ساعة.',
            'errors' => (object)[],
            'meta' => [
                'flag_id' => $this->flaggedId,
                'expected_review_hours' => 24,
            ],
        ];
    }
}
