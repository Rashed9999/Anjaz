<?php

namespace App\Services;

use App\Models\FeeChangeLog;
use App\Models\FeeScheme;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * AMIAL-FEE-ENGINE-001 — المصدر المركزي الوحيد لحساب أي رسم/ربح.
 *
 * بدل الدوال المبعثرة (get_sendmoney_charge, get_cashout_charge,
 * get_agent_commission ...) — كل مسار مالي ينادي FeeService::calculate().
 *
 * يضمن:
 *   - دقة decimal (MoneyService / bcmath) لا float.
 *   - platform_profit + agent_commission = fee بالضبط (لا انحراف تقريب).
 *   - لا رسم سالب.
 *   - النسخة المستخدَمة تُرجَع (scheme_id/version) ليُخزّنها المُتصل (snapshot)
 *     فتبقى العملية التاريخية مفسَّرة حتى لو تغيّرت النسبة لاحقاً.
 */
class FeeService
{
    private const CACHE_TTL = 600; // ثوانٍ
    private const CACHE_PREFIX = 'fee_scheme:';

    // ============================================================
    // الحساب
    // ============================================================

    /**
     * حساب الرسم لعملية حقيقية. يقرأ النسخة النشطة من القاعدة.
     *
     * @param string $code   كود العملية (SEND_MONEY, CASH_OUT, ...)
     * @param string|int|float $amount  أصل المبلغ
     * @param array  $context ['zone_code' => 'SOUTH', 'applies_to' => 'customer']
     * @return array تفصيل الرسم
     */
    public function calculate(string $code, string|int|float $amount, array $context = []): array
    {
        $zone = $context['zone_code'] ?? 'SOUTH';
        $appliesTo = $context['applies_to'] ?? 'customer';

        $scheme = $this->activeScheme($code, $zone, $appliesTo);

        return $this->compute($scheme, $code, $amount, $zone);
    }

    /**
     * محاكاة رسم بنسخة افتراضية (غير محفوظة) — للوحة التحكم قبل الحفظ.
     */
    public function simulate(array $schemeData, string|int|float $amount): array
    {
        $this->validate($schemeData);
        $scheme = new FeeScheme($schemeData);

        return $this->compute(
            $scheme,
            $schemeData['code'] ?? 'SIMULATION',
            $amount,
            $schemeData['zone_code'] ?? 'SOUTH'
        );
    }

