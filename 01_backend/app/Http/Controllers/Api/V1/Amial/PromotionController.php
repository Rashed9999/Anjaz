<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\FeatureAccessService;
use App\Services\PromotionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-PROMOTIONS-001 — إدارة العروض والخصومات (باقة ستارتر فأعلى).
 *
 *   GET    /merchant/promotions            القائمة
 *   POST   /merchant/promotions            إنشاء
 *   POST   /merchant/promotions/{id}       تعديل
 *   POST   /merchant/promotions/{id}/toggle
 *   DELETE /merchant/promotions/{id}
 *   POST   /merchant/promotions/apply      تقييم خصم لفاتورة (subtotal, code?)
 */
class PromotionController extends Controller
{
    public function __construct(
        private FeatureAccessService $access,
        private PromotionService $promos,
    ) {}

    private function guard(Request $request): mixed
    {
        $u = $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return $this->err('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($u, A::F_PROMOTIONS)) {
            return $this->err('FEATURE_LOCKED', 'العروض والخصومات متاحة في باقة ستارتر فأعلى', 402);
        }
        return $u;
    }

    public function index(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;
        $list = Promotion::where('merchant_user_id', $u->id)->orderByDesc('id')->get()
            ->map(fn (Promotion $p) => $this->arr($p));
        return $this->ok(['promotions' => $list, 'count' => $list->count()]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = $this->validatedData($request);
        if ($v instanceof JsonResponse) return $v;

        $p = Promotion::create(array_merge($v, [
            'merchant_user_id' => $u->id,
            'zone_code' => $u->zone_code ?? 'SOUTH',
        ]));
        return $this->ok(['promotion' => $this->arr($p)], 'CREATED', 'تم إنشاء العرض', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;
        $p = Promotion::where('id', $id)->where('merchant_user_id', $u->id)->first();
        if (!$p) return $this->err('NOT_FOUND', 'العرض غير موجود', 404);

        $v = $this->validatedData($request);
        if ($v instanceof JsonResponse) return $v;
        $p->update($v);
        return $this->ok(['promotion' => $this->arr($p->fresh())], 'UPDATED', 'تم التعديل');
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;
        $p = Promotion::where('id', $id)->where('merchant_user_id', $u->id)->first();
        if (!$p) return $this->err('NOT_FOUND', 'العرض غير موجود', 404);
        $p->is_active = !$p->is_active;
        $p->save();
        return $this->ok(['is_active' => (bool) $p->is_active], 'TOGGLED', 'تم');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;
        $p = Promotion::where('id', $id)->where('merchant_user_id', $u->id)->first();
        if (!$p) return $this->err('NOT_FOUND', 'العرض غير موجود', 404);
        $p->delete();
        return $this->ok([], 'DELETED', 'تم الحذف');
    }

    /** تقييم الخصم لفاتورة (معاينة للكاشير قبل الدفع). */
    public function apply(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), [
            'subtotal' => 'required|numeric|min:0.01',
            'code' => 'sometimes|nullable|string|max:40',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        $res = $this->promos->evaluate($u, (float) $request->input('subtotal'), $request->input('code'));
        if (!$res) {
            return $this->ok(['discount' => '0', 'promotion_id' => null],
                'NO_PROMO', 'لا يوجد خصم منطبق');
        }
        return $this->ok([
            'discount' => (string) $res['discount'],
            'promotion_id' => $res['promotion']->id,
            'label' => $res['label'],
        ], 'PROMO_OK', 'خصم منطبق: ' . $res['label']);
    }

    private function validatedData(Request $request): array|JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0.01',
            'code' => 'sometimes|nullable|string|max:40',
            'min_order_amount' => 'sometimes|nullable|numeric|min:0',
            'max_discount_amount' => 'sometimes|nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'starts_at' => 'sometimes|nullable|date',
            'ends_at' => 'sometimes|nullable|date',
            'usage_limit' => 'sometimes|nullable|integer|min:1',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);
        $data = $v->validated();
        if (($data['type'] ?? '') === 'percent' && (float) ($data['value'] ?? 0) > 100) {
            return $this->err('VALIDATION', 'النسبة لا تتجاوز 100%', 422);
        }
        return $data;
    }

    private function arr(Promotion $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type,
            'value' => (string) $p->value,
            'code' => $p->code,
            'min_order_amount' => (string) $p->min_order_amount,
            'max_discount_amount' => $p->max_discount_amount !== null ? (string) $p->max_discount_amount : null,
            'is_active' => (bool) $p->is_active,
            'usage_limit' => $p->usage_limit,
            'used_count' => $p->used_count,
            'starts_at' => $p->starts_at?->toIso8601String(),
            'ends_at' => $p->ends_at?->toIso8601String(),
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
