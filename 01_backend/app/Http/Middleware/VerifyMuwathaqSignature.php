<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** يتحقق من HMAC لقرار صادر من «موثّق»، وليس من متصفح أو تطبيق عميل. */
class VerifyMuwathaqSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = trim((string) config('muwathaq.decision_secret'));
        if ($secret === '') {
            return $this->error('MUWATHAQ_BRIDGE_NOT_CONFIGURED', 503);
        }

        $timestamp = (string) $request->header('X-Muwathaq-Timestamp', '');
        $signature = (string) $request->header('X-Muwathaq-Signature', '');
        $skew = max(30, (int) config('muwathaq.allowed_clock_skew_seconds', 300));

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $skew) {
            return $this->error('MUWATHAQ_SIGNATURE_EXPIRED', 401);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        if ($signature === '' || !hash_equals($expected, $signature)) {
            return $this->error('MUWATHAQ_SIGNATURE_INVALID', 401);
        }

        return $next($request);
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => 'تعذر التحقق من قناة موثّق.',
            'errors' => (object) [],
            'meta' => (object) [],
        ], $status);
    }
}
