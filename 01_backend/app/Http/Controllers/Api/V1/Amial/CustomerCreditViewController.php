<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditMovement;
use App\Models\User;
use App\Services\CustomerCreditSettleService;
use App\Services\CreditSourceSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-CUSTOMER-CREDIT-VIEW-001 — واجهة العميل لعرض ما عليه من آجل.
 *
 * كل حساب آجل يربطه التاجر برقم هاتف؛ إن طابق مستخدماً مسجّلاً يُربط
 * customer_user_id. هنا يرى العميل — بصفته المدين — حساباته الآجلة لدى
 * كل تاجر وفواتيرها (الحركات) لحظياً.
 *
 *   GET /api/v1/amial/customer/credits                  قائمة حساباتي الآجلة
 *   GET /api/v1/amial/customer/credits/{id}/statement   فواتير/حركات حساب واحد
 */
class CustomerCreditViewController extends Controller
{
    /** قائمة حسابات العميل الآجلة عبر كل التجّار مع الرصيد المستحقّ. */
    public function myAccounts(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // AMIAL-CREDIT-LINK-001 — استرجاع الحسابات غير المربوطة.
        //
        // حالتان تتركان customer_user_id فارغاً: (١) بيع آجل سُجّل قبل أن
        // يُنشئ العميل حسابه، (٢) حسابات أُنشئت قبل إصلاح مطابقة الهاتف.
        // في الحالتين المال مستحقّ فعلاً والعميل لا يراه. نطالب بها هنا عند
        // أول فتح للشاشة — مطابقةً بكل صيغ الرقم لا بنصّه الحرفي.
        try {
            $phones = \App\Support\Phone::variants((string) $request->user()->phone);
            CustomerCreditAccount::whereNull('customer_user_id')
                ->whereIn('customer_phone', $phones)
                ->update(['customer_user_id' => $userId]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Credit account back-link failed: ' . $e->getMessage()
            );
        }

        $accounts = CustomerCreditAccount::where('customer_user_id', $userId)
            ->orderByDesc('current_balance')
            ->get();

        $merchantNames = User::whereIn('id', $accounts->pluck('merchant_user_id')->unique())
            ->get()->keyBy('id');

        $totalOwed = '0';
        $items = $accounts->map(function (CustomerCreditAccount $a) use ($merchantNames, &$totalOwed) {
            $merchant = $merchantNames->get($a->merchant_user_id);
            $storeName = $merchant?->merchant?->store_name
                ?? trim(($merchant->f_name ?? '') . ' ' . ($merchant->l_name ?? ''))
                ?: 'تاجر';
            $totalOwed = bcadd($totalOwed, (string) $a->current_balance, 4);
            return [
                'account_id' => $a->id,
                'merchant_name' => $storeName,
                'current_balance' => (string) $a->current_balance,
                'credit_limit' => (string) $a->credit_limit,
                'classification' => $a->classification,
            ];
        })->values();

        return $this->ok([
            'total_owed' => $totalOwed,
            'accounts_count' => $items->count(),
            'accounts' => $items,
        ], 'OK', 'حساباتي الآجلة');
    }

