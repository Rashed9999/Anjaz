<?php

namespace App\Services\Access;

use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use App\Support\Access\CapabilityRegistry;

/**
 * AMIAL-PLAN-COMPARE-001 — **الفرقُ بين الباقات يُحسب، لا يُكتب مرّتين.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «أريد تحسينَ شكل الخطط، تبدو بداية. ووعودٌ أكثر — ويجب
 * التوضيحُ: الفرقُ بين كلّ باقةٍ بكلّ احترافيّة».
 *
 * **وقِيس قبل أن يُرسَم شيء، فالمشكلةُ لم تكن شكلاً:**
 *
 *     اتّحادُ ميزات الباقات الثلاث        →  ٣٩ ميزة
 *     المترجَمُ في خريطة `_featureLabel`  →  ١٩
 *     يُعرَض **رمزاً إنجليزيّاً خاماً**    →  ٢٠
 *     وله اسمٌ عربيٌّ في `CapabilityRegistry` → **٣٩ — كلُّها**
 *
 * فجدولُ المقارنة كان يعرض نصفَ صفوفه هكذا: `advanced_reports` ·
 * `wholesale_credit` · `fuel_shift_reconcile`. **والأسماءُ العربيّةُ
 * موجودةٌ كلُّها في الخادم** — كتبها صاحبُ المشروع نفسُه في السجلّ، ومعها
 * وصفٌ لكلّ قدرةٍ ومجموعتُها. والشاشةُ لا تسألها.
 *
 * وهي **رابعُ قائمةٍ موازيةٍ تشيخ** في هذا المشروع (بعد مرشّحات التدقيق،
 * و`AccessPresets` مقابل السجلّ، وكتالوج «خدمات التاجر») — تُضاف ميزةٌ
 * في الخادم فتظهر في الجدول **برمزها الإنجليزيّ إلى الأبد**، ولا خطأ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * **وما الذي يجعل المقارنةَ «احترافيّة»؟ ليس التزيين — بل ثلاثةُ أشياء:**
 *
 *   ① **أن يُقال ما تضيفه كلُّ باقةٍ على ما قبلها**، لا أن تُسرَد كلُّ
 *      ميزةٍ في كلّ عمود. القارئُ يسأل «ماذا أكسب إن رقّيت؟» وجدولٌ
 *      يُعيد التسعين المشتركةَ في العمودين يُخفي العشرَ الفارقة.
 *
 *   ② **وأن تُجمَّع بمعناها** — «الناس» و«المال والتقارير» و«المخزون» —
 *      لا قائمةً مسطّحةً من تسعةٍ وثلاثين سطراً.
 *
 *   ③ **وأن يُقال ما لا تفتحه الترقيةُ أبداً**: قدراتُ القطاع (صيدليّة ·
 *      وقود · جملة) تخصّ نشاطَك لا باقتَك. وبلا هذا يرقّي صاحبُ البقالة
 *      ليحصل على «الوصفات الطبيّة» فلا يجدها — **وعدٌ لا يُوفى**.
 *
 * **ولا يُخترَع نصٌّ تسويقيّ لقدرةٍ**: الاسمُ والوصفُ من السجلّ حرفاً
 * بحرف. وسطرُ الوعد لكلّ باقةٍ هو الشيءُ الوحيدُ المكتوبُ هنا، وهو **حكمٌ
 * على من هي له** لا وعدٌ بميزة.
 *
 * يظهر في : التطبيق ← الباقات (البطاقات · «ما يضيفه» · جدول المقارنة).
 * وفي لوحة الإدارة: لا. ويُوصل إليه من : «خدمات التاجر» ← ترقية ·
 * و«مزايا باقتي» · وكلِّ نافذةِ قدرةٍ مقفلة.
 *
 * @see \Tests\Feature\PlanComparisonGuardTest
 */
