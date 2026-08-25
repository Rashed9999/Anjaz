<?php

namespace App\Providers;

use App\Http\Controllers\Api\V1\Amial\WholesaleAccessController;
use App\Http\Middleware\EnforceWholesaleAccessPolicy;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * AMIAL-WHOLESALE-ACCESS-001 — يثبت الحارس على مجموعة API كلها.
 *
 * لا نضع middleware يدوياً على 20 route للجملة لأن endpoint جديداً يمكن أن
 * يُنسى. الحارس يمرّ بصمت لغير wholesale ويفشل مغلقاً لأي wholesale route
 * جديد غير معروف في Policy.
 */
final class WholesaleAccessServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->pushMiddlewareToGroup('api', EnforceWholesaleAccessPolicy::class);

        Route::prefix('api/v1/amial')
            ->middleware(['api', 'auth:api', 'amial.pos-device'])
            ->group(function (): void {
                Route::get('/merchant/wholesale/access', WholesaleAccessController::class)
                    ->name('amial.merchant.wholesale.access');
            });
    }
}
