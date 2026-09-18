<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-TRACE-001 — يربط الطلب وكل آثاره التشغيلية بمعرّف واحد.
 *
 * لا نثق بقيمة العميل إلا بعد تقييدها؛ والطلب الذي لا يحمل معرّفاً يحصل على
 * ULID جديد. يبقى المعرّف في Request attributes كي تستخدمه الخدمات دون تمرير
 * قيمة عبر كل طبقة، ويعاد في الاستجابة لتسهيل دعم العملاء.
 */
class CorrelationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = trim((string) $request->header('X-Correlation-Id', ''));
        $correlationId = preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $incoming)
            ? $incoming
            : (string) Str::ulid();

        $request->attributes->set('amial.correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
