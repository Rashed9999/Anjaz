<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-LIMIT-SCOPE-001 — **حدٌّ يُعرَض ولا يحدّ ما يظنّه قارئُه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * سأل صاحبُ المشروع عن «١٠٠ ألف في اليوم و٥٠٠ ألف في الشهر» في حساب تاجر
 * وقود: **«من الذي عمل هذه الحدود؟»**.
 *
 * وقِيس، فكان الجوابُ ثلاثةَ أشياء:
 *
 *   ① **لا أحدَ وضعها للوقود.** هي حدُّ فئة التوثيق من `kyc_tier_limits`
 *      (الفئة القياسيّة)، **واحدةٌ لكلّ الأنواع** — عميلاً وتاجراً ووكيلاً.
 *      وأصلُها `KycTierLimitsSeeder`، واحتياطُها `DEFAULT_LIMITS` في
 *      `KycTierService`.
 *
 *   ② **وهي لا تحكم البيع.** `assertTransactionAllowed` تُنادى في موضعين
 *      اثنين لا غير — التبرّعات والدفع الآمن — و`enforceFinancialPolicy`
 *      **بصفر نداء** رغم أنّ ثلاثَ خدماتٍ تستورد سِمتَها. فبيعُ التاجر
 *      وبيعُ الوقود والكاشير خارجَها تماماً.
 *
 *   ③ **واللوحةُ تعرضها «الحدود» بلا نطاق.** فيقرؤها المراجعُ سقفاً
 *      للبيع فلا يبحث عن سقفٍ حقيقيّ — ورقمٌ يُعرَض حدّاً ولا يحدّ ما
 *      يُظنّ به أسوأ من غيابه. (القاعدة السابعة، و`amial-financial-truth`.)
 *
 * **وهذا حارسُ صدقٍ لا حارسُ سلوك**: لا يفرض حدّاً على البيع — ذاك قرارُ
 * صاحب المشروع لا قرارُ شيفرة — بل يمنع أن **يتباعد النصُّ عن الشيفرة**.
 * فمن أضاف نداءً جديداً وسّع النطاقَ ولم يُحدّث السطر، أو حذف نداءً
 * فضيّقه، **يسقط هنا**.
 */
class LimitScopeTruthGuardTest extends TestCase
{
    /** المواضعُ التي تُنفَّذ فيها حدودُ الفئة فعلاً — تُقرأ ولا تُفترَض. */
    private function enforcementSites(): array
    {
        $out = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $rel = str_replace(base_path().'/', '', $file->getPathname());

            // السِّمةُ نفسُها والخدمةُ نفسُها تعريفٌ لا نداء.
            if (str_contains($rel, 'Traits/EnforcesFinancialPolicy.php')
                || str_contains($rel, 'Services/KycTierService.php')) {
                continue;
            }

            $src = $this->codeOnly((string) file_get_contents($file->getPathname()));

            if (str_contains($src, 'assertTransactionAllowed(')
                || str_contains($src, 'enforceFinancialPolicy(')) {
                $out[] = $rel;
            }
        }

        sort($out);

        return $out;
    }

    /** **التعليقُ الذي يصف العطلَ كان يُخفيه** — فتُنزَع التعليقاتُ أوّلاً. */
    private function codeOnly(string $src): string
    {
        $out = '';

        foreach (token_get_all($src) as $token) {
            if (is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @test */
    public function the_tier_limits_gate_only_what_the_panel_says_they_gate(): void
    {
        $sites = $this->enforcementSites();

        // **المقيسُ اليوم**: التبرّعاتُ والدفعُ الآمن، ولا ثالثَ.
        $this->assertSame([
            'app/Http/Controllers/Api/V1/Amial/SafePaymentController.php',
            'app/Services/DonationsService.php',
        ], $sites,
            "تغيّر نطاقُ حدود الفئة عمّا تقوله لوحةُ الإدارة.\n"
            ."المقيسُ الآن:\n  ".implode("\n  ", $sites)."\n"
            .'حدِّث سطرَ «نطاقُ هذه الحدود» في '
            .'`admin-views/amial/customer/index.blade.php` ليطابقَ الشيفرة — '
            .'ورقمٌ يُعرَض حدّاً ولا يحدّ ما يُظنّ به أسوأ من غيابه.');
    }

    /** @test */
    public function no_sale_path_is_silently_inside_or_outside_the_limit(): void
    {
        // **الوجهُ الثاني**: لو أُدخل البيعُ تحت الحدّ يوماً، فالسطرُ في
        // اللوحة يصير كاذباً في الاتّجاه المعاكس. فيُفحَص صراحةً.
        foreach ([
            'app/Services/MerchantSaleService.php',
            'app/Services/CashierService.php',
            'app/Services/FuelStationService.php',
            'app/Services/Fuel/FuelSaleService.php',
        ] as $rel) {
            $path = base_path($rel);

            if (! is_file($path)) {
                continue;   // خدمةٌ غيرُ موجودةٍ ليست خدمةً بلا حدّ.
            }

            $this->assertStringNotContainsString(
                'assertTransactionAllowed',
                $this->codeOnly((string) file_get_contents($path)),
                "دخل البيعُ تحت حدود فئة التوثيق في {$rel} — والحدُّ الافتراضيُّ "
                .'١٠٠٬٠٠٠ يوميّاً، وهو أقلُّ من يوم عملٍ في محطّة وقود. '
                .'إن كان مقصوداً فحدِّث سطرَ النطاق في اللوحة أوّلاً.');
        }
    }

    /** @test */
    public function the_panel_states_the_scope_and_does_not_leave_a_bare_number(): void
    {
        $src = (string) file_get_contents(resource_path(
            'views/admin-views/amial/customer/index.blade.php'));

        $this->assertStringContainsString('نطاقُ هذه الحدود', $src,
            'تُعرَض «الحدود» بلا نطاقها — فيقرؤها المراجعُ سقفاً للبيع');

        $this->assertStringContainsString('ولا تشمل', $src,
            'قيل ما تشمله ولم يُقَل ما لا تشمله — والثاني هو الذي يُضلّل');
    }
}
