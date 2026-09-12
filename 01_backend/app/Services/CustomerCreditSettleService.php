<?php

namespace App\Services;

use App\Jobs\SendTransactionNotificationJob;
use App\Models\CustomerCreditAccount;
use App\Models\Receipt;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-CUSTOMER-CREDIT-SETTLE-001 — سداد العميل لدَينه الآجل من محفظته.
 *
 * AMIAL-CREDIT-SETTLE-TRACE-001
 * السداد هنا عملية مالية كاملة، لا تعديل رصيد مباشر: ينتج Transaction ورقم
 * عملية وLedger/Audit وسند سداد دين، ثم يُخفض دفتر الآجل ويُربط السداد بنفس
 * رقم العملية. بذلك يظهر السداد في سجل العميل والتاجر ويمكن تتبعه من السند
 * إلى العملية ثم إلى دفتر الأستاذ.
 */
class CustomerCreditSettleService
{
    use TransactionTrait;

    public function __construct(
        private readonly CustomerCreditService $credit,
        private readonly CreditSourceSettlementService $sources,
    ) {}

    /**
     * @return array{
     *   new_balance:string,
     *   paid:string,
     *   transaction_id:string,
     *   transaction_no:string,
     *   receipt_id:?int,
     *   receipt_number:?string,
     *   allocations:array
     * }
     */
    public function settle(
        User $customer,
        CustomerCreditAccount $account,
        string $amount,
        ?string $saleMovementUlid = null,
    ): array
    {
        if ((int) $account->customer_user_id !== (int) $customer->id) {
            throw new InvalidArgumentException('هذا الحساب لا يخصّك');
        }

        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ السداد يجب أن يكون موجباً');
        }

        if (MoneyService::gt($amount, (string) $account->current_balance)) {
            throw new InvalidArgumentException('المبلغ أكبر من الدَّين المستحقّ');
        }

        $merchantId = (int) $account->merchant_user_id;
        $merchant = User::find($merchantId);
        if (!$merchant || !(bool) $merchant->is_active) {
            throw new RuntimeException('حساب التاجر غير متاح حالياً');
        }

        // نفس حراس العمليات المالية الأخرى: الحساب/المنطقة ثم AML قبل تحريك المال.
        $this->assertFinancialEligibility((int) $customer->id);
        $this->screenAml(
            (int) $customer->id,
            $merchantId,
            'pay_merchant',
            $amount,
            null,
            ['source' => 'debt_payment', 'credit_account_id' => (int) $account->id],
        );

        $idempotencyKey = request()?->header('Idempotency-Key');
        $idempotencyKey = is_string($idempotencyKey) && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : null;

