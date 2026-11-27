<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class BlockFrozenMerchantAdminRoutes
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_if($request->routeIs(
            'admin.amial.merchant-center.*', 'admin.amial.merchants.*',
            'admin.amial.merchant-payouts.*', 'admin.amial.hub.merchants',
            'admin.amial.hub.staff*', 'admin.amial.hub.subscriptions*', 'admin.amial.hub.plans*',
            'admin.amial.retail.*', 'admin.amial.fuel.*', 'admin.amial.catalog.*',
            'admin.amial.invoices.*', 'admin.amial.verticals.*', 'admin.amial.entitlements.*',
            'admin.amial.kyc.center.merchant-decision', 'admin.amial.kyc.center.merchant-document'
        ), 404);

        return $next($request);
    }
}
