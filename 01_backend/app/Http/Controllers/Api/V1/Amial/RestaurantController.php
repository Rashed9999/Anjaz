<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\RestaurantOrder;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Services\Merchant\MerchantPermissionService;
use App\Services\RestaurantService;
use App\Support\Access\AccessConstants as A;
use App\Support\Merchant\MerchantPermissions as P;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-RESTAURANT-001 — المطاعم: طاولات + طلبات + شاشة مطبخ.
 *
 *   الطاولات: GET/POST /restaurant/tables · POST /tables/{id} · DELETE /tables/{id}
 *   الطلبات:  GET /restaurant/orders · POST /orders · GET/POST /orders/{id}
 *             POST /orders/{id}/status · POST /orders/{id}/close
 *   المطبخ:   GET /restaurant/kitchen
 *
 * ══════════════════════════════════════════════════════════════════════
 * AMIAL-VERTICAL-RBAC-001 — **والطلبُ يمرّ بأيدٍ ثلاث.**
 *
 * وقِيس قبل هذا التغيير: **أحدَ عشرَ فعلاً وصفرُ فحصِ صلاحيّة**. فطاهٍ
 * يُغلق طلباً — **والإغلاقُ قبضٌ**: يُنهي الطلبَ ويُثبت مبلغَه. ونادلٌ
 * يحذف طاولةً عليها طلبٌ مفتوح.
 *
 * فالنادلُ يفتح ويُعدّل، والمطبخُ يُغيّر الحالةَ ولا يمسّ الأصناف،
 * والكاشيرُ يُغلق ويقبض.
 */
class RestaurantController extends Controller
{
    public function __construct(
        private FeatureAccessService $access,
        private RestaurantService $svc,
        private MerchantPermissionService $perm,
    ) {}

    /** يفحص الصلاحيّة — ويردّ ٤٠٣ برسالةِ المحرّك، أو `null` فيمضي. */
    private function guard(Request $request, string $permission, ?string $amount = null): ?JsonResponse
    {
        try {
            $this->perm->assert($request->user(), $permission, [], $amount);

            return null;
        } catch (DomainException $e) {
            return $this->err('FORBIDDEN', $e->getMessage(), 403);
        }
    }

    // ---------- الطاولات ----------

