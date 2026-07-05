<?php

namespace App\Exceptions;

use App\Aml\AmlDecision;
use RuntimeException;

/**
 * AMIAL-AML-001 — تُرمى عند تعليق (hold) معاملة للمراجعة اليدوية.
 *
 * AMIAL-AUDIT-FIX-001: نُقلت إلى ملفها الخاص (كانت في AmlBlockedException.php
 * مخالفةً لـ PSR-4 فلا تُحمَّل تلقائياً).
 */
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