    /**
     * المنطق المشترك للحساب. $scheme قد يكون null (=> رسم صفر).
     */
    private function compute(?FeeScheme $scheme, string $code, string|int|float $amount, string $zone): array
    {
        $amount = MoneyService::normalize($amount);

        if (!MoneyService::isNonNegative($amount)) {
            throw new InvalidArgumentException("Amount cannot be negative: {$amount}");
        }

        // لا توجد نسخة نشطة => العملية مجانية (رسم صفر)
        if ($scheme === null) {
            return [
                'code' => $code,
                'amount' => $amount,
                'fee' => '0.0000',
                'platform_profit' => '0.0000',
                'agent_commission' => '0.0000',
                'bearer' => 'sender',
                'total_debit' => $amount,
                'net_credit' => $amount,
                'scheme_id' => null,
                'scheme_version' => null,
                'zone_code' => $zone,
            ];
        }

        $feeType = $scheme->fee_type;

        // 1) الجزء النسبي = amount * percent / 100  (نضرب أولاً ثم نقسم لدقة أعلى)
        $percentPart = in_array($feeType, ['percent', 'percent_plus_fixed'], true)
            ? MoneyService::div(MoneyService::mul($amount, (string)$scheme->percent_rate), '100')
            : '0';

        // 2) الجزء الثابت
        $fixedPart = in_array($feeType, ['fixed', 'percent_plus_fixed'], true)
            ? MoneyService::normalize((string)$scheme->fixed_amount)
            : '0';

        $fee = MoneyService::add($percentPart, $fixedPart);

        // 3) تطبيق الحدّين (cap)
        if ($scheme->min_fee !== null && !MoneyService::gte($fee, (string)$scheme->min_fee)) {
            $fee = MoneyService::normalize((string)$scheme->min_fee);
        }
        if ($scheme->max_fee !== null && MoneyService::gt($fee, (string)$scheme->max_fee)) {
            $fee = MoneyService::normalize((string)$scheme->max_fee);
        }

        // 4) حصة الوكيل = fee * commission_percent/100 + commission_fixed، بحد أقصى = fee
        $agentCommission = MoneyService::add(
            MoneyService::div(MoneyService::mul($fee, (string)$scheme->agent_commission_percent), '100'),
            MoneyService::normalize((string)$scheme->agent_commission_fixed)
        );
        if (MoneyService::gt($agentCommission, $fee)) {
            $agentCommission = $fee; // لا تتجاوز حصة الوكيل الرسم نفسه
        }

        // 5) ربح المنصّة = الرسم − حصة الوكيل (يضمن المجموع = fee بالضبط)
        $platformProfit = MoneyService::sub($fee, $agentCommission);

        // 6) من يتحمّل الرسم
        $bearer = $scheme->bearer ?? 'sender';
        if ($bearer === 'sender') {
            $totalDebit = MoneyService::add($amount, $fee); // الدافع يدفع المبلغ + الرسم
            $netCredit = $amount;                           // المستلم يأخذ المبلغ كاملاً
        } else {
            $totalDebit = $amount;                          // الدافع يدفع المبلغ
            $netCredit = MoneyService::sub($amount, $fee);  // الرسم يُخصم من المستلم
        }

        return [
            'code' => $code,
            'amount' => $amount,
            'fee' => $fee,
            'platform_profit' => $platformProfit,
            'agent_commission' => $agentCommission,
            'bearer' => $bearer,
            'total_debit' => $totalDebit,
            'net_credit' => $netCredit,
            'scheme_id' => $scheme->id,
            'scheme_version' => $scheme->version,
            'zone_code' => $zone,
        ];
    }

    // ============================================================
    // قراءة النسخة النشطة (مع cache)
    // ============================================================

    public function activeScheme(string $code, string $zone = 'SOUTH', string $appliesTo = 'customer'): ?FeeScheme
    {
        $key = self::CACHE_PREFIX . "{$code}:{$zone}:{$appliesTo}";

        return Cache::remember($key, self::CACHE_TTL, function () use ($code, $zone, $appliesTo) {
            return FeeScheme::query()
                ->where('code', $code)
                ->where('zone_code', $zone)
                ->where('applies_to', $appliesTo)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->first();
        });
    }

    private function flushCache(string $code, string $zone, string $appliesTo): void
    {
        Cache::forget(self::CACHE_PREFIX . "{$code}:{$zone}:{$appliesTo}");
    }

    // ============================================================
    // إدارة النسخ (append-only)
    // ============================================================

