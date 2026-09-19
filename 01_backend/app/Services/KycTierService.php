<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Models\EMoney;
use App\Models\User;
use App\Services\Kyc\ResidenceVerificationService;
use App\Support\YemenGovernorates;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * AMIAL-PROGRESSIVE-KYC-001 — محفظة تبدأ صغيرة وتكبر مع المعرفة بالعميل.
 *
 * داخلياً تبقى القيم 0..3 كما هي، لكن واجهة العميل وأسماء الحالة هي:
 * 0 = عميل غير موثق.
 * 1 = عميل موثق جزئيا.
 * 2 = عميل موثق بهوية.
 * 3 = عميل موثق.
 *
 * الأرقام مفاتيح سياسة داخلية وليست تسمية واجهة.
 *
 * AMIAL-PROGRESSIVE-KYC-TURNOVER-003
 * الحدود اليومية والشهرية والسنوية = إجمالي أصل حركة العميل (وارد + صادر).
 * الرسوم وعمولات أميال لا تستهلك حد KYC، والحركات المحجوزة تستهلكه مؤقتاً
 * حتى تنجح أو تُلغى. customer_turnover_usage هو projection القرار، والدفتر
 * يبقى مصدر الحقيقة المحاسبي.
 */
class KycTierService
{
    private const NON_USAGE_LEDGER_SOURCES = [
        'opening_balance',
        'external_adjustment',
    ];

    /**
     * السقف الأعلى الذي لا تستطيع لوحة الإدارة تجاوزه.
     * يمكن للإدارة خفض السياسة التشغيلية، لكنها لا تستطيع رفعها فوق هذه القيم.
     */
    private const POLICY_CEILINGS = [
        1 => [
            'max_balance' => '100000',
            'max_single_transaction' => '100000',
            'max_daily_total' => '100000',
            'max_monthly_total' => '100000',
            'max_annual_total' => '0',
        ],
        2 => [
            'max_balance' => '250000',
            'max_single_transaction' => '250000',
            'max_daily_total' => '250000',
            'max_monthly_total' => '250000',
            'max_annual_total' => '0',
        ],
        3 => [
            'max_balance' => '8000000',
            'max_single_transaction' => '1000000',
            'max_daily_total' => '2000000',
            'max_monthly_total' => '5000000',
            'max_annual_total' => '50000000',
        ],
    ];

    public function effectiveTier(User $user): int
    {
        $status = app(\App\Services\Kyc\KycAccountStatusService::class)->for($user);
        if ($status['update_required']) return min(1, $status['tier']);
        if ($status['tier'] >= 2 && !$status['is_verified']) {
            // طلب توثيق أعلى لا يلغي «موثق جزئيا» إن كان مكتمل الشروط:
            // الهاتف مثبت + السكن معتمد. نطاق التشغيل لا يحدد حالة KYC.
            $residence = app(ResidenceVerificationService::class)->forUser($user);

            return (bool) ($user->is_phone_verified ?? false)
                && ($residence['status'] ?? null) === ResidenceVerificationService::STATUS_VERIFIED
                    ? 1
                    : 0;
        }
        if ($status['tier'] >= 1 && !(bool) ($user->is_phone_verified ?? false)) return 0;

        // «عميل موثق جزئيا» ليس مجرد OTP هاتف. للحسابات القديمة التي
        // حصلت على tier=1 قبل إصلاح المسار، نحسبها 🟤 حتى يوجد سكن
        // معتمد. نطاق التشغيل منفصل عن حالة التوثيق.
        if ($status['tier'] === 1) {
            $residence = app(ResidenceVerificationService::class)->forUser($user);
            if (($residence['status'] ?? null) !== ResidenceVerificationService::STATUS_VERIFIED) {
                return 0;
            }
        }

        return $status['tier'];
    }

