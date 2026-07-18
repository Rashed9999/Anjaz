<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\EMoney;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * FinancialGuardService — الطبقة المركزية الوحيدة المسموح لها بفحص + خصم رصيد.
 *
 * كل دالة في TransactionTrait تستدعي هذا الـ Service بدلاً من القراءة/الكتابة
 * المباشرة على EMoney. الهدف:
 *
 *   1. ضمان lockForUpdate في كل قراءة سيتبعها خصم.
 *   2. ضمان إجراء الفحص داخل DB::transaction (يرمي exception لو لا).
 *   3. ضمان رمي InsufficientBalanceException بدلاً من `return null` الغامض.
 *   4. كتابة decision في audit_decisions تلقائياً.
 *
 * مهم: هذا Service لا يبدأ DB::transaction — يفترض أن الـ caller داخل واحدة.
 * هذا متعمد لأن العملية المالية الواحدة تتضمن عدة خصم/إضافة كذرات،
 * وكلها يجب أن تكون في نفس الـ transaction.
 */
class FinancialGuardService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * يقفل صف المحفظة ويعيده. يستخدم SELECT ... FOR UPDATE.
     *
     * @throws \RuntimeException إن لم تكن داخل DB::transaction.
     */
    /**
     * AMIAL-SCALE-DEADLOCK-001 — يقفل عدة محافظ بترتيب ثابت (الأصغر user_id أولاً)
     * لمنع الـ deadlock عند العمليات المتعاكسة (A→B و B→A متزامنين).
     * يُستدعى أول كل عملية تلمس أكثر من محفظة، قبل أي debit/credit.
     */
    public function lockWalletsOrdered(array $userIds): void
    {
        $this->assertInTransaction();
        $ids = array_values(array_unique(array_filter($userIds)));
        sort($ids, SORT_NUMERIC); // ترتيب تصاعدي ثابت عبر كل العمليات
        foreach ($ids as $id) {
            $this->lockWallet((int) $id);
        }
    }

    public function lockWallet(int $userId): EMoney
    {
        $this->assertInTransaction();

        $wallet = EMoney::where('user_id', $userId)->lockForUpdate()->first();

        if (!$wallet) {
            // إنشاء محفظة لو غير موجودة — نادر، لكن آمن (mass assignment محمي في model).
            // ملاحظة: داخل transaction، الإنشاء يلتزم بـ atomicity.
            $wallet = EMoney::create([
                'user_id' => $userId,
                'current_balance' => '0.0000',
                'charge_earned' => '0.0000',
                'pending_balance' => '0.0000',
                'held_balance' => '0.0000',
                'zone_code' => 'SOUTH',
                'version' => 0,
            ]);
            // نعيد القفل (Eloquent::create لا يقفل الصف الجديد)
            $wallet = EMoney::where('user_id', $userId)->lockForUpdate()->first();
        }

        return $wallet;
    }

    /**
     * يخصم مبلغاً من محفظة المستخدم. يرمي InsufficientBalanceException عند عدم الكفاية.
     *
     * @param int    $userId
     * @param string $amount مبلغ decimal (string)
     * @return EMoney المحفظة بعد التحديث
     */
    /**
     * AMIAL-AGENT-NETWORK-001 (v2.3): قراءة المحفظة بدون قفل (للعرض/الإحصاءات).
     * لا تستخدمها للعمليات المالية — استخدم debit/credit.
     */
    public function getWallet(int $userId): ?EMoney
    {
        return EMoney::where('user_id', $userId)->first();
    }

    public function debit(int $userId, string $amount, string $reason = 'debit'): EMoney
    {
        $this->assertInTransaction();
        $amount = MoneyService::normalize($amount);

        if (!MoneyService::isPositive($amount)) {
            throw new \InvalidArgumentException('Debit amount must be positive');
        }

        $wallet = $this->lockWallet($userId);

        if (!MoneyService::gte($wallet->current_balance, $amount)) {
            // log القرار قبل رمي الـ exception
            $this->audit->record([
                'actor_type' => 'system',
                'actor_user_id' => $userId,
                'subject_type' => 'wallet',
                'subject_id' => (string)$userId,
                'action' => 'DEBIT_DENIED',
                'decision_code' => 'TX_INSUFFICIENT_BALANCE',
                'reason' => $reason,
                'severity' => 'warning',
                'context' => [
                    'required' => $amount,
                    'available' => (string)$wallet->current_balance,
                ],
            ]);

            throw new InsufficientBalanceException(
                userId: $userId,
                required: $amount,
                available: (string)$wallet->current_balance,
            );
        }

        $wallet->current_balance = MoneyService::sub($wallet->current_balance, $amount);
        $wallet->version = $wallet->version + 1;
        $wallet->save();

        return $wallet;
    }

    /**
     * يضيف مبلغاً لمحفظة المستخدم. لا فحص مطلوب لكنه atomic ومقفل.
     */
    public function credit(int $userId, string $amount, string $reason = 'credit'): EMoney
    {
        $this->assertInTransaction();
        $amount = MoneyService::normalize($amount);

        if (!MoneyService::isPositive($amount)) {
            throw new \InvalidArgumentException('Credit amount must be positive');
        }

        $wallet = $this->lockWallet($userId);
        $wallet->current_balance = MoneyService::add($wallet->current_balance, $amount);
        $wallet->version = $wallet->version + 1;
        $wallet->save();

        return $wallet;
    }

    /**
     * إضافة لرسوم admin (charge_earned). الأدمن فقط.
     */
    /**
     * AMIAL-SCALE-FEES-001 — يسجّل رسماً للمنصّة دون قفل صف الأدمن الساخن.
     *
     * بدل lockForUpdate على محفظة الأدمن الوحيدة (عنق زجاجة تحت الحمل)، نُدرج
     * قيداً append-only في platform_fee_entries. تُسوّى في رصيد الأدمن دورياً
     * عبر amial:reconcile-fees. الإدراج لا يتنافس على صف ساخن → تحمّل عالٍ.
     *
     * يُعيد محفظة الأدمن (قراءة بلا قفل) للتوافق مع لقطات الرصيد في القيود؛
     * قيمة charge_earned فيها = آخر تسوية (تقريبية للّقطة، والرسم الدقيق في القيد).
     */
    public function creditAdminCharge(int $adminUserId, string $charge, ?array $meta = null): EMoney
    {
        $this->assertInTransaction();
        $charge = MoneyService::normalize($charge);

        if (MoneyService::isPositive($charge)) {
            \App\Models\PlatformFeeEntry::create([
                'admin_user_id' => $adminUserId,
                'amount' => $charge,
                'source_type' => $meta['source_type'] ?? null,
                'transaction_id' => $meta['transaction_id'] ?? null,
                'from_user_id' => $meta['from_user_id'] ?? null,
                'zone_code' => $meta['zone_code'] ?? 'SOUTH',
                'reconciled' => false,
            ]);
        }

        // قراءة بلا قفل (لا تتنافس) — للقطة الرصيد فقط
        $wallet = EMoney::where('user_id', $adminUserId)->first();
        if (!$wallet) {
            $wallet = new EMoney([
                'user_id' => $adminUserId, 'current_balance' => '0.0000',
                'charge_earned' => '0.0000', 'pending_balance' => '0.0000',
                'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0,
            ]);
        }
        return $wallet;
    }

    /**
     * يحجز مبلغاً في held_balance (للدفع الآمن — v0.7 لكن نضع البنية الآن).
     * يخصم من current_balance ويضيف إلى held_balance — صفر-sum.
     */
    public function hold(int $userId, string $amount, string $reason): EMoney
    {
        $this->assertInTransaction();
        $amount = MoneyService::normalize($amount);

        $wallet = $this->lockWallet($userId);
        if (!MoneyService::gte($wallet->current_balance, $amount)) {
            throw new InsufficientBalanceException($userId, $amount, (string)$wallet->current_balance);
        }

        $wallet->current_balance = MoneyService::sub($wallet->current_balance, $amount);
        $wallet->held_balance = MoneyService::add($wallet->held_balance, $amount);
        $wallet->version = $wallet->version + 1;
        $wallet->save();

        return $wallet;
    }

    /**
     * يفكّ الحجز ويعيد المبلغ إلى الرصيد المتاح (إلغاء/انتهاء صلاحية).
     * held -= amount ، current += amount — صفر-sum.
     */
    public function releaseHold(int $userId, string $amount, string $reason = 'release_hold'): EMoney
    {
        $this->assertInTransaction();
        $amount = MoneyService::normalize($amount);

        $wallet = $this->lockWallet($userId);
        if (!MoneyService::gte($wallet->held_balance, $amount)) {
            throw new \RuntimeException("لا يوجد محجوز كافٍ لفكّه (user={$userId})");
        }

        $wallet->held_balance = MoneyService::sub($wallet->held_balance, $amount);
        $wallet->current_balance = MoneyService::add($wallet->current_balance, $amount);
        $wallet->version = $wallet->version + 1;
        $wallet->save();

        return $wallet;
    }

    /**
     * AMIAL-FIX(WITHDRAW-CANCEL): يفكّ من الحجز «ما هو موجود فعلاً» حتى سقف
     * المبلغ المطلوب، بدل الرمي عند النقص. لمسارات الإلغاء/انتهاء الصلاحية
     * فقط — حيث قد يكون الحجز حُرّر جزئياً من مسار آخر (كنس انتهاء الصلاحية،
     * إعادة بذر تجريبية…) ولا يجوز أن يبقى الطلب عالقاً بلا إلغاء.
     * تبقى releaseHold الصارمة للمسارات المالية الاعتيادية.
     *
     * @return string المبلغ الذي فُكّ فعلاً (قد يكون 0)
     */
    public function releaseHoldUpTo(int $userId, string $amount, string $reason = 'release_hold'): string
    {
        $this->assertInTransaction();
        $amount = MoneyService::normalize($amount);

        $wallet = $this->lockWallet($userId);
        $toRelease = MoneyService::gte($wallet->held_balance, $amount)
            ? $amount
            : MoneyService::normalize($wallet->held_balance);

        if (MoneyService::compare($toRelease, '0') <= 0) {
            return MoneyService::normalize('0');
        }

        $wallet->held_balance = MoneyService::sub($wallet->held_balance, $toRelease);
        $wallet->current_balance = MoneyService::add($wallet->current_balance, $toRelease);
        $wallet->version = $wallet->version + 1;
        $wallet->save();

        return $toRelease;
    }

    /**
     * يصرف المبلغ المحجوز نهائياً (يخرج من المحفظة) — عند تنفيذ السحب.
     * held -= amount فقط (المال غادر؛ يُضاف للوكيل/الأدمن بعمليات منفصلة).
     */
    public function captureHold(int $userId, string $amount, string $reason = 'capture_hold'): EMoney
    {
        $this->assertInTransaction();
        $amount = MoneyService::normalize($amount);

        $wallet = $this->lockWallet($userId);
        if (!MoneyService::gte($wallet->held_balance, $amount)) {
            throw new \RuntimeException("لا يوجد محجوز كافٍ لصرفه (user={$userId})");
        }

        $wallet->held_balance = MoneyService::sub($wallet->held_balance, $amount);
        $wallet->version = $wallet->version + 1;
        $wallet->save();

        return $wallet;
    }

    /**
     * يحرس أننا داخل DB::transaction. لو لم نكن، نرمي بوضوح.
     */
    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \RuntimeException(
                'FinancialGuardService methods must be called within DB::transaction(). ' .
                'Current transactionLevel=0. This is a safety guard to prevent partial writes.'
            );
        }
    }
}
