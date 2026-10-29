<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformProfitPayout;
use App\Services\PlatformProfitPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** واجهة خزينة الأرباح — الاعتماد نفسه يبقى في مركز الموافقات المركزي. */
class PlatformProfitPayoutController extends Controller
{
    public function __construct(private readonly PlatformProfitPayoutService $payouts) {}

    public function index(Request $request): JsonResponse
    {
        $query = PlatformProfitPayout::with(['requester:id,f_name,l_name', 'approval:id,request_number,status'])
            ->latest();
        if (($status = $request->query('status')) && $status !== 'all') {
            $query->where('status', $status);
        }
        $items = $query->paginate(min(100, max(10, (int) $request->query('per_page', 30))));

        return $this->ok([
            'summary' => $this->payouts->summary(),
            'requests' => $items->items(),
            'pagination' => [
                'current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total(),
            ],
        ], 'خزينة وأرباح المنصة');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'payout_method' => ['required', 'in:cash,bank'],
            'beneficiary_name' => ['required', 'string', 'min:3', 'max:200'],
            'destination_reference' => ['required', 'string', 'min:3', 'max:500'],
            'request_note' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        if ($validator->fails()) return $this->error('VALIDATION_FAILED', 'بيانات طلب الصرف غير صحيحة', 422, $validator->errors());

        try {
            $payout = $this->payouts->request($request->user(), $validator->validated(), $request->header('Idempotency-Key'));
            return $this->ok(['request' => $payout, 'approval_request' => $payout->approval],
                'أُنشئ طلب الصرف؛ لا مال تحرك قبل اعتماد مشرف آخر', 202);
        } catch (RuntimeException|\DomainException $e) {
            return $this->error('PAYOUT_REQUEST_FAILED', $e->getMessage(), 422);
        }
    }

    public function markPaid(Request $request, string $ulid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_reference' => ['required', 'string', 'min:3', 'max:160'],
            'proof_reference' => ['required', 'string', 'min:3', 'max:500'],
            'location' => ['nullable', 'string', 'max:160'],
        ]);
        if ($validator->fails()) return $this->error('VALIDATION_FAILED', 'مرجع الدفع وإثباته إلزاميان', 422, $validator->errors());

        $payout = PlatformProfitPayout::where('payout_ulid', $ulid)->first();
        if (! $payout) return $this->error('NOT_FOUND', 'طلب صرف الأرباح غير موجود', 404);
        try {
            return $this->ok(['request' => $this->payouts->markPaid(
                $payout, $request->user(), $validator->validated()['payment_reference'],
                $validator->validated()['proof_reference'], $validator->validated()['location'] ?? null,
            )], 'تم الدفع وتسجيل القيد والإثبات');
        } catch (RuntimeException|\DomainException $e) {
            return $this->error('PAYOUT_PAYMENT_FAILED', $e->getMessage(), 422);
        }
    }

    public function confirmCash(Request $request, string $ulid): JsonResponse
    {
        $payout = PlatformProfitPayout::where('payout_ulid', $ulid)->first();
        if (! $payout) return $this->error('NOT_FOUND', 'طلب صرف الأرباح غير موجود', 404);
        try {
            return $this->ok(['handover' => $this->payouts->confirmCashHandover(
                $payout, $request->user(), $request->input('note'),
            )], 'أكد المستفيد استلام النقد');
        } catch (RuntimeException|\DomainException $e) {
            return $this->error('HANDOVER_CONFIRM_FAILED', $e->getMessage(), 422);
        }
    }

    private function ok(array $meta, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'code' => 'OK', 'message' => $message,
            'errors' => (object) [], 'meta' => $meta], $status);
    }

    private function error(string $code, string $message, int $status, mixed $errors = null): JsonResponse
    {
        return response()->json(['success' => false, 'code' => $code, 'message' => $message,
            'errors' => $errors ?? (object) [], 'meta' => (object) []], $status);
    }
}