        $result = DB::transaction(function () use (
            $customer,
            $account,
            $merchantId,
            $amount,
            $saleMovementUlid,
            $idempotencyKey,
        ) {
            // الحساب يُقفل قبل احتساب توزيع الدفعة على الفواتير، فلا يستطيع
            // طلبان متزامنان تسديد المتبقي نفسه مرتين.
            $lockedAccount = CustomerCreditAccount::lockForUpdate()->findOrFail($account->id);

            if ((int) $lockedAccount->customer_user_id !== (int) $customer->id) {
                throw new InvalidArgumentException('هذا الحساب لا يخصّك');
            }
            if (MoneyService::gt($amount, (string) $lockedAccount->current_balance)) {
                throw new InvalidArgumentException('المبلغ أكبر من الدَّين المستحقّ');
            }

            $allocations = $this->sources->allocate($lockedAccount, $amount, $saleMovementUlid);

            // كل محافظ العملية تُقفل بترتيب ثابت قبل الخصم والإضافة.
            $this->guard()->lockWalletsOrdered([(int) $customer->id, $merchantId]);
            app(MerchantRiskService::class)->assertReceiveAllowed($merchantId, $amount);

            $customerWallet = $this->guard()->debit(
                (int) $customer->id,
                $amount,
                "debt_payment:{$lockedAccount->id}",
            );
            $merchantWallet = $this->guard()->credit(
                $merchantId,
                $amount,
                "debt_payment_received:{$lockedAccount->id}",
            );

            // سداد الآجل عميل → تاجر، لذلك يحمل بادئة عمليات الدفع (20).
            $primaryId = $this->newTransactionId();
            $transactionNo = $this->newTransactionNo('20');

            $this->recordTransaction([
                'user_id' => (int) $customer->id,
                'transaction_id' => $primaryId,
                'transaction_no' => $transactionNo,
                'ref_trans_id' => null,
                'transaction_type' => 'debt_payment',
                'debit' => $amount,
                'credit' => '0',
                'charge' => '0',
                'amount' => $amount,
                'balance' => (string) $customerWallet->current_balance,
                'from_user_id' => (int) $customer->id,
                'to_user_id' => $merchantId,
                'note' => 'سداد دين آجل',
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $customerWallet->zone_code ?? 'SOUTH',
            ]);

            $this->recordTransaction([
                'user_id' => $merchantId,
                'transaction_id' => $this->newTransactionId(),
                'ref_trans_id' => $primaryId,
                'transaction_type' => 'debt_payment_received',
                'debit' => '0',
                'credit' => $amount,
                'charge' => '0',
                'amount' => $amount,
                'balance' => (string) $merchantWallet->current_balance,
                'from_user_id' => (int) $customer->id,
                'to_user_id' => $merchantId,
                'note' => 'تحصيل دين آجل',
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $merchantWallet->zone_code ?? 'SOUTH',
            ]);

            // دفتر الديون يحمل مرجع العملية الرسمي نفسه، لا وصفاً بلا رقم.
            $movement = $this->credit->recordPayment(
                account: $lockedAccount,
                amount: $amount,
                note: 'سداد دين آجل عبر أميال باي',
                createdBy: (int) $customer->id,
                referenceType: 'debt_payment',
                referenceId: $primaryId,
                referenceNumber: $transactionNo,
            );

            if (!$movement) {
                throw new RuntimeException('تعذّر تسجيل السداد');
            }

            // ربط السداد بمصادر الدين (فاتورة جملة/بيع آجل...) داخل نفس المعاملة.
            $this->sources->apply($allocations, $movement, $customer);

            // القيد المحاسبي جزء من نجاح العملية؛ فشله يعيد كل شيء للخلف.
            $this->ledgerTransfer(
                fromUserId: (int) $customer->id,
                toUserId: $merchantId,
                amount: $amount,
                sourceType: 'debt_payment',
                sourceId: $primaryId,
                description: 'سداد دين آجل',
                idempotencyKey: $idempotencyKey ?: "debt_payment_{$primaryId}",
                metadata: [
                    'transaction_id' => $primaryId,
                    'transaction_no' => $transactionNo,
                    'credit_account_id' => (int) $lockedAccount->id,
                    'credit_movement_id' => (int) $movement->id,
                ],
            );

            $this->audit()->record([
                'actor_type' => 'user',
                'actor_user_id' => (int) $customer->id,
                'subject_type' => 'customer_credit_account',
                'subject_id' => (string) $lockedAccount->id,
                'action' => 'DEBT_PAYMENT_COMPLETED',
                'decision_code' => 'TX_OK',
                'transaction_id' => $primaryId,
                'idempotency_key' => $idempotencyKey,
                'zone_code' => $customerWallet->zone_code ?? 'SOUTH',
                'severity' => 'info',
                'context' => [
                    'merchant_user_id' => $merchantId,
                    'amount' => $amount,
                    'transaction_no' => $transactionNo,
                    'sale_movement_ulid' => $saleMovementUlid,
                ],
            ]);

            DB::afterCommit(function () use ($customer, $merchantId, $amount, $primaryId): void {
                SendTransactionNotificationJob::dispatch(
                    (int) $customer->id,
                    $amount,
                    'debt_payment',
                    transactionId: $primaryId,
                );
                SendTransactionNotificationJob::dispatch(
                    $merchantId,
                    $amount,
                    'debt_payment_received',
                    transactionId: $primaryId,
                );
            });

            return [
                'new_balance' => (string) $lockedAccount->fresh()->current_balance,
                'paid' => $amount,
                'transaction_id' => $primaryId,
                'transaction_no' => $transactionNo,
                'credit_movement_id' => (int) $movement->id,
                'allocations' => $allocations,
            ];
        }, self::TX_ATTEMPTS);

        // المستند طبقة لاحقة للمال: لو تعذر PDF لا نعيد خصم العميل، لكن سجل
        // Receipt نفسه يصدر فوراً ويظهر في «السندات» برقم يمكن تتبعه.
        $this->safeIssueReceipts([
            'from_user_id' => (int) $customer->id,
            'to_user_id' => $merchantId,
            'reference_transaction_id' => $result['transaction_id'],
            'receipt_type' => 'debt_payment',
            'amount' => $amount,
            'fee' => '0',
            'reference_type' => 'customer_credit_account',
            'reference_id' => (int) $account->id,
            'metadata' => [
                'credit_account_id' => (int) $account->id,
                'credit_movement_id' => $result['credit_movement_id'],
                'transaction_no' => $result['transaction_no'],
                'sale_movement_ulid' => $saleMovementUlid,
                'note' => 'سداد دين آجل',
            ],
            'zone_code' => $customer->zone_code ?? 'SOUTH',
        ]);

        $this->maybeAnalyzeMerchantRisk($merchantId, (int) $customer->id, $amount);

        $customerReceipt = Receipt::query()
            ->where('user_id', $customer->id)
            ->where('reference_transaction_id', $result['transaction_id'])
            ->where('receipt_type', 'debt_payment')
            ->where('direction', 'debit')
            ->first();

        return [
            'new_balance' => $result['new_balance'],
            'paid' => $result['paid'],
            'transaction_id' => $result['transaction_id'],
            'transaction_no' => $result['transaction_no'],
            'receipt_id' => $customerReceipt?->id,
            'receipt_number' => $customerReceipt?->receipt_number,
            'allocations' => $result['allocations'],
        ];
    }
}
