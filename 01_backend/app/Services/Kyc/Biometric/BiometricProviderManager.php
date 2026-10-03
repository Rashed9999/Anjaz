<?php

namespace App\Services\Kyc\Biometric;

use DomainException;

/**
 * Registry مغلق للمزودين البيومتريين.
 *
 * اسم المزود القادم من ENV ليس اسم class. لا يمكن تشغيل class اعتباطي من
 * إعداد خادم؛ لا يُحل إلا alias موجود صراحة في amial_kyc.biometric.drivers.
 */
final class BiometricProviderManager
{
    public function selectedAlias(): string
    {
        return trim((string) config('amial_kyc.biometric.provider', 'none'));
    }

    public function enabled(): bool
    {
        return (bool) config('amial_kyc.biometric.enabled', false);
    }

    /** @return array<string,class-string<BiometricProviderDriver>> */
    public function registeredDrivers(): array
    {
        $drivers = config('amial_kyc.biometric.drivers', []);

        return is_array($drivers) ? $drivers : [];
    }

    public function registered(string $alias): bool
    {
        $class = $this->registeredDrivers()[$alias] ?? null;

        return is_string($class)
            && $class !== ''
            && is_a($class, BiometricProviderDriver::class, true);
    }

    /**
     * يحل Driver مسجلاً. لا يشترط أن يكون المزود المختار حالياً حتى نستطيع
     * التحقق من callbacks لمحاولات بدأت قبل تبديل المزود.
     */
    public function driverFor(string $alias): BiometricProviderDriver
    {
        $alias = trim($alias);
        $class = $this->registeredDrivers()[$alias] ?? null;

        if (!is_string($class) || $class === '' || !is_a($class, BiometricProviderDriver::class, true)) {
            throw new DomainException('KYC_BIOMETRIC_PROVIDER_UNKNOWN');
        }

        $driver = app($class);
        if (!$driver instanceof BiometricProviderDriver) {
            throw new DomainException('KYC_BIOMETRIC_PROVIDER_INVALID');
        }

        return $driver;
    }

    public function selectedDriver(): BiometricProviderDriver
    {
        if (!$this->enabled()) {
            throw new DomainException('KYC_BIOMETRIC_PROVIDER_DISABLED');
        }

        $alias = $this->selectedAlias();
        if ($alias === '' || $alias === 'none') {
            throw new DomainException('KYC_BIOMETRIC_PROVIDER_NOT_CONFIGURED');
        }

        $driver = $this->driverFor($alias);
        if (!$driver->available()) {
            throw new DomainException('KYC_BIOMETRIC_PROVIDER_UNAVAILABLE');
        }

        return $driver;
    }

    public function configured(): bool
    {
        try {
            $this->selectedDriver();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** حالة تشغيلية آمنة للوحة الإدارة؛ لا أسماء classes ولا أسرار. */
    public function status(): array
    {
        $alias = $this->selectedAlias();
        $registered = $alias !== '' && $alias !== 'none' && $this->registered($alias);
        $available = false;

        if ($registered) {
            try {
                $available = $this->driverFor($alias)->available();
            } catch (\Throwable) {
                $available = false;
            }
        }

        return [
            'enabled' => $this->enabled(),
            'provider' => ($alias === '' || $alias === 'none') ? null : $alias,
            'driver_registered' => $registered,
            'available' => $this->enabled() && $available,
            'webhook_ready' => $available,
            'configured' => $this->enabled() && $registered && $available,
            'data_policy' => 'لا تُخزَّن صور/فيديو بيومترية ولا نصوص callbacks الخام في أميال.',
        ];
    }
}
