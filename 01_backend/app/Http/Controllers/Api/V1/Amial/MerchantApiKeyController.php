<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantApiKey;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * AMIAL-API-ACCESS-001 — إدارة مفاتيح API للتاجر (الباقة المؤسسية).
 * المفتاح الكامل يظهر مرّة واحدة فقط عند التوليد (يُخزَّن هاشه).
 *
 *   GET    /merchant/api-keys
 *   POST   /merchant/api-keys           (توليد)
 *   POST   /merchant/api-keys/{id}/toggle
 *   DELETE /merchant/api-keys/{id}
 */
class MerchantApiKeyController extends Controller
{
    public function __construct(private FeatureAccessService $access) {}

    private function guard(Request $request): mixed
    {
        $u = $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($u, A::F_API_ACCESS)) {
            return $this->error('FEATURE_LOCKED', 'الوصول عبر API متاح في الباقة المؤسسية', 402);
        }
        return $u;
    }

    public function index(Request $request): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;

        $keys = MerchantApiKey::where('merchant_user_id', $m->id)->orderByDesc('id')->get()
            ->map(fn (MerchantApiKey $k) => [
                'id' => $k->id, 'label' => $k->label,
                'masked' => $k->prefix . '••••••••',
                'is_active' => (bool) $k->is_active,
                'last_used_at' => $k->last_used_at?->toIso8601String(),
                'created_at' => $k->created_at?->toIso8601String(),
            ]);
        return $this->ok(['keys' => $keys, 'count' => $keys->count()], 'OK', 'مفاتيح API');
    }

    public function store(Request $request): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;

        $v = Validator::make($request->all(), ['label' => 'sometimes|nullable|string|max:60']);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        // المفتاح الكامل: amk_<random40>؛ نخزّن هاشه فقط
        $secret = Str::random(40);
        $prefix = 'amk_' . substr($secret, 0, 6);
        $full = 'amk_' . $secret;

        $key = MerchantApiKey::create([
            'merchant_user_id' => $m->id,
            'label' => $request->input('label') ?: 'مفتاح API',
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $full),
            'is_active' => true,
        ]);

        return $this->ok([
            'id' => $key->id,
            'api_key' => $full, // يُعرض مرّة واحدة فقط
            'note' => 'احفظ المفتاح الآن — لن يظهر مجدداً',
        ], 'CREATED', 'تم توليد المفتاح', 201);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $key = MerchantApiKey::where('id', $id)->where('merchant_user_id', $m->id)->first();
        if (!$key) return $this->error('NOT_FOUND', 'المفتاح غير موجود', 404);
        $key->is_active = !$key->is_active;
        $key->save();
        return $this->ok(['id' => $key->id, 'is_active' => (bool) $key->is_active], 'TOGGLED', 'تم');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $key = MerchantApiKey::where('id', $id)->where('merchant_user_id', $m->id)->first();
        if (!$key) return $this->error('NOT_FOUND', 'المفتاح غير موجود', 404);
        $key->delete();
        return $this->ok([], 'DELETED', 'تم الحذف');
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
