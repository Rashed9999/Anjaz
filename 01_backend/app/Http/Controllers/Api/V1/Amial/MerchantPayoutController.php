<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantPayoutRequest;
use App\Models\MerchantProfile;
use App\Services\MerchantPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** واجهة مالك التاجر فقط لطلب مستحقاته ومتابعة تسليمها. */
class MerchantPayoutController extends AmialApiController
{
    public function __construct(private readonly MerchantPayoutService $payouts) {}

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->owner($request);
        if ($merchant instanceof JsonResponse) return $merchant;

        $items = MerchantPayoutRequest::where('merchant_user_id', $merchant->id)
            ->latest()->limit(50)->get()->map(fn (MerchantPayoutRequest $p) => $this->serialize($p))->values();
        return $this->ok(['requests' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->owner($request);
        if ($merchant instanceof JsonResponse) return $merchant;
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|gt:0',
            'note' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);
        try {
            $p = $this->payouts->request($merchant, (string) $request->input('amount'), $request->input('note'));
            return $this->ok(['request' => $this->serialize($p)], 'PAYOUT_REQUESTED',
                'أُرسل الطلب وحُجز المبلغ حتى قرار الإدارة.', 201);
        } catch (RuntimeException $e) {
            return $this->error('PAYOUT_REQUEST_FAILED', $e->getMessage(), 422);
        }
    }

    public function confirmHandover(Request $request, string $ulid): JsonResponse
    {
        $merchant = $this->owner($request);
        if ($merchant instanceof JsonResponse) return $merchant;
        $p = MerchantPayoutRequest::where('payout_ulid', $ulid)->first();
        if (! $p) return $this->error('NOT_FOUND', 'طلب الصرف غير موجود', 404);
        try {
            $handover = $this->payouts->confirmHandover($p, $merchant, $request->input('note'));
            return $this->ok(['handover' => $handover], 'HANDOVER_CONFIRMED', 'تم تأكيد استلام النقد.');
        } catch (\DomainException|RuntimeException $e) {
            return $this->error('HANDOVER_CONFIRM_FAILED', $e->getMessage(), 422);
        }
    }

    private function owner(Request $request): mixed
    {
        $user = $request->user();
        if (! $user || ! MerchantProfile::where('user_id', $user->id)->exists()) {
            return $this->error('OWNER_ONLY', 'طلب الصرف متاح لمالك المنشأة فقط', 403);
        }
        return $user;
    }

    private function serialize(MerchantPayoutRequest $p): array
    {
        return [
            'payout_ulid' => $p->payout_ulid, 'amount' => (string) $p->amount,
            'currency' => $p->currency, 'status' => $p->status,
            'request_note' => $p->request_note,
            'collection_instructions' => $p->collection_instructions,
            'handover_ulid' => $p->handover_ulid,
            'created_at' => optional($p->created_at)->toIso8601String(),
            'approved_at' => optional($p->approved_at)->toIso8601String(),
            'paid_at' => optional($p->paid_at)->toIso8601String(),
            'rejection_reason' => $p->rejection_reason,
        ];
    }
}
