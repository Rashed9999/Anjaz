<?php

namespace App\Domain\Verticals;

use App\Support\Access\AccessConstants as A;

/**
 * AMIAL-VERTICAL-OOP-001 — **المربّع الثالث: الورثة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **القاعدةُ التي تحكم هذا المربّعَ كلَّه:**
 *
 *   الوارثُ **يُضيف ولا يستبدل** — و**يُقتطع منه ولا يُوسَّع**.
 *
 * فالقطاعُ يرث المشتركَ ويضيف نواتَه. ونقطةُ البيع ترث قطاعَ تاجرها
 * وباقتَه **ولا تملك باقةً خاصّةً بها**. والموظّفُ يرث ذلك كلَّه **ثمّ
 * يُقتطع منه** بالدور المسنَد إليه.
 *
 * **ولا يفتح وارثٌ ما لا يفتحه مورِّثُه أبداً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقطاعاتُ منقولةٌ حرفاً بحرف** عمّا تُخرجه `AccessPresets` اليوم —
 * لا تحسينَ ولا اجتهاد. والتطابقُ محروسٌ بـ`VerticalParityGuardTest`
 * على **ثماني عشرةَ تركيبة** (ستّةُ قطاعاتٍ × ثلاثُ باقات).
 *
 * فإن تطابقت الثمانيَ عشرةَ، صار التحويلُ آمناً **رياضيّاً لا ظنّاً**.
 */
final class VerticalRegistry
{
    /** @var array<string,MerchantVertical>|null */
    private static ?array $memo = null;

