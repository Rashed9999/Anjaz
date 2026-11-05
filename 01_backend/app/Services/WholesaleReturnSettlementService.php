<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\CustomerCreditAccount;
use App\Models\Retail\ShiftCashMovement;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use App\Models\WholesaleReturn;
use App\Models\WholesaleReturnSettlement;
use App\Services\Retail\MerchantShiftCashService;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-WHOLESALE-RETURN-PAYOUT-001
 *
 * يسوي الالتزام النقدي الناتج من مرتجع جملة مدفوع مسبقاً.
 * refund_due_amount التزام فقط، ولا يصبح نقداً خارجاً أو محفظةً خارجة
 * إلا عند إنشاء Settlement نجح معه تحريك المال فعلاً.
 */
final class WholesaleReturnSettlementService
{
    public function __construct(
        private readonly CashierShiftService $shifts,
        private readonly MerchantShiftCashService $cash,
        private readonly LedgerService $ledger,
        private readonly FinancialGuardService $guard,
        private readonly AuditService $audit,
    ) {}

    public function settle(
        User $merchant,
        WholesaleReturn $return,
        User $actor,
        string $amount,
        string $method,
        string $idempotencyKey,
        ?int $cashierShiftId = null,
        ?string $note = null,
        ?string $reference = null,
    ): WholesaleReturnSettlement {
        $amount = MoneyService::normalize($amount);
        $idempotencyKey = trim($idempotencyKey);
        $storedIdempotencyKey = hash('sha256', $merchant->id.'|'.$idempotencyKey);

        if (! MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ رد العميل يجب أن يكون موجباً');
        }
        if (! in_array($method, WholesaleReturnSettlement::METHODS, true)) {
            throw new InvalidArgumentException('طريقة صرف مستحق المرتجع غير صحيحة');
        }
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('مفتاح منع التكرار مطلوب');
        }

