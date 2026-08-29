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
                        A::F_CASHIER, A::F_DEBTS, A::F_REFUNDS,
                        A::F_PAYMENT_REQUESTS, A::F_SPLIT_BILL,
                    ];
                }
            },

            A::BIZ_WHOLESALE => new class extends MerchantVertical {
                public function code(): string { return A::BIZ_WHOLESALE; }

                public function own(): array
                {
                    return [
                        A::F_CASHIER, A::F_DEBTS,
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
                        A::F_PHARMACY_POS, A::F_PHARMACY_PRODUCTS,
                        A::F_PHARMACY_BATCHES, A::F_PHARMACY_ALERTS,
                        A::F_DEBTS,
                    ];
                }

                /**
                 * **و`pharmacy_customers` ليست هنا** — أُعلنت «قريباً» لأنّها
                 * بلا نقطة نهاية. ومنحُها يرسم زرّاً لا شيءَ خلفه.
                 */
                public function paidDepth(): array
                {
                    return [A::PLAN_BUSINESS => [A::F_PHARMACY_PRESCRIPTIONS]];
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
                        A::F_RESTAURANT_TABLES,
                        A::F_RESTAURANT_ORDERS,
                        A::F_RESTAURANT_KITCHEN,
                        A::F_DEBTS,
                    ];
                }
            },
        ];
    }

    public static function find(?string $code): ?MerchantVertical
    {
        return $code === null ? null : (self::all()[$code] ?? null);
    }

    /** يُفرَّغ في الاختبارات — ذاكرةٌ ساكنةٌ تعبر بينها تُنتج نتيجةً بترتيبها. */
    public static function flush(): void
    {
        self::$memo = null;
    }
}
