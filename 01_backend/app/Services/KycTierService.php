<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Models\EMoney;
use App\Models\User;
use App\Services\Kyc\ResidenceVerificationService;
use App\Support\YemenGovernorates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AMIAL-PROGRESSIVE-KYC-001 — محفظة تبدأ صغيرة وتكبر مع المعرفة بالعميل.
 *
 * Tier 0: الحساب موجود، لكن ملكية الهاتف لم تُثبت بعد.
 * Tier 1: هاتف مملوك + إقامة موثقة داخل نطاق التشغيل — محفظة أساسية.
 * Tier 2: هوية قانونية موثقة — حد متوسط، بلا سيلفي إلزامي.
 * Tier 3: KYC كامل + إثبات قوي لصاحب الهوية — الحدود الأعلى.
 *
 * الأرقام هنا fallback فقط؛ DB (kyc_tier_limits) هي مصدر سياسة التشغيل.
 */
class KycTierService
{
    public function effectiveTier(User $user): int
    {
        $status = app(\App\Services\Kyc\KycAccountStatusService::class)->for($user);
        if ($status['update_required']) return min(1, $status['tier']);
        if ($status['tier'] >= 2 && !$status['is_verified']) return 0;
        if ($status['tier'] >= 1 && !(bool) ($user->is_phone_verified ?? false)) return 0;
        return $status['tier'];
    }

    private const DEFAULT_LIMITS = [
        0 => [
            'name_ar' => 'غير موثق',
            'max_balance' => '0',
            'max_single_transaction' => '0',
            'max_daily_total' => '0',
            'max_monthly_total' => '0',
            'allowed_features' => [],
        ],
        1 => [
            'name_ar' => 'أساسي',
            'max_balance' => '100000',
            'max_single_transaction' => '100000',
            'max_daily_total' => '100000',
            'max_monthly_total' => '100000',
            'allowed_features' => ['send_money', 'receive_money', 'bill_pay', 'cash_out', 'merchant_pay'],
        ],
        2 => [
            'name_ar' => 'هوية موثقة',
            'max_balance' => '250000',
            'max_single_transaction' => '250000',
            'max_daily_total' => '250000',
            'max_monthly_total' => '250000',
            'allowed_features' => [
                'send_money', 'receive_money', 'bill_pay', 'cash_out', 'merchant_pay',
                'safe_payment', 'donations', 'family_fund',
            ],
        ],
        3 => [
            'name_ar' => 'كامل',
            'max_balance' => '2000000',
            'max_single_transaction' => '400000',
            'max_daily_total' => '700000',
            'max_monthly_total' => '2000000',
            'allowed_features' => ['*'],
        ],
    ];

    /**
     * سياسة مستوى بعينه كما ستطبقها العمليات فعلياً.
     *
     * أُخرجت كواجهة قراءة فقط لكي لا تنسخ واجهة العميل أرقام الحدود أو
     * المزايا داخل Flutter. قاعدة البيانات تبقى المصدر الأول، والـfallback
     * هنا يبقى المصدر الثاني نفسه الذي تستخدمه الحواجز المالية.
     */
    public function getLimits(int $tier): array
    {
        $tier = max(0, min(3, $tier));
        $dbLimit = DB::table('kyc_tier_limits')->where('tier', $tier)->where('is_active', true)->first();
        if ($dbLimit) {
            return [
                'tier' => $tier,
                'name_ar' => $dbLimit->name_ar,
                'max_balance' => (string) $dbLimit->max_balance,
                'max_single_transaction' => (string) $dbLimit->max_single_transaction,
                'max_daily_total' => (string) $dbLimit->max_daily_total,
                'max_monthly_total' => (string) $dbLimit->max_monthly_total,
                'allowed_features' => json_decode($dbLimit->allowed_features ?? '[]', true) ?? [],
            ];
        }

        return array_merge(['tier' => $tier], self::DEFAULT_LIMITS[$tier] ?? self::DEFAULT_LIMITS[0]);
    }

    private function getLimitsForUser(User $user): array
    {
        $limits = $this->getLimits($this->effectiveTier($user));
        $override = is_array($user->limit_override)
            ? $user->limit_override
            : (json_decode((string) $user->limit_override, true) ?: []);

        foreach (['max_balance', 'max_single_transaction', 'max_daily_total', 'max_monthly_total'] as $key) {
            if (!array_key_exists($key, $override)) continue;
            $value = (string) $override[$key];
            if (preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
                $limits[$key] = $value;
            }
        }
        return $limits;
    }

    /**
     * الحساب موجود فور التسجيل، لكن تحريك المال يحتاج إقامة موثقة داخل
     * المحافظات التشغيلية. الأصل وGPS لا يستطيعان اجتياز هذه البوابة.
     */
    private function assertOperationalResidence(User $user): string
    {
        $code = app(ResidenceVerificationService::class)->verifiedGovernorate($user);
        if ($code === null || empty($user->residence_verified_at)) {
            throw new RuntimeException(
                'فعّل محفظتك المالية بإثبات محل إقامتك الحالي أولاً. [RESIDENCE_NOT_VERIFIED]'
            );
        }
        if (!YemenGovernorates::isOperational($code)) {
            throw new RuntimeException(
                'محل إقامتك الموثق خارج نطاق تشغيل أميال الحالي. [RESIDENCE_OUTSIDE_OPERATIONAL_AREA]'
            );
        }
        return $code;
    }

