<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-COVERAGE-004 — لافتةُ التغطية: سطرٌ يُغلَق، لا جدارٌ دائم.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:**
 *
 * كانت اللافتة تعرض النصَّ الكامل — ثلاثةَ أسطرٍ تأكل ثلثَ الشاشة
 * الرئيسيّة، فوق الرصيد، **في كلّ فتحة، إلى الأبد**. ووصفها صاحبُ
 * المشروع: «مزعجة جدّاً… انظر كيف وصلت».
 *
 * **والخبرُ ثابت**: محافظةٌ بلا وكلاء اليوم هي بلا وكلاء غداً. فإعادةُ
 * إخباره كلَّ صباحٍ ليست معلومة — هي إزعاج.
 *
 * **وأثرُه أبعدُ من الضيق**: من يعتاد تجاوزَ لافتةٍ يتجاوز التي بعدها،
 * ولو كانت تحذيرَ احتيال. فالإزعاجُ يُنفق رصيدَ الانتباه على خبرٍ لا
 * يتغيّر، ولا يبقى منه شيءٌ لِما يهمّ.
 */
class CoverageBannerGuardTest extends TestCase
{
    private const SCREEN =
        '../02_flutter_app/lib/features/home/screens/amial_customer_home_screen.dart';

    private function screen(): string
    {
        // التعليقاتُ تُجرَّد: التوثيقُ يصف العطلَ بنصّه، فحارسٌ يمسح النصَّ
        // الخام يسقط على شرح إصلاحه. (وقعت مرّتين في جلسةٍ واحدة.)
        return (string) preg_replace(
            ['#/\*.*?\*/#s', '#^\s*//.*$#m', '#^\s*///.*$#m'],
            '',
            file_get_contents(base_path(self::SCREEN)),
        );
    }

    /**
     * @test
     *
     * **الخادمُ يرسل نصّاً قصيراً ونصّاً كاملاً — من مصدرٍ واحد.**
     *
     * فلو بنى التطبيقُ السطرَ القصيرَ بنفسه لصار للرسالة نسختان تفترقان
     * عند أوّل تعديل. (وهو ما أنتج في هذا المشروع أزرقين لعلامةٍ واحدة.)
     */
    public function the_api_sends_both_a_short_and_a_full_notice(): void
    {
        $ctl = file_get_contents(
            base_path('app/Http/Controllers/Api/V1/Amial/ServiceCoverageController.php'));

        $this->assertStringContainsString("'notice_short' =>", $ctl,
            'الخادمُ لا يرسل نصّاً قصيراً — فتُعرض ثلاثةُ أسطرٍ أو يُبنى نصٌّ ثانٍ في التطبيق');

        $this->assertStringContainsString('private function noticeShort(', $ctl,
            'لا بانيَ للنصّ القصير');

        // **والقصيرُ قصيرٌ فعلاً.** فاسمٌ لا يضمن طولاً.
        preg_match('/private function noticeShort\(.*?\n    \}/s', $ctl, $m);

        preg_match_all('/return "([^"]+)"/', $m[0] ?? '', $lines);

        $this->assertNotEmpty($lines[1] ?? [], 'لم تُقرأ نصوصُ الدالّة القصيرة');

        foreach ($lines[1] as $line) {
            // القوالبُ تُستبدل بأسماءٍ واقعيّة قبل القياس.
            $rendered = str_replace(
                ['{$agents}', '{$merchants}', '{$name}'],
                ['12', '34', 'حضرموت'],
                $line,
            );

            $this->assertLessThanOrEqual(48, mb_strlen($rendered),
                "سطرٌ «قصير» طولُه " . mb_strlen($rendered) . " محرفاً: «{$rendered}»\n"
                . 'وهو لا يسع سطراً واحداً على هاتفٍ عاديّ فيعود الجدارُ كما كان.');
        }
    }

    /**
     * @test
     *
     * **واللافتةُ تُطوى وتُغلَق — وكلُّ ما يُعرض يعمل.**
     */
    public function the_banner_can_be_expanded_and_dismissed(): void
    {
        $src = $this->screen();

        foreach (['_coverageShort', '_coverageExpanded', '_coverageDismissed',
                  '_dismissCoverage'] as $part) {
            $this->assertStringContainsString($part, $src, "ناقصٌ: {$part}");
        }

        $this->assertStringContainsString('maxLines: _coverageExpanded ? null : 1', $src,
            'اللافتةُ لا تُطوى إلى سطرٍ واحد — الجدارُ باقٍ');

        $this->assertStringContainsString(
            'if (_coverageNotice != null &&', $src,
            'شرطُ العرض لا يقرأ حالةَ الإغلاق');
    }

    /**
     * @test
     *
     * **والإغلاقُ يُحفظ بحالة التغطية لا مطلقاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * لو حُفظ «أُغلقت» وحدها لما عادت أبداً — **ويومَ يُعتمد وكيلٌ في
     * المحافظة لا يعلم به أحد**. فالخبرُ السارّ يُقتل بالإغلاق القديم.
     *
     * فالمفتاحُ يحمل المحافظةَ وعددَي الوكلاء والتجار: يتغيّر أحدُها
     * فيعود الإشعارُ من نفسه. (القاعدة السابعة: «أُغلق» ليس «لم يعد صحيحاً».)
     */
    public function dismissal_is_keyed_to_coverage_state_so_good_news_still_arrives(): void
    {
        $src = $this->screen();

        $this->assertMatchesRegularExpression(
            "/_coverageKey = 'cov:.*governorate_code.*agents.*merchants/u",
            $src,
            "مفتاحُ الإغلاق لا يحمل المحافظةَ وأعدادَ التغطية —\n"
            . 'فمن أغلق اللافتة لا تعود إليه ولو فُتح وكيلٌ في محافظته غداً.');

        // **ولافتةُ «حدّد محافظتك» لا تُغلَق** — تلك تنتظر فعلاً منه،
        // وإغلاقُها يترك الحسابَ بلا محافظة ولا طريقَ لضبطها.
        $this->assertStringContainsString('if (!_needsGovernorate)', $src,
            'لافتةُ نقص المحافظة قابلةٌ للإغلاق — فيبقى الحسابُ بلا محافظةٍ بلا طريق');
    }
}
