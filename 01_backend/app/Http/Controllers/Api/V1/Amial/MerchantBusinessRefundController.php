<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessRefund;
use App\Services\BusinessGateway\BusinessGatewayException;
use App\Services\BusinessGateway\BusinessRefundService;
use App\Services\MoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-BUSINESS-REFUND-001 — التاجر يعتمد استردادات «أميال أعمال» من تطبيقه.
 *
 *   GET  merchant/business-refunds                 المعلّق والأخير + التفويض الحالي
 *   POST merchant/business-refunds/{id}/approve    {pin}  يُخرج المال من محفظته
 *   POST merchant/business-refunds/{id}/reject     {note?}
 *   PUT  merchant/business-refunds/mandate         {per_refund, daily, pin}
 *
 * صاحب المحفظة وحده (حساب التاجر نفسه، لا موظفوه) يعتمد ويفوّض: الرابط مربوط
 * برقمه، وكل طلب لرابطٍ ليس له يُعامل غير موجود.
 */
class MerchantBusinessRefundController extends Controller
{
    public function index(Request $request, BusinessRefundService $refunds): JsonResponse
    {
        $link = BusinessLink::where('merchant_user_id', $request->user()->id)->first();
        if (!$link) {
            return $this->ok(['linked' => false, 'pending' => [], 'recent' => [], 'mandate' => null]);
        }
        $rows = BusinessRefund::with('intent')->where('business_link_id', $link->id)->latest('id')->limit(50)->get();

        return $this->ok([
            'linked' => true,
            'pending' => $rows->where('status', BusinessRefund::PENDING)->values()->map(fn ($r) => $refunds->present($r)),
            'recent' => $rows->where('status', '!=', BusinessRefund::PENDING)->take(20)->values()->map(fn ($r) => $refunds->present($r)),
            'mandate' => [
                'per_refund' => MoneyService::normalize((string) $link->refund_auto_limit),
                'daily' => MoneyService::normalize((string) $link->refund_daily_limit),
                'set_at' => $link->refund_mandate_set_at?->toIso8601String(),
            ],
        ]);
    }

    public function approve(Request $request, string $refund, BusinessRefundService $refunds): JsonResponse
    {
        if ($fail = $this->pinFails($request)) {
            return $fail;
        }

        return $this->guard(function () use ($request, $refund, $refunds) {
            $done = $refunds->approve($request->user(), $this->find($request, $refund));

            return $this->ok($refunds->present($done), 'REFUND_COMPLETED', 'تم الاسترداد إلى محفظة العميل');
        });
    }

    public function reject(Request $request, string $refund, BusinessRefundService $refunds): JsonResponse
    {
        $note = Validator::make($request->all(), ['note' => 'nullable|string|max:255'])->validate()['note'] ?? null;

        return $this->guard(fn () => $this->ok(
            $refunds->present($refunds->reject($request->user(), $this->find($request, $refund), $note)), 'REFUND_REJECTED', 'رُفض الطلب'));
    }

    public function mandate(Request $request, BusinessRefundService $refunds): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'per_refund' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'daily' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
        ]);
        if ($v->fails()) {
            return $this->error('VALIDATION', $v->errors()->first(), 422);
        }
        if ($fail = $this->pinFails($request)) {
            return $fail;
        }
        $link = BusinessLink::where('merchant_user_id', $request->user()->id)->first();
        if (!$link) {
            return $this->error('NOT_FOUND', 'حسابك غير مربوط بأميال أعمال', 404);
        }

        return $this->guard(function () use ($request, $refunds, $link) {
            $saved = $refunds->setMandate($request->user(), $link, (string) $request->input('per_refund'), (string) $request->input('daily'));

            return $this->ok([
                'per_refund' => MoneyService::normalize((string) $saved->refund_auto_limit),
                'daily' => MoneyService::normalize((string) $saved->refund_daily_limit),
            ], 'MANDATE_SAVED', 'حُفظ تفويض الاسترداد');
        });
    }

    private function find(Request $request, string $ulid): BusinessRefund
    {
        $refund = BusinessRefund::with('link', 'intent')->where('refund_ulid', $ulid)
            ->whereHas('link', fn ($q) => $q->where('merchant_user_id', $request->user()->id))->first();

        return $refund ?? throw new BusinessGatewayException('NOT_FOUND', 'الطلب غير موجود', 404);
    }

    private function pinFails(Request $request): ?JsonResponse
    {
        $pin = (string) $request->input('pin', '');
        if ($pin === '' || !Helpers::pin_check($request->user()->id, $pin)) {
            return $this->error('WRONG_PIN', 'رمز PIN غير صحيح', 403);
        }

        return null;
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessGatewayException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\App\Exceptions\InsufficientBalanceException) {
            return $this->error('INSUFFICIENT_BALANCE', 'رصيد محفظتك لا يكفي لهذا الاسترداد', 422);
        } catch (\App\Exceptions\AmlBlockedException $e) {
            return new JsonResponse($e->toApiArray(), 403);
        } catch (\App\Exceptions\AmlHeldException $e) {
            return new JsonResponse($e->toApiArray(), 202);
        } catch (\RuntimeException $e) {
            return $this->error('REFUND_NOT_ALLOWED', $e->getMessage(), 422);
        }
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK'): JsonResponse
    {
        return new JsonResponse(['success' => true, 'code' => $code, 'message' => $message, 'errors' => (object) [], 'meta' => $meta]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => $code, 'message' => $message, 'errors' => (object) [], 'meta' => (object) []], $status);
    }
}
