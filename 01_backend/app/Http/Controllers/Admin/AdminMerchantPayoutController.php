<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantPayoutRequest;
use App\Services\MerchantPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** طابور إداري: لا يُصرف طلب التاجر عند الاعتماد، بل بعد تسليم موثّق. */
class AdminMerchantPayoutController extends Controller
{
    public function __construct(private readonly MerchantPayoutService $payouts) {}

    public function index(Request $request): JsonResponse
    {
        $q = MerchantPayoutRequest::with('merchant:id,f_name,l_name,phone')->latest();
        if (($status = $request->query('status')) && $status !== 'all') $q->where('status', $status);
        $items = $q->paginate(min(100, max(10, (int) $request->query('per_page', 30))));
        return response()->json(['success' => true, 'code' => 'OK', 'message' => 'طلبات صرف التجار',
            'errors' => (object) [], 'meta' => ['requests' => $items->items(), 'pagination' => [
                'current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total(),
            ]]]);
    }

    public function approve(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), ['collection_instructions' => 'required|string|min:5|max:1000']);
        if ($v->fails()) return $this->validationError($v);
        return $this->run($request, $ulid, fn ($p) => $this->payouts->approve($p, $request->user(),
            (string) $request->input('collection_instructions')));
    }

    public function reject(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:5|max:500']);
        if ($v->fails()) return $this->validationError($v);
        return $this->run($request, $ulid, fn ($p) => $this->payouts->reject($p, $request->user(),
            (string) $request->input('reason')));
    }

    public function markPaid(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), ['location' => 'sometimes|nullable|string|max:160']);
        if ($v->fails()) return $this->validationError($v);
        return $this->run($request, $ulid, fn ($p) => $this->payouts->markPaid($p, $request->user(),
            $request->input('location')));
    }

    private function run(Request $request, string $ulid, callable $operation): JsonResponse
    {
        $p = MerchantPayoutRequest::where('payout_ulid', $ulid)->first();
        if (! $p) return $this->error('NOT_FOUND', 'طلب الصرف غير موجود', 404);
        try {
            $p = $operation($p);
            return response()->json(['success' => true, 'code' => 'OK', 'message' => 'تم تحديث طلب الصرف',
                'errors' => (object) [], 'meta' => ['request' => $p]]);
        } catch (RuntimeException|\DomainException $e) {
            return $this->error('PAYOUT_ACTION_FAILED', $e->getMessage(), 422);
        }
    }

    private function validationError($v): JsonResponse
    {
        return $this->error('VALIDATION_FAILED', 'بيانات غير صحيحة', 422, $v->errors());
    }

    private function error(string $code, string $message, int $status, mixed $errors = null): JsonResponse
    {
        return response()->json(['success' => false, 'code' => $code, 'message' => $message,
            'errors' => $errors ?? (object) [], 'meta' => (object) []], $status);
    }
}
