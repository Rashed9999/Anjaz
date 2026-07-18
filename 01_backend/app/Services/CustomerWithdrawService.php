<?php

namespace App\Services;

use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\NotificationService;
use App\Traits\TransactionTrait;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-CUSTOMER-WITHDRAW-001 — السحب المبدوء من العميل.
 *
 * التدفّق:
 *   1) request(): العميل يطلب → حجز (amount+fee) فوراً → عملية pending برقم ومؤقّت.
 *   2) cancel(): العميل يلغي قبل التنفيذ → فكّ الحجز.
 *   3) execute(): الوكيل يُدخل رقم العملية + معرّف العميل → صرف المحجوز،
 *      إضافة للوكيل (amount + عمولة)، ربح المنصّة، حالة completed.
 *   4) expireStale(): فكّ حجز العمليات المنتهية.
 *
 * لا SMS OTP: موافقة العميل مثبتة لأنه أنشأ الطلب بنفسه على جهازه.
 */
class CustomerWithdrawService
{
    use TransactionTrait;

    public function request(User $customer, string $amount): WithdrawalRequest
    {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ السحب يجب أن يكون موجباً');
        }
        $this->assertFinancialEligibility($customer->id);

        $breakdown = app(FeeService::class)->calculate('CASH_OUT', $amount, ['applies_to' => 'customer']);
        $fee = $breakdown['fee'];
        $totalDebit = MoneyService::add($amount, $fee); // العميل يدفع المبلغ + الرسم

        $expiryMinutes = (int) config('amial.withdraw.expiry_minutes', 45);

