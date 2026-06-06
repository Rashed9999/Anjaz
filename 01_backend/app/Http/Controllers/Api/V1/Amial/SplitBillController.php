<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\SplitBill;
use App\Models\SplitBillParticipant;
use App\Models\User;
use App\Services\SplitBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-SPLIT-BILL-001 — تقسيم الفاتورة.
 *
 *   POST /api/v1/amial/merchant/split-bills                       — التاجر/POS ينشئ
 *   GET  /api/v1/amial/merchant/split-bills/{ulid}                — عرض فاتورة
 *   GET  /api/v1/amial/split-bills/mine                           — حصص العميل المعلّقة
 *   POST /api/v1/amial/split-bills/participants/{id}/pay          — العميل يدفع حصته
 */
class SplitBillController extends Controller
{
    public function __construct(
        private readonly SplitBillService $service,
    ) {}

    /** التاجر أو موظف POS ينشئ فاتورة مقسّمة */
    public function create(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'total_amount' => 'required|numeric|min:0.01',
            'participants' => 'required|array|min:2',
            'participants.*' => 'required|string|min:6|max:32',
            'channel' => 'sometimes|in:qr,pos',
            'note' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $authUser = $request->user();

        // سياق التاجر: موظف POS → المالك هو التاجر، مع نسبة pos_user_id
        $pos = PosUser::where('user_id', $authUser->id)->where('is_active', true)->first();
        if ($pos) {
            $merchant = User::find($pos->merchant_user_id);
            $posUserId = $pos->id;
        } else {
            $merchant = $authUser;
            $posUserId = null;
            if (!MerchantProfile::where('user_id', $merchant->id)->exists()) {
                return $this->error('NOT_A_MERCHANT', 'الحساب ليس تاجراً', 422);
            }
        }

        if (!$merchant) {
            return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
        }

        try {
            $bill = $this->service->create(
                merchant: $merchant,
                totalAmount: (string)$request->input('total_amount'),
                participantPhones: $request->input('participants'),
                channel: $request->input('channel', 'qr'),
                posUserId: $posUserId,
                note: $request->input('note'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('SPLIT_INVALID', $e->getMessage(), 422);
        }

        return $this->ok(['split_bill' => $bill], 'SPLIT_CREATED', 'تم إنشاء الفاتورة المقسّمة');
    }

    public function show(Request $request, string $ulid): JsonResponse
    {
        $bill = SplitBill::where('split_ulid', $ulid)->with('participants')->first();
        if (!$bill) return $this->error('NOT_FOUND', 'الفاتورة غير موجودة', 404);

        // AUTHZ: التاجر، أو موظف POS التابع له، أو أحد المشاركين فقط
        $uid = $request->user()->id;
        $isMerchant = $bill->merchant_user_id === $uid;
        $isPos = $bill->pos_user_id !== null
            && PosUser::where('id', $bill->pos_user_id)->where('user_id', $uid)->exists();
        $isParticipant = $bill->participants->contains('customer_user_id', $uid);

        if (!$isMerchant && !$isPos && !$isParticipant) {
            return $this->error('FORBIDDEN', 'لا تملك صلاحية عرض هذه الفاتورة', 403);
        }

        return $this->ok(['split_bill' => $bill]);
    }

    /** حصص العميل المعلّقة (ما عليه دفعه) */
    public function mine(Request $request): JsonResponse
    {
        $shares = SplitBillParticipant::where('customer_user_id', $request->user()->id)
            ->where('status', 'pending')
            ->with('splitBill:id,split_ulid,merchant_user_id,total_amount,note,status')
            ->orderByDesc('id')
            ->get();

        return $this->ok(['shares' => $shares]);
    }

    /** العميل يدفع حصته */
    public function payShare(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->service->payShare(
                participantId: $id,
                payingUser: $request->user(),
                idempotencyKey: $request->header('Idempotency-Key'),
            );
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\RuntimeException $e) {
            return $this->error('SPLIT_PAY_FAILED', $e->getMessage(), 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('NOT_FOUND', 'الحصة غير موجودة', 404);
        }

        return $this->ok([
            'transaction_id' => $result['transaction_id'],
            'participant' => $result['participant'],
            'split_bill' => $result['bill'],
        ], 'SPLIT_PAY_OK', 'تم دفع حصتك بنجاح');
    }

    // ---- ردود منظّمة ----

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ], $status);
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
