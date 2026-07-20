<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-SHIFT-CLOSE-001 — ورديات الكاشير ودرج النقد (باقة الأعمال فأعلى).
 *
 *   GET  /cashier/shift            الوردية المفتوحة (إن وُجدت)
 *   POST /cashier/shift/open       بدء وردية (opening_float)
 *   GET  /cashier/shift/x          تقرير X (لحظي)
 *   POST /cashier/shift/close      تقرير Z (إقفال + جرد counted_cash)
 *   GET  /cashier/shift/history    آخر الورديات المُقفلة
 */
class CashierShiftController extends Controller
{
    public function __construct(
        private FeatureAccessService $access,
        private CashierShiftService $svc,
    ) {}

    public function current(Request $request): JsonResponse
    {
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posId] = $ctx;
        $shift = $this->svc->current($merchant, $posId);
        return $this->ok(['shift' => $shift ? $this->arr($shift) : null]);
    }

    public function open(Request $request): JsonResponse
    {
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posId] = $ctx;

        $v = Validator::make($request->all(), ['opening_float' => 'sometimes|numeric|min:0']);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $shift = $this->svc->open($merchant, $posId, (string) $request->input('opening_float', '0'));
        } catch (\RuntimeException $e) {
            return $this->err('OPEN_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['shift' => $this->arr($shift)], 'OPENED', 'بدأت الوردية', 201);
    }

    public function xReport(Request $request): JsonResponse
    {
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posId] = $ctx;
        $shift = $this->svc->current($merchant, $posId);
        if (!$shift) return $this->err('NO_SHIFT', 'لا توجد وردية مفتوحة', 404);
        return $this->ok(['report' => $this->svc->snapshot($shift)]);
    }

    public function close(Request $request): JsonResponse
    {
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posId] = $ctx;

        $v = Validator::make($request->all(), [
            'counted_cash' => 'required|numeric|min:0',
            'notes' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        $shift = $this->svc->current($merchant, $posId);
        if (!$shift) return $this->err('NO_SHIFT', 'لا توجد وردية مفتوحة', 404);

        try {
            $shift = $this->svc->close($shift, (string) $request->input('counted_cash'), $request->input('notes'));
        } catch (\RuntimeException $e) {
            return $this->err('CLOSE_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['shift' => $this->arr($shift)], 'CLOSED', 'أُقفلت الوردية');
    }

    public function history(Request $request): JsonResponse
    {
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $list = CashierShift::where('merchant_user_id', $merchant->id)
            ->where('status', 'closed')->orderByDesc('id')->limit(60)->get()
            ->map(fn ($s) => $this->arr($s));
        return $this->ok(['shifts' => $list, 'count' => $list->count()]);
    }

    private function resolve(Request $request): array|JsonResponse
    {
        $authUser = $request->user();
        $merchant = $authUser;
        $posId = null;
        $pos = PosUser::where('user_id', $authUser->id)->where('is_active', true)->first();
        if ($pos) {
            $merchant = User::find($pos->merchant_user_id);
            if (!$merchant) return $this->err('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
            $posId = $pos->id;
        } elseif (!MerchantProfile::where('user_id', $authUser->id)->exists()) {
            return $this->err('NOT_A_MERCHANT', 'متاح للتجّار وموظفيهم فقط', 403);
        }
        if (!$this->access->hasFeature($merchant, A::F_SHIFT_CLOSE)) {
            return $this->err('FEATURE_LOCKED', 'إقفال الوردية متاح في باقة الأعمال فأعلى', 402);
        }
        return [$merchant, $posId];
    }

    private function arr(CashierShift $s): array
    {
        return [
            'id' => $s->id,
            'opening_float' => (string) $s->opening_float,
            'cash_sales' => (string) $s->cash_sales,
            'sales_count' => (int) $s->sales_count,
            'expected_cash' => $s->expected_cash !== null ? (string) $s->expected_cash : null,
            'counted_cash' => $s->counted_cash !== null ? (string) $s->counted_cash : null,
            'variance' => $s->variance !== null ? (string) $s->variance : null,
            'status' => $s->status,
            'notes' => $s->notes,
            'opened_at' => $s->opened_at?->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
        ];
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse(['success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta], $status);
    }

    private function err(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) []], $status);
    }
}
