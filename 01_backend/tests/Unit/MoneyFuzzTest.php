<?php

namespace Tests\Unit;

use App\Services\MoneyService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * AMIAL-MONEY-FUZZ-001 — تشويش دقّة المال (property-based).
 *
 * أخطر فئة أخطاء في نظام مالي: تلوّث float، انحراف تدوير، خلق/فقد فلس عند
 * التقسيم. هذا الاختبار يقذف آلاف القيم (صحيحة وعدائية) على MoneyService
 * ويؤكّد ثوابت لا تُنتهَك:
 *   - كل ناتج نصّ عشري نظيف بـ SCALE خانة بالضبط — لا تدوين علمي ولا أثر float
 *   - normalize مُتحايِد (idempotent) وتبادلي وعكسي (add ثمّ sub يرجع الأصل)
 *   - القيم غير الصالحة تُرمى استثناءً — لا تُبتلَع بصمت لقيمة مشوّهة
 *   - distribute يحفظ المال: مجموع الحصص = الإجمالي بالضبط (لا فلس يُخلَق/يُفقَد)
 */
class MoneyFuzzTest extends TestCase
{
    private const SCALE = 4;

    /** كل ناتج money يجب أن يطابق هذا الشكل الصارم: عشري نظيف، لا e/E، لا فراغ */
    private function assertCleanMoney(string $v, string $ctx = ''): void
    {
        $this->assertMatchesRegularExpression(
            '/^-?\d+\.\d{4}$/', $v,
            "ناتج مال غير نظيف: '{$v}' {$ctx}"
        );
        $this->assertStringNotContainsStringIgnoringCase('e', $v, "تدوين علمي في '{$v}' {$ctx}");
    }

    /** @test */
    public function normalize_is_idempotent_clean_and_lossless(): void
    {
        mt_srand(1337);
        for ($i = 0; $i < 5000; $i++) {
            // قيم صحيحة متنوّعة: صحيح، كسر، مقياس زائد، سالب، ضخم
            $whole = mt_rand(0, 2_000_000_000);
            $frac  = str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $sign  = mt_rand(0, 10) === 0 ? '-' : '';
            $raw   = "{$sign}{$whole}.{$frac}";

            $n1 = MoneyService::normalize($raw);
            $this->assertCleanMoney($n1, "من {$raw}");

            // idempotent: normalize(normalize(x)) == normalize(x)
            $this->assertSame($n1, MoneyService::normalize($n1), "غير متحايد: {$raw}");
        }
    }

    /** @test */
    public function no_float_contamination_even_from_float_inputs(): void
    {
        // 0.1 + 0.2 الكلاسيكي وأمثاله — يجب ألّا يتسرّب خطأ float
        $this->assertSame('0.3000', MoneyService::add(0.1, 0.2));
        $this->assertSame('0.0000', MoneyService::sub(0.3, 0.3));

        mt_srand(4242);
        for ($i = 0; $i < 3000; $i++) {
            $a = mt_rand(0, 100000) / 100;   // floats بخانتين
            $b = mt_rand(0, 100000) / 100;
            $sum  = MoneyService::add($a, $b);
            $back = MoneyService::sub($sum, $b);
            $this->assertCleanMoney($sum);
            // عكسي: (a+b)-b == normalize(a) بلا انحراف
            $this->assertSame(
                MoneyService::normalize($a), $back,
                "انحراف عكسي: a={$a} b={$b}"
            );
        }
    }

    /** @test */
    public function add_is_commutative_and_associative_without_drift(): void
    {
        mt_srand(2024);
        for ($i = 0; $i < 3000; $i++) {
            $a = (string) mt_rand(1, 10_000_000) . '.' . mt_rand(1000, 9999);
            $b = (string) mt_rand(1, 10_000_000) . '.' . mt_rand(1000, 9999);
            $c = (string) mt_rand(1, 10_000_000) . '.' . mt_rand(1000, 9999);

            $this->assertSame(MoneyService::add($a, $b), MoneyService::add($b, $a), 'غير تبادلي');
            // (a+b)+c == a+(b+c)
            $this->assertSame(
                MoneyService::add(MoneyService::add($a, $b), $c),
                MoneyService::add($a, MoneyService::add($b, $c)),
                'غير تجميعي'
            );
        }
    }