    public function assertMinimumTier(User $user, int $tier): void
    {
        if ($this->effectiveTier($user) < $tier) {
            throw new RuntimeException('هذه العملية تتطلب مستوى توثيق أعلى.');
        }
    }

    /**
     * بوابة الميزة بلا احتساب مبلغ. مناسبة لفتح شاشة/إنشاء طلب لا يحرك مالاً.
     * لا تستخدمها مكان assertTransactionAllowed عند الخصم الفعلي.
     *
     * @return array<string,mixed> حدود المستوى الفعلية للمستخدم.
     */
    public function assertFeatureAllowed(User $user, string $feature): array
    {
        $limits = $this->getLimitsForUser($user);
        if ((int) $limits['tier'] <= 0) {
            throw new RuntimeException('تحقق من ملكية رقم هاتفك لتفعيل المحفظة.');
        }

        $this->assertOperationalResidence($user);

        $features = $limits['allowed_features'];
        if (!in_array('*', $features, true) && !in_array($feature, $features, true)) {
            throw new RuntimeException(
                "هذه الميزة تتطلب مستوى توثيق أعلى. مستواك الحالي: {$limits['name_ar']}"
            );
        }

        return $limits;
    }

    public function assertTransactionAllowed(User $user, string $amount, string $feature = 'send_money'): void
    {
        $limits = $this->assertFeatureAllowed($user, $feature);

        if (bccomp($amount, '0', 4) <= 0) {
            throw new RuntimeException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        if (bccomp($amount, $limits['max_single_transaction'], 4) > 0) {
            throw new RuntimeException(
                'المبلغ يتجاوز حد العملية الواحدة (' . Helpers::money($limits['max_single_transaction']) . ' ر.ي) لمستواك'
            );
        }

        $todayTotal = $this->getTodayTotal($user->id);
        if (bccomp(bcadd($todayTotal, $amount, 4), $limits['max_daily_total'], 4) > 0) {
            throw new RuntimeException(
                'هذه العملية ستتجاوز حدك اليومي (' . Helpers::money($limits['max_daily_total']) . ' ر.ي)'
            );
        }

        $monthTotal = $this->getMonthTotal($user->id);
        if (bccomp(bcadd($monthTotal, $amount, 4), $limits['max_monthly_total'], 4) > 0) {
            throw new RuntimeException(
                'هذه العملية ستتجاوز حدك الشهري (' . Helpers::money($limits['max_monthly_total']) . ' ر.ي)'
            );
        }
    }

    /**
     * استقبال المال قرار مختلف عن إرساله: لا نضيف المبلغ إلى إنفاق المستلم،
     * لكننا نلزم أهليته للاستلام ونمنع تجاوز حد الرصيد لمستواه.
     */
    public function assertCanReceive(User $user, string $incomingAmount): void
    {
        $this->assertFeatureAllowed($user, 'receive_money');

        if (bccomp($incomingAmount, '0', 4) <= 0) {
            throw new RuntimeException('المبلغ المستلم يجب أن يكون أكبر من صفر.');
        }

        $current = (string) (EMoney::query()
            ->where('user_id', $user->id)
            ->value('current_balance') ?? '0');

        $this->assertBalanceAllowed($user, bcadd($current, $incomingAmount, 4));
    }

    public function assertBalanceAllowed(User $user, string $newBalance): void
    {
        $limits = $this->getLimitsForUser($user);
        if ((int) $limits['tier'] <= 0) {
            throw new RuntimeException('تحقق من ملكية رقم هاتفك لتفعيل المحفظة.');
        }
        $this->assertOperationalResidence($user);

        if (bccomp($newBalance, $limits['max_balance'], 4) > 0) {
            throw new RuntimeException(
                'الرصيد سيتجاوز الحد المسموح (' . Helpers::money($limits['max_balance'])
                . ' ر.ي) لمستواك. أكمل التوثيق لرفع الحد.'
            );
        }
    }

    public function upgradeTier(User $user, int $newTier, ?int $adminId = null): void
    {
        if ($newTier < 0 || $newTier > 3) throw new RuntimeException('مستوى غير صالح');

        $user->kyc_tier = $newTier;
        $user->kyc_tier_updated_at = now();
        $user->save();

        \Log::info('KYC tier upgraded', [
            'user_id' => $user->id, 'new_tier' => $newTier, 'admin_id' => $adminId,
        ]);
    }

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
        return (string) ($total ?: '0');
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
        return (string) ($total ?: '0');
    }

    public function getUserTierInfo(User $user): array
    {
        $storedTier = max(0, min(3, (int) ($user->kyc_tier ?? 0)));
        $effectiveTier = $this->effectiveTier($user);
        $limits = $this->getLimitsForUser($user);
        $nextTier = $effectiveTier < 3 ? $this->getLimits($effectiveTier + 1) : null;
        $residence = app(ResidenceVerificationService::class)->forUser($user);

        return [
            'current_tier' => $effectiveTier,
            'stored_tier' => $storedTier,
            'tier_name' => $limits['name_ar'],
            'limits' => $limits,
            'today_used' => $this->getTodayTotal($user->id),
            'month_used' => $this->getMonthTotal($user->id),
            'next_tier' => $nextTier,
            'residence' => $residence,
        ];
    }
}
