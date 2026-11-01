<?php

namespace App\Http\Middleware;

use App\Models\BusinessGateway\BusinessLink;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — يتحقق أن الطلب صادر من خادم أميال أعمال.
 *
 * ثلاثة حواجز بالترتيب: الوقت (نافذة محدودة) ← التوقيع (يغطي الطريقة
 * والمسار والجسم) ← الرقم الوحيد nonce (طلبٌ موقّع لا يُعاد إرساله). ثم
 * يُحدَّد الرابط من الترويسة، ولا يُقبل رقم تاجر من الجسم إطلاقاً.
 */
class VerifyBusinessGatewaySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = trim((string) config('amial_business.inbound_secret'));
        if ($secret === '') {
            return $this->error('BUSINESS_GATEWAY_NOT_CONFIGURED', 'قناة أميال أعمال غير مضبوطة', 503);
        }

        $timestamp = (string) $request->header('X-Amial-Business-Timestamp', '');
        $nonce = (string) $request->header('X-Amial-Business-Nonce', '');
        $signature = (string) $request->header('X-Amial-Business-Signature', '');
        $skew = max(30, (int) config('amial_business.allowed_clock_skew_seconds', 300));

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $skew) {
            return $this->error('BUSINESS_SIGNATURE_EXPIRED', 'انتهت صلاحية توقيع الطلب', 401);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)) {
            return $this->error('BUSINESS_NONCE_INVALID', 'معرّف الطلب الوحيد غير صالح', 401);
        }

        $canonical = BusinessGatewaySignature::inboundCanonical(
            $timestamp,
            $nonce,
            $request->getMethod(),
            $request->getPathInfo(),
            (string) $request->getQueryString(),
            $request->getContent(),
        );
        $expected = BusinessGatewaySignature::sign($canonical, $secret);
        if ($signature === '' || !hash_equals($expected, $signature)) {
            return $this->error('BUSINESS_SIGNATURE_INVALID', 'توقيع الطلب غير صحيح', 401);
        }

        try {
            DB::table('business_request_nonces')->insert(['nonce' => $nonce, 'created_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return $this->error('BUSINESS_REQUEST_REPLAYED', 'هذا الطلب أُرسل سابقاً', 409);
        }

        $linkUlid = (string) $request->header('X-Amial-Business-Link', '');
        $link = preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $linkUlid)
            ? BusinessLink::where('link_ulid', $linkUlid)->first()
            : null;
        if (!$link) {
            return $this->error('BUSINESS_LINK_NOT_FOUND', 'ربط المنشأة غير موجود', 404);
        }

        $request->attributes->set('business_link', $link);

        return $next($request);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => (object) [],
        ], $status);
    }
}
