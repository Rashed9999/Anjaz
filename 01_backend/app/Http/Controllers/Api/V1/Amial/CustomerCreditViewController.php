<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditMovement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return $this->ok([
            'account_id' => $account->id,
            'merchant_name' => $storeName,
            'current_balance' => (string) $account->current_balance,
            'credit_limit' => (string) $account->credit_limit,
            'movements' => $movements,
        ], 'OK', 'كشف الحساب الآجل');
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
