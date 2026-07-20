<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\InstallmentContract;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Services\InstallmentService;
use App\Support\Access\AccessConstants as A;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-INSTALLMENTS-001 — البيع بالتقسيط (باقة التاجر برو فأعلى).
 *
 * التاجر:  GET/POST /merchant/installments/plan   ·  POST .../quote
 *          POST .../contracts (إنشاء)  ·  GET .../contracts  ·  GET .../contracts/{id}
 * العميل:  GET /me/installments  ·  POST /me/installments/{id}/pay
 */
class InstallmentController extends Controller
{
    public function __construct(
        private FeatureAccessService $access,
        private InstallmentService $svc,
    ) {}

    // ================= جهة التاجر =================

    private function merchantGuard(Request $request): mixed
    {
        $u = $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return $this->err('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($u, A::F_INSTALLMENTS)) {
            return $this->err('FEATURE_LOCKED', 'البيع بالتقسيط متاح في باقة التاجر برو فأعلى', 402);
        }
        return $u;
    }

    public function plan(Request $request): JsonResponse
    {
        $u = $this->merchantGuard($request);
        if ($u instanceof JsonResponse) return $u;
        return $this->ok(['plan' => $this->planArr($this->svc->plan($u))]);
    }

    public function savePlan(Request $request): JsonResponse
    {
        $u = $this->merchantGuard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), [
            'is_active' => 'sometimes|boolean',
            'min_amount' => 'sometimes|numeric|min:0',
            'max_amount' => 'sometimes|numeric|min:0',
            'down_payment_percent' => 'sometimes|numeric|min:0|max:100',
            'durations' => 'sometimes|array',
            'durations.*' => 'integer|min:1|max:60',
            'markup_percent' => 'sometimes|numeric|min:0|max:100',
            'late_fee_percent' => 'sometimes|numeric|min:0|max:100',
            'grace_days' => 'sometimes|integer|min:0|max:60',
            'require_kyc' => 'sometimes|boolean',
            'require_guarantor' => 'sometimes|boolean',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        $p = $this->svc->savePlan($u, $request->all());
        return $this->ok(['plan' => $this->planArr($p)], 'SAVED', 'تم حفظ شروط التقسيط');
    }

    public function quote(Request $request): JsonResponse
    {
        $u = $this->merchantGuard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), [
            'principal' => 'required|numeric|min:0.01',
            'months' => 'required|integer|min:1|max:60',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        try {
            $q = $this->svc->quote($this->svc->plan($u), (float) $request->input('principal'), (int) $request->input('months'));
        } catch (\InvalidArgumentException $e) {
            return $this->err('QUOTE_INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['quote' => $q]);
    }

    public function createContract(Request $request): JsonResponse
    {
        $u = $this->merchantGuard($request);
        if ($u instanceof JsonResponse) return $u;

        $v = Validator::make($request->all(), [
            'customer_phone' => 'required|string|max:32',
            'principal' => 'required|numeric|min:0.01',
            'months' => 'required|integer|min:1|max:60',
            'guarantor_phone' => 'sometimes|nullable|string|max:32',
            'item_name' => 'sometimes|nullable|string|max:120',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        $customer = $this->resolveCustomer($request->input('customer_phone'));
        if (!$customer) return $this->err('CUSTOMER_NOT_FOUND', 'العميل غير مسجّل في أميال باي', 404);

        $guarantor = null;
        if ($request->filled('guarantor_phone')) {
            $guarantor = $this->resolveCustomer($request->input('guarantor_phone'));
            if (!$guarantor) return $this->err('GUARANTOR_NOT_FOUND', 'الكفيل غير مسجّل', 404);
        }

        try {
            $contract = $this->svc->createContract(
                $u, $customer, (float) $request->input('principal'), (int) $request->input('months'),
                $guarantor, $request->input('item_name'),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->err('CONTRACT_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['contract' => $this->contractArr($contract, true)], 'CREATED', 'تم إنشاء عقد التقسيط', 201);
    }

    public function contracts(Request $request): JsonResponse
    {
        $u = $this->merchantGuard($request);
        if ($u instanceof JsonResponse) return $u;
        $list = InstallmentContract::where('merchant_user_id', $u->id)->orderByDesc('id')->limit(200)->get()
            ->map(fn ($c) => $this->contractArr($c));
        return $this->ok(['contracts' => $list, 'count' => $list->count()]);
    }

    public function showContract(Request $request, int $id): JsonResponse
    {
        $u = $this->merchantGuard($request);
        if ($u instanceof JsonResponse) return $u;
        $c = InstallmentContract::where('id', $id)->where('merchant_user_id', $u->id)->with('schedules')->first();
        if (!$c) return $this->err('NOT_FOUND', 'العقد غير موجود', 404);
        return $this->ok(['contract' => $this->contractArr($c, true)]);
    }

    // ================= جهة العميل =================

    public function myContracts(Request $request): JsonResponse
    {
        $u = $request->user();
        $list = InstallmentContract::where('customer_user_id', $u->id)->orderByDesc('id')->limit(100)->get()
            ->map(fn ($c) => $this->contractArr($c));
        return $this->ok(['contracts' => $list, 'count' => $list->count()]);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $u = $request->user();
        $v = Validator::make($request->all(), ['amount' => 'required|numeric|min:0.01']);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        $contract = InstallmentContract::where('id', $id)->where('customer_user_id', $u->id)->first();
        if (!$contract) return $this->err('NOT_FOUND', 'العقد غير موجود', 404);

        try {
            $res = $this->svc->payInstallment($u, $contract, (string) $request->input('amount'));
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->err('PAY_FAILED', $e->getMessage(), 422);
        }
        return $this->ok($res, 'PAID', 'تم سداد القسط');
    }

    // ================= helpers =================

    private function resolveCustomer(string $phone): ?User
    {
        return User::where('type', 2)->whereIn('phone', Phone::variants($phone))->first();
    }

    private function planArr($p): array
    {
        return [
            'is_active' => (bool) $p->is_active,
            'min_amount' => (string) $p->min_amount,
            'max_amount' => (string) $p->max_amount,
            'down_payment_percent' => (string) $p->down_payment_percent,
            'durations' => $p->durations ?: [3, 6, 12],
            'markup_percent' => (string) $p->markup_percent,
            'late_fee_percent' => (string) $p->late_fee_percent,
            'grace_days' => (int) $p->grace_days,
            'require_kyc' => (bool) $p->require_kyc,
            'require_guarantor' => (bool) $p->require_guarantor,
        ];
    }

    private function contractArr(InstallmentContract $c, bool $withSchedule = false): array
    {
        $remaining = bcsub((string) $c->total_payable, (string) $c->paid_amount, 2);
        $arr = [
            'id' => $c->id,
            'item_name' => $c->item_name,
            'principal' => (string) $c->principal,
            'down_payment' => (string) $c->down_payment,
            'total_payable' => (string) $c->total_payable,
            'paid_amount' => (string) $c->paid_amount,
            'remaining' => $remaining,
            'months' => (int) $c->months,
            'monthly_amount' => (string) $c->monthly_amount,
            'status' => $c->status,
            'started_at' => $c->started_at?->toIso8601String(),
        ];
        if ($withSchedule) {
            $arr['schedules'] = $c->schedules->map(fn ($s) => [
                'seq' => $s->seq,
                'due_date' => $s->due_date?->toDateString(),
                'amount' => (string) $s->amount,
                'paid_amount' => (string) $s->paid_amount,
                'status' => $s->status,
            ]);
        }
        return $arr;
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
