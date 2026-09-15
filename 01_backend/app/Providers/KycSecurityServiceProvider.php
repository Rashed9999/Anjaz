<?php

namespace App\Providers;

use App\Services\Kyc\GuardedKycDocumentService;
use App\Services\Kyc\GuardedKycOcrService;
use App\Services\Kyc\ResidenceAwareZoneAssignmentService;
use App\Services\KycDocumentService;
use App\Services\KycOcrService;
use App\Services\ZoneAssignmentService;
use Illuminate\Support\ServiceProvider;

/**
 * AMIAL-KYC-CENTRAL-GUARD-001 — كل من يطلب خدمات KYC الحساسة يأخذ
 * النسخة المحروسة. ربط المنطقة هنا يمنع أي مسار قديم من استعمال الأصل
 * كبديل عن الإقامة الموثقة.
 */
class KycSecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KycDocumentService::class, function ($app) {
            return $app->make(GuardedKycDocumentService::class);
        });

        $this->app->singleton(KycOcrService::class, function ($app) {
            return $app->make(GuardedKycOcrService::class);
        });

        $this->app->singleton(ZoneAssignmentService::class, function ($app) {
            return $app->make(ResidenceAwareZoneAssignmentService::class);
        });
    }
}
