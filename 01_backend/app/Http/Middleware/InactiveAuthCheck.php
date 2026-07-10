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
        $user = $request->user();
        if (!$user) {
            return $next($request); // يترك auth:api يتولّى غياب المصادقة
        }

        // AMIAL-FIX(POST-LOGIN): إصلاح خطأين كانا يُرجعان «Token Expired» فوراً:
        //  1) أسبقية العوامل: `$diff->i >= الإعداد ?? 20` تُحسب كـ
        //     `($diff->i >= الإعداد) ?? 20`؛ فحين يكون الإعداد null (خادم أميال
        //     الجديد) يصير `0 >= null` = `0 >= 0` = true → انتهاء فوري لكل طلب.
        //  2) `$diff->i` جزء الدقائق (0-59) لا الإجمالي. نستخدم diffInMinutes.
        // كما نتعامل مع last_active_at الفارغ (أوّل طلب بعد الدخول) كـ«نشط».
        $limit = (int) (Helpers::get_business_settings('inactive_auth_minute') ?: 20);
        $lastActive = $user->last_active_at;

        if ($lastActive !== null) {
            $minutesInactive = Carbon::parse($lastActive)->diffInMinutes(now());
            if ($minutesInactive >= $limit) {
                //clear token
                Auth::user()->tokens->each(function ($token, $key) {
                    $token->delete();
                });

                return response()->json(['message' => 'Token Expired'], 401);
            }
        }

        return $next($request);
    }
}
