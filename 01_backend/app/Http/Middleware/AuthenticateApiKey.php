<?php

namespace App\Http\Middleware;

use App\Models\MerchantApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-API-ACCESS-001 — مصادقة نقاط الشركاء عبر مفتاح API.
 * الترويسة: X-Api-Key: amk_xxx  (أو ?api_key=). يضع merchant_user_id في الطلب.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-Api-Key') ?: $request->query('api_key');
        if (!$provided) {
            return response()->json(['success' => false, 'message' => 'مفتاح API مفقود'], 401);
        }

        $key = MerchantApiKey::where('key_hash', hash('sha256', $provided))
            ->where('is_active', true)->first();
        if (!$key) {
            return response()->json(['success' => false, 'message' => 'مفتاح API غير صحيح أو موقوف'], 401);
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('api_merchant_user_id', $key->merchant_user_id);

        return $next($request);
    }
}
