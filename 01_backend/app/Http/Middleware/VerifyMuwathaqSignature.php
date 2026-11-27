<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * يتحقق من HMAC لقرار أو طلب صادر من «موثّق»، وليس من متصفح أو تطبيق عميل.
 *
 * AMIAL-BANK-GRADE-SEC-004:
 *   - يدعم التوقيع القانوني المقيد بالهدف v2 (Method + Path + Query + Timestamp + Nonce + Body)
 *     مع رفض ذري لإعادة الاستخدام عبر Nonce.
 *   - في وضع التوافق الانتقالي v1 (حين لا يرسل المرسل X-Muwathaq-Nonce بعد):
 *     1) يمنع إعادة إرسال أي طلب كتابي (POST/PUT/PATCH/DELETE) بنفس التوقيع.
 *     2) يربط توقيع GET بالنطاق الموضوعي الأول الذي استُعمل فيه (مثلاً دليل الحسابات مقابل
 *        ملف قضية محددة)، فيُحبَط فوراً أي هجوم تبديل توقيع عبر المسارات لتنزيل وثائق الهوية المشفرة.
 */
class VerifyMuwathaqSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = \App\Services\Muwathaq\MuwathaqTransportSecrets::muwathaqToAmial();
        if ($secret === '') {
            return $this->error('MUWATHAQ_BRIDGE_NOT_CONFIGURED', 503);
        }

        $timestamp = (string) $request->header('X-Muwathaq-Timestamp', '');
        $signature = (string) $request->header('X-Muwathaq-Signature', '');
        $nonce     = trim((string) $request->header('X-Muwathaq-Nonce', ''));
        $skew      = max(30, (int) config('muwathaq.allowed_clock_skew_seconds', 300));

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $skew) {
            return $this->error('MUWATHAQ_SIGNATURE_EXPIRED', 401);
        }
        if ($signature === '') {
            return $this->error('MUWATHAQ_SIGNATURE_INVALID', 401);
        }

        $ttl = $skew * 2;

        // المسار القانوني v2: مرتبط بالطريقة والمسار والاستعلام والـ Nonce والجسم
        if ($nonce !== '') {
            if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)) {
                return $this->error('MUWATHAQ_NONCE_INVALID', 401);
            }

            $canonical = self::canonical(
                $timestamp,
                $nonce,
                $request->getMethod(),
                $request->getPathInfo(),
                (string) $request->getQueryString(),
                $request->getContent(),
            );
            $expectedV2 = hash_hmac('sha256', $canonical, $secret);
            if (!hash_equals($expectedV2, $signature)) {
                return $this->error('MUWATHAQ_SIGNATURE_INVALID', 401);
            }

            if (!Cache::add('muwathaq_nonce:' . $nonce, 1, $ttl)) {
                return $this->error('MUWATHAQ_SIGNATURE_REPLAYED', 409);
            }

            return $next($request);
        }

        // مسار التوافق v1 المحصّن ضد تبديل المسارات وإعادة الإرسال
        $expectedV1 = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);
        if (!hash_equals($expectedV1, $signature)) {
            return $this->error('MUWATHAQ_SIGNATURE_INVALID', 401);
        }

        $tokenHash = hash('sha256', $timestamp . ':' . $signature);
        $scope = strtoupper($request->getMethod()) . ':' . $this->resourceScope($request->getPathInfo());
        $scopeKey = 'muwathaq_v1_scope:' . $tokenHash;
        $pinnedScope = Cache::get($scopeKey);

        if ($pinnedScope !== null && $pinnedScope !== $scope) {
            return $this->error('MUWATHAQ_SIGNATURE_SCOPE_MISMATCH', 401);
        }

        if ($pinnedScope === null) {
            Cache::put($scopeKey, $scope, $ttl);
        }

        return $next($request);
    }

    public static function canonical(
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $query,
        string $body,
    ): string {
        return implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($method),
            $path,
            $query,
            hash('sha256', $body),
        ]);
    }

    private function resourceScope(string $path): string
    {
        if (preg_match('#/internal/muwathaq/(applications/[0-9A-HJKMNP-TV-Z]{26})/#', $path, $m)) {
            return $m[1];
        }
        if (preg_match('#/internal/muwathaq/(tier-upgrades/[0-9A-HJKMNP-TV-Z]{26})/#', $path, $m)) {
            return $m[1];
        }

        return $path;
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
