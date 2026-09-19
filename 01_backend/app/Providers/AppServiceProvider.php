<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register()
    {
        // AMIAL-EMAIL-IDENTITY-001 — request-scoped authority for controlled
        // email credential mutations. It is a singleton so the User observer
        // and OTP controller share the same short-lived authorization context.
        $this->app->singleton(\App\Services\EmailIdentityService::class);

        // AMIAL-PROGRESSIVE-KYC-DOCS-001 — كل مستهلك قديم يطلب
        // KycDocumentService يمر من السياسة التدريجية نفسها. بهذه الطريقة
        // لا يبقى مسار إداري قديم يعتبر selfie شرطاً لـ Tier 2 بينما
        // التطبيق الحديث يعرض وجه/ظهر الهوية فقط.
        $this->app->bind(
            \App\Services\KycDocumentService::class,
            \App\Services\ProgressiveKycDocumentService::class,
        );

        // AMIAL-KYC-OCR-001 — محرّك قراءة الوثائق.
        $this->app->bind(
            \App\Services\Ocr\OcrDriverInterface::class,
            fn () => new \App\Services\Ocr\TesseractOcrDriver(
                binary: (string) config('amial.kyc.ocr.binary', 'tesseract'),
                languages: (string) config('amial.kyc.ocr.languages', 'ara+eng'),
                timeout: (int) config('amial.kyc.ocr.timeout_seconds', 25),
            ),
        );

        $aliases = [
            'Helpers'  => \App\CentralLogics\helpers::class,
            'Location' => \Stevebauman\Location\Facades\Location::class,
        ];

        foreach ($aliases as $alias => $class) {
            if (! class_exists($alias) && class_exists($class)) {
                class_alias($class, $alias);
            }
        }
    }

    /** Bootstrap any application services. */
    public function boot(): void
    {
        Paginator::useBootstrap();

        $this->loadRoutesFrom(base_path('routes/admin/email-center.php'));
        $this->loadRoutesFrom(base_path('routes/admin/reporting-center.php'));
        $this->loadRoutesFrom(base_path('routes/admin/user-limits.php'));
        $this->loadRoutesFrom(base_path('routes/admin/customer-systems.php'));
        $this->loadRoutesFrom(base_path('routes/api/v1/customer-reports.php'));

        \App\Models\EMoney::observe(\App\Observers\EMoneyObserver::class);
        \App\Models\User::observe(\App\Observers\UserEmailIdentityObserver::class);

        // AMIAL-PROGRESSIVE-KYC-TURNOVER-003 — حد العميل الفردي يُربط
        // بالأحداث المالية نفسها، بما فيها المسارات التي لا تكتب Transaction.
        \App\Models\Transaction::observe(\App\Observers\CustomerTurnoverObserver::class);
        \App\Models\BillPaymentOrder::observe(\App\Observers\BillPaymentTurnoverObserver::class);
        \App\Models\WithdrawalRequest::observe(\App\Observers\WithdrawalTurnoverObserver::class);
        \App\Models\Donation::observe(\App\Observers\DonationTurnoverObserver::class);
        \App\Models\FamilyFundTransaction::observe(\App\Observers\FamilyFundTurnoverObserver::class);
        \App\Models\SafePayment::observe(\App\Observers\SafePaymentTurnoverObserver::class);
        \App\Models\PendingTransfer::observe(\App\Observers\PendingTransferTurnoverObserver::class);

        // AMIAL-CLEANUP: بوابة تفعيل 6amtech ونظام الإضافات القديم محذوفان.
    }
}
