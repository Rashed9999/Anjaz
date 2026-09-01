<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\CustomerCreditAccount;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\CustomerCreditService;
use App\Services\Merchant\MerchantPermissionService;
use App\Support\Merchant\MerchantPermissions as P;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-CUSTOMER-CREDIT-001 — نظام ديون العملاء (للتاجر).
 *
 *   GET  /api/v1/amial/merchant/credit/dashboard
 *   GET  /api/v1/amial/merchant/credit/customers              (?search=&filter=debtors|over_limit|paid_up)
 *   POST /api/v1/amial/merchant/credit/customers              (إنشاء/تحديث)
 *   GET  /api/v1/amial/merchant/credit/customers/{id}
 *   GET  /api/v1/amial/merchant/credit/customers/{id}/statement  (?from=&to=)
 *   POST /api/v1/amial/merchant/credit/customers/{id}/payment     (تسجيل سداد)
 *   POST /api/v1/amial/merchant/credit/customers/{id}/return      (تسجيل مرتجع)
 *   POST /api/v1/amial/merchant/credit/customers/{id}/adjustment  (تعديل يدوي)
 */
class CustomerCreditController extends Controller
{
    public function __construct(
        private readonly CustomerCreditService $credit,
        private readonly MerchantPermissionService $perm,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        if ($deny = $this->guard($request, P::LEDGER_VIEW)) return $deny;
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        return $this->ok($this->credit->dashboardSummary($merchant->id));
    }

