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

        // AMIAL-LEDGER-OPENING-002: محفظةٌ تولد مموَّلة تدخل الدفتر برصيدها.
        // بلا هذا يبدأ حسابها بصفر فيُرفض أوّل خصمٍ ويُبتلع الرفض، فيتحرّك
        // المال بلا قيد. انظر شرح EMoneyObserver.
        \App\Models\EMoney::observe(\App\Observers\EMoneyObserver::class);

        // AMIAL-CLEANUP: أُزيلت بوّابة تفعيل 6amtech + إعداد addon_admin_routes
        // (نظام إضافات 6cash — بلا وحدات، ومستهلِكوه محذوفون).
    }
}
