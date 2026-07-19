<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantCurrency;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-MULTI-CURRENCY-001 — عملات التاجر (باقة التاجر برو فأعلى).
 * العملة الأساس ر.ي (rate=1 ضمناً)؛ يضيف التاجر عملات بأسعار صرف تظهر مقابلها
 * على الفواتير.
 *
 *   GET/POST  /merchant/currencies
 *   POST      /merchant/currencies/{id}
 *   POST      /merchant/currencies/{id}/toggle
 *   DELETE    /merchant/currencies/{id}
 */
class MerchantCurrencyController extends Controller
{
    public function __construct(private FeatureAccessService $access) {}

    private function guard(Request $request): mixed
    {
        $u = $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($u, A::F_MULTI_CURRENCY)) {
            return $this->error('FEATURE_LOCKED', 'تعدّد العملات متاح في باقة التاجر برو فأعلى', 402);
        }
        return $u;
    }

    public function index(Request $request): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;

        $list = MerchantCurrency::where('merchant_user_id', $m->id)->orderBy('code')->get()
            ->map(fn (MerchantCurrency $x) => $this->arr($x));
        return $this->ok(['base' => 'ر.ي', 'currencies' => $list, 'count' => $list->count()], 'OK', 'عملات التاجر');
    }

    public function store(Request $request): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;

        $v = Validator::make($request->all(), [
            'code' => 'required|string|max:8',
            'name' => 'required|string|max:40',
            'symbol' => 'sometimes|nullable|string|max:8',
            'rate_to_base' => 'required|numeric|min:0.000001',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        $code = strtoupper(trim($request->input('code')));
        if (MerchantCurrency::where('merchant_user_id', $m->id)->where('code', $code)->exists()) {
            return $this->error('DUP', 'العملة مضافة مسبقاً', 422);
        }

        $cur = MerchantCurrency::create([
            'merchant_user_id' => $m->id,
            'code' => $code,
            'name' => $request->input('name'),
            'symbol' => $request->input('symbol'),
            'rate_to_base' => $request->input('rate_to_base'),
            'is_active' => true,
        ]);
        return $this->ok(['currency' => $this->arr($cur)], 'CREATED', 'تمت الإضافة', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $cur = MerchantCurrency::where('id', $id)->where('merchant_user_id', $m->id)->first();
        if (!$cur) return $this->error('NOT_FOUND', 'العملة غير موجودة', 404);

        $v = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:40',
            'symbol' => 'sometimes|nullable|string|max:8',
            'rate_to_base' => 'sometimes|numeric|min:0.000001',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        foreach (['name', 'symbol', 'rate_to_base'] as $f) {
            if ($request->has($f)) $cur->{$f} = $request->input($f);
        }
        $cur->save();
        return $this->ok(['currency' => $this->arr($cur)], 'UPDATED', 'تم التحديث');
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $cur = MerchantCurrency::where('id', $id)->where('merchant_user_id', $m->id)->first();
        if (!$cur) return $this->error('NOT_FOUND', 'العملة غير موجودة', 404);
        $cur->is_active = !$cur->is_active;
        $cur->save();
        return $this->ok(['id' => $cur->id, 'is_active' => (bool) $cur->is_active], 'TOGGLED', 'تم');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $cur = MerchantCurrency::where('id', $id)->where('merchant_user_id', $m->id)->first();
        if (!$cur) return $this->error('NOT_FOUND', 'العملة غير موجودة', 404);
        $cur->delete();
        return $this->ok([], 'DELETED', 'تم الحذف');
    }

    private function arr(MerchantCurrency $x): array
    {
        return [
            'id' => $x->id, 'code' => $x->code, 'name' => $x->name,
            'symbol' => $x->symbol, 'rate_to_base' => (string) $x->rate_to_base,
            'is_active' => (bool) $x->is_active,
        ];
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse(['success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) []], $status);
    }
}
