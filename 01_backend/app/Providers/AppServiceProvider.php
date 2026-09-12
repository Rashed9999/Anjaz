<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;

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

        // AMIAL-KYC-OCR-001 — محرّك قراءة الوثائق.
        //
        // يُربط بالواجهة لا بالصنف: استبدالُ Tesseract بخدمةٍ سحابية لاحقاً
        // (والوثيقة تُلمّح إليها) يصير تغييرَ سطرٍ هنا لا تعديلاً في كلّ
        // مستدعٍ. ولا يُفحص وجود الملفّ التنفيذيّ هنا — يفحصه المحرّك عند
        // أوّل استعمال ويُعيد `unavailable` صراحةً.
        $this->app->bind(
            \App\Services\Ocr\OcrDriverInterface::class,
            fn () => new \App\Services\Ocr\TesseractOcrDriver(
                binary: (string) config('amial.kyc.ocr.binary', 'tesseract'),
                languages: (string) config('amial.kyc.ocr.languages', 'ara+eng'),
                timeout: (int) config('amial.kyc.ocr.timeout_seconds', 25),
            ),
        );

        // Custom class aliases (facades) used in your app
        $aliases = [
            'Helpers'  => \App\CentralLogics\helpers::class,
            'Location' => \Stevebauman\Location\Facades\Location::class,
        ];

        foreach ($aliases as $alias => $class) {
            if (! class_exists($alias) && class_exists($class)) {
                class_alias($class, $alias);
            }
        }

        // any other register logic...
    }


    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrap();

        // AMIAL-EMAIL-ADMIN-001 — مسارات مركز البريد منفصلة عن ملف Amial
        // الضخم كي تبقى القراءة والإدارة بصلاحيتين دقيقتين. loadRoutesFrom
        // يحترم route:cache؛ فلا تختفي الشاشة في الإنتاج عند تفعيل الكاش.
        $this->loadRoutesFrom(base_path('routes/admin/email-center.php'));

        // AMIAL-LEDGER-OPENING-002: محفظةٌ تولد مموَّلة تدخل الدفتر برصيدها.
        // بلا هذا يبدأ حسابها بصفر فيُرفض أوّل خصمٍ ويُبتلع الرفض، فيتحرّك
        // المال بلا قيد. انظر شرح EMoneyObserver.
        \App\Models\EMoney::observe(\App\Observers\EMoneyObserver::class);

        // AMIAL-EMAIL-IDENTITY-001: حارس واحد يغطي العميل والتاجر والوكيل
        // والإدارة والموظفين لأنهم جميعاً يعتمدون User كمصدر هوية الحساب.
        \App\Models\User::observe(\App\Observers\UserEmailIdentityObserver::class);

        // AMIAL-CLEANUP: أُزيلت بوّابة تفعيل 6amtech + إعداد addon_admin_routes
        // (نظام إضافات 6cash — بلا وحدات، ومستهلِكوه محذوفون).
    }
}
