<?php

namespace App\Aml;

use Carbon\Carbon;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * TransactionContext — كل المعلومات التي تحتاجها قواعد AML للتقييم.
 *
 * تُمرَّر بين الـ rules. immutable.
 */
class TransactionContext
{
    public function __construct(
        public readonly int $actorUserId,
        public readonly ?int $counterpartyUserId,
        public readonly string $transactionType, // send_money, safe_payment_fund, donation, ...
        public readonly string $amount, // decimal as string
        public readonly Carbon $timestamp,
        public readonly ?string $transactionUlid = null,
        public readonly ?string $ipAddress = null,
        public readonly array $metadata = [],
    ) {}

    public function asArray(): array
    {
        return [
            'actor_user_id' => $this->actorUserId,
            'counterparty_user_id' => $this->counterpartyUserId,
            'transaction_type' => $this->transactionType,
            'amount' => $this->amount,
            'timestamp' => $this->timestamp->toIso8601String(),
            'transaction_ulid' => $this->transactionUlid,
            'ip_address' => $this->ipAddress,
            'metadata' => $this->metadata,
        ];
    }

    public function getHour(): int
    {
        return (int)$this->timestamp->hour;
    }

    public function amountAsFloat(): float
    {
        return (float)$this->amount;
    }
}
