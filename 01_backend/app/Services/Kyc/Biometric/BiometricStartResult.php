<?php

namespace App\Services\Kyc\Biometric;

use InvalidArgumentException;

/** نتيجة بدء الجلسة فقط؛ ليست نتيجة تحقق بيومتري. */
final class BiometricStartResult
{
    public function __construct(
        public readonly string $providerReference,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $sdkToken = null,
        public readonly ?string $expiresAt = null,
    ) {
        if (trim($providerReference) === '') {
            throw new InvalidArgumentException('BIOMETRIC_PROVIDER_REFERENCE_REQUIRED');
        }

        if (mb_strlen($providerReference) > 160) {
            throw new InvalidArgumentException('BIOMETRIC_PROVIDER_REFERENCE_TOO_LONG');
        }
    }

    /** البيانات التي يحتاجها العميل لإطلاق SDK/صفحة المزود فقط. */
    public function clientPayload(): array
    {
        return array_filter([
            'provider_reference' => $this->providerReference,
            'redirect_url' => $this->redirectUrl,
            'sdk_token' => $this->sdkToken,
            'expires_at' => $this->expiresAt,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
