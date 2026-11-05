<?php

namespace App\Services\BillPay;

final class BillProviderBalanceResponse
{
    public function __construct(
        public readonly bool $available,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $message = null,
        public readonly array $rawResponse = [],
        public readonly int $latencyMs = 0,
        public readonly ?int $httpStatus = null,
    ) {}

    public static function available(string $amount, ?string $currency, ?string $message = null, array $raw = [], int $latency = 0, ?int $httpStatus = 200): self
    {
        return new self(true, $amount, $currency, $message, $raw, $latency, $httpStatus);
    }

    public static function unavailable(string $message, array $raw = [], int $latency = 0, ?int $httpStatus = null): self
    {
        return new self(false, null, null, $message, $raw, $latency, $httpStatus);
    }
}
