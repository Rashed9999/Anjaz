<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Concerns\DeniesByPlan;
use App\Http\Controllers\Controller;
use App\Models\CorporateAccount;
use App\Models\CorporateAccountMember;
use App\Services\CorporateAccountService;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-CORPORATE-ACCOUNTS-001 — حسابات الشركات (B2B) للتاجر.
 * محميّة بميزة corporate_accounts (الباقة المؤسسية).
 *
 *   GET  /merchant/corporate/dashboard
 *   GET  /merchant/corporate/accounts
 *   POST /merchant/corporate/accounts
 *   GET  /merchant/corporate/accounts/{id}
 *   POST /merchant/corporate/accounts/{id}                 (تحديث)
 *   POST /merchant/corporate/accounts/{id}/members         (إضافة عضو)
 *   POST /merchant/corporate/accounts/{id}/charge          (شراء)
 *   POST /merchant/corporate/accounts/{id}/settle          (سداد)
 *   GET  /merchant/corporate/accounts/{id}/statement
 */
class CorporateAccountController extends Controller
{
    use DeniesByPlan;

    public function __construct(
        private FeatureAccessService $access,
        private CorporateAccountService $svc,
    ) {}

    private function guard(Request $request): mixed
    {
        $u = $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($u, A::F_CORPORATE_ACCOUNTS)) {
            return $this->error('FEATURE_LOCKED', 'حسابات الشركات متاحة في الباقة المؤسسية', 402);
        }
        return $u;
    }

    /** يجلب حساباً يملكه التاجر أو يرمي رداً 404. */
    private function ownedAccount($merchant, int $id): mixed
    {
        $account = CorporateAccount::where('id', $id)
            ->where('merchant_user_id', $merchant->id)->first();
        return $account ?: $this->error('NOT_FOUND', 'الحساب غير موجود', 404);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        return $this->ok($this->svc->dashboard($m->id), 'OK', 'ملخّص حسابات الشركات');
    }

    public function index(Request $request): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;

        $accounts = CorporateAccount::where('merchant_user_id', $m->id)
            ->orderByDesc('current_balance')->get()
            ->map(fn (CorporateAccount $a) => $this->accountArray($a));

        return $this->ok(['accounts' => $accounts, 'count' => $accounts->count()], 'OK', 'حسابات الشركات');
    }

    public function store(Request $request): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;

        $v = Validator::make($request->all(), [
            'company_name' => 'required|string|max:160',
            'contact_person' => 'sometimes|nullable|string|max:120',
            'contact_phone' => 'sometimes|nullable|string|max:20',
            'tax_number' => 'sometimes|nullable|string|max:40',
            'credit_limit' => 'required|numeric|min:0',
            'monthly_limit' => 'sometimes|nullable|numeric|min:0',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        // **والإنشاءُ يضبط الحدَّ أوّلَ مرّة** — فيمرّ بالقدرة نفسِها.
        // وحراسةُ التعديل وحدَه بابُ التفاف: يُنشأ الحسابُ بالحدّ المطلوب
        // فلا يُحتاج تعديلٌ أصلاً.
        if ($deny = $this->denyUnless($request, 'corporate_credit_limits')) {
            return $deny;
        }

        $account = $this->svc->createAccount($m, $request->all());
        return $this->ok(['account' => $this->accountArray($account)], 'CREATED', 'تم إنشاء الحساب', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $account = $this->ownedAccount($m, $id);
        if ($account instanceof JsonResponse) return $account;

        $members = $account->members()->orderBy('member_name')->get()
            ->map(fn (CorporateAccountMember $x) => [
                'id' => $x->id, 'member_name' => $x->member_name,
                'identifier' => $x->identifier, 'per_txn_limit' => (string) $x->per_txn_limit,
                'is_active' => (bool) $x->is_active,
            ]);

        return $this->ok([
            'account' => $this->accountArray($account),
            'members' => $members,
        ], 'OK', 'تفاصيل الحساب');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $account = $this->ownedAccount($m, $id);
        if ($account instanceof JsonResponse) return $account;

        $v = Validator::make($request->all(), [
            'company_name' => 'sometimes|string|max:160',
            'contact_person' => 'sometimes|nullable|string|max:120',
            'contact_phone' => 'sometimes|nullable|string|max:20',
            'tax_number' => 'sometimes|nullable|string|max:40',
            'credit_limit' => 'sometimes|numeric|min:0',
            'monthly_limit' => 'sometimes|nullable|numeric|min:0',
            'status' => 'sometimes|in:active,suspended',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        // ══════════════════════════════════════════════════════════════
        // **الحدُّ الائتمانيُّ قدرةٌ مستقلّةٌ عن فتح الحساب.**
        //
        // كان السطحُ كلُّه خلف `corporate_accounts` وحدَها، وكلتاهما
        // «مؤسسيّة» — فبدا محروساً. **لكنّ التقريرَ يقرأ الحارسَ باسمه**،
        // فقال «`corporate_credit_limits` بلا حارس» وهو صادقٌ حرفيّاً:
        // لا موضعَ في الشيفرة كلِّها يذكر هذه القدرة.
        //
        // وحراسةُ قدرةٍ باسم أختها تعمل **حتّى يفترق سعراهما يوماً** —
        // فيُرفع الحدُّ الائتمانيُّ إلى باقةٍ أعلى، ولا يتغيّر شيءٌ في
        // الواقع، ولا يُلاحظ أحد. فتُحرَس باسمها.
        if (array_key_exists('credit_limit', $request->all())
            || array_key_exists('monthly_limit', $request->all())) {
            if ($deny = $this->denyUnless($request, 'corporate_credit_limits')) {
                return $deny;
            }
        }

        $account = $this->svc->updateAccount($account, $request->all());
        return $this->ok(['account' => $this->accountArray($account)], 'UPDATED', 'تم التحديث');
    }

    public function addMember(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $account = $this->ownedAccount($m, $id);
        if ($account instanceof JsonResponse) return $account;

        $v = Validator::make($request->all(), [
            'member_name' => 'required|string|max:120',
            'identifier' => 'sometimes|nullable|string|max:40',
            'per_txn_limit' => 'sometimes|nullable|numeric|min:0',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        try {
            $member = $this->svc->addMember($account, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['id' => $member->id], 'MEMBER_ADDED', 'تمت إضافة العضو', 201);
    }

    public function charge(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $account = $this->ownedAccount($m, $id);
        if ($account instanceof JsonResponse) return $account;

        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'member_id' => 'sometimes|nullable|integer',
            'note' => 'sometimes|nullable|string|max:255',
            'reference_number' => 'sometimes|nullable|string|max:64',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        $member = null;
        if ($request->filled('member_id')) {
            $member = CorporateAccountMember::where('id', $request->input('member_id'))
                ->where('corporate_account_id', $account->id)->first();
        }

        try {
            $movement = $this->svc->recordCharge(
                account: $account,
                amount: (string) $request->input('amount'),
                member: $member,
                createdBy: $m->id,
                note: $request->input('note'),
                referenceNumber: $request->input('reference_number'),
            );
        } catch (\Throwable $e) {
            return $this->error('CHARGE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'balance_after' => (string) $movement->balance_after,
            'available' => $account->fresh()->availableCredit(),
        ], 'CHARGED', 'تم تسجيل الشراء على الحساب', 201);
    }

    public function settle(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $account = $this->ownedAccount($m, $id);
        if ($account instanceof JsonResponse) return $account;

        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|nullable|string|max:255',
            'reference_number' => 'sometimes|nullable|string|max:64',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        try {
            $movement = $this->svc->recordSettlement(
                account: $account,
                amount: (string) $request->input('amount'),
                createdBy: $m->id,
                note: $request->input('note'),
                referenceNumber: $request->input('reference_number'),
            );
        } catch (\Throwable $e) {
            return $this->error('SETTLE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['balance_after' => (string) $movement->balance_after], 'SETTLED', 'تم تسجيل السداد', 201);
    }

    public function statement(Request $request, int $id): JsonResponse
    {
        $m = $this->guard($request);
        if ($m instanceof JsonResponse) return $m;
        $account = $this->ownedAccount($m, $id);
        if ($account instanceof JsonResponse) return $account;

        $data = $this->svc->statement($account);
        $movements = $data['movements']->map(fn ($x) => [
            'type' => $x->type,
            'type_label' => match ($x->type) {
                'charge' => 'شراء', 'payment' => 'سداد', 'adjustment' => 'تعديل', default => $x->type,
            },
            'amount' => (string) $x->amount,
            'balance_after' => (string) $x->balance_after,
            'note' => $x->note,
            'reference_number' => $x->reference_number,
            'created_at' => $x->created_at?->toIso8601String(),
        ]);

        return $this->ok([
            'account' => $this->accountArray($data['account']),
            'movements' => $movements,
        ], 'OK', 'كشف حساب الشركة');
    }

    private function accountArray(CorporateAccount $a): array
    {
        return [
            'id' => $a->id,
            'account_code' => $a->account_code,
            'company_name' => $a->company_name,
            'contact_person' => $a->contact_person,
            'contact_phone' => $a->contact_phone,
            'credit_limit' => (string) $a->credit_limit,
            'current_balance' => (string) $a->current_balance,
            'available' => $a->availableCredit(),
            'status' => $a->status,
        ];
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }
}
