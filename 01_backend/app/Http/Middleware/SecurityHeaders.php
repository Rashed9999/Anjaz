<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-SEC-HEADERS-001 — يضيف ترويسات أمان دفاعية لكلّ استجابة.
 *
 * كشف اختبار الاختراق (AMIAL-PENTEST-001) غيابها كلّها. تحمي من:
 *   - MIME sniffing (X-Content-Type-Options)
 *   - clickjacking (X-Frame-Options)
 *   - تسريب المُحيل لأطراف ثالثة (Referrer-Policy)
 *   - هبوط HTTPS→HTTP (HSTS — فقط فوق TLS)
 *   - حقن محتوى/XSS (CSP محافظ)
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'X-XSS-Protection'       => '0', // معطّل عمداً (المعيار الحديث: CSP لا هذا)
            'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'; "
                . "base-uri 'self'; object-src 'none'",
            'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=()',
        ];

        // HSTS فقط فوق HTTPS (وضعه فوق HTTP يُعطبه)
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        foreach ($headers as $key => $value) {
            if (!$response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }
}
