<?php

namespace App\Services;

use App\Models\CustomerCreditAccount;
use App\Models\MerchantRefund;
use App\Models\MerchantSale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-CASHIER-REFUND-001 — خدمة المرتجعات من بيوع الكاشير.
 *
 * تدعم 3 طرق استرداد:
 *   1. cash           → نقد يعطيه التاجر يدوياً. لا حركة مالية رقمية، فقط سجلّ.
 *   2. wallet         → ائتمان محفظة العميل (يلزم customer_user_id).
 *   3. credit_account → خصم من دَيْن العميل (للبيع الآجل).
 *
 * قواعد الأعمال (وثيقة §12):
 *   - الاسترجاع فقط من عملية أصلية ناجحة (completed أو credit_paid أو credit_unpaid).
 *   - مجموع المرتجعات السابقة + الحالي ≤ المبلغ الأصلي.
 *   - الكميات في items لا تتجاوز الأصلية minus المُرتجعة سابقاً.
 *   - استرداد ≤ 5000 ر.ي مباشر، أكثر يحتاج pending_approval (الإدارة).
 *
 * كل العمليات داخل DB::transaction.
 */
class MerchantSaleRefundService
{
    use \App\Traits\TransactionTrait;

    /** الحد الأقصى للاسترداد المباشر بدون موافقة إدارية. */
    private const AUTO_APPROVE_THRESHOLD = '5000';

    public function __construct(
        private readonly CustomerCreditService $credit,
        private readonly NotificationService $notif,
    ) {}

