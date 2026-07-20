<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantExpense;
use App\Services\FeatureAccessService;
use App\Services\MoneyService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-EXPENSES-001 — المصروفات والصندوق النثري (باقة الأعمال فأعلى).
 *
 *   GET    /merchant/expenses?from=&to=   القائمة + ملخّص
 *   POST   /merchant/expenses             تسجيل مصروف
 *   POST   /merchant/expenses/{id}        تعديل
 *   DELETE /merchant/expenses/{id}        حذف
 */
class ExpenseController extends Controller
{
    public function __construct(private FeatureAccessService $access) {}

    private function guard(Request $request): mixed
    {
        $u = $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return $this->err('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($u, A::F_EXPENSES)) {
            return $this->err('FEATURE_LOCKED', 'المصروفات متاحة في باقة الأعمال فأعلى', 402);
        }
        return $u;
    }

    public function index(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $q = MerchantExpense::where('merchant_user_id', $u->id);
        if ($request->filled('from')) $q->whereDate('spent_on', '>=', $request->query('from'));
        if ($request->filled('to')) $q->whereDate('spent_on', '<=', $request->query('to'));

        $items = $q->orderByDesc('spent_on')->orderByDesc('id')->limit(500)->get();

        $total = '0';
        $byCat = [];
        foreach ($items as $e) {
            $total = MoneyService::add($total, (string) $e->amount);
            $byCat[$e->category] = MoneyService::add($byCat[$e->category] ?? '0', (string) $e->amount);
        }

        return $this->ok([
            'expenses' => $items->map(fn ($e) => $this->arr($e)),
            'count' => $items->count(),
            'total' => MoneyService::normalize($total),
            'by_category' => array_map(fn ($v) => MoneyService::normalize($v), $byCat),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = $this->validatedData($request);
        if ($v instanceof JsonResponse) return $v;

        $e = MerchantExpense::create(array_merge($v, [
            'merchant_user_id' => $u->id,
            'created_by' => $u->id,
            'zone_code' => $u->zone_code ?? 'SOUTH',
        ]));
        return $this->ok(['expense' => $this->arr($e)], 'CREATED', 'سُجّل المصروف', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;
        $e = MerchantExpense::where('id', $id)->where('merchant_user_id', $u->id)->first();
        if (!$e) return $this->err('NOT_FOUND', 'المصروف غير موجود', 404);

        $v = $this->validatedData($request);
        if ($v instanceof JsonResponse) return $v;
        $e->update($v);
        return $this->ok(['expense' => $this->arr($e->fresh())], 'UPDATED', 'تم التعديل');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;
        $e = MerchantExpense::where('id', $id)->where('merchant_user_id', $u->id)->first();
        if (!$e) return $this->err('NOT_FOUND', 'المصروف غير موجود', 404);
        $e->delete();
        return $this->ok([], 'DELETED', 'تم الحذف');
    }

    private function validatedData(Request $request): array|JsonResponse
    {
        $v = Validator::make($request->all(), [
            'title' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'sometimes|nullable|in:' . implode(',', MerchantExpense::CATEGORIES),
            'spent_on' => 'sometimes|nullable|date',
            'note' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);
        $data = $v->validated();
        $data['category'] = $data['category'] ?? 'other';
        $data['spent_on'] = $data['spent_on'] ?? now()->toDateString();
        return $data;
    }

    private function arr(MerchantExpense $e): array
    {
        return [
            'id' => $e->id,
            'category' => $e->category,
            'title' => $e->title,
            'amount' => (string) $e->amount,
            'spent_on' => $e->spent_on?->toDateString(),
            'note' => $e->note,
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
