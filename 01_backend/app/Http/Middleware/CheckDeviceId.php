<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\UserLogHistory;

class CheckDeviceId
{
    public function handle(Request $request, Closure $next): mixed
    {
        if($request->ip() == "::1") {
            return $next($request);
        }

        $device_id = $request->header('device-id');
        if($device_id == '') {
            abort(response()->json(response_formatter(DEFAULT_400), 400));
        }

        $device = UserLogHistory::where('user_id', $request->user()->id)->where('device_id', $device_id)->where('is_active', 1)->first();
        if (isset($device)) {
            return $next($request);
        }

        abort(response()->json(response_formatter(DEFAULT_403), 403));
    }
}
