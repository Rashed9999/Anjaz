<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MerchantMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (Auth::guard('user')->check() && Auth::user()->type === MERCHANT_TYPE) {
            return $next($request);
        }
        return redirect()->route('merchant.auth.login');
    }
}
