<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * يُرمى عندما:
 *  - نفس Idempotency-Key يصل مع body مختلف (Conflict 409).
 *  - أو نفس Key وصل بينما الطلب الأول لا يزال processing (Conflict 409).
 *
 * ملاحظة: عندما نفس Key يصل بنفس body بعد اكتمال الأصلي،
 * لا نرمي exception — نُرجع الـ response المخزن (هذا السلوك الطبيعي للـ idempotency).
 */
class DuplicateTransactionException extends Exception
{
    public string $decisionCode = 'TX_IDEMPOTENCY_CONFLICT';

    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $reason, // 'in_progress' | 'body_mismatch'
        public readonly ?string $originalTransactionId = null,
        string $message = 'Duplicate request detected',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function context(): array
    {
        return [
            // لا نلوغ المفتاح كاملاً — نقطع لـ 8 أحرف للـ trace
            'idempotency_key_prefix' => substr($this->idempotencyKey, 0, 8),
            'reason' => $this->reason,
            'original_transaction_id' => $this->originalTransactionId,
        ];
    }

    public function toApiArray(): array
    {
        return [
            'success' => false,
            'message' => 'Duplicate request',
            'code' => $this->decisionCode,
            'errors' => (object)[],
            'meta' => [
                'reason' => $this->reason,
                'original_transaction_id' => $this->originalTransactionId,
            ],
        ];
    }
}
