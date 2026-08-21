<?php

namespace App\Services;

use App\CentralLogics\Helpers;

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

            // AMIAL-LEDGER-CASHOUT-001 — الحجزُ يُرحَّل ساعةَ يقع، لا عند
            // الصرف. وداخلَ المعاملة: إمّا أن يتمّ الحجزُ وقيدُه معاً وإمّا
            // لا يتمّ شيء. (‏وهذا سببُ حذف `safeLedgerPost`.)
            $this->ledgerCashOutRequested(
                customerUserId: $customer->id,
                totalDebit: $totalDebit,
                sourceId: (string) $req->id,
            );

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
                    "تم إنشاء طلب سحب بمبلغ " . Helpers::money($req->amount) . " ر.ي. رقم العملية: {$req->op_code}. اذهب لأقرب وكيل.",
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
            $expired = $req->expires_at->isPast();
            $req->update(['status' => $expired ? 'expired' : 'cancelled']);

            $this->ledgerCashOutReleased(
                customerUserId: $customer->id,
                totalDebit: (string) $req->total_debit,
                sourceId: (string) $req->id,
                why: $expired ? 'expired' : 'cancelled',
            );

            return $req;
        });
    }

    /**
     * تنفيذ الوكيل. $identifier = هاتف العميل أو رقم حسابه أو هويته الوطنية.
     */
    public function execute(User $agent, string $opCode, string $identifier): array
    {
        // AMIAL-WITHDRAW-EXPIRE-001 — **فكُّ حجزٍ كان يُردَّ مع الاستثناء.**
        //
        // كان فكُّ الحجز ووسمُ الطلب «منتهياً» يقعان **داخل** معاملةِ
        // التنفيذ، ثمّ يُرمى `RuntimeException` في السطر التالي — فتُردّ
        // المعاملةُ كلُّها ويعود الطلبُ `pending` والحجزُ قائماً. أي أنّ
        // هذا الفرعَ لم يُنجز شيئاً قطّ منذ كُتب: يقرأ الوكيلُ «انتهت
        // الصلاحيّة» ولا يتغيّر شيء، ويبقى مالُ العميل محجوزاً حتّى يمرّ
        // كنسُ `expireStale` المجدول.
        //
        // **ولا يكشفه اختبارٌ يتحقّق من الرسالة وحدَها** — الرسالةُ صحيحة.
        // فصار الانتهاءُ يقع في معاملةٍ مستقلّةٍ **تُثبَّت** قبل أن يُرمى.
        $this->expireIfPast($opCode);

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

            // AMIAL-WITHDRAW-FIX: رقم العملية (op_code) هو المُصرّح الفعلي. تأكيد
            // الهوية اختياري (دفاع في العمق) — يُطابَق فقط إن أدخله الوكيل، ولا
            // يمنع تنفيذ عملية صحيحة. سابقاً كان إجبارياً ويقارن الهاتف حرفياً
            // (777… ≠ 967777…) فيرمي خطأً ولا تُسجَّل المعاملة إطلاقاً.
            $identifier = trim($identifier);
            if ($identifier !== '' && !$this->identifierMatches($req->customer_user_id, $identifier)) {
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

            // **الصرفُ يُرحَّل**: من الحجز إلى الوكيل وإلى إيراد المنصّة.
            // ولا خصمَ من العميل هنا — خُصم عند الطلب.
            $this->ledgerCashOutExecuted(
                agentUserId: (int) $agent->id,
                amount: (string) $req->amount,
                agentCommission: (string) $req->agent_commission,
                platformProfit: (string) $req->platform_profit,
                sourceId: (string) $req->id,
            );

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
                        "تم استلام " . Helpers::money($req->amount) . " ر.ي من الوكيل. رقم العملية: {$opCode}.",
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

            // AMIAL-RECEIPTS-002: إصدار إيصال للعميل عند اكتمال السحب.
            //
            // كان مسار السحب المبدوء من العميل هو المسار المالي الوحيد الذي لا
            // يُصدر إيصالاً — فتظهر كل العمليات في «الإيصالات» و«آخر العمليات»
            // إلا السحوبات. المستخدم يرى مالاً غادر حسابه بلا مستند يُثبته.
            //
            // يُصدَر بعد نجاح كل القيود، ويلتقط أي خطأ بصمت عبر
            // safeIssueSingleReceipt — العملية المالية تمّت، وفشل الإيصال
            // يُسجَّل ولا يُبطلها.
            $receiptNumber = $this->safeIssueSingleReceipt('debit', [
                'user_id' => $req->customer_user_id,
                'counterparty_user_id' => $agent->id,
                'reference_transaction_id' => $txId,
                'receipt_type' => 'cash_out',
                'amount' => (string) $req->amount,
                'fee' => (string) $req->fee,
                'zone_code' => $req->zone_code,
                'metadata' => [
                    'op_code' => $opCode,
                    'agent_user_id' => $agent->id,
                    'channel' => 'customer_withdraw',
                ],
            ]);

            // رقم الإيصال يُعاد ليطبعه الصرّاف للعميل الواقف أمامه.
            return [
                'transaction_id' => $txId,
                'receipt_number' => $receiptNumber,
                'request' => $req->fresh(),
            ];
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

                        // **المخرجُ الرابع** — الكنسُ المجدول. وهو الذي
                        // يُنهي أكثرَ الطلبات فعلاً: من طلب ولم يذهب إلى
                        // وكيلٍ لا يُلغي بنفسه غالباً.
                        $this->ledgerCashOutReleased(
                            customerUserId: (int) $locked->customer_user_id,
                            totalDebit: (string) $locked->total_debit,
                            sourceId: (string) $locked->id,
                            why: 'expired',
                        );
                    }
                });
                $count++;
            });
        return $count;
    }

    // ---- helpers ----

    /**
     * ينهي طلباً انتهت صلاحيّتُه **في معاملةٍ مستقلّةٍ تُثبَّت**.
     *
     * ولا يرمي: من نادى هذه يريد أن يقع الفكُّ، ثمّ يقرّر هو ما يقوله
     * للوكيل. ورميٌ من هنا يُعيد العطلَ الذي أُصلح — استثناءٌ يمرّ عبر
     * معاملةِ المنادي فيردّ فكَّه.
     */
    private function expireIfPast(string $opCode): void
    {
        DB::transaction(function () use ($opCode) {
            $req = WithdrawalRequest::where('op_code', $opCode)->lockForUpdate()->first();

            if (! $req || $req->status !== 'pending' || ! $req->expires_at->isPast()) {
                return;
            }

            $this->guard()->releaseHoldUpTo(
                $req->customer_user_id, (string) $req->total_debit, 'withdraw_expired');
            $req->update(['status' => 'expired']);

            // **المخرجُ الثالث من الحجز** — يكتشفه الوكيلُ لا العميل. وبلا
            // قيدٍ هنا يبقى الحجزُ معلّقاً في الدفتر أبداً بينما رجع المالُ
            // إلى المحفظة فعلاً: انحرافٌ دائمٌ بمقداره.
            $this->ledgerCashOutReleased(
                customerUserId: (int) $req->customer_user_id,
                totalDebit: (string) $req->total_debit,
                sourceId: (string) $req->id,
                why: 'expired',
            );
        });
    }

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

        // AMIAL-WITHDRAW-FIX: توحيد صيغة الهاتف — الوكيل قد يُدخل الرقم المحلّي
        // (777…) بينما المخزَّن بصيغة الدولة (967777…). نطابق كل الصيغ المكافئة.
        $inputVariants = \App\Support\Phone::variants($identifier);
        $storedVariants = !empty($customer->phone) ? \App\Support\Phone::variants($customer->phone) : [];
        if (array_intersect($inputVariants, $storedVariants)) return true;
        if (!empty($customer->account_number) && $customer->account_number === $identifier) return true;

        // ملاحظة: الهوية الوطنية تُخزَّن مجزّأة في سجلّات الفحص لا كحقل عميل موثوق،
        // فلا نطابق عليها هنا. الوكيل يرى بطاقة العميل بصرياً للتأكيد، ورقم العملية
        // (op_code) هو المُصرّح الفعلي الذي أنشأه العميل بنفسه.
        return false;
    }
}
