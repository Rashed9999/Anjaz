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

// AMIAL-AUDIT-FIX-001: AmlHeldException نُقلت إلى App\Exceptions\AmlHeldException (PSR-4).
