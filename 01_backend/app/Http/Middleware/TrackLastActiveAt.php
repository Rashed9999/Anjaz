<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TrackLastActiveAt
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
        if ($request->user()->last_active_at == null || Carbon::parse($request->user()->last_active_at)->isPast()) {
            $request->user()->update([
                'last_active_at' => now(),
            ]);
        }
        return $next($request);
    }
}