    /** فواتير/حركات حساب آجل واحد — فقط إن كان يخصّ العميل الحالي. */
    public function myStatement(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $account = CustomerCreditAccount::where('id', $id)
            ->where('customer_user_id', $userId)
            ->first();
        if (!$account) {
            return $this->error('NOT_FOUND', 'الحساب غير موجود أو لا يخصّك', 404);
        }

        $merchant = User::find($account->merchant_user_id);
        $storeName = $merchant?->merchant?->store_name
            ?? trim(($merchant->f_name ?? '') . ' ' . ($merchant->l_name ?? ''))
            ?: 'تاجر';

        $movements = CustomerCreditMovement::where('account_id', $account->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (CustomerCreditMovement $m) => [
                'id' => $m->id,
                'type' => $m->type, // sale | payment | return | adjustment
                'type_label' => $this->typeLabel($m->type),
                'amount' => (string) $m->amount,
                'balance_after' => (string) $m->balance_after,
                'due_date' => $m->due_date?->toDateString(),
                'note' => $m->note,
                'reference_number' => $m->reference_number,
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        // هذه فواتير العميل المستحقة فعلاً، وكلّ واحدة تحمل متبقيها بعد
        // السدادات الجزئية والمرتجعات من دفتر الديون نفسه.
        $invoices = app(CreditSourceSettlementService::class)->openInvoices($account);

        // ══════════════════════════════════════════════════════════════
        // AMIAL-CREDIT-GAP-001 — **الفرقُ يُقال، ولا يُترَك للقارئ يجمع.**
        //
        // `openInvoices` تقتصر على قيود البيع عمداً — والتعديلُ اليدويُّ
        // الموجب (دينٌ قديمٌ مُرحَّل) ليس فاتورةً تُسدَّد وحدَها. **لكنّه
        // في الرصيد.** فقِيس:
        //
        //     تعديلٌ موجب  1000  ثمّ بيعةٌ آجلة  500
        //     current_balance = 1500
        //     invoices        =  500   ← ألفٌ بلا سطر
        //
        // فيقرأ العميلُ «عليّ ٥٠٠» في قائمة الفواتير و«١٥٠٠» في الرصيد،
        // **ولا شيءَ يفسّر الألف**. وهو عينُ القاعدة السابعة: الغيابُ
        // يُقال ولا يُترَك فراغاً يُقرأ صفراً.
        //
        // **ولا يُخترَع له سطرُ فاتورةٍ وهميّ** — سطرٌ يُعرَض بزرّ سدادٍ
        // لا يقبله المحرّك أسوأ من لافتةٍ تشرح.
        // ══════════════════════════════════════════════════════════════
        $invoicesTotal = '0';
        foreach ($invoices as $invoice) {
            $invoicesTotal = \App\Services\MoneyService::add(
                $invoicesTotal, (string) ($invoice['remaining'] ?? '0'));
        }

        $unlinked = \App\Services\MoneyService::sub(
            (string) $account->current_balance, $invoicesTotal);

        return $this->ok([
            'account_id' => $account->id,
            'merchant_name' => $storeName,
            'current_balance' => (string) $account->current_balance,
            'credit_limit' => (string) $account->credit_limit,
            'movements' => $movements,
            'invoices' => $invoices,
            'invoices_total' => $invoicesTotal,
            // موجبٌ يعني: دينٌ في الرصيد بلا فاتورةٍ تُسدَّد وحدَها.
            'unlinked_balance' => $unlinked,
            'unlinked_note_ar' => \App\Services\MoneyService::isPositive($unlinked)
                ? 'مبلغٌ من رصيدك ليس فاتورةً مستقلّة (تعديلٌ يدويٌّ أو '
                    . 'دَينٌ سابقٌ مُرحَّل). يُسدَّد بـ«سداد الآجل» كاملاً.'
                : null,
        ], 'OK', 'كشف الحساب الآجل');
    }

    /** سداد الدَّين الآجل (كلّه أو جزء) من محفظة العميل. */
    public function settle(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'pin' => 'required|string|min:4|max:8',
            'sale_movement_ulid' => 'sometimes|nullable|string|max:40',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        if (!Helpers::pin_check($user->id, $request->input('pin'))) {
            return $this->error('WRONG_PIN', 'رمز الدخول غير صحيح', 403);
        }

        $account = CustomerCreditAccount::where('id', $id)
            ->where('customer_user_id', $user->id)->first();
        if (!$account) {
            return $this->error('NOT_FOUND', 'الحساب غير موجود أو لا يخصّك', 404);
        }

        try {
            $result = app(CustomerCreditSettleService::class)->settle(
                $user,
                $account,
                (string) $request->input('amount'),
                $request->filled('sale_movement_ulid')
                    ? (string) $request->input('sale_movement_ulid') : null,
            );
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return $this->error('INSUFFICIENT_BALANCE', 'رصيد محفظتك لا يكفي', 422);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('SETTLE_FAILED', 'تعذّر تنفيذ السداد', 422);
        }

        return $this->ok([
            'paid' => $result['paid'],
            'new_balance' => $result['new_balance'],
            'allocations' => $result['allocations'] ?? [],
        ], 'SETTLED', 'تم السداد بنجاح');
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'sale' => 'فاتورة آجل',
            'payment' => 'سداد',
            'return' => 'مرتجع',
            'adjustment' => 'تعديل',
            default => $type,
        };
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
