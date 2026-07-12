<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * يُرمى داخل DB::transaction للإبلاغ عن رصيد غير كافٍ.
 * Laravel يقوم بـ rollback تلقائياً عند رمي exception داخل DB::transaction
 * — هذا أفضل من `return null` الذي لا يـ rollback بل يفلت من الـ closure.
 *
 * يحمل معلومات منظمة لتُحول إلى structured JSON error
 * (قسم 22 من الوثيقة):
 *   code: TX_INSUFFICIENT_BALANCE
 *   message: ...
 *   meta: { required, available, currency }
 */
class InsufficientBalanceException extends Exception
{
    public string $decisionCode = 'TX_INSUFFICIENT_BALANCE';

    public function __construct(
        public readonly int $userId,
        public readonly string $required,    // decimal string (لا float!)
        public readonly string $available,   // decimal string
        public readonly string $currency = 'YER',
        string $message = 'الرصيد غير كافٍ',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * شكل آمن للـ logging — لا يكشف معلومات حساسة
     */
    public function context(): array
    {
        return [
            'user_id' => $this->userId,
            'required' => $this->required,
            'available' => $this->available,
            'currency' => $this->currency,
            'decision_code' => $this->decisionCode,
        ];
    }

    /**
     * Structured JSON error (قسم 22)
     */
    public function toApiArray(): array
    {
        return [
            'success' => false,
            'message' => 'الرصيد غير كافٍ',
            'code' => $this->decisionCode,
            'errors' => (object)[],
            'meta' => [
                'required' => $this->required,
                'available' => $this->available,
                'currency' => $this->currency,
            ],
        ];
    }
}
