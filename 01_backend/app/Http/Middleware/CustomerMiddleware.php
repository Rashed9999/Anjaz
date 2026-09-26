<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CustomerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->user() && $request->user()->type == CUSTOMER_TYPE) {
            return $next($request);
        }

        abort(response()->json([
            'success' => false,
            'code' => 'CUSTOMER_ACCESS_REQUIRED',
            'message' => 'هذه الخدمة متاحة لحساب العميل فقط.',
        ], 403));
    }
}
