<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Traits\AddonHelper;
use Illuminate\Support\Facades\Config;

class AppServiceProvider extends ServiceProvider
{
    use AddonHelper;

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

        // AMIAL-CLEANUP: أُزيلت بوّابة تفعيل 6amtech (كانت تحجب دخول الأدمن وتُحوّله
        // لـ 6amtech.com/software-activation إن لم يكن «مُفعَّلاً») — إرث ترخيص 6cash.

        Config::set('addon_admin_routes', $this->get_addon_admin_routes());
    }
}