        return DB::transaction(function () use (
            $merchant, $return, $actor, $amount, $method, $idempotencyKey,
            $storedIdempotencyKey, $cashierShiftId, $note, $reference,
        ): WholesaleReturnSettlement {
            $existing = WholesaleReturnSettlement::where('idempotency_key', $storedIdempotencyKey)
                ->where('merchant_user_id', $merchant->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            $locked = WholesaleReturn::whereKey($return->id)->lockForUpdate()->first();
            if (! $locked) throw new RuntimeException('طلب المرتجع غير موجود');

            $business = WholesaleBusiness::whereKey($locked->business_id)
                ->where('merchant_user_id', $merchant->id)->first();
            if (! $business) {
                throw new RuntimeException('هذا المرتجع لا يخص منشأتك');
            }

            $invoice = WholesaleInvoice::whereKey($locked->invoice_id)
                ->where('business_id', $locked->business_id)
                ->first(['id', 'branch_id']);
            if (! $invoice) {
                throw new RuntimeException('فاتورة المرتجع الأصلية غير موجودة؛ أوقف الصرف وراجع سلامة البيانات');
            }

            if ($locked->status !== 'approved') {
                throw new RuntimeException('لا يمكن صرف مستحق مرتجع قبل اعتماده');
            }

            $due = MoneyService::normalize((string) $locked->refund_due_amount);
            if (! MoneyService::isPositive($due)) {
                throw new RuntimeException('لا يوجد مبلغ مستحق للرد في هذا المرتجع');
            }

            $paid = MoneyService::normalize((string) WholesaleReturnSettlement::where('return_id', $locked->id)
                ->sum('amount'));
            $remaining = MoneyService::sub($due, $paid);
            if (! MoneyService::isPositive($remaining)) {
                throw new RuntimeException('تم صرف كامل مستحق هذا المرتجع سابقاً');
            }
            if (MoneyService::compare($amount, $remaining) > 0) {
                throw new RuntimeException(
                    'المبلغ يتجاوز المستحق المتبقي للعميل ('
                    .MoneyService::display($remaining, 2).' ر.ي)'
                );
            }

            $settlementUlid = (string) Str::ulid();
            $customerUserId = null;
            $ledgerEntryUlid = null;
            $shiftId = null;

            if ($method === 'cash') {
                if (! $cashierShiftId) {
                    throw new InvalidArgumentException(
                        'اختر وردية POS مفتوحة لأن النقد سيخرج فعلياً من درجها'
                    );
                }

                $shift = CashierShift::whereKey($cashierShiftId)
                    ->where('merchant_user_id', $merchant->id)
                    ->lockForUpdate()->first();

                if (! $shift || $shift->status !== 'open') {
                    throw new RuntimeException('الوردية المختارة غير مفتوحة أو لا تخص منشأتك');
                }

                $invoiceBranchId = $invoice->branch_id !== null ? (int) $invoice->branch_id : null;
                $shiftBranchId = $shift->branch_id !== null ? (int) $shift->branch_id : null;
                if ($invoiceBranchId !== $shiftBranchId) {
                    throw new RuntimeException(
                        'لا يمكن صرف مرتجع فرع من درج فرع آخر؛ اختر وردية موقع الفاتورة الأصلية'
                    );
                }

                $snapshot = $this->shifts->snapshot($shift);
                if (MoneyService::compare($amount, (string) $snapshot['expected_cash']) > 0) {
                    throw new RuntimeException(
                        'النقد المتوقع في الدرج لا يكفي لصرف المستحق (المتاح '
                        .MoneyService::display((string) $snapshot['expected_cash'], 2).' ر.ي)'
                    );
                }

                $this->cash->record(
                    shiftType: ShiftCashMovement::CASHIER,
                    shiftId: (int) $shift->id,
                    actor: $actor,
                    direction: 'out',
                    reason: 'refund',
                    amount: $amount,
                    note: $note ?: 'صرف مستحق مرتجع جملة '.$locked->return_ulid,
                    reference: $settlementUlid,
                    merchantUserId: $merchant->id,
                );
                $shiftId = (int) $shift->id;
            } else {
                $customerUserId = $this->resolveCustomerUserId($merchant, (int) $locked->customer_id);
                if (! $customerUserId) {
                    throw new RuntimeException(
                        'لا يوجد حساب أميال موثوق مرتبط بهذا العميل لإيداع الاسترداد'
                    );
                }

                $merchantWallet = $this->ledger->getOrCreateUserWallet($merchant->id);
                $customerWallet = $this->ledger->getOrCreateUserWallet($customerUserId);

                $entry = $this->ledger->post(
                    sourceType: 'wholesale_return_refund',
                    sourceId: $settlementUlid,
                    description: 'صرف مستحق مرتجع جملة '.$locked->return_ulid,
                    lines: [
                        [
                            'account' => $merchantWallet->account_code,
                            'direction' => 'debit',
                            'amount' => $amount,
                        ],
                        [
                            'account' => $customerWallet->account_code,
                            'direction' => 'credit',
                            'amount' => $amount,
                        ],
                    ],
                    idempotencyKey: 'wret_settle_'.hash('sha256', $idempotencyKey),
                    createdByUserId: $actor->id,
                    metadata: [
                        'wholesale_return_id' => $locked->id,
                        'return_ulid' => $locked->return_ulid,
                        'customer_id' => $locked->customer_id,
                    ],
                    zoneCode: $merchant->zone_code ?? 'SOUTH',
                );

                $this->guard->lockWalletsOrdered([$merchant->id, $customerUserId]);
                $this->guard->debit($merchant->id, $amount, 'wholesale_return_refund:'.$locked->return_ulid);
                $this->guard->credit($customerUserId, $amount, 'wholesale_return_refund:'.$locked->return_ulid);

                $ledgerEntryUlid = $entry->entry_ulid;
            }

            $settlement = WholesaleReturnSettlement::create([
                'settlement_ulid' => $settlementUlid,
                'return_id' => $locked->id,
                'business_id' => $locked->business_id,
                'merchant_user_id' => $merchant->id,
                'customer_id' => $locked->customer_id,
                'paid_by_user_id' => $actor->id,
                'cashier_shift_id' => $shiftId,
                'method' => $method,
                'amount' => $amount,
                'customer_user_id' => $customerUserId,
                'ledger_entry_ulid' => $ledgerEntryUlid,
                'reference' => $reference,
                'idempotency_key' => $storedIdempotencyKey,
                'note' => $note,
            ]);

            $newPaid = MoneyService::add($paid, $amount);
            $locked->settlement_type = MoneyService::compare($newPaid, $due) >= 0
                ? 'refund_paid'
                : 'refund_partial';
            $locked->save();

            $this->audit->record([
                'actor_type' => 'merchant',
                'actor_user_id' => $actor->id,
                'subject_type' => 'wholesale_return',
                'subject_id' => $locked->return_ulid,
                'action' => 'WHOLESALE_RETURN_REFUND_PAID',
                'decision_code' => 'COMPLETED',
                'reason' => $method === 'cash'
                    ? 'صُرف مستحق المرتجع نقداً من وردية POS'
                    : 'صُرف مستحق المرتجع إلى محفظة العميل',
                'context' => [
                    'merchant_user_id' => $merchant->id,
                    'settlement_ulid' => $settlementUlid,
                    'amount' => $amount,
                    'method' => $method,
                    'cashier_shift_id' => $shiftId,
                    'customer_user_id' => $customerUserId,
                    'ledger_entry_ulid' => $ledgerEntryUlid,
                    'remaining_after' => MoneyService::sub($due, $newPaid),
                ],
            ]);

            return $settlement->fresh(['customer']);
        });
    }

    public function remaining(WholesaleReturn $return): string
    {
        $paid = (string) WholesaleReturnSettlement::where('return_id', $return->id)->sum('amount');

        $remaining = MoneyService::sub(
            (string) $return->refund_due_amount,
            MoneyService::normalize($paid ?: '0'),
        );

        return MoneyService::compare($remaining, '0') > 0
            ? MoneyService::normalize($remaining)
            : MoneyService::normalize('0');
    }

    private function resolveCustomerUserId(User $merchant, int $customerId): ?int
    {
        $customer = WholesaleCustomer::whereKey($customerId)->first();
        if (! $customer || ! $customer->phone) return null;

        $linked = CustomerCreditAccount::where('merchant_user_id', $merchant->id)
            ->whereIn('customer_phone', Phone::variants((string) $customer->phone))
            ->whereNotNull('customer_user_id')
            ->value('customer_user_id');

        if ($linked !== null) return (int) $linked;

        $ids = User::whereIn('phone', Phone::variants((string) $customer->phone))
            ->where('is_active', true)
            ->limit(2)
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
