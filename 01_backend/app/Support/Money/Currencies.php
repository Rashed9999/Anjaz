<?php

namespace App\Support\Money;

/**
 * AMIAL-MULTI-CURRENCY-002 — **العملاتُ المدعومةُ ومصدرُها الواحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **قرارُ صاحب المشروع:** أربعُ عملاتٍ بأعيانها — الريالُ اليمنيُّ أساساً،
 * وفوقه السعوديُّ والدولارُ والدرهم. قائمةٌ مغلقةٌ لا يخترع فيها التاجرُ
 * رمزاً.
 *
 * **ولمَ مغلقة:** الرمزُ المكتوبُ بيدٍ يُخطئ (`USD` · `usd` · `US$` ·
 * `دولار`)، فيصير للتاجر الواحد محفظتان لعملةٍ واحدة، **ولا يمسك ذلك
 * حارسٌ** — كلاهما صفٌّ سليمٌ في جدولٍ سليم. والمالُ ينقسم بينهما صامتاً.
 *
 * **والأساسُ ليس تفضيلاً بل بنية:** كلُّ ما في المنصّة اليوم — عهدةُ
 * الوكيل، والتسوية، والرسوم، والحدود، والمصالحةُ الليليّة — بالريال
 * اليمنيّ. فالأساسُ هو العملةُ التي تُقاس بها الأخرياتُ ويُسوّى بها،
 * وتغييرُه ليس سطراً هنا.
 */
final class Currencies
{
    /** العملةُ الأساس — كلُّ رصيدٍ قائمٍ في المنصّة بها. */
    public const BASE = 'YER';

    /**
     * القائمةُ المغلقة: الرمز ⇒ [الاسم العربيّ، الرمز المختصر، المنازل].
     *
     * **والمنازلُ ليست زينة:** الريالُ اليمنيُّ يُتداول بلا كسورٍ عمليّاً
     * والدولارُ بمنزلتين. وتقريبٌ إلى منزلتين على مبلغٍ بالريال يُنتج
     * فروقاً تتراكم في المصالحة.
     */
    public const ALL = [
        'YER' => ['name' => 'ريال يمني', 'symbol' => 'ر.ي', 'decimals' => 2],
        'SAR' => ['name' => 'ريال سعودي', 'symbol' => 'ر.س', 'decimals' => 2],
        'USD' => ['name' => 'دولار أمريكي', 'symbol' => '$', 'decimals' => 2],
        'AED' => ['name' => 'درهم إماراتي', 'symbol' => 'د.إ', 'decimals' => 2],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists(strtoupper($code), self::ALL);
    }

    /**
     * يُطبّع الرمزَ أو يرمي — **ولا يُرجع الأساسَ صامتاً عند الجهل.**
     *
     * فردُّ `YER` على رمزٍ مجهولٍ يحوّل خطأً إلى مالٍ في المحفظة الخطأ،
     * وهو أسوأُ من رفضٍ واضح. (القاعدة السابعة: «غير معروف» ليس الأساس.)
     */
    public static function normalize(?string $code): string
    {
        $up = strtoupper(trim((string) $code));
        if (!self::isSupported($up)) {
            throw new \InvalidArgumentException("عملة غير مدعومة: {$code}");
        }

        return $up;
    }

    public static function symbol(string $code): string
    {
        return self::ALL[self::normalize($code)]['symbol'];
    }

    public static function nameAr(string $code): string
    {
        return self::ALL[self::normalize($code)]['name'];
    }

    public static function decimals(string $code): int
    {
        return self::ALL[self::normalize($code)]['decimals'];
    }

    public static function isBase(?string $code): bool
    {
        return self::isSupported($code) && self::normalize($code) === self::BASE;
    }

    /** للعرض: «١٢٬٥٠٠ ر.ي». */
    public static function format(string $amount, string $code): string
    {
        $c = self::normalize($code);

        return number_format((float) $amount, self::decimals($c)).' '.self::ALL[$c]['symbol'];
    }
}
