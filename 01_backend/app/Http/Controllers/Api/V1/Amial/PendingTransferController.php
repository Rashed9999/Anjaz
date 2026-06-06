<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\PendingTransfer;
use App\Models\User;
use App\Services\PendingTransferService;
use App\Services\RecipientVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-TRANSFER-COOLDOWN-001 (v2.7)
 *
 * تحويل بنافذة إلغاء + PIN.
 */
class PendingTransferController extends Controller
{
    public function __construct(
        private readonly PendingTransferService $service,
        private readonly RecipientVerificationService $verifier,
    ) {}

    /** POST /api/v1/amial/transfer/initiate */
    public function initiate(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'recipient_id' => 'required|integer|exists:users,id',
            'verification_token' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'pin' => 'required|string|min:4|max:8',
            'fee' => 'sometimes|nullable|numeric|min:0',
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

        try {
            $pending = $this->service->initiate(
                sender: $sender,
                recipient: $recipient,
                amount: (string)$request->input('amount'),
                pin: $request->input('pin'),
                fee: (string)$request->input('fee', '0'),
                note: $request->input('note'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('TRANSFER_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'transfer_ulid' => $pending->transfer_ulid,
            'status' => $pending->status,
            'amount' => (string)$pending->amount,
            'seconds_remaining' => $pending->secondsRemaining(),
            'releasable_at' => $pending->releasable_at->toIso8601String(),
            'can_cancel' => true,
        ], 'TRANSFER_HOLDING', 'التحويل قيد التنفيذ. يمكنك إلغاؤه خلال المهلة.');
    }

    /** POST /api/v1/amial/transfer/{ulid}/cancel */
    public function cancel(Request $request, string $ulid): JsonResponse
    {
        $pending = PendingTransfer::where('transfer_ulid', $ulid)->first();
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

        return $this->ok([
            'transfer_ulid' => $pending->transfer_ulid,
            'status' => $pending->status,
            'amount' => (string)$pending->amount,
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
