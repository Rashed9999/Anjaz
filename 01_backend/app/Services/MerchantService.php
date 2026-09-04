<?php

namespace App\Services;

use App\Models\Ledger\LedgerEntryLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-MERCHANT-001 (v1.7)
 *
 * MerchantService — refund + ledger reads + daily stats.
 *
 * يستخدم LedgerService للقيود المحاسبية الحقيقية.
 */
class MerchantService
{
    use \App\Traits\EnforcesFinancialPolicy;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly FinancialGuardService $guard,
        private readonly AuditService $audit,
    ) {}

    private const DIRECT_REFUND_LIMIT = '5000.0000';

    /**
     * استرجاع مبلغ لعميل من عملية أصلية.
     *
     * @throws RuntimeException
     */
    public function processRefund(
        User $merchant,
        string $originalTransactionId,
        string $refundAmount,
        ?string $reason,
        ?int $posUserId = null,
    ): array {
        $amount = MoneyService::normalize($refundAmount);

        if (bccomp($amount, '0', 4) <= 0) {
            throw new RuntimeException('مبلغ الاسترجاع يجب أن يكون موجباً');
        }

        // AMIAL-SECURITY-AUDIT-001 (v2.1): فحص zone + sanction قبل الاسترجاع
        // (الاسترجاع لا يخضع لحدود KYC لأنه إعادة مال للعميل، لكن التاجر يجب أن يكون في SOUTH)
        $this->enforceZone($merchant);
        $this->enforceSanction($merchant);

        // ابحث عن العملية الأصلية في الـ ledger
        // (نفترض أن send_money/pay_merchant سجّل قيداً للتاجر)
        $original = DB::table('ledger_journal_entries')
            ->where('source_id', $originalTransactionId)
            ->whereIn('source_type', ['pay_merchant', 'pos_payment', 'qr_payment', 'send_money'])
            ->where('status', 'posted')
            ->first();

        if (!$original) {
            throw new RuntimeException('العملية الأصلية غير موجودة أو غير ناجحة');
        }

        // ابحث عن العميل من العملية الأصلية (من الـ ledger lines)
        $customerLine = DB::table('ledger_entry_lines as lel')
            ->join('ledger_accounts as la', 'lel.account_id', '=', 'la.id')
            ->where('lel.journal_entry_id', $original->id)
            ->where('lel.direction', 'debit') // العميل هو المدين في الدفعة الأصلية
            ->where('la.owner_type', 'user')
            ->where('la.owner_user_id', '<>', $merchant->id)
            ->first();

        if (!$customerLine) {
            throw new RuntimeException('تعذّر تحديد العميل الأصلي');
        }

        $customerUserId = $customerLine->owner_user_id;
        $originalAmount = (string) $customerLine->amount;

        // تحقق أن مبلغ الاسترجاع لا يتجاوز الأصلي - المسترجع سابقاً
        $alreadyRefunded = (string) DB::table('merchant_refunds')
            ->where('original_transaction_id', $originalTransactionId)
            ->where('status', 'completed')
            ->sum('refund_amount');

        $remaining = bcsub($originalAmount, $alreadyRefunded, 4);
        if (bccomp($amount, $remaining, 4) > 0) {
            throw new RuntimeException(
                "مبلغ الاسترجاع ({$amount}) يتجاوز المتبقي القابل للاسترجاع ({$remaining})"
            );
        }

        // هل يحتاج موافقة؟ (> 5000)
        $needsApproval = bccomp($amount, self::DIRECT_REFUND_LIMIT, 4) > 0;

        if ($needsApproval) {
            // تسجيل طلب ينتظر موافقة (بدون تنفيذ مالي)
            $refundUlid = (string) Str::ulid();
            DB::table('merchant_refunds')->insert([
                'refund_ulid' => $refundUlid,
                'merchant_user_id' => $merchant->id,
                'customer_user_id' => $customerUserId,
                'pos_user_id' => $posUserId,
                'original_transaction_id' => $originalTransactionId,
                'original_amount' => $originalAmount,
                'refund_amount' => $amount,
                'reason' => $reason ? mb_substr($reason, 0, 500) : null,
                'status' => 'pending_approval',
                'zone_code' => 'SOUTH',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'status' => 'pending_approval',
                'refund_ulid' => $refundUlid,
                'message' => 'الاسترجاع يتجاوز الحد المباشر، ينتظر موافقة الإدارة',
            ];
        }

        // تنفيذ مباشر مع قيد محاسبي
        return DB::transaction(function () use (
            $merchant, $customerUserId, $amount, $originalTransactionId,
            $originalAmount, $reason, $posUserId,
        ) {
            $refundUlid = (string) Str::ulid();

            // 1) القيد المحاسبي (double-entry)
            $merchantWallet = $this->ledger->getOrCreateUserWallet($merchant->id);
            $customerWallet = $this->ledger->getOrCreateUserWallet($customerUserId);

            $journalEntry = $this->ledger->post(
                sourceType: 'refund_merchant',
                sourceId: $refundUlid,
                description: "استرجاع من التاجر للعميل (عملية أصلية: {$originalTransactionId})",
                lines: [
                    ['account' => $merchantWallet->account_code, 'direction' => 'debit', 'amount' => $amount,
                     'description' => 'خصم استرجاع من التاجر'],
                    ['account' => $customerWallet->account_code, 'direction' => 'credit', 'amount' => $amount,
                     'description' => 'إضافة استرجاع للعميل'],
                ],
                idempotencyKey: "refund_{$refundUlid}",
                createdByUserId: $merchant->id,
                metadata: ['original_tx' => $originalTransactionId, 'reason' => $reason],
            );

            // 2) خصم/إضافة فعلي على محافظ Cash6 (legacy EMoney)
            $this->guard->debit($merchant->id, $amount, "merchant_refund:{$refundUlid}");
            $this->guard->credit($customerUserId, $amount, "merchant_refund:{$refundUlid}");

            // 3) تسجيل الـ refund record
            DB::table('merchant_refunds')->insert([
                'refund_ulid' => $refundUlid,
                'merchant_user_id' => $merchant->id,
                'customer_user_id' => $customerUserId,
                'pos_user_id' => $posUserId,
                'original_transaction_id' => $originalTransactionId,
                'original_amount' => $originalAmount,
                'refund_amount' => $amount,
                'reason' => $reason ? mb_substr($reason, 0, 500) : null,
                'status' => 'completed',
                'ledger_entry_ulid' => $journalEntry->entry_ulid,
                'zone_code' => 'SOUTH',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record([
                'actor_type' => 'merchant',
                'actor_user_id' => $merchant->id,
                'subject_type' => 'merchant_refund',
                'subject_id' => $refundUlid,
                'action' => 'MERCHANT_REFUND_COMPLETED',
                'decision_code' => 'OK',
                'severity' => 'info',
                'context' => ['amount' => $amount, 'customer' => $customerUserId],
            ]);

            return [
                'status' => 'completed',
                'refund_ulid' => $refundUlid,
                'ledger_entry' => $journalEntry->entry_ulid,
            ];
        });
    }

    /**
     * الدفتر المحاسبي للتاجر — من الـ ledger.
     */
    public function getLedger(User $merchant, int $page = 1, int $perPage = 20): array
    {
        $wallet = $this->ledger->getOrCreateUserWallet($merchant->id);

        $query = LedgerEntryLine::where('account_id', $wallet->id)
            ->with('journalEntry:id,entry_ulid,source_type,description_ar,posted_at')
            ->orderByDesc('id');

        $total = $query->count();
        $lines = $query->forPage($page, $perPage)->get();

        return [
            'account' => [
                'code' => $wallet->account_code,
                'current_balance' => $wallet->current_balance,
                'currency' => $wallet->currency,
            ],
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
            ],
            'entries' => $lines->map(fn($l) => [
                'direction' => $l->direction,
                'amount' => $l->amount,
                'balance_before' => $l->balance_before,
                'balance_after' => $l->balance_after,
                'description' => $l->description_ar ?? $l->journalEntry?->description_ar,
                'source_type' => $l->journalEntry?->source_type,
                'reference' => $l->journalEntry?->entry_ulid,
                'date' => $l->created_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * إحصاءات اليوم للتاجر.
     */
    public function getDailyStats(User $merchant): array
    {
        $wallet = $this->ledger->getOrCreateUserWallet($merchant->id);
        $startOfDay = Carbon::now()->startOfDay();

        // ══════════════════════════════════════════════════════════════
        // AMIAL-MERCHANT-RECEIVE-LIMIT-002 — **بيعٌ يُعدّ بيعاً، وتحويلٌ
        // يُقال تحويلاً.**
        //
        // سأل صاحبُ المشروع: «هل يؤثّر ذلك على تنظيم المحفظة الماليّة
        // بتحويلات دون مبيعات؟». **وقِيس فالجوابُ نعم**: كان
        // `send_money` في قائمة المصادر أدناه — أي أنّ **مالاً يرسله
        // قريبٌ إلى التاجر يُكتب في «مبيعات اليوم»**. فالرقمُ الذي يبني
        // عليه صاحبُ المتجر يومَه يحمل ما ليس بيعاً، ولا شيءَ في الشاشة
        // يقول ذلك.
        //
        // **ولا يُحذَف المال بل يُفصَل** (القاعدة السابعة: الغيابُ يُقال
        // ولا يُطوى): يخرج من «المبيعات» ويظهر في «تحويلات واردة»،
        // فيُقرأ كلٌّ على حقيقته ويبقى مجموعُ المحفظة كما هو.
        //
        // دفعُ QR يمرّ عبر `PaymentRequestService` فينشأ منه
        // `payment_request` — وإسقاطُه جعل البيعَ المؤكَّدَ يظهر في سجلّ
        // الصيدليّة بينما بطاقةُ التاجر تقول صفراً. وهذا عدٌّ من الدفتر
        // نفسِه لا تقديرٌ من واجهة البيع.
        // ══════════════════════════════════════════════════════════════
        $todaySales = (string) LedgerEntryLine::where('account_id', $wallet->id)
            ->where('direction', 'credit')
            ->where('created_at', '>=', $startOfDay)
            ->whereHas('journalEntry', fn($q) =>
                $q->whereIn('source_type', ['pay_merchant', 'pos_payment', 'qr_payment', 'payment_request']))
            ->sum('amount');

        // **تحويلاتٌ واردةٌ لا بيع** — تُعرَض باسمها ولا تُخلَط بالمبيعات.
        $todayTransfersIn = (string) LedgerEntryLine::where('account_id', $wallet->id)
            ->where('direction', 'credit')
            ->where('created_at', '>=', $startOfDay)
            ->whereHas('journalEntry', fn($q) => $q->where('source_type', 'send_money'))
            ->sum('amount');

        // الاسترجاعات اليوم (debits على حساب التاجر)
        $todayRefunds = (string) LedgerEntryLine::where('account_id', $wallet->id)
            ->where('direction', 'debit')
            ->where('created_at', '>=', $startOfDay)
            ->whereHas('journalEntry', fn($q) =>
                $q->where('source_type', 'refund_merchant'))
            ->sum('amount');

        $todayCount = LedgerEntryLine::where('account_id', $wallet->id)
            ->where('created_at', '>=', $startOfDay)
            ->count();

        return [
            'today_sales' => $todaySales ?: '0',
            'today_transfers_in' => $todayTransfersIn ?: '0',
            'today_refunds' => $todayRefunds ?: '0',
            'today_net' => bcsub($todaySales ?: '0', $todayRefunds ?: '0', 4),
            'today_count' => $todayCount,
            'current_balance' => (string) $wallet->current_balance,
        ];
    }

    public function approveRefund(string $refundUlid, User $admin): array
    {
        return DB::transaction(function () use ($refundUlid, $admin) {
            $refund = DB::table('merchant_refunds')->where('refund_ulid', $refundUlid)->lockForUpdate()->first();
            if (!$refund) throw new RuntimeException('طلب الاسترجاع غير موجود');
            if ($refund->status !== 'pending_approval') throw new RuntimeException('الطلب ليس قيد الانتظار');

            $merchantWallet = $this->ledger->getOrCreateUserWallet($refund->merchant_user_id);
            $customerWallet = $this->ledger->getOrCreateUserWallet($refund->customer_user_id);

            $journalEntry = $this->ledger->post(
                sourceType: 'refund_merchant',
                sourceId: $refundUlid,
                description: "استرجاع معتمد من الإدارة (عملية: {$refund->original_transaction_id})",
                lines: [
                    ['account' => $merchantWallet->account_code, 'direction' => 'debit', 'amount' => (string)$refund->refund_amount],
                    ['account' => $customerWallet->account_code, 'direction' => 'credit', 'amount' => (string)$refund->refund_amount],
                ],
                idempotencyKey: "refund_{$refundUlid}",
                createdByUserId: $admin->id,
            );

            $this->guard->debit($refund->merchant_user_id, (string)$refund->refund_amount, "merchant_refund:{$refundUlid}");
            $this->guard->credit($refund->customer_user_id, (string)$refund->refund_amount, "merchant_refund:{$refundUlid}");

            DB::table('merchant_refunds')->where('refund_ulid', $refundUlid)->update([
                'status' => 'completed',
                'approved_by_admin_id' => $admin->id,
                'approved_at' => now(),
                'ledger_entry_ulid' => $journalEntry->entry_ulid,
                'updated_at' => now(),
            ]);

            return ['status' => 'completed', 'ledger_entry' => $journalEntry->entry_ulid];
        });
    }
}
