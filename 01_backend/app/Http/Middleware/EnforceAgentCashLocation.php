<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use App\Support\YemenGovernorates;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-ZONE-BOUNDARY-001 — موقع الوكيل لحظة العملية النقدية.
 *
 * **الثغرة التي يسدّها:**
 * منطقة الحساب تُسند مرّة عند الاعتماد ولا تتغيّر. فوكيل معتمد في عدن
 * يستطيع أخذ هاتفه إلى صنعاء ومزاولة الإيداع والسحب هناك، والنظام يرى
 * عملياته سليمة تماماً لأن zone_code ما زال SOUTH. عندها يسلّم ويستلم
 * ريالاً جديداً مقابل رصيد مقوَّم بالريال القديم — بسعر خاطئ في كل عملية.
 *
 * **لماذا هنا وحده يُلزَم الموقع:**
 * موقع الجهاز يُزوَّر، ولذلك لا نبني عليه صلاحيات. لكن الوكيل ليس مستخدماً
 * عادياً: هو عقدة معتمدة، مُلزَمة تعاقدياً، تلمس النقد الحقيقي. إلزامه
 * بموقعه لحظة العملية ليس حماية من التزوير — بل أثر مُدقَّق يجعل المخالفة
 * فعلاً متعمّداً موثّقاً لا لبس فيه، ويوقف الحالة العَرَضية (وكيل سافر).
 * العميل بخلافه لا يُلزَم إطلاقاً: عملياته إمّا داخل الدفتر وإمّا تحتاج
 * وكيلاً أو تاجراً محكومَين أصلاً.
 *
 * **أوضاع التطبيق (AMIAL_AGENT_LOCATION_MODE):**
 *   soft   (افتراضي) — يُرفض إن أُرسل موقع خارج النطاق، ويُسجَّل تحذير إن غاب.
 *                      يسمح بالنشر قبل وصول التطبيق الجديد لكل الوكلاء.
 *   strict — يُرفض أيضاً إن غاب الموقع. فعّله بعد تحديث كل الوكلاء.
 *   off    — تسجيل فقط. للطوارئ.
 */
class EnforceAgentCashLocation
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('amial.agent_location_mode', 'soft');
        $user = $request->user();

        if (!$user || (int) $user->type !== AGENT_TYPE || $mode === 'off') {
            return $next($request);
        }

        [$lat, $lng] = $this->coordinates($request);

        // ── الموقع غائب ─────────────────────────────────────────────
        if ($lat === null || $lng === null) {
            if ($mode === 'strict') {
                return $this->deny(
                    $user->id, 'AGENT_LOCATION_REQUIRED',
                    'يجب تفعيل خدمة الموقع لتنفيذ العمليات النقدية. حدّث التطبيق وفعّل GPS.'
                );
            }

            Log::warning('Agent cash operation without device location', [
                'agent_id' => $user->id, 'path' => $request->path(),
            ]);

            return $next($request);
        }

        // ── الموقع حاضر: يُفحص في كل الأوضاع عدا off ────────────────
        $governorate = YemenGovernorates::codeFromCoordinates($lat, $lng);

        if ($governorate === null || !YemenGovernorates::isOperational($governorate)) {
            $place = $governorate === null
                ? 'خارج اليمن'
                : 'محافظة ' . YemenGovernorates::name($governorate);

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $user->id,
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'action' => 'AGENT_CASH_OUTSIDE_ZONE',
                'decision_code' => 'BLOCKED',
                'severity' => 'critical',
                'context' => [
                    'governorate' => $governorate,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'path' => $request->path(),
                ],
            ]);

            return $this->deny(
                $user->id, 'AGENT_OUTSIDE_OPERATIONAL_ZONE',
                "موقعك الحالي ({$place}) خارج نطاق تشغيل أميال باي. "
                . 'العمليات النقدية متاحة داخل النطاق المعتمد فقط.'
            );
        }

        $request->attributes->set('amial.agent_governorate', $governorate);

        return $next($request);
    }

    /**
     * الإحداثيات من الترويسات أو الجسم.
     * الترويسات أفضل: لا تتعارض مع تحقّق حقول العملية.
     */
    private function coordinates(Request $request): array
    {
        $lat = $request->header('X-Amial-Lat') ?? $request->input('latitude');
        $lng = $request->header('X-Amial-Lng') ?? $request->input('longitude');

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return [null, null];
        }

        return [(float) $lat, (float) $lng];
    }

    private function deny(int $agentId, string $code, string $message): JsonResponse
    {
        Log::warning('Agent cash operation denied by location', [
            'agent_id' => $agentId, 'code' => $code,
        ]);

        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'errors' => (object) [],
            'meta' => (object) [],
        ], 403);
    }
}
