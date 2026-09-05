<?php

namespace App\Services;

use App\CentralLogics\Helpers;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AMIAL-KYC-TIERS-001 (v1.9)
 *
 * KycTierService — مستويات التحقق وحدودها.
 *
 * **المستويات:**
 *   Tier 0 (unverified): عرض فقط، لا عمليات مالية
 *   Tier 1 (basic): هاتف موثق — حدود منخفضة
 *   Tier 2 (standard): هوية موثقة — حدود متوسطة
 *   Tier 3 (full): توثيق كامل + عنوان — حدود عالية
 *
 * **الفائدة:**
 *   - يقلل المخاطر (مستخدم جديد لا يحرّك مبالغ كبيرة)
 *   - يطابق متطلبات AML/CFT (تدرّج حسب المعرفة بالعميل)
 *   - يحفّز المستخدمين على إكمال التوثيق
 */
class KycTierService
{
    /**
     * حدود افتراضية (تُحمَّل من DB، fallback هنا).
     */
    private const DEFAULT_LIMITS = [
        0 => [
            'name_ar' => 'غير موثق',
            'max_balance' => '0',
            'max_single_transaction' => '0',
            'max_daily_total' => '0',
            'max_monthly_total' => '0',
            'allowed_features' => [], // عرض فقط
        ],
        1 => [
            'name_ar' => 'أساسي',
            'max_balance' => '50000',
            'max_single_transaction' => '5000',
            'max_daily_total' => '10000',
            'max_monthly_total' => '50000',
            'allowed_features' => ['send_money', 'receive_money', 'bill_pay'],
        ],
        2 => [
            'name_ar' => 'قياسي',
            'max_balance' => '500000',
            'max_single_transaction' => '50000',
            'max_daily_total' => '100000',
            'max_monthly_total' => '500000',
            'allowed_features' => ['send_money', 'receive_money', 'bill_pay', 'safe_payment', 'donations', 'family_fund'],
        ],
        3 => [
            'name_ar' => 'كامل',
            'max_balance' => '5000000',
            'max_single_transaction' => '500000',
            'max_daily_total' => '1000000',
            'max_monthly_total' => '5000000',
            'allowed_features' => ['*'], // كل الميزات
        ],
    ];

    /**
     * جلب حدود مستوى معين.
     */
    private function getLimits(int $tier): array
    {
        $dbLimit = DB::table('kyc_tier_limits')->where('tier', $tier)->where('is_active', true)->first();
        if ($dbLimit) {
            return [
                'tier' => $tier,
                'name_ar' => $dbLimit->name_ar,
                'max_balance' => (string)$dbLimit->max_balance,
                'max_single_transaction' => (string)$dbLimit->max_single_transaction,
                'max_daily_total' => (string)$dbLimit->max_daily_total,
                'max_monthly_total' => (string)$dbLimit->max_monthly_total,
                'allowed_features' => json_decode($dbLimit->allowed_features ?? '[]', true) ?? [],
            ];
        }

        return array_merge(['tier' => $tier], self::DEFAULT_LIMITS[$tier] ?? self::DEFAULT_LIMITS[0]);
    }