    private const DEFAULT_LIMITS = [
        0 => [
            'name_ar' => 'عميل غير موثق',
            'max_balance' => '0',
            'max_single_transaction' => '0',
            'max_daily_total' => '0',
            'max_monthly_total' => '0',
            'max_annual_total' => '0',
            'allowed_features' => [],
        ],
        1 => [
            'name_ar' => 'عميل موثق جزئيا',
            'max_balance' => '100000',
            'max_single_transaction' => '100000',
            'max_daily_total' => '100000',
            'max_monthly_total' => '100000',
            'max_annual_total' => '0',
            'allowed_features' => ['send_money', 'receive_money', 'bill_pay', 'cash_out', 'merchant_pay'],
        ],
        2 => [
            'name_ar' => 'عميل موثق بهوية',
            'max_balance' => '250000',
            'max_single_transaction' => '250000',
            'max_daily_total' => '250000',
            'max_monthly_total' => '250000',
            'max_annual_total' => '0',
            'allowed_features' => [
                'send_money', 'receive_money', 'bill_pay', 'cash_out', 'merchant_pay',
                'safe_payment', 'donations', 'family_fund',
            ],
        ],
        3 => [
            'name_ar' => 'عميل موثق',
            'max_balance' => '8000000',
            'max_single_transaction' => '1000000',
            'max_daily_total' => '2000000',
            'max_monthly_total' => '5000000',
            'max_annual_total' => '50000000',
            'allowed_features' => ['*'],
        ],
    ];

