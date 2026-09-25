<?php

namespace App\Http\Middleware;

use App\Models\MerchantProfile;
use App\Support\Access\AccessConstants as A;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-MERCHANT-WEB-001 — لا عميل ولا POS ولا موظف المنصّة داخل بوابة المالك.
 * auth:merchant_web مستقل عن auth:user المستعمل في لوحة إدارة أميال.
 */
class MerchantWebMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('merchant_web');
        $user = $guard->user();

        if (!$user) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'code' => 'UNAUTHENTICATED',
                    'message' => 'سجّل دخولك إلى بوابة المنشأة.'], 401)
                : redirect()->route('merchant.web.login');
        }

        if ((int) $user->type !== MERCHANT_TYPE
            || (string) $user->role !== A::ROLE_MERCHANT
            || (int) $user->is_active !== 1
            || (int) ($user->is_temp_blocked ?? 0) === 1
            || !MerchantProfile::where('user_id', $user->id)->exists()) {
            $guard->logout();
            return $request->expectsJson()
                ? response()->json(['success' => false, 'code' => 'MERCHANT_OWNER_REQUIRED',
                    'message' => 'هذه البوابة مخصصة لمالك منشأة نشطة فقط.'], 403)
                : redirect()->route('merchant.web.login')->withErrors([
                    'identifier' => 'بوابة المنشأة مخصصة لمالك تاجر نشط فقط.',
                ]);
        }

        // يجعل Request::user() داخل الخدمات الأصلية يرى المالك نفسه،
        // لا admin أو customer قد يسجّل دخولهما بجلسة حارس مختلف.
        Auth::shouldUse('merchant_web');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        return $response;
    }
}
