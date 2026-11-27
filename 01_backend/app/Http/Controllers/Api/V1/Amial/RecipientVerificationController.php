<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FeeDiscountPolicy;
use App\Services\FeeService;
use App\Services\MoneyService;
use App\Services\RecipientVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-RECIPIENT-VERIFY-001 (v2.6) + AMIAL-TRANSFER-FEE-TRUTH-001
 *
 * التحقق من المستلم قبل التحويل (يمنع التحويل بالغلط) + تسعيرة رسم التحويل
 * من محرك الرسوم المركزي (`FeeService` + خصم الرقم المفضّل) عند إرسال المبلغ.
 */
class RecipientVerificationController extends Controller
{
    public function __construct(
        private readonly RecipientVerificationService $service,
        private readonly FeeService $fees,
        private readonly FeeDiscountPolicy $discounts,
    ) {}

    /** POST /api/v1/amial/transfer/verify-recipient */
    public function verify(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'phone' => 'required|string|min:6|max:20',
            'amount' => 'sometimes|nullable|numeric|min:0.01',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $sender = $request->user();

        try {
            $result = $this->service->verifyRecipient(
                $request->input('phone'),
                $sender->id,
            );
        } catch (\RuntimeException $e) {
            return $this->error('RECIPIENT_INVALID', $e->getMessage(), 422);
        }

        if ($request->filled('amount')) {
            $amount = MoneyService::normalize((string) $request->input('amount'));
            if (MoneyService::isPositive($amount)) {
                $recipient = User::find((int) $result['recipient_id']);
                $breakdown = $this->fees->calculate('SEND_MONEY', $amount, [
                    'zone_code' => $sender->zone_code ?? 'SOUTH',
                    'applies_to' => 'customer',
                ]);
                $discounted = $this->discounts->applyFavouriteNumber(
                    $sender,
                    (string) ($recipient?->phone ?? ''),
                    $breakdown['fee'],
                    'SEND_MONEY',
                );
                $result['fee_quote'] = [
                    'fee' => $discounted['fee'],
                    'base_fee' => $breakdown['fee'],
                    'discount' => $discounted['discount'],
                    'discount_reason' => $discounted['reason'],
                    'total_debit' => MoneyService::add($amount, $discounted['fee']),
                    'pricing_state' => $breakdown['pricing_state'] ?? 'priced',
                ];
            }
        }

        return $this->ok($result, 'RECIPIENT_VERIFIED',
            'تأكد من اسم المستلم قبل الإرسال');
    }

    private function ok(array $meta, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