    public function tables(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_TABLE_VIEW)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $list = RestaurantTable::where('merchant_user_id', $merchant->id)->orderBy('id')->get()
            ->map(fn ($t) => $this->tableArr($t));
        return $this->ok(['tables' => $list, 'count' => $list->count()]);
    }

    public function createTable(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_TABLE_MANAGE)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $v = Validator::make($request->all(), [
            'label' => 'required|string|max:60',
            'seats' => 'sometimes|integer|min:1|max:50',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $t = $this->svc->createTable($merchant, $request->input('label'), (int) $request->input('seats', 4));
        } catch (\InvalidArgumentException $e) {
            return $this->err('TABLE_INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['table' => $this->tableArr($t)], 'CREATED', 'تمت إضافة الطاولة', 201);
    }

    public function updateTable(Request $request, int $id): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_TABLE_MANAGE)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $t = RestaurantTable::where('id', $id)->where('merchant_user_id', $merchant->id)->first();
        if (!$t) return $this->err('NOT_FOUND', 'الطاولة غير موجودة', 404);

        $v = Validator::make($request->all(), [
            'label' => 'sometimes|string|max:60',
            'seats' => 'sometimes|integer|min:1|max:50',
            'is_active' => 'sometimes|boolean',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        $t->fill($request->only(['label', 'seats', 'is_active']));
        $t->save();
        return $this->ok(['table' => $this->tableArr($t)], 'UPDATED', 'تم التعديل');
    }

    public function deleteTable(Request $request, int $id): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_TABLE_MANAGE)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $t = RestaurantTable::where('id', $id)->where('merchant_user_id', $merchant->id)->first();
        if (!$t) return $this->err('NOT_FOUND', 'الطاولة غير موجودة', 404);
        if ($t->status === 'occupied') return $this->err('TABLE_BUSY', 'لا يمكن حذف طاولة مشغولة', 422);
        $t->delete();
        return $this->ok([], 'DELETED', 'تم الحذف');
    }

    // ---------- الطلبات ----------

    public function orders(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_ORDER_VIEW_ALL)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $list = $this->svc->activeOrders($merchant)->map(fn ($o) => $this->orderArr($o));
        return $this->ok(['orders' => $list, 'count' => $list->count()]);
    }

    public function openOrder(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_ORDER_OPEN)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $v = Validator::make($request->all(), [
            'table_id' => 'sometimes|nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:120',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
            'notes' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $o = $this->svc->openOrder($merchant, $request->input('table_id'),
                $request->input('items', []), $request->input('notes'), $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return $this->err('ORDER_INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['order' => $this->orderArr($o)], 'CREATED', 'تم فتح الطلب', 201);
    }

    public function showOrder(Request $request, int $id): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_ORDER_VIEW_ALL)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $o = RestaurantOrder::where('id', $id)->where('merchant_user_id', $merchant->id)->first();
        if (!$o) return $this->err('NOT_FOUND', 'الطلب غير موجود', 404);
        return $this->ok(['order' => $this->orderArr($o)]);
    }

    public function updateOrder(Request $request, int $id): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_ORDER_UPDATE)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $o = RestaurantOrder::where('id', $id)->where('merchant_user_id', $merchant->id)->first();
        if (!$o) return $this->err('NOT_FOUND', 'الطلب غير موجود', 404);

        $v = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:120',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
            'notes' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $o = $this->svc->updateItems($merchant, $o, $request->input('items', []), $request->input('notes'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->err('UPDATE_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['order' => $this->orderArr($o)], 'UPDATED', 'تم تعديل الطلب');
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_ORDER_STATUS)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $o = RestaurantOrder::where('id', $id)->where('merchant_user_id', $merchant->id)->first();
        if (!$o) return $this->err('NOT_FOUND', 'الطلب غير موجود', 404);

        $v = Validator::make($request->all(), ['status' => 'required|string']);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $o = $this->svc->setStatus($merchant, $o, $request->input('status'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->err('STATUS_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['order' => $this->orderArr($o)], 'STATUS_SET', 'تم تحديث الحالة');
    }

    public function closeOrder(Request $request, int $id): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_ORDER_CLOSE)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posId] = $ctx;
        $o = RestaurantOrder::where('id', $id)->where('merchant_user_id', $merchant->id)->first();
        if (!$o) return $this->err('NOT_FOUND', 'الطلب غير موجود', 404);

        $v = Validator::make($request->all(), [
            'payment_method' => 'required|in:cash,amial_pay,credit',
            'customer.name' => 'sometimes|nullable|string|max:120',
            'customer.phone' => 'sometimes|nullable|string|max:32',
            'paid_transaction_id' => 'sometimes|nullable|string|max:40',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $res = $this->svc->closeOrder($merchant, $o, $request->input('payment_method'),
                $request->input('customer'), $request->input('paid_transaction_id'), $posId,
                $request->user()->id);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->err('CLOSE_FAILED', $e->getMessage(), 422);
        }
        return $this->ok([
            'order' => $this->orderArr($res['order']),
            'sale' => $res['sale'],
        ], 'CLOSED', 'تم إغلاق الطلب وتسجيل الفاتورة');
    }

    public function kitchen(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::RESTAURANT_KITCHEN_VIEW)) {
            return $deny;
        }
        $ctx = $this->resolve($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $list = $this->svc->kitchenOrders($merchant)->map(fn ($o) => $this->orderArr($o));
        return $this->ok(['orders' => $list, 'count' => $list->count()]);
    }

    // ---------- helpers ----------

    /** يحلّ التاجر (أو موظف POS تابع له) ويتحقّق من ميزة المطاعم. */
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
        } elseif ($merchantId = DB::table('merchant_user_roles')
            ->where('user_id', $authUser->id)
            ->where('is_active', true)
            ->value('merchant_user_id')) {
            $merchant = User::find($merchantId);
            if (!$merchant) return $this->err('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
        } elseif (!MerchantProfile::where('user_id', $authUser->id)->exists()) {
            return $this->err('NOT_A_MERCHANT', 'متاح للمطاعم وموظفيها فقط', 403);
        }

        if (!$this->access->hasFeature($merchant, A::F_RESTAURANT_ORDERS)) {
            return $this->err('FEATURE_LOCKED', 'قطاع المطاعم متاح لحسابات المطاعم', 402);
        }
        return [$merchant, $posId];
    }

    private function tableArr(RestaurantTable $t): array
    {
        return [
            'id' => $t->id, 'label' => $t->label, 'seats' => (int) $t->seats,
            'status' => $t->status, 'is_active' => (bool) $t->is_active,
        ];
    }

    private function orderArr(RestaurantOrder $o): array
    {
        return [
            'id' => $o->id,
            'order_no' => $o->order_no,
            'table_id' => $o->table_id,
            'status' => $o->status,
            'items' => $o->items ?? [],
            'subtotal' => (string) $o->subtotal,
            'total' => (string) $o->total,
            'notes' => $o->notes,
            'opened_at' => $o->opened_at?->toIso8601String(),
            'sale_ulid' => $o->sale_ulid,
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