    /** @return array<string,MerchantVertical> */
    public static function all(): array
    {
        return self::$memo ??= [
            A::BIZ_QUICK_SALE => new class extends MerchantVertical {
                public function code(): string { return A::BIZ_QUICK_SALE; }

                /** بائعُ السمك والخضار: بساطةٌ قصوى — بيعٌ ودَينٌ ومرتجع. */
                public function own(): array
                {
                    return [A::F_QUICK_SALE, A::F_DEBTS, A::F_REFUNDS];
                }
            },

            A::BIZ_RETAIL => new class extends MerchantVertical {
                public function code(): string { return A::BIZ_RETAIL; }

                /**
                 * **ولا `F_PRODUCTS` ولا `F_CUSTOMERS` هنا** — قرارُ صاحب
                 * المشروع: «صنفُ النشاط يقول ما ينطبق، لا ما اشتُري».
                 * فالمنتجاتُ والعملاءُ تبيعهما الباقةُ لا يمنحهما النشاط.
                 */
                public function own(): array
                {
                    return [
                    // AMIAL-VERTICAL-SCOPE-001 — **`F_QUICK_SALE` نُقلت
                    // إلى هنا من `planFeatures`**، حيث كانت تُمنَح لكلّ
                    // تاجرٍ بلا نظرٍ إلى قطاعه — ومنها محطّةُ الوقود،
                    // والتدقيق يمنعها عنها بنصّه. **ولم يفقدها هذا
                    // القطاع**: ما كان يصله من الباقة صار يصله من مربّعه.
                        A::F_CASHIER, A::F_DEBTS, A::F_REFUNDS,
                        A::F_QUICK_SALE,
                        A::F_PAYMENT_REQUESTS, A::F_SPLIT_BILL,
                    ];
                }
            },

            A::BIZ_WHOLESALE => new class extends MerchantVertical {
                public function code(): string { return A::BIZ_WHOLESALE; }

                public function own(): array
                {
                    return [
                    // AMIAL-VERTICAL-SCOPE-001 — **`F_QUICK_SALE` نُقلت
                    // إلى هنا من `planFeatures`**، حيث كانت تُمنَح لكلّ
                    // تاجرٍ بلا نظرٍ إلى قطاعه — ومنها محطّةُ الوقود،
                    // والتدقيق يمنعها عنها بنصّه. **ولم يفقدها هذا
                    // القطاع**: ما كان يصله من الباقة صار يصله من مربّعه.
                        A::F_CASHIER, A::F_DEBTS, A::F_QUICK_SALE,
                        A::F_WHOLESALE_INVOICES, A::F_WHOLESALE_COLLECTIONS,
                    ];
                }

                public function paidDepth(): array
                {
                    return [A::PLAN_BUSINESS => [A::F_WHOLESALE_MULTI_PRICING]];
                }
            },

            A::BIZ_PHARMACY => new class extends MerchantVertical {
                public function code(): string { return A::BIZ_PHARMACY; }

                /**
                 * **والدفعاتُ وتنبيهاتُ الصلاحيّة نواةٌ لا عمق**: صيدليّةٌ
                 * لا تعرف تاريخَ انتهاء دواءٍ تبيع ما لا يُباع.
                 */
                public function own(): array
                {
                    return [
                    // AMIAL-VERTICAL-SCOPE-001 — **`F_QUICK_SALE` نُقلت
                    // إلى هنا من `planFeatures`**، حيث كانت تُمنَح لكلّ
                    // تاجرٍ بلا نظرٍ إلى قطاعه — ومنها محطّةُ الوقود،
                    // والتدقيق يمنعها عنها بنصّه. **ولم يفقدها هذا
                    // القطاع**: ما كان يصله من الباقة صار يصله من مربّعه.
                        A::F_PHARMACY_POS, A::F_PHARMACY_PRODUCTS,
                        A::F_PHARMACY_BATCHES, A::F_PHARMACY_ALERTS,
                        A::F_DEBTS, A::F_QUICK_SALE,
                    ];
                }

                /**
                 * الملف الصحي للعميل مبني في الصيدلية نفسها (بحث، إضافة،
                 * تعديل، حساسية وأدوية مزمنة). يفتح في الأعمال لأنه يضيف
                 * إدارة العميل إلى البيع، لا لأنه مجرد بطاقة تسويقية.
                 */
                public function paidDepth(): array
                {
                    return [A::PLAN_BUSINESS => [
                        A::F_PHARMACY_CUSTOMERS,
                        A::F_PHARMACY_PRESCRIPTIONS,
                        A::F_PHARMACY_SUBSTITUTIONS,
                        A::F_PHARMACY_BATCH_DISPOSITION,
                    ]];
                }
            },

            A::BIZ_FUEL => new class extends MerchantVertical {
                public function code(): string { return A::BIZ_FUEL; }

                /** **ولا `F_DEBTS`** — ائتمانُ المحطّة ببطاقاتٍ لا بدفترِ دَين. */
                public function own(): array
                {
                    return [A::F_FUEL_POS, A::F_FUEL_PUMPS, A::F_FUEL_SHIFTS];
                }

                public function paidDepth(): array
                {
                    return [A::PLAN_BUSINESS => [
                        A::F_FUEL_PRODUCTS, A::F_FUEL_COMPANIES,
                        A::F_FUEL_CARDS, A::F_FUEL_VARIANCE,
                    ]];
                }
            },

            A::BIZ_RESTAURANT => new class extends MerchantVertical {
                public function code(): string { return A::BIZ_RESTAURANT; }

                /**
                 * **والثلاثةُ نواةٌ معاً.** مُنحت «الطاولات» وحدَها يوماً،
                 * فرأى صاحبُ المطعم طاولاتِه ولم يفتح طلباً واحداً — ورُدّ
                 * بـ«قطاع المطاعم متاح لحسابات المطاعم» وهو حسابُ مطعم.
                 * وطاولةٌ لا يُفتح عليها طلبٌ لا تبيع.
                 */
                public function own(): array
                {
                    return [
                    // AMIAL-VERTICAL-SCOPE-001 — **`F_QUICK_SALE` نُقلت
                    // إلى هنا من `planFeatures`**، حيث كانت تُمنَح لكلّ
                    // تاجرٍ بلا نظرٍ إلى قطاعه — ومنها محطّةُ الوقود،
                    // والتدقيق يمنعها عنها بنصّه. **ولم يفقدها هذا
                    // القطاع**: ما كان يصله من الباقة صار يصله من مربّعه.
                        A::F_RESTAURANT_TABLES,
                        A::F_RESTAURANT_ORDERS,
                        A::F_RESTAURANT_KITCHEN,
                        A::F_DEBTS, A::F_QUICK_SALE,
                    ];
                }
            },
        ];
    }