    /**
     * إنشاء نسخة رسم جديدة. تُلغي النسخة النشطة السابقة لنفس
     * (code + zone + applies_to) وترفع رقم النسخة.
     */
    public function createVersion(array $data, ?int $adminId = null, ?string $ip = null): FeeScheme
    {
        $this->validate($data);

        $code = $data['code'];
        $zone = $data['zone_code'] ?? 'SOUTH';
        $appliesTo = $data['applies_to'] ?? 'customer';

        return DB::transaction(function () use ($data, $code, $zone, $appliesTo, $adminId, $ip) {
            $current = FeeScheme::query()
                ->where('code', $code)
                ->where('zone_code', $zone)
                ->where('applies_to', $appliesTo)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            $newVersion = 1;
            if ($current) {
                $newVersion = $current->version + 1;
                $old = $current->toArray();
                $current->is_active = false;
                $current->effective_to = now();
                $current->save();
                $this->log($current->id, $code, 'deactivated', $old, null, $adminId, $ip);
            }

            $scheme = FeeScheme::create([
                'code' => $code,
                'label' => $data['label'] ?? null,
                'zone_code' => $zone,
                'applies_to' => $appliesTo,
                'fee_type' => $data['fee_type'],
                'percent_rate' => MoneyService::normalize((string)($data['percent_rate'] ?? 0)),
                'fixed_amount' => MoneyService::normalize((string)($data['fixed_amount'] ?? 0)),
                'min_fee' => isset($data['min_fee']) && $data['min_fee'] !== null && $data['min_fee'] !== ''
                    ? MoneyService::normalize((string)$data['min_fee']) : null,
                'max_fee' => isset($data['max_fee']) && $data['max_fee'] !== null && $data['max_fee'] !== ''
                    ? MoneyService::normalize((string)$data['max_fee']) : null,
                'agent_commission_percent' => MoneyService::normalize((string)($data['agent_commission_percent'] ?? 0)),
                'agent_commission_fixed' => MoneyService::normalize((string)($data['agent_commission_fixed'] ?? 0)),
                'bearer' => $data['bearer'] ?? 'sender',
                'version' => $newVersion,
                'is_active' => true,
                'effective_from' => $data['effective_from'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $adminId,
            ]);

            $this->log($scheme->id, $code, 'created', null, $scheme->toArray(), $adminId, $ip);
            $this->flushCache($code, $zone, $appliesTo);

            return $scheme;
        });
    }

    /** تعطيل نسخة (لإيقاف رسم عملية مؤقتاً). */
    public function deactivate(int $schemeId, ?int $adminId = null, ?string $ip = null): void
    {
        DB::transaction(function () use ($schemeId, $adminId, $ip) {
            $scheme = FeeScheme::lockForUpdate()->findOrFail($schemeId);
            $old = $scheme->toArray();
            $scheme->is_active = false;
            $scheme->effective_to = now();
            $scheme->save();
            $this->log($scheme->id, $scheme->code, 'deactivated', $old, null, $adminId, $ip);
            $this->flushCache($scheme->code, $scheme->zone_code, $scheme->applies_to);
        });
    }

    // ============================================================
    // تحقق + تدقيق
    // ============================================================

    private function validate(array $data): void
    {
        if (empty($data['code']) || !in_array($data['code'], FeeScheme::CODES, true)) {
            throw new InvalidArgumentException('Invalid fee code.');
        }
        if (empty($data['fee_type']) || !in_array($data['fee_type'], FeeScheme::FEE_TYPES, true)) {
            throw new InvalidArgumentException('Invalid fee_type.');
        }
        $appliesTo = $data['applies_to'] ?? 'customer';
        if (!in_array($appliesTo, FeeScheme::APPLIES_TO, true)) {
            throw new InvalidArgumentException('Invalid applies_to.');
        }
        $bearer = $data['bearer'] ?? 'sender';
        if (!in_array($bearer, FeeScheme::BEARERS, true)) {
            throw new InvalidArgumentException('Invalid bearer.');
        }

        $percent = (float)($data['percent_rate'] ?? 0);
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('percent_rate must be between 0 and 100.');
        }
        $commPercent = (float)($data['agent_commission_percent'] ?? 0);
        if ($commPercent < 0 || $commPercent > 100) {
            throw new InvalidArgumentException('agent_commission_percent must be between 0 and 100.');
        }
        if ((float)($data['fixed_amount'] ?? 0) < 0) {
            throw new InvalidArgumentException('fixed_amount cannot be negative.');
        }
        if ((float)($data['agent_commission_fixed'] ?? 0) < 0) {
            throw new InvalidArgumentException('agent_commission_fixed cannot be negative.');
        }

        $min = $data['min_fee'] ?? null;
        $max = $data['max_fee'] ?? null;
        if ($min !== null && $min !== '' && $max !== null && $max !== '' && (float)$min > (float)$max) {
            throw new InvalidArgumentException('min_fee cannot be greater than max_fee.');
        }
    }

    private function log(?int $schemeId, string $code, string $action, ?array $old, ?array $new, ?int $adminId, ?string $ip): void
    {
        FeeChangeLog::create([
            'fee_scheme_id' => $schemeId,
            'code' => $code,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'admin_id' => $adminId,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