    public function getPolicyCeiling(int $tier): array
    {
        if (! isset(self::POLICY_CEILINGS[$tier])) {
            throw new DomainException('مستوى الحدود غير قابل للإدارة');
        }

        return ['tier' => $tier] + self::POLICY_CEILINGS[$tier];
    }

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
                'max_annual_total' => isset($dbLimit->max_annual_total)
                    ? (string) $dbLimit->max_annual_total
                    : '0',
                'allowed_features' => json_decode($dbLimit->allowed_features ?? '[]', true) ?? [],
            ];
        }

        return array_merge(['tier' => $tier], self::DEFAULT_LIMITS[$tier] ?? self::DEFAULT_LIMITS[0]);
    }

    /**
     * حدود العميل الفردي الفعلية بعد تطبيق أي override إداري مشروع.
     *
     * الاستثناء الفردي لا يستطيع رفع حد العميل فوق سياسة مستوى KYC.
     * هذه قاعدة أمنية متعمدة: تعديل عميل واحد ليس طريقاً خلفياً لتجاوز
     * سقف المستوى أو السقف التنظيمي.
     */
    public function getLimitsForUser(User $user): array
    {
        $base = $this->getLimits($this->effectiveTier($user));
        $limits = $base;
        $override = is_array($user->limit_override)
            ? $user->limit_override
            : (json_decode((string) $user->limit_override, true) ?: []);

        foreach ([
            'max_balance',
            'max_single_transaction',
            'max_daily_total',
            'max_monthly_total',
            'max_annual_total',
        ] as $key) {
            if (!array_key_exists($key, $override)) continue;

            $value = trim((string) $override[$key]);
            if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) continue;

            $baseValue = (string) ($base[$key] ?? '0');
            // الصفر في أي حد مالي غير السنوي يعني «ممنوع» وليس «غير محدود».
            // لذلك لا يستطيع override قديم أن يفتح Tier 0 من الصفر.
            if ($key !== 'max_annual_total' && bccomp($baseValue, '0', 4) === 0) {
                $limits[$key] = '0';
                continue;
            }

            // صفر السنوي للمستويين 1 و2 يعني «لا قيد سنوي إضافي».
            // يسمح باستثناء أكثر تحفظاً فقط إن أرادت الإدارة وضع سقف سنوي.
            if ($key === 'max_annual_total' && bccomp($baseValue, '0', 4) === 0) {
                $limits[$key] = $value;
                continue;
            }

            $limits[$key] = bccomp($value, $baseValue, 4) > 0
                ? $baseValue
                : $value;
        }

        return $limits;
    }

    /**
     * تعديل سياسة مستوى كامل من لوحة الإدارة.
     * لا DB update مباشر من Controller؛ التحقق والتدقيق والمعاملة هنا.
     */
    public function updateTierPolicy(
        int $tier,
        array $payload,
        User $actor,
        string $reason,
    ): array {
        if (! isset(self::POLICY_CEILINGS[$tier])) {
            throw new DomainException('لا يمكن تعديل هذا المستوى');
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainException('سبب تعديل السياسة إلزامي (10 أحرف على الأقل)');
        }
        if (! Schema::hasColumn('kyc_tier_limits', 'max_annual_total')) {
            throw new RuntimeException('ترحيل الحد السنوي لم يُطبّق بعد');
        }

        $fields = [
            'max_balance',
            'max_single_transaction',
            'max_daily_total',
            'max_monthly_total',
            'max_annual_total',
        ];
        $current = $this->getLimits($tier);
        $next = [];
        foreach ($fields as $field) {
            $value = array_key_exists($field, $payload)
                ? trim((string) $payload[$field])
                : (string) ($current[$field] ?? '0');

            if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
                throw new DomainException('قيمة حد غير صالحة: ' . $field);
            }

            $ceiling = (string) self::POLICY_CEILINGS[$tier][$field];
            if (bccomp($ceiling, '0', 4) === 0) {
                if (bccomp($value, '0', 4) !== 0) {
                    throw new DomainException('لا يوجد سقف سنوي إضافي لهذا المستوى؛ اترك القيمة صفراً');
                }
            } elseif (bccomp($value, '0', 4) <= 0 || bccomp($value, $ceiling, 4) > 0) {
                throw new DomainException(
                    'القيمة تتجاوز سقف أميال المسموح لهذا المستوى: ' . $field
                );
            }

            $next[$field] = $value;
        }

        if (bccomp($next['max_single_transaction'], $next['max_daily_total'], 4) > 0
            || bccomp($next['max_daily_total'], $next['max_monthly_total'], 4) > 0) {
            throw new DomainException('يجب أن يكون حد العملية ≤ اليومي ≤ الشهري');
        }
        if (bccomp($next['max_annual_total'], '0', 4) > 0
            && bccomp($next['max_monthly_total'], $next['max_annual_total'], 4) > 0) {
            throw new DomainException('يجب أن يكون الحد الشهري ≤ السنوي');
        }

        return DB::transaction(function () use ($tier, $actor, $reason, $next, $fields) {
            $row = DB::table('kyc_tier_limits')->where('tier', $tier)->lockForUpdate()->first();
            if (!$row) {
                throw new RuntimeException('سياسة المستوى غير موجودة');
            }

            $before = [];
            foreach ($fields as $field) {
                $before[$field] = (string) ($row->{$field} ?? '0');
            }

            DB::table('kyc_tier_limits')->where('tier', $tier)->update(
                $next + ['updated_at' => now()]
            );

            $auditId = app(AuditService::class)->record([
                'actor_type' => 'admin',
                'actor_user_id' => $actor->id,
                'subject_type' => 'kyc_tier_limits',
                'subject_id' => (string) $tier,
                'action' => 'KYC_TIER_LIMITS_UPDATED',
                'decision_code' => 'OK',
                'reason' => mb_substr(trim($reason), 0, 500),
                'severity' => 'critical',
                'context' => [
                    'tier' => $tier,
                    'before' => $before,
                    'after' => $next,
                    'policy_ceiling' => self::POLICY_CEILINGS[$tier],
                ],
            ]);

            if ($auditId === null) {
                throw new RuntimeException('تعذر حفظ سجل التدقيق؛ لم تُغيّر السياسة');
            }

            return [
                'message' => 'تم تحديث سياسة حدود المستوى وتوثيق القرار',
                'tier' => $tier,
                'limits' => $this->getLimits($tier),
                'ceiling' => $this->getPolicyCeiling($tier),
            ];
        });
    }

    /**
     * كل رفض حرج في سياسة العميل يجب أن يترك أثراً مرئياً للإدارة.
     * AuditService fail-soft؛ فتعطل التدقيق لا يغيّر قرار المنع نفسه.
     */
    private function recordPolicyBlock(
        User $user,
        string $code,
        string $reason,
        array $context = [],
    ): void {
        app(AuditService::class)->record([
            'actor_type' => 'system',
            'actor_user_id' => $user->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'CUSTOMER_POLICY_BLOCKED',
            'decision_code' => $code,
            'reason' => mb_substr($reason, 0, 255),
            'severity' => 'warning',
            'zone_code' => $user->zone_code ?? null,
            'context' => array_merge([
                'stored_tier' => max(0, min(3, (int) ($user->kyc_tier ?? 0))),
            ], $context),
        ]);
    }

    private function assertOperationalResidence(User $user): string
    {
        $code = app(ResidenceVerificationService::class)->verifiedGovernorate($user);
        if ($code === null || empty($user->residence_verified_at)) {
            $message = 'فعّل محفظتك المالية بإثبات محل إقامتك الحالي أولاً. [RESIDENCE_NOT_VERIFIED]';
            $this->recordPolicyBlock($user, 'RESIDENCE_NOT_VERIFIED', $message);
            throw new RuntimeException($message);
        }
        if (!YemenGovernorates::isOperational($code)) {
            $message = 'محل إقامتك الموثق خارج نطاق تشغيل أميال الحالي. [RESIDENCE_OUTSIDE_OPERATIONAL_AREA]';
            $this->recordPolicyBlock($user, 'RESIDENCE_OUTSIDE_OPERATIONAL_AREA', $message, [
                'verified_governorate' => $code,
            ]);
            throw new RuntimeException($message);
        }
        return $code;
    }

    public function assertMinimumTier(User $user, int $tier): void
    {
        if ($this->effectiveTier($user) < $tier) {
            $message = 'هذه العملية تتطلب مستوى توثيق أعلى.';
            $this->recordPolicyBlock($user, 'KYC_MINIMUM_TIER_REQUIRED', $message, [
                'required_tier' => $tier,
            ]);
            throw new RuntimeException($message);
        }
    }

    /** هل هذا حساب العميل الفردي الذي تنطبق عليه مستويات KYC؟ */
    public function isIndividualCustomer(User $user): bool
    {
        return (int) ($user->type ?? 0) === 2;
    }

    /**
     * بوابة آمنة للاستدعاءات المشتركة: لا تفرض Tier العميل على أي دور آخر.
     * تعيد null للأدوار المؤسسية عمداً؛ لها سياساتها وصلاحياتها المستقلة.
     */
    public function assertIndividualFeatureAllowed(User $user, string $feature): ?array
    {
        return $this->isIndividualCustomer($user)
            ? $this->assertFeatureAllowed($user, $feature)
            : null;
    }

    /** يفرض الميزة والحدود فقط عندما يكون صاحب الحركة عميلاً فردياً. */
    public function assertIndividualTransactionAllowed(User $user, string $amount, string $feature): void
    {
        if ($this->isIndividualCustomer($user)) {
            $this->assertTransactionAllowed($user, $amount, $feature);
        }
    }

    /** يفرض أهلية الاستقبال وحدّ الرصيد فقط على العميل الفردي المستلم. */
    public function assertIndividualCanReceive(User $user, string $incomingAmount): void
    {
        if ($this->isIndividualCustomer($user)) {
            $this->assertCanReceive($user, $incomingAmount);
        }
    }

    public function assertFeatureAllowed(User $user, string $feature): array
    {
        $limits = $this->getLimitsForUser($user);
        if ((int) $limits['tier'] <= 0) {
            $message = 'أكمل إثبات الهاتف واعتماد السكن لتفعيل المحفظة.';
            $this->recordPolicyBlock($user, 'KYC_WALLET_NOT_ACTIVATED', $message, [
                'feature' => $feature,
                'tier' => (int) $limits['tier'],
            ]);
            throw new RuntimeException($message);
        }

        $this->assertOperationalResidence($user);

        $features = $limits['allowed_features'];
        if (!in_array('*', $features, true) && !in_array($feature, $features, true)) {
            $message = "هذه الميزة تتطلب مستوى توثيق أعلى. مستواك الحالي: {$limits['name_ar']}";
            $this->recordPolicyBlock($user, 'KYC_FEATURE_NOT_ALLOWED', $message, [
                'feature' => $feature,
                'tier' => (int) $limits['tier'],
            ]);
            throw new RuntimeException($message);
        }

        return $limits;
    }

    public function assertTransactionAllowed(User $user, string $amount, string $feature = 'send_money'): void
    {
        $limits = $this->assertFeatureAllowed($user, $feature);

        if (bccomp($amount, '0', 4) <= 0) {
            throw new RuntimeException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        $this->assertMovementAllowed($user, $amount, $limits, $feature);
    }

    public function assertCanReceive(User $user, string $incomingAmount): void
    {
        $limits = $this->assertFeatureAllowed($user, 'receive_money');

        if (bccomp($incomingAmount, '0', 4) <= 0) {
            throw new RuntimeException('المبلغ المستلم يجب أن يكون أكبر من صفر.');
        }

        $this->assertMovementAllowed($user, $incomingAmount, $limits, 'receive_money');

        $current = (string) (EMoney::query()
            ->where('user_id', $user->id)
            ->value('current_balance') ?? '0');

        $this->assertBalanceAllowed($user, bcadd($current, $incomingAmount, 4));
    }

    private function assertMovementAllowed(User $user, string $amount, array $limits, string $feature = 'money_movement'): void
    {
        if (bccomp($amount, $limits['max_single_transaction'], 4) > 0) {
            $message = 'المبلغ يتجاوز حد العملية الواحدة (' . Helpers::money($limits['max_single_transaction']) . ' ر.ي) لمستواك';
            $this->recordPolicyBlock($user, 'KYC_SINGLE_LIMIT_EXCEEDED', $message, [
                'feature' => $feature,
                'tier' => (int) $limits['tier'],
                'amount' => $amount,
                'limit' => (string) $limits['max_single_transaction'],
            ]);
            throw new RuntimeException($message);
        }

        $todayTotal = $this->getTodayMovementTotal($user->id);
        if (bccomp(bcadd($todayTotal, $amount, 4), $limits['max_daily_total'], 4) > 0) {
            $remaining = $this->remaining($limits['max_daily_total'], $todayTotal);
            $message = 'هذه العملية ستتجاوز حد إجمالي الحركة اليومي ('
                . Helpers::money($limits['max_daily_total']) . ' ر.ي). المتبقي اليوم: '
                . Helpers::money($remaining) . ' ر.ي';
            $this->recordPolicyBlock($user, 'KYC_DAILY_LIMIT_EXCEEDED', $message, [
                'feature' => $feature,
                'tier' => (int) $limits['tier'],
                'amount' => $amount,
                'used' => $todayTotal,
                'limit' => (string) $limits['max_daily_total'],
            ]);
            throw new RuntimeException($message);
        }

        $monthTotal = $this->getMonthMovementTotal($user->id);
        if (bccomp(bcadd($monthTotal, $amount, 4), $limits['max_monthly_total'], 4) > 0) {
            $remaining = $this->remaining($limits['max_monthly_total'], $monthTotal);
            $message = 'هذه العملية ستتجاوز حد إجمالي الحركة الشهري ('
                . Helpers::money($limits['max_monthly_total']) . ' ر.ي). المتبقي هذا الشهر: '
                . Helpers::money($remaining) . ' ر.ي. أكمل التوثيق لرفع الحد.';
            $this->recordPolicyBlock($user, 'KYC_MONTHLY_LIMIT_EXCEEDED', $message, [
                'feature' => $feature,
                'tier' => (int) $limits['tier'],
                'amount' => $amount,
                'used' => $monthTotal,
                'limit' => (string) $limits['max_monthly_total'],
            ]);
            throw new RuntimeException($message);
        }

        $annualLimit = (string) ($limits['max_annual_total'] ?? '0');
        if (bccomp($annualLimit, '0', 4) > 0) {
            $yearTotal = $this->getYearMovementTotal($user->id);
            if (bccomp(bcadd($yearTotal, $amount, 4), $annualLimit, 4) > 0) {
                $remaining = $this->remaining($annualLimit, $yearTotal);
                $message = 'هذه العملية ستتجاوز حد إجمالي الحركة السنوي ('
                    . Helpers::money($annualLimit) . ' ر.ي). المتبقي هذه السنة: '
                    . Helpers::money($remaining) . ' ر.ي';
                $this->recordPolicyBlock($user, 'KYC_ANNUAL_LIMIT_EXCEEDED', $message, [
                    'feature' => $feature,
                    'tier' => (int) $limits['tier'],
                    'amount' => $amount,
                    'used' => $yearTotal,
                    'limit' => $annualLimit,
                ]);
                throw new RuntimeException($message);
            }
        }
    }

    private function remaining(string $limit, string $used): string
    {
        return bccomp($limit, $used, 4) > 0 ? bcsub($limit, $used, 4) : '0';
    }

    public function assertBalanceAllowed(User $user, string $newBalance): void
    {
        $limits = $this->getLimitsForUser($user);
        if ((int) $limits['tier'] <= 0) {
            $message = 'تحقق من ملكية رقم هاتفك لتفعيل المحفظة.';
            $this->recordPolicyBlock($user, 'KYC_BALANCE_NOT_ACTIVATED', $message, [
                'feature' => 'balance',
                'tier' => (int) $limits['tier'],
                'new_balance' => $newBalance,
            ]);
            throw new RuntimeException($message);
        }
        $this->assertOperationalResidence($user);

        if (bccomp($newBalance, $limits['max_balance'], 4) > 0) {
            $message = 'الرصيد سيتجاوز الحد المسموح (' . Helpers::money($limits['max_balance'])
                . ' ر.ي) لمستواك. أكمل التوثيق لرفع الحد.';
            $this->recordPolicyBlock($user, 'KYC_BALANCE_LIMIT_EXCEEDED', $message, [
                'feature' => 'balance',
                'tier' => (int) $limits['tier'],
                'new_balance' => $newBalance,
                'limit' => (string) $limits['max_balance'],
            ]);
            throw new RuntimeException($message);
        }
    }

    /**
     * الحارس المركزي لتسلسل توثيق العميل الفرد.
     *
     * لا يكفي أن تكون وثائق Tier 3 مكتملة: لا يجوز منحها لحساب لم يمر
     * فعلياً بـ Tier 2. نستخدم effectiveTier حتى لا يُحسب OTP وحده Tier 1
     * قبل اعتماد السكن. الاستثناء الوحيد هو إعادة توثيق مستوى سبق للحساب
     * امتلاكه فعلاً عند kyc_update_required.
     */
    public function assertSequentialVerificationDecision(User $user, int $targetTier): void
    {
        if (!$this->isIndividualCustomer($user)) {
            return;
        }

        if (!in_array($targetTier, [2, 3], true)) {
            $message = 'مستوى التوثيق المطلوب غير صالح لمسار قرار الهوية. [KYC_TIER_TARGET_INVALID]';
            $this->recordPolicyBlock($user, 'KYC_TIER_TARGET_INVALID', $message, [
                'feature' => 'kyc_upgrade',
                'target_tier' => $targetTier,
            ]);
            throw new DomainException($message);
        }

        $storedTier = max(0, min(3, (int) ($user->kyc_tier ?? 0)));
        $updateRequired = Schema::hasColumn('users', 'kyc_update_required')
            && (int) ($user->kyc_update_required ?? 0) === 1;

        if ($updateRequired && $storedTier >= 2) {
            $previousTier = Schema::hasColumn('users', 'kyc_update_previous_tier')
                ? max(0, min(3, (int) ($user->kyc_update_previous_tier ?? 0)))
                : 0;

            // حالة تحديث صحيحة لا يجوز أن تقول إن المستوى السابق أقل من
            // المستوى الذي ما زال الحساب يحمله؛ وإلا أمكن تنظيف Tier 3
            // بوثائق Tier 2 ثم إبقاء الحد المالي الأعلى.
            if ($previousTier >= 2 && $previousTier !== $storedTier) {
                $message = 'حالة تحديث التوثيق غير متسقة مع مستوى الحساب الحالي. '
                    . '[KYC_UPDATE_TIER_STATE_INVALID]';
                $this->recordPolicyBlock($user, 'KYC_UPDATE_TIER_STATE_INVALID', $message, [
                    'feature' => 'kyc_reverification',
                    'target_tier' => $targetTier,
                    'previous_tier' => $previousTier,
                ]);
                throw new DomainException($message);
            }

            // إعادة التوثيق ليست ترقية ولا تخفيضاً: تعيد إثبات المستوى نفسه.
            if ($targetTier === $storedTier) {
                return;
            }

            $message = 'إعادة التوثيق يجب أن تتم لمستوى الحساب الحالي نفسه. '
                . '[KYC_TIER_SEQUENCE_VIOLATION]';
            $this->recordPolicyBlock($user, 'KYC_TIER_SEQUENCE_VIOLATION', $message, [
                'feature' => 'kyc_reverification',
                'target_tier' => $targetTier,
                'current_tier' => $storedTier,
            ]);
            throw new DomainException($message);
        }

        $effectiveTier = $this->effectiveTier($user);
        $expectedTarget = $effectiveTier + 1;

        if ($effectiveTier < 1 || $targetTier !== $expectedTarget) {
            $message = 'لا يمكن تجاوز مراحل التوثيق. يجب الانتقال بالتسلسل: '
                . 'غير موثق ← موثق جزئياً ← موثق بهوية ← موثق بالكامل. '
                . '[KYC_TIER_SEQUENCE_VIOLATION]';
            $this->recordPolicyBlock($user, 'KYC_TIER_SEQUENCE_VIOLATION', $message, [
                'feature' => 'kyc_upgrade',
                'tier' => $effectiveTier,
                'target_tier' => $targetTier,
                'expected_target' => $expectedTarget,
            ]);
            throw new DomainException($message);
        }
    }

    /**
     * @deprecated تغيير مستوى العميل الفرد مباشرةً ممنوع. Tier 2 وTier 3
     * لا يُمنحان إلا من قرار KYC الموثق الذي يمر بالمستندات والمراجعة.
     */
    public function upgradeTier(User $user, int $newTier, ?int $adminId = null): void
    {
        if ($newTier < 0 || $newTier > 3) {
            throw new RuntimeException('مستوى غير صالح');
        }

        if ($this->isIndividualCustomer($user)) {
            $message = 'تغيير مستوى توثيق العميل مباشرةً ممنوع؛ استخدم مسار قرار KYC المتدرج. '
                . '[KYC_DIRECT_TIER_MUTATION_FORBIDDEN]';
            $this->recordPolicyBlock($user, 'KYC_DIRECT_TIER_MUTATION_FORBIDDEN', $message, [
                'feature' => 'kyc_upgrade',
                'target_tier' => $newTier,
                'admin_id' => $adminId,
            ]);
            throw new RuntimeException($message);
        }

        $currentTier = max(0, min(3, (int) ($user->kyc_tier ?? 0)));
        if ($newTier !== $currentTier + 1) {
            throw new RuntimeException(
                'لا يمكن تجاوز مستوى توثيق أثناء الترقية. [KYC_TIER_SEQUENCE_VIOLATION]'
            );
        }

        $user->kyc_tier = $newTier;
        $user->kyc_tier_updated_at = now();
        $user->save();

        \Log::info('KYC tier upgraded', [
            'user_id' => $user->id,
            'old_tier' => $currentTier,
            'new_tier' => $newTier,
            'admin_id' => $adminId,
        ]);
    }

    private function getTodayMovementTotal(int $userId): string
    {
        return $this->getMovementTotalSince($userId, Carbon::now()->startOfDay());
    }

    private function getMonthMovementTotal(int $userId): string
    {
        return $this->getMovementTotalSince($userId, Carbon::now()->startOfMonth());
    }

    private function getYearMovementTotal(int $userId): string
    {
        return $this->getMovementTotalSince($userId, Carbon::now()->startOfYear());
    }

    /**
     * customer_turnover_usage يحسب reserved + posted فقط؛ released لا يعود
     * يستهلك من الحد. fallback الدفتر موجود فقط قبل تنفيذ migration الجديدة.
     */
    private function getMovementTotalSince(int $userId, Carbon $since): string
    {
        if (Schema::hasTable('customer_turnover_usage')) {
            return app(CustomerTurnoverService::class)->totalSince($userId, $since);
        }

        $wallet = DB::table('ledger_accounts')
            ->where('account_code', "USER_WALLET_{$userId}")
            ->first();
        if (!$wallet) return '0';

        $total = DB::table('ledger_entry_lines as line')
            ->join('ledger_journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
            ->where('line.account_id', $wallet->id)
            ->where('journal.status', 'posted')
            ->where('journal.is_reversal', false)
            ->whereNotIn('journal.source_type', self::NON_USAGE_LEDGER_SOURCES)
            ->where('line.created_at', '>=', $since)
            ->sum('line.amount');

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
            'usage_basis' => 'principal_wallet_turnover_excluding_fees',
            'today_used' => $this->getTodayMovementTotal($user->id),
            'month_used' => $this->getMonthMovementTotal($user->id),
            'year_used' => $this->getYearMovementTotal($user->id),
            'next_tier' => $nextTier,
            'residence' => $residence,
        ];
    }
}