    /**
     * فحص: هل المستخدم يستطيع تنفيذ عملية بهذا المبلغ؟
     *
     * @throws RuntimeException إذا تجاوز الحدود
     */
    public function assertTransactionAllowed(User $user, string $amount, string $feature = 'send_money'): void
    {
        $tier = (int)($user->kyc_tier ?? 0);
        $limits = $this->getLimits($tier);

        // 1) فحص الميزة مسموحة
        $features = $limits['allowed_features'];
        if (!in_array('*', $features, true) && !in_array($feature, $features, true)) {
            throw new RuntimeException(
                "هذه الميزة تتطلب مستوى توثيق أعلى. مستواك الحالي: {$limits['name_ar']}"
            );
        }

        // 2) فحص حد العملية الواحدة
        if (bccomp($amount, $limits['max_single_transaction'], 4) > 0) {
            throw new RuntimeException(
                "المبلغ يتجاوز حد العملية الواحدة (" . Helpers::money($limits['max_single_transaction']) . " ر.ي) لمستواك"
            );
        }

        // 3) فحص الحد اليومي
        $todayTotal = $this->getTodayTotal($user->id);
        $newDailyTotal = bcadd($todayTotal, $amount, 4);
        if (bccomp($newDailyTotal, $limits['max_daily_total'], 4) > 0) {
            throw new RuntimeException(
                "هذه العملية ستتجاوز حدك اليومي (" . Helpers::money($limits['max_daily_total']) . " ر.ي)"
            );
        }

        // 4) فحص الحد الشهري
        $monthTotal = $this->getMonthTotal($user->id);
        $newMonthlyTotal = bcadd($monthTotal, $amount, 4);
        if (bccomp($newMonthlyTotal, $limits['max_monthly_total'], 4) > 0) {
            throw new RuntimeException(
                "هذه العملية ستتجاوز حدك الشهري (" . Helpers::money($limits['max_monthly_total']) . " ر.ي)"
            );
        }
    }

    /**
     * فحص: هل الرصيد الجديد ضمن الحد المسموح؟
     */
    public function assertBalanceAllowed(User $user, string $newBalance): void
    {
        $tier = (int)($user->kyc_tier ?? 0);
        $limits = $this->getLimits($tier);

        if (bccomp($newBalance, $limits['max_balance'], 4) > 0) {
            throw new RuntimeException(
                "الرصيد سيتجاوز الحد المسموح (" . Helpers::money($limits['max_balance']) . " ر.ي) لمستواك. أكمل التوثيق لرفع الحد."
            );
        }
    }

    /**
     * ترقية مستوى المستخدم (بعد موافقة admin على التوثيق).
     */
    public function upgradeTier(User $user, int $newTier, ?int $adminId = null): void
    {
        if ($newTier < 0 || $newTier > 3) {
            throw new RuntimeException('مستوى غير صالح');
        }

        $user->kyc_tier = $newTier;
        $user->kyc_tier_updated_at = now();
        $user->save();

        \Log::info('KYC tier upgraded', [
            'user_id' => $user->id, 'new_tier' => $newTier, 'admin_id' => $adminId,
        ]);
    }

    /**
     * إجمالي عمليات اليوم (من الـ ledger).
     */
    private function getTodayTotal(int $userId): string
    {
        $wallet = DB::table('ledger_accounts')
            ->where('account_code', "USER_WALLET_{$userId}")->first();
        if (!$wallet) return '0';

        $total = DB::table('ledger_entry_lines')
            ->where('account_id', $wallet->id)
            ->where('direction', 'debit')
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->sum('amount');

        return (string)($total ?: '0');
    }

    private function getMonthTotal(int $userId): string
    {
        $wallet = DB::table('ledger_accounts')
            ->where('account_code', "USER_WALLET_{$userId}")->first();
        if (!$wallet) return '0';

        $total = DB::table('ledger_entry_lines')
            ->where('account_id', $wallet->id)
            ->where('direction', 'debit')
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('amount');

        return (string)($total ?: '0');
    }

    /**
     * معلومات مستوى المستخدم (للعرض).
     */
    public function getUserTierInfo(User $user): array
    {
        $tier = (int)($user->kyc_tier ?? 0);
        $limits = $this->getLimits($tier);
        $nextTier = $tier < 3 ? $this->getLimits($tier + 1) : null;

        return [
            'current_tier' => $tier,
            'tier_name' => $limits['name_ar'],
            'limits' => $limits,
            'today_used' => $this->getTodayTotal($user->id),
            'month_used' => $this->getMonthTotal($user->id),
            'next_tier' => $nextTier,
        ];
    }
}
