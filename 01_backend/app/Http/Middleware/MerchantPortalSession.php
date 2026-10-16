<?php

namespace App\Http\Middleware;

use App\Support\PortalHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant sessions are isolated by cookie name and host even if an operator
 * accidentally configures a shared SESSION_DOMAIN. Runs before StartSession.
 */
class MerchantPortalSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $merchantHost = PortalHost::merchant();
        if ($merchantHost === null || mb_strtolower($request->getHost()) !== $merchantHost) {
            return $next($request);
        }

        $previousCookie = config('session.cookie');
        $previousDomain = config('session.domain');

        config([
            'session.cookie' => 'amial_merchant_session',
            'session.domain' => null,
        ]);

        try {
            return $next($request);
        } finally {
            // Restore for subsequent requests under long-lived workers and tests.
            config([
                'session.cookie' => $previousCookie,
                'session.domain' => $previousDomain,
            ]);
        }
    }
}