class PlanComparisonService
{
    /**
     * **سطرُ الوعد لكلّ باقة** — ولمن هي، لا ماذا فيها.
     *
     * وهو المكتوبُ الوحيدُ هنا: البقيّةُ محسوبةٌ من السجلّ. وقائمةٌ تُكتب
     * هنا لكلّ ميزةٍ تُعيد العطلَ الذي وُلدت هذه الخدمةُ لإصلاحه.
     */
    private const PITCH = [
        A::PLAN_FREE => [
            'headline' => 'ابدأ اليوم بلا بطاقة',
            'for_whom' => 'لمن يبيع بنفسه ويريد فاتورةً وحساباً نظيفاً.',
            'promise' => 'بِع واقبض واطبع، واعرف ربحَ يومك — بلا اشتراك.',
        ],
        A::PLAN_BUSINESS => [
            'headline' => 'حين يكبر المحلّ عن يدٍ واحدة',
            'for_whom' => 'لمن صار له موظّفون ومخزونٌ يُدار وأصنافٌ تُسعَّر.',
            'promise' => 'افتح الأصناف والمخزون والموظّفين، وارفع سقفَ عمليّاتك.',
        ],
        A::PLAN_ENTERPRISE => [
            'headline' => 'فروعٌ وتقاريرُ تُقنع محاسباً',
            'for_whom' => 'لمن يدير أكثر من فرعٍ ويحتاج رقابةً وتقاريرَ عميقة.',
            'promise' => 'كلُّ شيءٍ في «الأعمال»، ومعه الفروعُ والرقابةُ والتحليل.',
        ],
    ];

    /**
     * كتالوجُ المقارنة الكامل — **لنشاطٍ بعينه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **العطلُ الذي أُصلح هنا، وقد قِيس:**
     *
     *     ما تَعِد به «الأعمال» في الشاشة  →  retail.catalog · retail.variants
     *                                        · retail.price_versions
     *                                        · retail.waste
     *                                        · retail.returns.by_line
     *     ما تأخذه صيدليّةٌ منها فعلاً      →  **صفر**
     *
     * `AccessPresets::planFeatures()` تُرجع قائمةَ الباقة **خاماً**، وهي
     * ليست ما يصل التاجر: `FeatureAccessService::resolveFeatures` تمرّ
     * بعدها على `CapabilityRegistry` فتنزع كلَّ قدرةٍ لا تنطبق على نوع
     * النشاط. **فالمحرّكُ سليمٌ والشاشةُ وحدَها تَعِد بما لا يُعطى.**
     *
     * وأسوأُ ما فيه أنّ الملاحظةَ أسفلَ الجدول كانت تُسمّي «صيدليّة ·
     * محطّة وقود · جملة» ولا تذكر التجزئة — **فيقرؤها صاحبُ الصيدليّة
     * عكسَ مرادها**: «قدراتُ الصيدليّة لا تفتحها الترقيةُ، إذن قدراتُ
     * التجزئة المذكورةُ تحت الباقة تُفتَح». وهي لا تُفتَح.
     *
     * فصار الحسابُ يمرّ بالمرشِّح نفسِه الذي يحكم وقتَ التشغيل — **مصدرٌ
     * واحدٌ للحقيقة، لا حسابان يفترقان.**
     *
     * @param  string|null  $businessType  نوعُ نشاط القارئ. و`null` تعني
     *                                     «لا نعرف بعد» — فتُعرَض القائمةُ
     *                                     الخام ويُقال ذلك في الملاحظة،
     *                                     ولا تُقدَّم على أنّها مضمونة.
     *
     * @return array{plans:array<int,array<string,mixed>>,vertical_note:string}
     */
    public function catalogue(?string $businessType = null): array
    {
        $ladder = A::ALL_PLANS;
        $out = [];
        $previousCodes = [];

        foreach ($ladder as $i => $plan) {
            $codes = $this->grantedFor($businessType, $plan);

            // **ما تضيفه هذه الباقةُ على التي تحتها** — وهو جوابُ السؤال
            // الذي يسأله من يفكّر في الترقية.
            $added = array_values(array_diff($codes, $previousCodes));

            $out[] = [
                'code' => $plan,
                'label' => A::PLAN_LABELS[$plan] ?? $plan,
                'price_monthly' => A::PLAN_PRICES_SAR[$plan] ?? 0,
                'price_annual' => A::PLAN_PRICES_SAR_ANNUAL[$plan] ?? 0,

                // **العملةُ تُقال ولا تُحوَّل** — والرصيدُ في المنتج يمنيّ
                // والسعرُ سعوديّ. (`amial-financial-truth`، والقسمُ في
                // `CLAUDE.md`: «رقمٌ صحيحٌ بعملةٍ كاذبة».)
                'currency' => A::PLAN_PRICE_CURRENCY,

                'is_free' => $plan === A::PLAN_FREE,
                'is_top' => $i === count($ladder) - 1,
                'pitch' => self::PITCH[$plan] ?? null,
                'limits' => $this->limits($plan),
                'adds' => $this->describe($added),
                'adds_count' => count($added),
                'total_count' => count($codes),
            ];

            $previousCodes = $codes;
        }

        return [
            'plans' => $out,
            'groups' => $this->groupOrder(),
            'business_type' => $businessType,
            'vertical_note' => $this->verticalNote($businessType),

            // **وما حُجب عن هذا النشاط يُقال بعدده** — فالقارئُ يعلم أنّ
            // الجدولَ مفصَّلٌ له لا قائمةً عامّة.
            'withheld_count' => count($this->withheldFrom($businessType)),
        ];
    }