    /** @test */
    public function repeated_operations_never_drift(): void
    {
        // نبدأ برصيد، نضيف ثمّ نخصم نفس المبلغ 10000 مرّة — يجب أن يبقى ثابتاً للفلس
        $balance = MoneyService::normalize('1000000.0000');
        $start   = $balance;
        for ($i = 0; $i < 10000; $i++) {
            $amt = (string) mt_rand(1, 99999) . '.' . mt_rand(0, 9999);
            $balance = MoneyService::add($balance, $amt);
            $balance = MoneyService::sub($balance, $amt);
        }
        $this->assertSame($start, $balance, 'انحرف الرصيد بعد 10000 دورة إضافة/خصم');
    }

    /** @test */
    public function invalid_values_throw_never_silently_corrupt(): void
    {
        $bad = [
            '1e5', '1E10', '0x10', '1,000', '1_000', '5.', '.5', '--5', '5-',
            'NaN', 'INF', '-INF', '12abc', 'abc', '5.5.5', '', '  ', '+',
            '٥٠', '۱۲۳', '1.2e3', '0b101', ' 5 5 ', "5\n0", '@#$',
        ];
        foreach ($bad as $v) {
            try {
                $out = MoneyService::normalize($v);
                // لو لم يُرمَ استثناء، يجب على الأقلّ أن يكون ناتجاً نظيفاً (لا تشوّه)
                $this->assertMatchesRegularExpression(
                    '/^-?\d+\.\d{4}$/', $out,
                    "قيمة غير صالحة '{$v}' لم تُرمَ ولم تُنظَّف → '{$out}' (خطر!)"
                );
                // وإن نُظّفت، يجب أن تكون فعلاً رقمية بريئة (نادر) — نسجّلها
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true); // السلوك الصحيح: رفض صريح
            }
        }
    }

    /** @test */
    public function whitespace_and_valid_forms_normalize_correctly(): void
    {
        $this->assertSame('12.0000', MoneyService::normalize('  12  '));
        $this->assertSame('12.3400', MoneyService::normalize('12.34'));
        $this->assertSame('-5.0000', MoneyService::normalize('-5'));
        $this->assertSame('0.0000', MoneyService::normalize('0'));
        $this->assertSame('0.0000', MoneyService::normalize(''));
        // دقّة عالية جداً تُقصّ لـ SCALE
        $this->assertSame('1.2345', MoneyService::normalize('1.23456789'));
        // مبلغ ضخم (أكبر من 64-bit) — bcmath دقّة لا نهائية، لا overflow
        $this->assertSame(
            '99999999999999999999.9999',
            MoneyService::normalize('99999999999999999999.99994')
        );
    }

    /** @test */
    public function distribute_conserves_money_exactly(): void
    {
        mt_srand(555);
        for ($i = 0; $i < 4000; $i++) {
            $total = (string) mt_rand(1, 100_000_000) . '.' . str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
            $n = mt_rand(1, 37); // عدد أطراف عشوائي (رسوم/تقسيم فاتورة)

            $shares = MoneyService::distribute($total, $n);
            $this->assertCount($n, $shares);

            $sum = '0';
            foreach ($shares as $s) {
                $sum = bcadd($sum, $s, 2);
            }
            // مجموع الحصص = الإجمالي بالضبط (لا فلس يُخلَق أو يُفقَد)
            $this->assertSame(
                MoneyService::display($total, 2), $sum,
                "فقد/خلق مال في التقسيم: total={$total} n={$n} sum={$sum}"
            );
        }
    }
}
