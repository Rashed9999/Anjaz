<?php

namespace App\Providers;

use App\Traits\ActivationClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Traits\AddonHelper;
use Illuminate\Support\Facades\Config;

class AppServiceProvider extends ServiceProvider
{
    use ActivationClass, AddonHelper;

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
    public function boot(): RedirectResponse|int
    {
        Paginator::useBootstrap();

        if (request()->is('admin/auth/login')) {
            $response = $this->actch();
            $data = json_decode($response->getContent(), true);
            if (!$data['active']) {
                return Redirect::away(base64_decode('aHR0cHM6Ly82YW10ZWNoLmNvbS9zb2Z0d2FyZS1hY3RpdmF0aW9u'))->send();
            }
        }

        Config::set('addon_admin_routes',$this->get_addon_admin_routes());

        return 0;
    }
}
