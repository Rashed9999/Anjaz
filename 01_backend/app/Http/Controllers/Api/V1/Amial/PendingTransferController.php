<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\PendingTransfer;
use App\Models\User;
use App\Services\FeeDiscountPolicy;
use App\Services\FeeService;
use App\Services\MoneyService;
use App\Services\PendingTransferService;
use App\Services\RecipientVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-TRANSFER-COOLDOWN-001 (v2.7) + AMIAL-TRANSFER-FEE-TRUTH-001
 *
 * تحويل بنافذة إلغاء + PIN + احتساب رسم الخادم حصراً (منع تزوير حقل fee من العميل).
 */
class PendingTransferController extends Controller
{
    public function __construct(
        private readonly PendingTransferService $service,
        private readonly RecipientVerificationService $verifier,
        private readonly FeeService $fees,
        private readonly FeeDiscountPolicy $discounts,
    ) {}

    /** POST /api/v1/amial/transfer/initiate */
    public function initiate(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'recipient_id' => 'required|integer|exists:users,id',
            'verification_token' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'pin' => 'required|string|digits_between:4,6',
            'note' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $sender = $request->user();
        $recipientId = (int)$request->input('recipient_id');

        // تأكيد أن المستلم مُتحقَّق منه (token من verify-recipient)
        try {
            $this->verifier->assertValidToken($sender->id, $request->input('verification_token'), $recipientId);
        } catch (\RuntimeException $e) {
            return $this->error('VERIFICATION_REQUIRED', $e->getMessage(), 422);
        }

        $recipient = User::find($recipientId);
        if (!$recipient) {
            return $this->error('RECIPIENT_NOT_FOUND', 'المستلم غير موجود', 404);
        }

        $amount = MoneyService::normalize((string) $request->input('amount'));
        $breakdown = $this->fees->calculate('SEND_MONEY', $amount, [
            'zone_code' => $sender->zone_code ?? 'SOUTH',
            'applies_to' => 'customer',
        ]);
        $discounted = $this->discounts->applyFavouriteNumber(
            $sender,
            (string) ($recipient->phone ?? ''),
            $breakdown['fee'],
            'SEND_MONEY',
        );

        try {
            $pending = $this->service->initiate(
                sender: $sender,
                recipient: $recipient,
                amount: $amount,
                pin: (string) $request->input('pin'),
                fee: $discounted['fee'],
                note: $request->input('note'),
            );
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'PIN') ? 'PIN_INVALID' : 'TRANSFER_FAILED';
            $httpStatus = $code === 'PIN_INVALID' ? 403 : 422;
            return $this->error($code, $e->getMessage(), $httpStatus);
        }

        return $this->ok([
            'transfer_ulid' => $pending->transfer_ulid,
            'status' => $pending->status,
            'amount' => (string)$pending->amount,
            'fee' => (string)$pending->fee,
            'total_debited' => (string)$pending->total_debited,
            'seconds_remaining' => $pending->secondsRemaining(),
            'releasable_at' => $pending->releasable_at->toIso8601String(),
            'can_cancel' => true,
        ], 'TRANSFER_HOLDING', 'التحويل قيد التنفيذ. يمكنك إلغاؤه خلال المهلة.');
    }

    /** POST /api/v1/amial/transfer/{ulid}/cancel */
    public function cancel(Request $request, string $ulid): JsonResponse
    {
        // AMIAL-TRANSFER-CONTRACT-001: مقيَّد بالمرسل كما في status.
        //
        // المنع نفسه قائم في الخدمة (ترمي «غير مصرّح لك»)، لكنّ البحث
        // بالمعرّف وحده كان يجعل الردّ 422 على تحويل قائم و404 على غير
        // القائم. والفرق بين الردّين يُخبر من يجرّب معرّفات أن هذا المعرّف
        // حقيقي. لا فائدة في قول ذلك، والتقييد هنا يجعل الجوابين واحداً.
        $pending = PendingTransfer::where('transfer_ulid', $ulid)
            ->where('sender_user_id', $request->user()->id)
            ->first();
        if (!$pending) return $this->error('NOT_FOUND', 'التحويل غير موجود', 404);

        try {
            $cancelled = $this->service->cancel($pending, $request->user());
        } catch (\RuntimeException $e) {
            return $this->error('CANCEL_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'transfer_ulid' => $cancelled->transfer_ulid,
            'status' => $cancelled->status,
            'refunded' => (string)$cancelled->total_debited,
        ], 'TRANSFER_CANCELLED', 'تم إلغاء التحويل واسترداد المبلغ بالكامل');
    }

    /** GET /api/v1/amial/transfer/{ulid}/status */
    public function status(Request $request, string $ulid): JsonResponse
    {
        $pending = PendingTransfer::where('transfer_ulid', $ulid)
            ->where('sender_user_id', $request->user()->id)
            ->first();
        if (!$pending) return $this->error('NOT_FOUND', 'التحويل غير موجود', 404);

        // AMIAL-SPEED: تسليم كسول إن انتهت النافذة — المستخدم يرى «مكتمل»
        // لحظة انتهاء العدّاد دون انتظار دورة المجدول.
        $pending = $this->service->releaseIfDue($pending);

        return $this->ok([
            'transfer_ulid' => $pending->transfer_ulid,
            'status' => $pending->status,
            'amount' => (string)$pending->amount,
            'fee' => (string)$pending->fee,
            'total_debited' => (string)$pending->total_debited,
            'seconds_remaining' => $pending->secondsRemaining(),
            'can_cancel' => $pending->isCancellable(),
        ]);
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK'): JsonResponse
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