    public function listCustomers(Request $request): JsonResponse
    {
        if ($deny = $this->guard($request, P::LEDGER_VIEW)) return $deny;
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $page = $this->credit->listCustomers(
            $merchant->id,
            $request->query('search'),
            $request->query('filter'),
        );

        return $this->ok([
            'customers' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function upsertCustomer(Request $request): JsonResponse
    {
        if ($deny = $this->guard($request, P::SETTINGS_MANAGE)) return $deny;
        $v = Validator::make($request->all(), [
            'phone' => 'required|string|max:32',
            'name' => 'required|string|max:120',
            'credit_limit' => 'sometimes|nullable|numeric|min:0',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        try {
            $account = $this->credit->findOrCreateAccount(
                $merchant->id,
                $request->input('phone'),
                $request->input('name'),
                $request->input('credit_limit'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('CUSTOMER_INVALID', $e->getMessage(), 422);
        }

        return $this->ok(['account' => $account], 'CUSTOMER_SAVED', 'تم حفظ العميل');
    }

    public function showCustomer(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->guard($request, P::LEDGER_VIEW)) return $deny;
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $account = CustomerCreditAccount::where('id', $id)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$account) return $this->error('NOT_FOUND', 'العميل غير موجود', 404);

        return $this->ok([
            'account' => $account,
            'utilization_percent' => $account->utilizationPercent(),
            'is_over_limit' => $account->isOverLimit(),
        ]);
    }

    public function statement(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->guard($request, P::LEDGER_VIEW)) return $deny;
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $account = CustomerCreditAccount::where('id', $id)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$account) return $this->error('NOT_FOUND', 'العميل غير موجود', 404);

        return $this->ok($this->credit->getStatement(
            $account,
            $request->query('from'),
            $request->query('to'),
        ));
    }

    /** AMIAL-CREDIT-PDF-001 — تصدير كشف الحساب PDF. */
    public function statementPdf(Request $request, int $id)
    {
        if ($deny = $this->guard($request, P::LEDGER_VIEW)) return $deny;
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $account = CustomerCreditAccount::where('id', $id)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$account) return $this->error('NOT_FOUND', 'العميل غير موجود', 404);

        $pdfSvc = app(\App\Services\CreditStatementPdfService::class);
        $from = $request->query('from');
        $to = $request->query('to');

        // AMIAL-PDF-CACHE-001: كشف الحساب مستند حيّ لا فاتورة ثابتة، فمفتاحه
        // يشمل ما يتبدّل بتبدّله: مدى التاريخ، ووقت آخر تعديل، والرصيد.
        // فأي دفعة تُسجَّل تُغيّر الرصيد فيُصيَّر الكشف من جديد. وخدمة كشف
        // قديم أسوأ من بطئه: رقمٌ خاطئ يظهر واثقاً ويُبنى عليه تحصيل.
        $pdf = app(\App\Services\PdfCacheService::class)->remember(
            "credit_stmt_{$account->id}_{$from}_{$to}"
                . "_{$account->updated_at?->timestamp}_{$account->current_balance}",
            fn () => $pdfSvc->generate($account, $from, $to),
        );
        $filename = $pdfSvc->suggestedFilename($account);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($pdf),
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->validationError($v);
        if ($deny = $this->guard($request, P::CASH_MOVE, (string) $request->input('amount'))) return $deny;

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posUserId] = $ctx;

        $account = CustomerCreditAccount::where('id', $id)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$account) return $this->error('NOT_FOUND', 'العميل غير موجود', 404);

        try {
            $movement = $this->credit->recordPayment(
                account: $account,
                amount: (string) $request->input('amount'),
                note: $request->input('note'),
                createdBy: $posUserId ?? $merchant->id,
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error('PAYMENT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['movement' => $movement, 'account' => $account->fresh()], 'PAYMENT_OK', 'تم تسجيل السداد');
    }

    public function recordReturn(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->validationError($v);
        if ($deny = $this->guard($request, P::RETAIL_RETURN_CREATE, (string) $request->input('amount'))) return $deny;

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posUserId] = $ctx;

        $account = CustomerCreditAccount::where('id', $id)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$account) return $this->error('NOT_FOUND', 'العميل غير موجود', 404);

        try {
            $movement = $this->credit->recordReturn(
                account: $account,
                amount: (string) $request->input('amount'),
                note: $request->input('note'),
                createdBy: $posUserId ?? $merchant->id,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('RETURN_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['movement' => $movement, 'account' => $account->fresh()], 'RETURN_OK', 'تم تسجيل المرتجع');
    }

    public function recordAdjustment(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'signed_amount' => 'required|string', // مثل "-1000" أو "+500"
            'note' => 'required|string|max:255',
        ]);
        if ($v->fails()) return $this->validationError($v);
        if ($deny = $this->guard($request, P::ADJUSTMENT_REQUEST)) return $deny;

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posUserId] = $ctx;

        $account = CustomerCreditAccount::where('id', $id)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$account) return $this->error('NOT_FOUND', 'العميل غير موجود', 404);

        try {
            $movement = $this->credit->recordAdjustment(
                account: $account,
                signedAmount: (string) $request->input('signed_amount'),
                note: $request->input('note'),
                createdBy: $posUserId ?? $merchant->id,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('ADJUSTMENT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['movement' => $movement, 'account' => $account->fresh()], 'ADJUSTMENT_OK', 'تم التعديل');
    }

    // ---- helpers ----

    private function guard(Request $request, string $permission, ?string $amount = null): ?JsonResponse
    {
        try {
            $this->perm->assert($request->user(), $permission, [], $amount);
            return null;
        } catch (DomainException $e) {
            return $this->error('FORBIDDEN', $e->getMessage(), 403);
        }
    }

    /** يرجّع [merchant(User), posUserId(?int)] أو JsonResponse عند الخطأ. */
    private function resolveMerchant(Request $request): array|JsonResponse
    {
        $authUser = $request->user();
        $pos = PosUser::where('user_id', $authUser->id)->where('is_active', true)->first();

        if ($pos) {
            $merchant = User::find($pos->merchant_user_id);
            if (!$merchant) return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
            return [$merchant, $pos->id];
        }
        if (!MerchantProfile::where('user_id', $authUser->id)->exists()) {
            return $this->error('NOT_A_MERCHANT', 'نظام الديون متاح للتجار وموظفي نقاط البيع فقط', 403);
        }
        return [$authUser, null];
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
