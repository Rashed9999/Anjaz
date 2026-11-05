<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantExpense;
use App\Models\MerchantExpenseReversal;
use App\Models\Retail\ShiftCashMovement;
use App\Services\FeatureAccessService;
use App\Services\MoneyService;
use App\Services\Retail\MerchantShiftCashService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-EXPENSES-002 — المصروفات مع مصدر النقد وسجل تصحيح لا يمحو الماضي.
 *
 * المصروف ليس حركة محفظة أميال تلقائياً. إن خرج من درج POS يختار المالك
 * الوردية صراحةً، فيدخل الخروج في معادلة الإغلاق. وإن دُفع من الخارج فلا
 * نخصم من درج موظف بالتخمين.
 *
 * الحقول المالية بعد التسجيل immutable. الخطأ يُلغى بسببٍ مكتوب، ولا يُحذف
 * صف كان قد أثّر في تقرير أو وردية.
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
        if (!$request->boolean('include_voided')) {
            $q->where('status', 'active');
        }
        if ($request->filled('from')) $q->whereDate('spent_on', '>=', $request->query('from'));
        if ($request->filled('to')) $q->whereDate('spent_on', '<=', $request->query('to'));

        $items = $q->orderByDesc('spent_on')->orderByDesc('id')->limit(500)->get();

        $active = $items->where('status', 'active');
        $total = '0';
        $byCat = [];
        foreach ($active as $e) {
            $total = MoneyService::add($total, (string) $e->amount);
            $byCat[$e->category] = MoneyService::add(
                $byCat[$e->category] ?? '0', (string) $e->amount
            );
        }

        return $this->ok([
            'expenses' => $items->map(fn ($e) => $this->arr($e))->values(),
            'count' => $active->count(),
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

        try {
            $e = DB::transaction(function () use ($request, $u, $v) {
                $shift = $this->lockCashierShiftForOutflow(
                    $request, $u->id, (string) $v['amount']
                );

                $e = MerchantExpense::create([
                    'expense_ulid' => (string) Str::ulid(),
                    'merchant_user_id' => $u->id,
                    'category' => $v['category'],
                    'title' => $v['title'],
                    'amount' => $v['amount'],
                    'payment_source' => $shift ? 'cash_shift' : 'external',
                    'cashier_shift_id' => $shift?->id,
                    'status' => 'active',
                    'spent_on' => $v['spent_on'],
                    'note' => $v['note'] ?? null,
                    'created_by' => $u->id,
                    'zone_code' => $u->zone_code ?? 'SOUTH',
                ]);

                if ($shift) {
                    app(MerchantShiftCashService::class)->record(
                        ShiftCashMovement::CASHIER,
                        $shift->id,
                        $u,
                        'out',
                        'expense',
                        (string) $e->amount,
                        $e->title,
                        'EXPENSE-'.$e->expense_ulid,
                        $u->id,
                    );
                }

                app(\App\Services\AuditService::class)->record([
                    'actor_type' => 'merchant',
                    'actor_user_id' => $u->id,
                    'subject_type' => 'merchant_expense',
                    'subject_id' => $e->expense_ulid,
                    'action' => 'MERCHANT_EXPENSE_RECORDED',
                    'decision_code' => 'RECORDED',
                    'severity' => 'info',
                    'context' => [
                        'amount' => (string) $e->amount,
                        'category' => $e->category,
                        'payment_source' => $e->payment_source,
                        'cashier_shift_id' => $e->cashier_shift_id,
                    ],
                ]);

                return $e;
            }, 3);
        } catch (RuntimeException $e) {
            return $this->err('EXPENSE_CASH_SOURCE_INVALID', $e->getMessage(), 422);
        }

        return $this->ok(['expense' => $this->arr($e)], 'CREATED', 'سُجّل المصروف', 201);
    }

    /**
     * الوصف والتصنيف والملاحظة قابلة للتصحيح، أما المبلغ/التاريخ/مصدر النقد
     * فلا تُكتب فوقها. الخطأ المالي يُلغى ثم يُسجّل قيد صحيح جديد.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $e = MerchantExpense::where('id', $id)
            ->where('merchant_user_id', $u->id)->first();
        if (!$e) return $this->err('NOT_FOUND', 'المصروف غير موجود', 404);
        if ($e->status !== 'active') {
            return $this->err('EXPENSE_VOIDED', 'المصروف ملغى ولا يُعدّل', 409);
        }

        if ($request->has('amount')
            && bccomp((string) $request->input('amount'), (string) $e->amount, 2) !== 0) {
            return $this->err('EXPENSE_FINANCIAL_FIELDS_IMMUTABLE',
                'لا يُعدّل مبلغ مصروف مالي بعد تسجيله؛ ألغِ القيد بسبب واضح ثم سجّل الصحيح', 422);
        }
        if ($request->has('spent_on')
            && (string) $request->input('spent_on') !== $e->spent_on?->toDateString()) {
            return $this->err('EXPENSE_FINANCIAL_FIELDS_IMMUTABLE',
                'لا يُعدّل تاريخ المصروف بعد تسجيله؛ ألغِ القيد ثم سجّل الصحيح', 422);
        }
        if ($request->has('cashier_shift_id')
            && (int) $request->input('cashier_shift_id') !== (int) ($e->cashier_shift_id ?? 0)) {
            return $this->err('EXPENSE_FINANCIAL_FIELDS_IMMUTABLE',
                'لا يمكن نقل مصروف مسجّل إلى درج وردية أخرى', 422);
        }

        $v = Validator::make($request->all(), [
            'title' => 'required|string|max:160',
            'category' => 'sometimes|nullable|in:'.implode(',', MerchantExpense::CATEGORIES),
            'note' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        $data = $v->validated();
        $data['category'] = $data['category'] ?? $e->category;
        $e->update($data);

        return $this->ok(['expense' => $this->arr($e->fresh())], 'UPDATED',
            'تم تعديل وصف المصروف دون تغيير أثره المالي');
    }

    /** DELETE يعني إلغاءً موثقاً، لا DELETE SQL لرقم دخل التقارير. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $u = $this->guard($request);
        if ($u instanceof JsonResponse) return $u;

        $reason = trim((string) $request->input('reason'));
        if (mb_strlen($reason) < 5) {
            return $this->err('VOID_REASON_REQUIRED',
                'سبب إلغاء المصروف مطلوب (5 أحرف على الأقل)', 422);
        }

        try {
            $expense = DB::transaction(function () use ($request, $u, $id, $reason) {
                $e = MerchantExpense::where('id', $id)
                    ->where('merchant_user_id', $u->id)
                    ->lockForUpdate()->first();

                if (!$e) throw new RuntimeException('المصروف غير موجود');
                if ($e->status === 'voided') return $e;

                if ($e->cashier_shift_id) {
                    $shift = \App\Models\CashierShift::whereKey($e->cashier_shift_id)
                        ->where('merchant_user_id', $u->id)
                        ->lockForUpdate()->first();

                    // لا نعيد كتابة وردية أُغلقت. إن كانت ما زالت مفتوحة
                    // فالمال لم يُجرد بعد، فيُسجل عكسٌ صريح يعيد المتوقع.
                    if ($shift && $shift->status === 'open') {
                        app(MerchantShiftCashService::class)->record(
                            ShiftCashMovement::CASHIER,
                            $shift->id,
                            $u,
                            'in',
                            'expense_reversal',
                            (string) $e->amount,
                            'إلغاء مصروف: '.$reason,
                            'EXPENSE-VOID-'.$e->expense_ulid,
                            $u->id,
                        );
                    }
                }

                $voidedAt = now();
                $reversal = MerchantExpenseReversal::firstOrCreate(
                    ['expense_id' => $e->id],
                    [
                        'reversal_ulid' => (string) Str::ulid(),
                        'merchant_user_id' => $u->id,
                        'amount' => (string) $e->amount,
                        'effective_on' => $voidedAt->copy()->setTimezone('Asia/Riyadh')->toDateString(),
                        'reason' => mb_substr($reason, 0, 500),
                        'created_by' => $u->id,
                    ]
                );

                $e->update([
                    'status' => 'voided',
                    'voided_at' => $voidedAt,
                    'void_reason' => mb_substr($reason, 0, 500),
                ]);

                app(\App\Services\AuditService::class)->record([
                    'actor_type' => 'merchant',
                    'actor_user_id' => $u->id,
                    'subject_type' => 'merchant_expense',
                    'subject_id' => $e->expense_ulid,
                    'action' => 'MERCHANT_EXPENSE_VOIDED',
                    'decision_code' => 'VOIDED',
                    'severity' => 'notice',
                    'reason' => $reason,
                    'context' => [
                        'amount' => (string) $e->amount,
                        'cashier_shift_id' => $e->cashier_shift_id,
                        'shift_reversed' => (bool) ($shift ?? null)
                            && ($shift->status ?? null) === 'open',
                        'reversal_ulid' => $reversal->reversal_ulid,
                        'reversal_effective_on' => $reversal->effective_on?->toDateString(),
                    ],
                ]);

                return $e->fresh();
            }, 3);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'المصروف غير موجود') {
                return $this->err('NOT_FOUND', $e->getMessage(), 404);
            }
            return $this->err('EXPENSE_VOID_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['expense' => $this->arr($expense)], 'VOIDED',
            'تم إلغاء المصروف مع الاحتفاظ بأثره التاريخي');
    }

    private function validatedData(Request $request): array|JsonResponse
    {
        $v = Validator::make($request->all(), [
            'title' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'sometimes|nullable|in:'.implode(',', MerchantExpense::CATEGORIES),
            'spent_on' => 'sometimes|nullable|date',
            'note' => 'sometimes|nullable|string|max:255',
            'cashier_shift_id' => 'sometimes|nullable|integer|min:1',
        ]);
        if ($v->fails()) return $this->err('VALIDATION', $v->errors()->first(), 422);

        $data = $v->validated();
        $data['category'] = $data['category'] ?? 'other';
        $data['spent_on'] = $data['spent_on'] ?? now()->toDateString();

        return $data;
    }

    private function lockCashierShiftForOutflow(
        Request $request, int $merchantUserId, string $amount
    ): ?\App\Models\CashierShift {
        if (!$request->filled('cashier_shift_id')) return null;

        $shift = \App\Models\CashierShift::where('id', (int) $request->input('cashier_shift_id'))
            ->where('merchant_user_id', $merchantUserId)
            ->where('status', 'open')
            ->lockForUpdate()->first();

        if (!$shift) {
            throw new RuntimeException('الوردية النقدية غير موجودة أو مغلقة');
        }

        $snapshot = app(\App\Services\CashierShiftService::class)->snapshot($shift);
        if (bccomp($amount, (string) ($snapshot['expected_cash'] ?? '0'), 4) > 0) {
            throw new RuntimeException(
                'رصيد الدرج المتوقع لا يكفي للمصروف — سجّل إيداعاً نقدياً أو اختر دفعاً خارج الدرج'
            );
        }

        return $shift;
    }

    private function arr(MerchantExpense $e): array
    {
        return [
            'id' => $e->id,
            'expense_ulid' => $e->expense_ulid,
            'category' => $e->category,
            'title' => $e->title,
            'amount' => (string) $e->amount,
            'payment_source' => $e->payment_source,
            'cashier_shift_id' => $e->cashier_shift_id,
            'status' => $e->status,
            'spent_on' => $e->spent_on?->toDateString(),
            'note' => $e->note,
            'voided_at' => $e->voided_at?->toIso8601String(),
            'void_reason' => $e->void_reason,
        ];
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ], $status);
    }

    private function err(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }
}