        return DB::transaction(function () use ($customer, $amount, $fee, $breakdown, $totalDebit, $expiryMinutes) {
            // حجز المبلغ فوراً (يمنع الإنفاق المزدوج قبل وصول الوكيل)
            $this->guard()->hold($customer->id, $totalDebit, 'withdraw_request_hold');

            $req = WithdrawalRequest::create([
                'op_code' => $this->generateOpCode(),
                'customer_user_id' => $customer->id,
                'amount' => $amount,
                'fee' => $fee,
                'agent_commission' => $breakdown['agent_commission'],
                'platform_profit' => $breakdown['platform_profit'],
                'total_debit' => $totalDebit,
                'fee_scheme_id' => $breakdown['scheme_id'],
                'fee_scheme_version' => $breakdown['scheme_version'],
                'status' => 'pending',
                'expires_at' => now()->addMinutes($expiryMinutes),
                'zone_code' => $customer->zone_code ?? 'SOUTH',
            ]);

            $this->audit()->record([
                'actor_type' => 'customer', 'actor_user_id' => $customer->id,
                'action' => 'withdraw_request', 'decision_code' => 'WITHDRAW_PENDING',
                'severity' => 'info', 'zone_code' => $req->zone_code,
                'context' => ['op_code' => $req->op_code, 'amount' => $amount],
            ]);

            // AMIAL-NOTIFICATIONS-001 — إشعار العميل بإنشاء الطلب
            try {
                app(NotificationService::class)->dispatch(
                    $customer,
                    'withdraw_pending',
                    'طلب سحب جديد',
                    "تم إنشاء طلب سحب بمبلغ {$req->amount} ر.ي. رقم العملية: {$req->op_code}. اذهب لأقرب وكيل.",
                    data: [
                        'op_code' => $req->op_code,
                        'amount' => (string)$req->amount,
                        'expires_at' => $req->expires_at->toIso8601String(),
                        'request_id' => $req->id,
                    ],
                );
            } catch (\Throwable $e) {
                logger()->warning('Withdraw notification failed: ' . $e->getMessage());
            }

            return $req;
        });
    }

    public function cancel(User $customer, int $requestId): WithdrawalRequest
    {
        return DB::transaction(function () use ($customer, $requestId) {
            $req = WithdrawalRequest::where('id', $requestId)
                ->where('customer_user_id', $customer->id)
                ->lockForUpdate()
                ->firstOrFail();

            // AMIAL-FIX(WITHDRAW-CANCEL): الملغاة/المنتهية سلفاً = نجاح صامت
            // (idempotent) — لا نرمي خطأً يمنع المستخدم من إغلاق الشاشة.
            if (in_array($req->status, ['cancelled', 'expired'], true)) {
                return $req;
            }
            if ($req->status !== 'pending') {
                throw new RuntimeException('لا يمكن إلغاء عملية غير معلّقة');
            }

            // AMIAL-FIX(WITHDRAW-CANCEL): نفكّ ما هو محجوز فعلاً حتى سقف المبلغ
            // بدل الرمي عند النقص — الحجز قد يكون حُرّر من مسار آخر (كنس
            // انتهاء الصلاحية مثلاً) بينما الطلب ما زال pending، فكان الإلغاء
            // يفشل بـ«لا يوجد محجوز كافٍ لفكّه» ويعلق المستخدم في الشاشة.
            $this->guard()->releaseHoldUpTo($customer->id, (string)$req->total_debit, 'withdraw_cancel');
            $req->update(['status' => $req->expires_at->isPast() ? 'expired' : 'cancelled']);
            return $req;
        });
    }

    /**
     * تنفيذ الوكيل. $identifier = هاتف العميل أو رقم حسابه أو هويته الوطنية.
     */
    public function execute(User $agent, string $opCode, string $identifier): array
    {
        return DB::transaction(function () use ($agent, $opCode, $identifier) {
            $req = WithdrawalRequest::where('op_code', $opCode)->lockForUpdate()->first();
            if (!$req) {
                throw new RuntimeException('رقم العملية غير صحيح');
            }
            if ($req->status === 'completed') {
                throw new RuntimeException('تم تنفيذ هذه العملية مسبقاً');
            }
            if ($req->status !== 'pending') {
                throw new RuntimeException('العملية ملغاة أو منتهية');
            }
            if ($req->expires_at->isPast()) {
                // منتهية: نفكّ الحجز ونعلّمها
                $this->guard()->releaseHoldUpTo($req->customer_user_id, (string)$req->total_debit, 'withdraw_expired');
                $req->update(['status' => 'expired']);
                throw new RuntimeException('انتهت صلاحية العملية');
            }

            // مطابقة معرّف العميل (دفاع في العمق ضد الخطأ المطبعي/الوكيل)
            if (!$this->identifierMatches($req->customer_user_id, $identifier)) {
                throw new RuntimeException('معرّف العميل لا يطابق صاحب العملية');
            }

            // حدود الوكيل (سيولة/يومي) — نعيد استخدام فحص الوكيل
            app(AgentNetworkService::class)->assertCashInAllowed($agent->id, (string)$req->amount);

            $adminId = \App\CentralLogics\Helpers::get_admin_id();
            $agentCredit = MoneyService::add((string)$req->amount, (string)$req->agent_commission);
            $txId = $this->newTransactionId();

            // 1) صرف المحجوز من العميل (المال غادر)
            $this->guard()->captureHold($req->customer_user_id, (string)$req->total_debit, 'withdraw_execute');
            // 2) إضافة للوكيل: المبلغ (تعويض النقد) + العمولة
            $this->guard()->credit($agent->id, $agentCredit, 'withdraw_agent_credit');
            // 3) ربح المنصّة
            if (MoneyService::isPositive((string)$req->platform_profit)) {
                $this->guard()->creditAdminCharge($adminId, (string)$req->platform_profit);
            }

            // قيود محاسبية
            $this->recordTransaction([
                'transaction_id' => $txId,
                'ref_trans_id' => $txId,
                'user_id' => $req->customer_user_id,
                'transaction_type' => 'cash_out',
                'debit' => (string)$req->total_debit,
                'charge' => (string)$req->fee,
                'fee_scheme_id' => $req->fee_scheme_id,
                'fee_scheme_version' => $req->fee_scheme_version,
                'reference_id' => $agent->id,
                'zone_code' => $req->zone_code,
            ]);
            $this->recordTransaction([
                'transaction_id' => (string) \Illuminate\Support\Str::ulid(),
                'ref_trans_id' => $txId,
                'user_id' => $agent->id,
                'transaction_type' => 'cash_out',
                'credit' => $agentCredit,
                'reference_id' => $req->customer_user_id,
                'zone_code' => $req->zone_code,
            ]);
            if (MoneyService::isPositive((string)$req->platform_profit)) {
                $this->recordTransaction([
                    'transaction_id' => (string) \Illuminate\Support\Str::ulid(),
                    'ref_trans_id' => $txId,
                    'user_id' => $adminId,
                    'transaction_type' => ADMIN_CHARGE,
                    'credit' => (string)$req->platform_profit,
                    'reference_id' => $req->customer_user_id,
                    'zone_code' => $req->zone_code,
                ]);
            }

            // تسجيل حركة سيولة الوكيل
            app(AgentNetworkService::class)->recordFloatMovement($agent->id, 'cash_out', (string)$req->amount);

            $req->update([
                'status' => 'completed',
                'agent_user_id' => $agent->id,
                'transaction_id' => $txId,
                'completed_at' => now(),
            ]);

            $this->audit()->record([
                'actor_type' => 'agent', 'actor_user_id' => $agent->id,
                'subject_type' => 'user', 'subject_id' => $req->customer_user_id,
                'action' => 'withdraw_execute', 'decision_code' => 'WITHDRAW_COMPLETED',
                'severity' => 'info', 'transaction_id' => $txId, 'zone_code' => $req->zone_code,
                'context' => ['op_code' => $opCode, 'amount' => (string)$req->amount],
            ]);

            // AMIAL-NOTIFICATIONS-001 — إشعار العميل بنجاح السحب
            try {
                $customer = \App\Models\User::find($req->customer_user_id);
                if ($customer) {
                    app(NotificationService::class)->dispatch(
                        $customer,
                        'withdrawal_completed',
                        'تم سحب المبلغ بنجاح',
                        "تم استلام {$req->amount} ر.ي من الوكيل. رقم العملية: {$opCode}.",
                        data: [
                            'op_code' => $opCode,
                            'amount' => (string)$req->amount,
                            'agent_id' => $agent->id,
                            'transaction_id' => $txId,
                        ],
                    );
                }
            } catch (\Throwable $e) {
                logger()->warning('Withdraw completion notification failed: ' . $e->getMessage());
            }

            return ['transaction_id' => $txId, 'request' => $req->fresh()];
        });
    }

    /** يفكّ حجز العمليات المعلّقة المنتهية (يُستدعى من أمر مجدول). */
    public function expireStale(int $limit = 200): int
    {
        $count = 0;
        WithdrawalRequest::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->limit($limit)
            ->get()
            ->each(function ($req) use (&$count) {
                DB::transaction(function () use ($req) {
                    $locked = WithdrawalRequest::where('id', $req->id)->lockForUpdate()->first();
                    if ($locked && $locked->status === 'pending') {
                        $this->guard()->releaseHoldUpTo($locked->customer_user_id, (string)$locked->total_debit, 'withdraw_expired');
                        $locked->update(['status' => 'expired']);
                    }
                });
                $count++;
            });
        return $count;
    }

    // ---- helpers ----

    private function generateOpCode(): string
    {
        do {
            $code = (string) random_int(100000000, 999999999); // 9 أرقام
        } while (WithdrawalRequest::where('op_code', $code)->exists());
        return $code;
    }

    /** يطابق معرّف العميل: هاتف أو رقم حساب (الحقلان الموثوقان على users). */
    private function identifierMatches(int $customerId, string $identifier): bool
    {
        $identifier = trim($identifier);
        $customer = User::find($customerId);
        if (!$customer) return false;

        if ($customer->phone === $identifier) return true;
        if (!empty($customer->account_number) && $customer->account_number === $identifier) return true;

        // ملاحظة: الهوية الوطنية تُخزَّن مجزّأة في سجلّات الفحص لا كحقل عميل موثوق،
        // فلا نطابق عليها هنا. الوكيل يرى بطاقة العميل بصرياً للتأكيد، ورقم العملية
        // (op_code) هو المُصرّح الفعلي الذي أنشأه العميل بنفسه.
        return false;
    }
}
