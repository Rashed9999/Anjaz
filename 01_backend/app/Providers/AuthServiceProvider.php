<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // AMIAL-PILOT: رُفعت من 40 دقيقة إلى 30 يوماً حتى لا تنتهي الجلسة أثناء
        // التجربة («Token Expired»). قلّلها للإنتاج حسب سياسة الأمان.
        Passport::personalAccessTokensExpireIn(Carbon::now()->addDays(30));
    }
}
