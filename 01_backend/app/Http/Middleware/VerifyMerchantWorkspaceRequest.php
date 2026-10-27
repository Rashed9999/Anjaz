<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Verify short-lived, one-use, signed requests from the isolated merchant app. */
final class VerifyMerchantWorkspaceRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('amial.merchant_workspace.integration_secret', '');
        $timestamp = (string) $request->header('X-Amial-Timestamp', '');
        $nonce = (string) $request->header('X-Amial-Nonce', '');
        $encoded = (string) $request->header('X-Amial-Context', '');
        $signature = (string) $request->header('X-Amial-Signature', '');
        $fail = static fn (string $code, int $status = 401) => response()->json([
            'success' => false, 'code' => $code, 'message' => 'تعذر التحقق من طلب أميال التاجر',
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);

        if (strlen($secret) < 32 || ! preg_match('/^[0-9]{10,11}$/D', $timestamp)
            || abs(time() - (int) $timestamp) > 300
            || ! preg_match('/^[a-zA-Z0-9_-]{20,128}$/D', $nonce)
            || ! preg_match('/^[a-zA-Z0-9_-]{10,8192}$/D', $encoded)
            || ! preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            return $fail('INVALID_INTEGRATION_SIGNATURE');
        }

        $canonical = $timestamp."\n".$nonce."\n".strtoupper($request->method())."\n"
            .$request->getRequestUri()."\n".$encoded."\n".hash('sha256', $request->getContent());
        if (! hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            return $fail('INVALID_INTEGRATION_SIGNATURE');
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $context = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($context)
            || ($context['iss'] ?? null) !== config('amial.merchant_workspace.read_issuer', 'amial-merchant')
            || ($context['aud'] ?? null) !== config('amial.merchant_workspace.read_audience', 'amial-pay')
            || ($context['actor_type'] ?? null) !== 'owner'
            || ! ctype_digit((string) ($context['actor_id'] ?? ''))
            || ! is_string($context['tenant_id'] ?? null)
            || ! is_int($context['iat'] ?? null) || abs(time() - $context['iat']) > 300
            || ! is_int($context['exp'] ?? null) || $context['exp'] < time() || $context['exp'] > time() + 90
            || ($context['scopes'] ?? null) !== ['merchant.financial.read']) {
            return $fail('INVALID_INTEGRATION_CONTEXT');
        }

        if (! Cache::add('amial:merchant-workspace:nonce:'.hash('sha256', $nonce), 1, 360)) {
            return $fail('REPLAYED_INTEGRATION_REQUEST', 409);
        }

        $request->attributes->set('merchant.workspace.context', $context);
        return $next($request);
    }
}
