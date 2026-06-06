<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * MoneyService — كل العمليات الحسابية المالية تمر هنا.
 *
 * المشكلة المُحلولة:
 *   PHP float لا يستطيع تمثيل 0.1 + 0.2 بدقة. كل عملية تتراكم خطأ تقريب.
 *   تحت ملايين العمليات، رصيد المحفظة ينحرف بـ سنتات → التسويات تفشل.
 *
 * الحل في v0.6:
 *   كل المبالغ تُمرر كـ string (مثل "100.50") وتُعالج بـ BC Math.
 *   هذا يضمن دقة 4 أرقام عشرية بلا فقدان.
 *
 * v0.7 (مقترح، خارج نطاق هذه الدفعة):
 *   اعتماد minor units (BIGINT). amount=100.50 → 1005000 (×10^4).
 *   يصبح كل الحساب integer arithmetic.
 *
 * هذا Service stateless — لا state، آمن للاستخدام في jobs و requests معاً.
 */
class MoneyService
{
    /** عدد المنازل العشرية المعتمدة (4 ربما لأن لدينا عملات مختلفة، يُكفي للريال) */
    public const SCALE = 4;

    /** تنسيق العرض الافتراضي للريال (2 منازل عشرية للمستخدم النهائي) */
    public const DISPLAY_SCALE = 2;

    /**
     * يضمن أن القيمة decimal string صالحة. يحول int/float بحذر.
     *
     * تحذير: نمنع تمرير float مباشرة — قد يكون 0.1 وصلنا كـ 0.10000000000000000555...
     * نطلب string من المُتصل، ونوفر converter منفصل toDecimal($val) للتعامل مع
     * inputs قديمة.
     */
    public static function normalize(string|int|float $value): string
    {
        if (is_int($value)) {
            return bcadd((string)$value, '0', self::SCALE);
        }

        if (is_float($value)) {
            // نحوّل float لـ string بأقل خطأ ممكن — نستخدم %.{SCALE}f
            $str = sprintf('%.' . self::SCALE . 'f', $value);
            return bcadd($str, '0', self::SCALE);
        }

        $value = trim($value);
        if ($value === '' || $value === null) {
            return '0.0000';
        }

        // نتحقق من شكل decimal صالح: optional sign, digits, optional dot+digits
        if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid money value: {$value}");
        }

        return bcadd($value, '0', self::SCALE);
    }

    /** a + b */
    public static function add(string|int|float $a, string|int|float $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** a - b */
    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** a * b */
    public static function mul(string|int|float $a, string|int|float $b): string
    {
        return bcmul(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** a / b */
    public static function div(string|int|float $a, string|int|float $b): string
    {
        $b = self::normalize($b);
        if (bccomp($b, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero');
        }
        return bcdiv(self::normalize($a), $b, self::SCALE);
    }

    /** a >= b ? */
    public static function gte(string|int|float $a, string|int|float $b): bool
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE) >= 0;
    }

    /** a > b ? */
    public static function gt(string|int|float $a, string|int|float $b): bool
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE) === 1;
    }

    /** a == b ? */
    public static function eq(string|int|float $a, string|int|float $b): bool
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE) === 0;
    }

    /**
     * يقارن a بـ b ويُرجع:
     *   -1 إن a < b
     *    0 إن a == b
     *    1 إن a > b
     */
    public static function compare(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** a > 0 ? */
    public static function isPositive(string|int|float $a): bool
    {
        return self::gt($a, '0');
    }

    /** a >= 0 ? */
    public static function isNonNegative(string|int|float $a): bool
    {
        return self::gte($a, '0');
    }

    /** عرض للمستخدم: 100.5000 → "100.50" */
    public static function display(string|int|float $value, int $scale = self::DISPLAY_SCALE): string
    {
        $normalized = self::normalize($value);
        // bcadd مع scale أقل = truncate (لا rounding) — للأمان نستخدم round
        // نريد rounding HALF_UP للعرض، نستخدم number_format بعد bcdiv آمن
        $factor = bcpow('10', (string)$scale, 0);
        // نقرب: round(value * factor) / factor
        $multiplied = bcmul($normalized, $factor, self::SCALE);
        $rounded = self::bcRoundHalfUp($multiplied, 0);
        return bcdiv($rounded, $factor, $scale);
    }

    /**
     * Round HALF_UP لأن BC Math لا يدعم rounding مباشرة.
     */
    private static function bcRoundHalfUp(string $value, int $scale): string
    {
        if (str_contains($value, '.')) {
            $negative = str_starts_with($value, '-');
            $abs = ltrim($value, '-');
            $parts = explode('.', $abs);
            $intPart = $parts[0];
            $fracPart = $parts[1] ?? '0';

            if (strlen($fracPart) > $scale) {
                $halfChar = $fracPart[$scale] ?? '0';
                $fracKept = substr($fracPart, 0, $scale);
                $result = $scale > 0 ? $intPart . '.' . $fracKept : $intPart;

                if ((int)$halfChar >= 5) {
                    $step = $scale > 0 ? '0.' . str_repeat('0', $scale - 1) . '1' : '1';
                    $result = bcadd($result, $step, $scale);
                }

                return ($negative ? '-' : '') . $result;
            }
        }
        return bcadd($value, '0', $scale);
    }

    /**
     * توزيع مبلغ بين عدد من المشاركين مع معالجة فرق التقريب (SPLIT-BILL-001).
     * مثال: 100.00 / 3 = [33.34, 33.33, 33.33]   (المشارك الأول يأخذ الفرق)
     *
     * يعيد array<string> بطول $participants.
     */
    public static function distribute(string|int|float $total, int $participants, int $scale = self::DISPLAY_SCALE): array
    {
        if ($participants < 1) {
            throw new InvalidArgumentException('participants must be >= 1');
        }

        $total = self::normalize($total);
        $share = self::bcRoundHalfUp(bcdiv($total, (string)$participants, self::SCALE), $scale);

        $shares = array_fill(0, $participants, $share);

        // مجموع الحصص الموزعة
        $sum = '0';
        foreach ($shares as $s) {
            $sum = bcadd($sum, $s, $scale);
        }

        // الفرق (موجب أو سالب) يُضاف على الأولى
        $diff = bcsub(self::display($total, $scale), $sum, $scale);
        if (bccomp($diff, '0', $scale) !== 0) {
            $shares[0] = bcadd($shares[0], $diff, $scale);
        }

        return $shares;
    }
}