    /**
     * **ما يصل هذا النشاطَ فعلاً في هذه الباقة** — بالمرشِّح نفسِه.
     *
     * ولا يُعاد بناءُ المنطق هنا: `resolveFeatures` هي الحَكَم وقتَ
     * التشغيل، **فحسابٌ ثانٍ يوازيها يفترق عنها يومَ تتغيّر**. (وهي
     * القائمةُ الموازيةُ الخامسةُ التي كادت تُولَد في هذا المشروع.)
     *
     * @return array<int,string>
     */
    private function grantedFor(?string $businessType, string $plan): array
    {
        if ($businessType === null) {
            return AccessPresets::planFeatures($plan);
        }

        $resolved = app(\App\Services\FeatureAccessService::class)->resolveFeatures(
            A::ROLE_MERCHANT, 'verified', $businessType, $plan);

        // **ويُقتصر على ما تفتحه الباقةُ** — لا ما يفتحه الدورُ أو النشاط:
        // الجدولُ يقارن باقاتٍ، فإدراجُ ما يأتي مجّاناً من النشاط يجعل
        // «المجّانيّة» تبدو وكأنّها تبيعه.
        return array_values(array_intersect(
            AccessPresets::planFeatures($plan), $resolved));
    }

    /**
     * القدراتُ المُدرجةُ في الباقة العليا ولا يفتحها هذا النشاط أبداً.
     *
     * @return array<int,string>
     */
    private function withheldFrom(?string $businessType): array
    {
        if ($businessType === null) {
            return [];
        }

        return array_values(array_diff(
            AccessPresets::planFeatures(A::PLAN_ENTERPRISE),
            $this->grantedFor($businessType, A::PLAN_ENTERPRISE)));
    }