    /**
     * **وتبحث في القائم لا في المبنيّ.** لو بحثت في الستّة وحدَها لَما
     * نال قطاعٌ أنشأته الإدارةُ قدرةً واحدة: `AccessPresets` تسأل هذه
     * الدالّةَ بعينِها عن نواة القطاع وعمقِه، فتردّ `null` فتُمنَح
     * القائمةُ الفارغة — **تاجرٌ يُسجَّل في قطاعٍ ويُفتَح له لا شيء**.
     */
    public static function find(?string $code): ?MerchantVertical
    {
        return $code === null ? null : (self::current()[$code] ?? null);
    }

    // ══════════════════════════════════════════════════════════════════
    //  AMIAL-VERTICAL-COMPOSE-001 — **الستّةُ المبنيّةُ وما تُنشئه الإدارة**
    // ══════════════════════════════════════════════════════════════════

    /** @var array<string,MerchantVertical>|null */
    private static ?array $memoAll = null;

    /**
     * **الستّةُ المبنيّةُ وحدَها** — وهي ما كانت `all()` تُرجعه قبل هذا
     * المربّع. وتُقرأ منفردةً حيث يُقصد «ما في الشيفرة» لا «ما هو قائم»:
     * `VerticalParityGuardTest` يقارن بها، والقائمةُ الافتراضيّةُ في
     * التطبيق مبنيّةٌ عليها.
     *
     * @return array<string,MerchantVertical>
     */
    public static function builtIn(): array
    {
        return self::all();
    }

    /**
     * **كلُّ قطاعٍ قائمٍ الآن**: الستّةُ في الشيفرة، ثمّ ما عرّفته الإدارةُ
     * في `merchant_verticals`.
     *
     * **والمبنيُّ لا يُستبدَل من الجدول.** صفٌّ برمزٍ من الستّة يُتجاهَل
     * هنا (والمتحكّمُ يمنع إنشاءَه أصلاً، فهذا حزامٌ ثانٍ): تعديلُ قطاعٍ
     * حيٍّ يبيع اليومَ من لوحةٍ يُغيّر ما يراه تجّارٌ قائمون بلا مراجعة،
     * وحدُّ هذا المربّع **الإضافةُ لا المساس بالقائم**.
     *
     * @return array<string,MerchantVertical>
     */
    public static function current(): array
    {
        if (self::$memoAll !== null) {
            return self::$memoAll;
        }

        $out = self::all();

        foreach (\App\Models\Access\MerchantVerticalDefinition::active() as $row) {
            $code = (string) $row->code;

            if ($code === '' || isset($out[$code])) {
                continue;
            }

            $out[$code] = new DbVertical($row);
        }

        return self::$memoAll = $out;
    }

    /**
     * @return array<int,string> رموزُ كلّ القطاعات القائمة
     */
    public static function codes(): array
    {
        return array_keys(self::current());
    }

    /**
     * العناوينُ للعرض والاختيار: رمز ⇒ اسم.
     *
     * **والستّةُ تحتفظ بوصفها الكامل** من `BUSINESS_TYPE_LABELS`
     * («تجزئة (بقالة، سوبرماركت)») لأنّه ما تعرضه قائمةُ الاختيار منذ
     * البداية، والقطاعُ المُضاف يُعرَض باسمه وتلميحُه بجانبه.
     *
     * @return array<string,string>
     */
    public static function labels(): array
    {
        $out = [];

        foreach (self::current() as $code => $vertical) {
            $out[$code] = A::BUSINESS_TYPE_LABELS[$code] ?? $vertical->nameAr();
        }

        return $out;
    }

    /** أهذا القطاعُ ممّا أنشأته الإدارةُ لا ممّا في الشيفرة؟ */
    public static function isAdminDefined(?string $code): bool
    {
        return $code !== null
            && ! isset(self::all()[$code])
            && isset(self::current()[$code]);
    }

    /** يُفرَّغ في الاختبارات — ذاكرةٌ ساكنةٌ تعبر بينها تُنتج نتيجةً بترتيبها. */
    public static function flush(): void
    {
        self::$memo = null;
        self::$memoAll = null;
        \App\Models\Access\MerchantVerticalDefinition::flush();
    }
}
