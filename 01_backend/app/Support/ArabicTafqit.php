<?php

namespace App\Support;

/**
 * AMIAL-TAFQIT-PHP-001 — تفقيط المبالغ بالحروف العربية.
 *
 * نسخة الخادم من نفس الخوارزمية المستعملة في التطبيق
 * (lib/util/arabic_number_words.dart) — تُستعمل في إشعارات PDF.
 *
 * التفقيط معيار مصرفي لا زينة: الرقم وحده قابل للتحريف بإضافة خانة أو نقل
 * فاصلة، أما المبلغ مكتوباً بالحروف فيُثبّت القيمة. كل إشعار من شركات
 * الصرافة اليمنية يحمله.
 */
final class ArabicTafqit
{
    private const ONES = [
        '', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة',
        'ستة', 'سبعة', 'ثمانية', 'تسعة',
    ];

    private const TENS = [
        '', 'عشرة', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون',
        'ستون', 'سبعون', 'ثمانون', 'تسعون',
    ];

    private const TEENS = [
        'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر',
        'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر',
    ];

    private const HUNDREDS = [
        '', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة',
        'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة',
    ];

    private function __construct()
    {
    }

    /** خانات المئات (1..999). */
    private static function under1000(int $n): string
    {
        $parts = [];
        $h = intdiv($n, 100);
        $rest = $n % 100;

        if ($h > 0) {
            $parts[] = self::HUNDREDS[$h];
        }

        if ($rest >= 10 && $rest < 20) {
            $parts[] = self::TEENS[$rest - 10];
        } else {
            $t = intdiv($rest, 10);
            $o = $rest % 10;
            // العربية تقدّم الآحاد على العشرات: «خمسة وعشرون»
            if ($o > 0 && $t > 0) {
                $parts[] = self::ONES[$o] . ' و' . self::TENS[$t];
            } elseif ($o > 0) {
                $parts[] = self::ONES[$o];
            } elseif ($t > 0) {
                $parts[] = self::TENS[$t];
            }
        }

        return implode(' و', $parts);
    }

    /** صيغة المعدود: مفرد/مثنّى/جمع. */
    private static function scale(int $count, string $one, string $two, string $plural): string
    {
        if ($count === 1) {
            return $one;
        }
        if ($count === 2) {
            return $two;
        }
        if ($count >= 3 && $count <= 10) {
            return self::under1000($count) . ' ' . $plural;
        }

        return self::under1000($count) . ' ' . $one;
    }

    /** يحوّل عدداً صحيحاً إلى حروف عربية (بلا اسم العملة). */
    public static function integerToWords(int $n): string
    {
        if ($n === 0) {
            return 'صفر';
        }
        if ($n < 0) {
            return 'سالب ' . self::integerToWords(-$n);
        }

        $parts = [];
        $millions = intdiv($n, 1000000);
        $thousands = intdiv($n % 1000000, 1000);
        $rest = $n % 1000;

        if ($millions > 0) {
            $parts[] = self::scale($millions, 'مليون', 'مليونان', 'ملايين');
        }
        if ($thousands > 0) {
            $parts[] = self::scale($thousands, 'ألف', 'ألفان', 'آلاف');
        }
        if ($rest > 0) {
            $parts[] = self::under1000($rest);
        }

        return implode(' و', $parts);
    }

    /**
     * التفقيط الكامل بالريال اليمني.
     * مثال: 5000 → «خمسة آلاف ريال يمني».
     */
    public static function yer(string|float|int|null $amount): string
    {
        $v = is_numeric($amount) ? (float) $amount : 0.0;
        $v = abs($v);

        $whole = (int) floor($v);
        $frac = (int) round(($v - $whole) * 100);

        $out = self::integerToWords($whole) . ' ريال يمني';

        if ($frac > 0) {
            $out .= ' و' . self::integerToWords($frac) . ' فلساً';
        }

        return $out;
    }
}
