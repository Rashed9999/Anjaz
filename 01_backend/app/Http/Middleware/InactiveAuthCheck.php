<?php

namespace App\Http\Middleware;

use App\CentralLogics\helpers;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class InactiveAuthCheck
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
        $difference = (Carbon::parse($request->user()->last_active_at))->diff(now());
        if($difference->i >= Helpers::get_business_settings('inactive_auth_minute')??20) {
            //clear token
            Auth::user()->tokens->each(function($token, $key) {
                $token->delete();
            });

            return response()->json(['message' => 'Token Expired'], 401);
        }

        return $next($request);
    }
}
