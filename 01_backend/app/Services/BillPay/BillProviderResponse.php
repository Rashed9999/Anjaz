<?php

namespace App\Services\BillPay;

/**
 * AMIAL-BILL-PAY-001
 *
 * Response موحد من أي مزود (يلف الـ HTTP/SMS/Stub responses).
 */
final class BillProviderResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status, // success | pending | failed
        public readonly ?string $providerReference = null,
        public readonly ?string $message = null,
        public readonly array $rawResponse = [],
        public readonly int $latencyMs = 0,
        public readonly ?int $httpStatus = null,
    ) {}

    public static function success(string $ref, ?string $message = null, array $raw = [], int $latency = 0): self
    {
        return new self(true, 'success', $ref, $message, $raw, $latency, 200);
    }

    public static function pending(string $ref, ?string $message = null, array $raw = [], int $latency = 0): self
    {
        return new self(true, 'pending', $ref, $message, $raw, $latency, 202);
    }

    public static function failure(string $message, array $raw = [], int $latency = 0, ?int $httpStatus = null): self
    {
        return new self(false, 'failed', null, $message, $raw, $latency, $httpStatus);
    }
}
