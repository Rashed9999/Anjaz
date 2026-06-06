<?php

namespace App\Http\Middleware;

use App\Services\ZonePolicyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-ZONE-001
 *
 * يطبق سياسة zone على endpoint. الاستخدام:
 *
 *   Route::middleware(['auth:api', 'amial.zone:send_money'])->post(...)
 *
 * الـ parameter (مثل 'send_money') هو اسم action الذي يخضع للسياسة.
 * يجب أن يكون موجوداً في ZonePolicyService::FINANCIAL_ACTIONS أو READ_ACTIONS.
 *
 * عند الرفض: HTTP 403 مع decision_code (TX_ZONE_BLOCKED, ACCOUNT_ZONE_UNKNOWN, ...).
 *
 * أيضاً: يضيف الـ zone metadata إلى الـ Request (لاستخدامها لاحقاً في
 * TransactionTrait لملء حقول zone_code/request_zone/counterparty_zone).
 */
class EnforceZonePolicy
{
    public function __construct(
        private readonly ZonePolicyService $service,
    ) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $decision = $this->service->authorize($request->user(), $action, $request);

        // نُمرر الـ decision للـ controller (يستخدمها لملء zone fields في Transaction)
        $request->attributes->set('amial.zone_decision', $decision);
        $request->attributes->set('amial.account_zone', $decision['account_zone']);
        $request->attributes->set('amial.request_zone', $decision['request_zone']);

        if (!$decision['allowed']) {
            return new JsonResponse([
                'success' => false,
                'message' => $decision['reason'],
                'code' => $decision['decision_code'],
                'errors' => (object)[],
                'meta' => [
                    'account_zone' => $decision['account_zone'],
                    'request_zone' => $decision['request_zone'],
                    'allowed_operational_zone' => ZonePolicyService::ALLOWED_OPERATIONAL_ZONE,
                ],
            ], 403);
        }

        return $next($request);
    }
}