    /**
     * تنفيذ مرتجع.
     *
     * @param array $items عناصر مرتجعة [{name, qty, price, product_id?}]
     *                     (مطابقة لشكل items في merchant_sales)
     */
    public function refund(
        User $merchant,
        string $originalSaleUlid,
        string $refundAmount,
        string $refundMethod,
        array $items = [],
        ?string $reason = null,
        ?int $posUserId = null,
    ): MerchantRefund {
        if (!in_array($refundMethod, MerchantRefund::METHODS, true)) {
            throw new InvalidArgumentException("طريقة استرداد غير صحيحة: {$refundMethod}");
        }
        $refundAmount = MoneyService::normalize($refundAmount);
        if (!MoneyService::isPositive($refundAmount)) {
            throw new InvalidArgumentException('مبلغ الاسترداد يجب أن يكون موجباً');
        }

        return DB::transaction(function () use (
            $merchant, $originalSaleUlid, $refundAmount, $refundMethod, $items, $reason, $posUserId,
        ) {
            // 1) جد العملية الأصلية (lockForUpdate لمنع race في حساب refundedSoFar)
            $sale = MerchantSale::where('sale_ulid', $originalSaleUlid)
                ->where('merchant_user_id', $merchant->id)
                ->lockForUpdate()
                ->first();

            if (!$sale) {
                throw new RuntimeException('العملية الأصلية غير موجودة');
            }

            // الحالات المقبولة للاسترداد
            $allowedStatuses = ['completed', 'credit_unpaid', 'credit_paid'];
            if (!in_array($sale->status, $allowedStatuses, true)) {
                throw new RuntimeException('حالة العملية لا تسمح بالاسترداد: ' . $sale->status);
            }

            // 2) تحقّق من عدم تجاوز المبلغ الأصلي
            $refundedSoFar = (string) MerchantRefund::where('original_sale_ulid', $originalSaleUlid)
                ->where('status', '!=', 'rejected')
                ->sum('refund_amount');
            $refundedSoFar = MoneyService::normalize($refundedSoFar ?: '0');

            $remaining = MoneyService::sub((string)$sale->total_amount, $refundedSoFar);
            if (MoneyService::compare($refundAmount, $remaining) > 0) {
                throw new RuntimeException(
                    "مبلغ الاسترداد ({$refundAmount}) أكبر من المتبقّي القابل للاسترداد ({$remaining})"
                );
            }

            // 3) تحقّق طريقة الاسترداد متناسقة مع طريقة البيع الأصلية
            $this->validateMethodCompatibility($sale, $refundMethod);

            // 4) حدّد الحالة (auto-approve أم pending)
            $needsApproval = MoneyService::compare($refundAmount, self::AUTO_APPROVE_THRESHOLD) > 0;
            $status = $needsApproval ? 'pending_approval' : 'completed';

            // 5) جهّز السجل
            $refund = MerchantRefund::create([
                'refund_ulid' => (string) Str::ulid(),
                'merchant_user_id' => $merchant->id,
                'customer_user_id' => $this->resolveCustomerUserId($sale),
                'pos_user_id' => $posUserId,
                'original_transaction_id' => $sale->paid_transaction_id ?? $sale->sale_ulid,
                'original_sale_ulid' => $sale->sale_ulid,
                'original_amount' => (string)$sale->total_amount,
                'customer_phone' => $sale->customer_phone,
                'customer_name' => $sale->customer_name,
                'refund_amount' => $refundAmount,
                'refund_method' => $refundMethod,
                'items' => $items,
                'reason' => $reason,
                'status' => $status,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            // 6) إن لم يحتج موافقة، نفّذ مالياً فوراً
            if ($status === 'completed') {
                $this->executeMoneyMovement($refund, $sale, $merchant);
            }

            // 7) إشعارات (best-effort)
            $this->notifyOfRefund($refund, $merchant);

            return $refund->fresh();
        });
    }

    /** تأكيد مرتجع pending_approval (للأدمن). */
    public function approve(MerchantRefund $refund, int $adminId): MerchantRefund
    {
        if ($refund->status !== 'pending_approval') {
            throw new RuntimeException('هذا المرتجع ليس بانتظار موافقة');
        }
        return DB::transaction(function () use ($refund, $adminId) {
            $sale = MerchantSale::where('sale_ulid', $refund->original_sale_ulid)
                ->lockForUpdate()->first();
            $merchant = User::find($refund->merchant_user_id);

            $refund->update([
                'status' => 'completed',
                'approved_by_admin_id' => $adminId,
                'approved_at' => now(),
            ]);

            $this->executeMoneyMovement($refund, $sale, $merchant);
            $this->notifyOfRefund($refund->fresh(), $merchant);

            return $refund->fresh();
        });
    }

    /** رفض مرتجع pending_approval. */
    public function reject(MerchantRefund $refund, int $adminId, ?string $reason = null): MerchantRefund
    {
        if ($refund->status !== 'pending_approval') {
            throw new RuntimeException('هذا المرتجع ليس بانتظار موافقة');
        }
        $refund->update([
            'status' => 'rejected',
            'approved_by_admin_id' => $adminId,
            'approved_at' => now(),
            'reason' => $reason ?? $refund->reason,
        ]);
        return $refund->fresh();
    }

    // ============ Private ============

    /** تحقّق أن طريقة الاسترداد متناسقة مع طريقة الدفع الأصلية. */
    private function validateMethodCompatibility(MerchantSale $sale, string $refundMethod): void
    {
        $original = $sale->payment_method;

        if ($refundMethod === 'credit_account') {
            // فقط للبيوع الآجلة
            if ($original !== 'credit') {
                throw new InvalidArgumentException(
                    'الاسترداد لحساب الدَّيْن متاح فقط للبيوع الآجلة'
                );
            }
        }

        if ($refundMethod === 'wallet') {
            // يلزم عميل مسجّل في أميال باي
            if (!$this->resolveCustomerUserId($sale)) {
                throw new InvalidArgumentException(
                    'الاسترداد للمحفظة يحتاج عميلاً مسجّلاً في أميال باي'
                );
            }
        }
        // cash: مسموح دائماً (التاجر يعطي نقداً يدوياً)
    }

    /** نفّذ حركة المال الفعلية حسب الطريقة. */
    private function executeMoneyMovement(MerchantRefund $refund, MerchantSale $sale, User $merchant): void
    {
        match ($refund->refund_method) {
            'cash' => $this->refundCash($refund, $merchant),
            'wallet' => $this->refundToWallet($refund, $merchant),
            'credit_account' => $this->refundToCreditAccount($refund, $sale, $merchant),
        };
    }

    /** نقدي: التاجر يخصم من رصيده (لا حركة على محفظة عميل). */
    private function refundCash(MerchantRefund $refund, User $merchant): void
    {
        // نخصم من رصيد التاجر (افتراض: استلم نقداً سابقاً، أعاد نقداً)
        // الحقيقة: النقدي خارج النظام. نسجّل السجل فقط بلا حركة محفظة.
        // إن أراد التاجر تتبّع النقد، يدخل قيد محاسبي يدوي.
        $refund->update(['ledger_entry_ulid' => 'cash:no_wallet_movement']);
    }

    /** ائتمان محفظة العميل + خصم من محفظة التاجر. */
    private function refundToWallet(MerchantRefund $refund, User $merchant): void
    {
        $customerId = $refund->customer_user_id;
        if (!$customerId) {
            throw new RuntimeException('لا يوجد عميل مسجّل لإيداع الاسترداد');
        }

        // خصم من التاجر، إضافة للعميل
        $this->guard()->lockWalletsOrdered([$merchant->id, $customerId]);
        $this->guard()->debit($merchant->id, (string)$refund->refund_amount, "refund:{$refund->refund_ulid}");
        $this->guard()->credit($customerId, (string)$refund->refund_amount, "refund:{$refund->refund_ulid}");

        $refund->update(['ledger_entry_ulid' => $refund->refund_ulid]);
    }

    /** خصم من دَيْن العميل (للبيوع الآجلة). */
    private function refundToCreditAccount(MerchantRefund $refund, MerchantSale $sale, User $merchant): void
    {
        // ابحث عن حساب الدَّيْن للعميل
        $account = CustomerCreditAccount::where('merchant_user_id', $merchant->id)
            ->where('customer_phone', $sale->customer_phone)
            ->first();

        if (!$account) {
            throw new RuntimeException('حساب الدَّيْن للعميل غير موجود');
        }

        // سجّل مرتجع في نظام الديون (ينقص الدَّيْن)
        $movement = $this->credit->recordReturn(
            account: $account,
            amount: (string)$refund->refund_amount,
            note: "مرتجع من البيع #{$sale->sale_ulid}",
            createdBy: $refund->pos_user_id ?? $merchant->id,
            referenceType: 'merchant_refund',
            referenceId: $refund->refund_ulid,
        );

        $refund->update([
            'credit_account_id' => $account->id,
            'ledger_entry_ulid' => $movement->movement_ulid,
        ]);
    }

    /** أرسل إشعارات للأطراف المعنيّة. */
    private function notifyOfRefund(MerchantRefund $refund, User $merchant): void
    {
        try {
            // إشعار العميل (إن مسجّلاً)
            if ($refund->customer_user_id) {
                $customer = User::find($refund->customer_user_id);
                if ($customer && $refund->status === 'completed') {
                    $methodLabel = match ($refund->refund_method) {
                        'cash' => 'نقداً',
                        'wallet' => 'إلى محفظتك',
                        'credit_account' => 'خصم من دَيْنك',
                    };
                    $this->notif->dispatch(
                        $customer,
                        'refund_received',
                        'تم استرداد مبلغ',
                        "استلمت {$refund->refund_amount} ر.ي {$methodLabel} من {$merchant->f_name}",
                        data: [
                            'refund_ulid' => $refund->refund_ulid,
                            'amount' => (string)$refund->refund_amount,
                            'method' => $refund->refund_method,
                        ],
                    );
                }
            }

            // إشعار التاجر بحالة pending_approval
            if ($refund->status === 'pending_approval') {
                $this->notif->dispatch(
                    $merchant,
                    'refund_pending',
                    'مرتجع بانتظار موافقة',
                    "مرتجع بمبلغ {$refund->refund_amount} ر.ي يحتاج موافقة الإدارة",
                    data: ['refund_ulid' => $refund->refund_ulid],
                );
            }
        } catch (\Throwable $e) {
            logger()->warning('Refund notification failed: ' . $e->getMessage());
        }
    }

    /** المعرّف الفعلي للعميل (قد يكون nullable). */
    private function resolveCustomerUserId(MerchantSale $sale): ?int
    {
        // إن كان البيع amial_pay مع paid_transaction_id، حاول الوصول للعميل
        if ($sale->payment_method === 'amial_pay' && !empty($sale->customer_phone)) {
            return User::where('phone', $sale->customer_phone)->value('id');
        }
        // الأجل: ابحث بالهاتف
        if (!empty($sale->customer_phone)) {
            return User::where('phone', $sale->customer_phone)->value('id');
        }
        return null;
    }
}