    /**
     * ③ **وما لا تفتحه الترقيةُ يُقال صراحةً وبأسمائه.**
     *
     * وكانت جملةً مكتوبةً تُسمّي ثلاثةَ أنشطةٍ بعينها — **فتشيخ يومَ
     * يُضاف نشاطٌ رابع، وتُقرأ عكسَ مرادها** من كان نشاطُه خارجها.
     * فصارت تُبنى من الفرق المحسوب: تقول كم قدرةً حُجبت وأمثلةً منها
     * بأسمائها العربيّة من السجلّ.
     */
    private function verticalNote(?string $businessType): string
    {
        if ($businessType === null) {
            return 'هذه القائمةُ عامّةٌ لكلّ الأنشطة. وبعضُ القدرات تخصّ '
                .'نشاطاً بعينه، فما يصلك يُحسب بعد اختيار نوع نشاطك.';
        }

        $withheld = $this->withheldFrom($businessType);

        if ($withheld === []) {
            return 'كلُّ ما في هذه الباقات يُفتَح لنشاطك — لا شيءَ محجوبٌ '
                .'بنوع النشاط.';
        }

        $registry = CapabilityRegistry::all();
        $names = [];

        foreach (array_slice($withheld, 0, 3) as $code) {
            $names[] = $registry[$code]?->name() ?: $code;
        }

        return 'وفي هذه الباقات '.count($withheld).' قدرةً تخصّ أنشطةً أخرى '
            .'('.implode(' · ', $names).(count($withheld) > 3 ? ' وغيرُها' : '')
            .') — **لا تفتحها الترقيةُ لنشاطك**، ولم تُحتسَب في الأرقام أعلاه.';
    }

    /**
     * **الوصفُ من السجلّ حرفاً بحرف** — ولا يُخترَع نصٌّ لقدرة.
     *
     * ورمزٌ لا يعرفه السجلُّ **يُقال إنّه بلا وصف** ولا يُعرَض خاماً
     * كأنّه اسم. (القاعدة السابعة: الغيابُ يُقال ولا يُلبَس ثوبَ الحضور.)
     *
     * @param  array<int,string>  $codes
     * @return array<int,array<string,string>>
     */
    private function describe(array $codes): array
    {
        $registry = CapabilityRegistry::all();
        $rows = [];

        foreach ($codes as $code) {
            $cap = $registry[$code] ?? null;

            $rows[] = [
                'code' => $code,
                'name' => $cap?->name() ?: $code,
                'description' => $cap?->description() ?: '',
                'group' => $cap?->groupName() ?: 'أخرى',
                'documented' => $cap !== null && $cap->name() !== $code,
            ];
        }

        // ② **مجموعةً مجموعة، وبترتيبٍ ثابت** — لا سرداً مسطّحاً.
        usort($rows, fn ($a, $b) => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);

        return $rows;
    }

    /** ترتيبُ المجموعات كما يظهر في السجلّ — لا مكتوباً هنا. */
    private function groupOrder(): array
    {
        $seen = [];

        foreach (CapabilityRegistry::all() as $cap) {
            $g = $cap->groupName();
            if ($g !== '' && ! in_array($g, $seen, true)) {
                $seen[] = $g;
            }
        }

        return $seen;
    }

    /**
     * الحدودُ بصيغةٍ تُقرأ — و**«بلا حدّ» ليست رقماً**.
     *
     * `-1` تعني غيرَ محدود و`0` تعني ممنوعاً، وعرضُهما رقمين يجعل
     * «‎-1 منتج» و«0 فرع» سطرين لا يُقرآن. (القاعدة السابعة.)
     */
    private function limits(string $plan): array
    {
        $raw = AccessPresets::planLimits($plan);

        $labels = [
            'monthly_operations' => 'عمليّات البيع شهريّاً',
            'max_products' => 'الأصناف',
            'max_employees' => 'الموظّفون',
            'max_branches' => 'الفروع',
            'max_pos_devices' => 'نقاط البيع',
            'archive_days' => 'مدّة الأرشيف (يوم)',
        ];

        $out = [];

        foreach ($labels as $key => $label) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $v = (int) $raw[$key];

            $out[] = [
                'key' => $key,
                'label' => $label,
                'value' => $v,
                'text' => match (true) {
                    $v < 0 => 'بلا حدّ',
                    $v === 0 => 'غير متاح',
                    default => (string) $v,
                },
            ];
        }

        return $out;
    }
}
