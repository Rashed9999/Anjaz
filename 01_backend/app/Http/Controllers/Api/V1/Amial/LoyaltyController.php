<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyAccount;
use App\Services\FeatureAccessService;
use App\Services\LoyaltyService;
use App\Support\Access\AccessConstants as A;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-LOYALTY-001 — إدارة برنامج الولاء (باقة الأعمال فأعلى).
 *
 *   GET  /merchant/loyalty/program          إعداد البرنامج
 *   POST /merchant/loyalty/program          حفظ الإعداد
 *   GET  /merchant/loyalty/accounts         أعلى العملاء نقاطاً
 *   GET  /merchant/loyalty/lookup?phone=    رصيد عميل (للكاشير)
 *   POST /merchant/loyalty/redeem           استبدال نقاط بخصم
 *   POST /merchant/loyalty/adjust           تعديل يدوي
 */
class LoyaltyController extends Controller
{
    public function __construct(
        private FeatureAccessService $access,
        private LoyaltyService $loyalty,
    ) {}

    private function guard(Request $request): mixed
    {
        $u = $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return $this->err('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($u, A::F_LOYALTY)) {
            return $this->err('FEATURE_LOCKED', 'برنامج الولاء متاح في باقة الأعمال فأعلى', 402);
        }
        return $u;
    }

    public function program(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;
        $p = $this->loyalty->program($u);
        return $this->ok(['program' => $this->programArr($p)]);
    }

    public function saveProgram(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), [
            'is_active' => 'sometimes|boolean',
            'earn_points_per_100' => 'sometimes|numeric|min:0|max:1000',
            'redeem_value_per_point' => 'sometimes|numeric|min:0|max:100000',
            'min_redeem_points' => 'sometimes|integer|min:0',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        $p = $this->loyalty->saveProgram($u, $request->all());
        return $this->ok(['program' => $this->programArr($p)], 'SAVED', 'تم حفظ إعداد البرنامج');
    }

    public function accounts(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $accounts = LoyaltyAccount::where('merchant_user_id', $u->id)
            ->orderByDesc('points_balance')->limit(200)->get()
            ->map(fn (LoyaltyAccount $a) => [
                'id' => $a->id,
                'customer_name' => $a->customer_name,
                'customer_phone' => $a->customer_phone,
                'points_balance' => (string) $a->points_balance,
                'total_earned' => (string) $a->total_earned,
                'total_redeemed' => (string) $a->total_redeemed,
            ]);
        return $this->ok(['accounts' => $accounts, 'count' => $accounts->count()]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $phone = (string) $request->query('phone', '');
        if ($phone === '') return $this->err('VALIDATION', 'أدخل رقم الهاتف', 422);

        $a = LoyaltyAccount::where('merchant_user_id', $u->id)
            ->where('customer_phone', Phone::canonical($phone))->first();
        $program = $this->loyalty->program($u);

        return $this->ok([
            'found' => (bool) $a,
            'points_balance' => (string) ($a->points_balance ?? '0'),
            'customer_name' => $a->customer_name ?? null,
            'redeem_value_per_point' => (string) $program->redeem_value_per_point,
            'min_redeem_points' => (int) $program->min_redeem_points,
            'estimated_value' => (string) round((float) ($a->points_balance ?? 0) * (float) $program->redeem_value_per_point, 2),
        ]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), [
            'phone' => 'required|string|max:32',
            'points' => 'required|numeric|min:0.01',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $res = $this->loyalty->redeem($u, $request->input('phone'), (float) $request->input('points'), $u->id);
        } catch (\RuntimeException $e) {
            return $this->err('REDEEM_FAILED', $e->getMessage(), 422);
        }
        return $this->ok([
            'discount' => $res['discount'],
            'points_balance' => (string) $res['account']->points_balance,
        ], 'REDEEMED', 'تم استبدال النقاط — طبّق الخصم على الفاتورة');
    }

    public function adjust(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), [
            'phone' => 'required|string|max:32',
            'points' => 'required|numeric',
            'note' => 'sometimes|nullable|string|max:120',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $a = $this->loyalty->adjust($u, $request->input('phone'),
                (float) $request->input('points'), (string) $request->input('note', ''), $u->id);
        } catch (\RuntimeException $e) {
            return $this->err('ADJUST_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['points_balance' => (string) $a->points_balance], 'ADJUSTED', 'تم التعديل');
    }

    private function programArr($p): array
    {
        return [
            'is_active' => (bool) $p->is_active,
            'earn_points_per_100' => (string) $p->earn_points_per_100,
            'redeem_value_per_point' => (string) $p->redeem_value_per_point,
            'min_redeem_points' => (int) $p->min_redeem_points,
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
