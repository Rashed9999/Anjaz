<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AgentMiddleware
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
        if ($request->user() && $request->user()->type == AGENT_TYPE) {
            return $next($request);
        }
        //else
        abort(response()->json([
            'success' => false,
            'code' => 'AGENT_ACCESS_REQUIRED',
            'message' => 'هذه الخدمة متاحة لحساب الوكيل فقط.',
        ], 403));
    }
}
