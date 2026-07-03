<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Service Providers
    |--------------------------------------------------------------------------
    */

    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    // App\Providers\BroadcastServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
    App\Providers\ConfigServiceProvider::class,

    /*
    |--------------------------------------------------------------------------
    | Third-Party Service Providers
    |--------------------------------------------------------------------------
    */

    Laravel\Passport\PassportServiceProvider::class,
    Laravelpkg\Laravelchk\LaravelchkServiceProvider::class,
    Stevebauman\Location\LocationServiceProvider::class,
];
