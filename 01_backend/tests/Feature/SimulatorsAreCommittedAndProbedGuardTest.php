<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-SIM-GUARD-002 — **محاكٍ في مجلّدٍ مؤقّتٍ يموت مع الحاوية.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع:** بُني محاكيا التجزئة والوقود في مجلّدٍ مؤقّتٍ
 * **خارج المستودع**، فرآهما صاحبُ المشروع على شاشته ثمّ ذهبا مع الحاوية.
 * صفرُ محاكياتٍ بلغت فرعَ النشر. وسأل: «كم محاكي تجار تم دفعه إلى
 * النشر؟» — والجوابُ كان صفراً.
 *
 * **وهو نمطُ العطل الذي يحاربه المشروع كلُّه — مبنيٌّ ولا يُوصَل إليه —
 * واقعاً على ما بُني ليُرى.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثانيةٌ فوقها: مسبارٌ لا يُنفَّذ ليس مسباراً.** كان في `test/` تسعةَ
 * عشرَ ملفّاً لا يشغّلها شيء، وفيها حارسٌ ساقطٌ منذ كُتب ولم يره أحد.
 * فلكلّ محاكٍ مسبارٌ **موجودٌ ويُشغَّل في البوّابة**.
 */
class SimulatorsAreCommittedAndProbedGuardTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../docs/محاكيات';

    /** @return list<string> أسماءُ ملفّات المحاكيات بلا امتداد. */
    private function simulators(): array
    {
        $files = glob(self::DIR . '/*.html') ?: [];

        return array_map(
            fn (string $p): string => basename($p, '.html'),
            $files);
    }

    /** @test */
    public function every_simulator_lives_in_the_repository(): void
    {
        $this->assertDirectoryExists(self::DIR,
            '**مجلّدُ المحاكيات مفقود.** وما يُبنى للعرض يُلتزَم به — '
            . 'وإلّا ذهب مع الحاوية ولم يبلغ النشرَ أبداً.');

        $sims = $this->simulators();

        $this->assertNotEmpty($sims,
            '**لا محاكيَ واحدٌ في المستودع.** وقد بُني اثنان في مجلّدٍ '
            . 'مؤقّتٍ ثمّ ذهبا — فلا يُعاد ذلك.');
    }

    /**
     * **ولكلّ محاكٍ مسبارٌ يضغط أزراره.**
     *
     * (القاعدة التاسعة: زرٌّ لم يُضغط ليس مبنيّاً. وقد أخرج مسبارُ الجملة
     * ثلاثةَ أعطالٍ حقيقيّةٍ لم تكشفها القراءة — زرَّ رجوعٍ لا يرجع،
     * ودرجاً يفتح من الجهة الخطأ في واجهةٍ عربيّة، وزرَّ طريقةِ تحصيلٍ
     * يُضغط ولا يحدث شيءٌ ولا خطأَ في أيّ سجلّ.)
     */
    /** @test */
    public function every_simulator_has_a_probe_that_presses_its_buttons(): void
    {
        $orphans = [];

        foreach ($this->simulators() as $sim) {
            // «محاكي-تاجر-الجملة» ← «فحص-محاكي-الجملة» ليس اشتقاقاً آليّاً،
            // فيُقبل أيُّ مسبارٍ يذكر اسمَ ملفّ المحاكي.
            $probes = glob(self::DIR . '/*.mjs') ?: [];
            $covered = false;

            foreach ($probes as $probe) {
                $src = (string) file_get_contents($probe);
                if (str_contains($src, $sim)) {
                    $covered = true;
                    break;
                }
            }

            if (! $covered) {
                $orphans[] = $sim;
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "**محاكياتٌ بلا مسبارٍ يضغط أزرارَها:**\n  %s\n\n"
            . 'ومحاكٍ لم تُضغط أزرارُه يُعرَض على صاحب المشروع فيقرّر على '
            . 'شاشةٍ لم يجرّبها أحد.',
            implode("\n  ", $orphans)));
    }

    /**
     * **والمسبارُ يُشغَّل في البوّابة — وإلّا كان ملفّاً لا حارساً.**
     */
    /** @test */
    public function the_gate_actually_runs_the_simulator_probes(): void
    {
        $verify = (string) file_get_contents(__DIR__ . '/../../scripts/verify.sh');

        $this->assertStringContainsString('محاكيات', $verify,
            '**البوّابةُ لا تشغّل مسابرَ المحاكيات.** ومسبارٌ لا يُنفَّذ '
            . 'ليس مسباراً: كان في المشروع تسعةَ عشرَ ملفّ اختبارٍ لا '
            . 'يشغّلها شيء، وفيها حارسٌ ساقطٌ منذ كُتب ولم يره أحد.');
    }

    /**
     * **ولا محاكيَ بلا ورقةٍ تقول ما يغطّي وما لا يغطّي.**
     *
     * فمحاكٍ يُقرأ اختباراً للتطبيق يخدع أشدَّ من غيابه: هو مرآةُ قرارِ
     * استحقاقٍ لا اتّصالَ لها بخادم.
     */
    /** @test */
    public function the_simulators_folder_says_what_it_is_and_is_not(): void
    {
        $readme = self::DIR . '/اقرأني.md';
        $this->assertFileExists($readme, 'مجلّدُ المحاكيات بلا «اقرأني».');

        $src = (string) file_get_contents($readme);

        foreach (['ما لا تفعله', 'لا تتّصل بخادم'] as $needle) {
            $this->assertStringContainsString($needle, $src,
                "**«اقرأني» لا يقول حدَّ المحاكي («{$needle}»).** "
                . 'ومحاكٍ يُقرأ اختباراً للتطبيق يُطمئن ولا يفحص.');
        }
    }
}
