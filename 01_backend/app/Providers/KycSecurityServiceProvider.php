<?php

namespace App\Providers;

use App\Services\Kyc\GuardedKycDocumentService;
use App\Services\Kyc\GuardedKycOcrService;
use App\Services\KycDocumentService;
use App\Services\KycOcrService;
use Illuminate\Support\ServiceProvider;

/**
 * AMIAL-KYC-CENTRAL-GUARD-001 — كل من يطلب خدمات KYC الحساسة يأخذ
 * النسخة المحروسة. هذا الربط هو ما يجعل الحارس مركزياً فعلاً؛ من دونِه
 * سيبقى الصنف الجديد صحيحاً لكنه غير مستخدم، وهو أخطر نوع من الحماية.
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
    }
}
