<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Services\FeatureAccessService;
use App\Services\GiftCardService;
use App\Support\Access\AccessConstants as A;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-GIFT-CARDS-001 — بطاقات الهدايا (باقة الأعمال فأعلى).
 *
 * التاجر: GET/POST /merchant/gift-cards · GET /gift-cards/lookup?code=
 *         POST /gift-cards/redeem · POST /gift-cards/topup · POST /gift-cards/void
 * العميل: GET /me/gift-cards (البطاقات الصادرة لهاتفه)
 */
class GiftCardController extends Controller
{
    public function __construct(
        private FeatureAccessService $access,
        private GiftCardService $svc,
    ) {}

    private function guard(Request $request): mixed
    {
        $u = $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return $this->err('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($u, A::F_GIFT_CARDS)) {
            return $this->err('FEATURE_LOCKED', 'بطاقات الهدايا متاحة في باقة الأعمال فأعلى', 402);
        }
        return $u;
    }

    public function index(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;
        $list = GiftCard::where('merchant_user_id', $u->id)->orderByDesc('id')->limit(200)->get()
            ->map(fn ($c) => $this->arr($c));
        return $this->ok(['cards' => $list, 'count' => $list->count()]);
    }

    public function issue(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'phone' => 'sometimes|nullable|string|max:32',
            'name' => 'sometimes|nullable|string|max:120',
            'expires_at' => 'sometimes|nullable|date',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        $card = $this->svc->issue($u, (string) $request->input('amount'), [
            'phone' => $request->input('phone'),
            'name' => $request->input('name'),
            'expires_at' => $request->input('expires_at'),
        ]);
        return $this->ok(['card' => $this->arr($card, true)], 'ISSUED', 'تم إصدار البطاقة', 201);
    }

    public function lookup(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;
        $code = (string) $request->query('code', '');
        if ($code === '') return $this->err('VALIDATION', 'أدخل كود البطاقة', 422);
        $card = $this->svc->findByCode($u, $code);
        if (!$card) return $this->err('NOT_FOUND', 'البطاقة غير موجودة', 404);
        return $this->ok(['card' => $this->arr($card)]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), [
            'code' => 'required|string|max:40',
            'amount' => 'required|numeric|min:0.01',
            'sale_ulid' => 'sometimes|nullable|string|max:40',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $res = $this->svc->redeem($u, $request->input('code'), (string) $request->input('amount'), $request->input('sale_ulid'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->err('REDEEM_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['applied' => $res['applied'], 'balance' => $res['balance']], 'REDEEMED', 'تم الاستبدال');
    }

    public function topUp(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), [
            'code' => 'required|string|max:40',
            'amount' => 'required|numeric|min:0.01',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $card = $this->svc->topUp($u, $request->input('code'), (string) $request->input('amount'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->err('TOPUP_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['card' => $this->arr($card)], 'TOPPED_UP', 'تم الشحن');
    }

    public function void(Request $request): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), ['code' => 'required|string|max:40']);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $card = $this->svc->void($u, $request->input('code'));
        } catch (\RuntimeException $e) {
            return $this->err('VOID_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['card' => $this->arr($card)], 'VOIDED', 'تم الإلغاء');
    }

    /** جهة العميل: البطاقات الصادرة لهاتفه. */
    public function mine(Request $request): JsonResponse
    {
        $u = $request->user();
        $phone = Phone::canonical($u->phone ?? '');
        $list = GiftCard::where('issued_to_phone', $phone)->orderByDesc('id')->limit(100)->get()
            ->map(fn ($c) => [
                'code' => $c->code,
                'balance' => (string) $c->balance,
                'status' => $c->status,
                'expires_at' => $c->expires_at?->toDateString(),
            ]);
        return $this->ok(['cards' => $list, 'count' => $list->count()]);
    }

    private function arr(GiftCard $c, bool $full = false): array
    {
        $a = [
            'id' => $c->id,
            'code' => $c->code,
            'balance' => (string) $c->balance,
            'initial_balance' => (string) $c->initial_balance,
            'status' => $c->status,
            'issued_to_name' => $c->issued_to_name,
            'issued_to_phone' => $c->issued_to_phone,
            'expires_at' => $c->expires_at?->toDateString(),
        ];
        return $a;
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
