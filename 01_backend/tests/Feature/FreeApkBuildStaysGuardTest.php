<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * AMIAL-CI-APK-002 — **بابُ البناء المجّانيُّ يبقى مفتوحاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع، بالرقم:** فاتورةُ **مئةِ دولار** من Codemagic على
 * دقائق `mac_mini_m2` — سبعةُ بناءاتٍ متتاليةٍ لا يمسّ التزامٌ منها
 * ملفَّ فلاتر واحداً. فنُزع `triggering` من `codemagic.yaml` كلُّه
 * **بقرارٍ صريحٍ من صاحب المشروع**، فلا يُبنى شيءٌ إلّا بضغطةٍ منه.
 *
 * **وبُني البديل** في `10f87a5` — «بناءُ APK على Linux، مخرجٌ من رصيد
 * Mac الذي نفد»: زرٌّ واحدٌ في GitHub، ولا دقيقةَ Mac.
 *
 * ثمّ **حُذف صامتاً**. الالتزامُ `19e50e2` عنوانُه «ci: replace
 * false-positive structural checker» — أي عن فاحصٍ بنيويّ — وفيه
 * **أربعُ إزالاتٍ لمهمّة البناء وصفرُ إضافة**، ولا كلمةَ عنها في
 * الرسالة.
 *
 * **ولا شيءَ كان يمسك هذا**: البوّابةُ تفحص الشيفرة ولا تفحص نفسَها،
 * فمهمّةٌ تُحذف من `ci.yml` لا تُسقط اختباراً واحداً. والنقصُ يُقرأ
 * سلامةً حتّى يحتاج صاحبُ المشروع بناءً فلا يجده.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأربعةُ شروطٍ لا واحد** — لأنّ ثلاثةً منها كافيةٌ وحدَها لإبطاله:
 * المهمّةُ قائمة · وزرُّها معرَّف · و**لا تعمل تلقائيّاً** (وإلّا عاد ما
 * أنتج الفاتورة) · و**لا تجرّ نشرةً** (فزرُّ بناءٍ يُطلق خطّافَ Coolify
 * ينشر إنتاجاً بلا قصد، وCoolify على `Source: Manual` عمداً).
 */
class FreeApkBuildStaysGuardTest extends TestCase
{
    private function workflow(): array
    {
        $path = dirname(base_path()).'/.github/workflows/ci.yml';

        $this->assertFileExists($path, 'ملفُّ البوّابة مفقود — ولا فحصَ بلا بوّابة.');

        return Yaml::parseFile($path);
    }

    /** مفتاحُ `on` يُقرأ في YAML قيمةً منطقيّةً `true` — وهي حالةٌ معروفة. */
    private function triggers(array $wf): array
    {
        return $wf['on'] ?? $wf[1] ?? $wf[true] ?? [];
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① مهمّةُ البناء قائمةٌ ولها زرّ.**
     */
    /** @test */
    public function the_free_apk_build_job_still_exists(): void
    {
        $wf = $this->workflow();

        $this->assertArrayHasKey('apk', $wf['jobs'] ?? [],
            '**حُذفت مهمّةُ بناء APK المجّانيّة من البوّابة.** وهي البديلُ '
            .'الوحيدُ الباقي بعد نزع مُطلِق Codemagic — وقد حُذفت مرّةً '
            .'من قبلُ في التزامٍ عنوانُه عن فاحصٍ بنيويّ، ولم يرها أحد.');

        $this->assertArrayHasKey('workflow_dispatch', $this->triggers($wf),
            '**لا زرَّ تشغيلٍ يدويّ.** فالمهمّةُ موجودةٌ ولا سبيلَ إلى '
            .'بدئها — مبنيّةٌ ولا يُوصَل إليها.');
    }

    /**
     * **② ولا تعمل إلّا بالزرّ.**
     *
     * فبناءٌ يجري مع كلّ دفعٍ هو بعينه ما أنتج فاتورةَ المئة دولار —
     * وإن كان Linux أرخصَ من Mac، فالقرارُ المكتوبُ «لا يُبنى شيءٌ إلّا
     * بضغطةٍ من صاحب المشروع».
     */
    /** @test */
    public function it_never_builds_by_itself(): void
    {
        $apk = $this->workflow()['jobs']['apk'] ?? [];

        $this->assertStringContainsString("workflow_dispatch", (string) ($apk['if'] ?? ''),
            '**بناءُ APK بلا شرطِ التشغيل اليدويّ** — فيجري مع كلّ دفع. '
            .'وذاك عينُ ما أنتج فاتورةَ المئة دولار: بناءٌ لا يطلبه أحد.');

        $this->assertStringNotContainsString('!=', (string) ($apk['if'] ?? ''),
            'شرطُ البناء مقلوب — فيجري في كلّ حالٍ إلّا الزرّ.');
    }

    /**
     * **③ ولا تجرّ نشرةً ولا صورة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `deploy` ينادي `COOLIFY_DEPLOY_HOOK`. فلولا العزلُ لكانت ضغطةُ زرّ
     * البناء **تُطلق نشرةَ إنتاج** — وصاحبُ المشروع يضبط Coolify على
     * `Source: Manual` عمداً وينشر بيده.
     *
     * **وهو ما فعله `fe64f3c` بعنوانه** — «isolate manual APK builds
     * from deploy jobs» — ثمّ ذهب مع المهمّة حين حُذفت.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function pressing_the_build_button_deploys_nothing(): void
    {
        $jobs = $this->workflow()['jobs'];
        $leaks = [];

        foreach (['deploy', 'docker'] as $name) {
            if (! isset($jobs[$name])) {
                continue;
            }

            if (! str_contains((string) ($jobs[$name]['if'] ?? ''), "!= 'workflow_dispatch'")) {
                $leaks[] = "  $name";
            }
        }

        $this->assertSame([], $leaks, sprintf(
            "**مهامُّ تجري مع ضغطة زرّ البناء:**\n%s\n\n"
            .'و`deploy` ينادي خطّافَ Coolify — فزرٌّ لبناء ملفٍّ ينشر '
            ."إنتاجاً بلا قصد.\n"
            .'وCoolify مضبوطٌ على `Source: Manual` بقرارٍ صريح: النشرُ بيدٍ.',
            implode("\n", $leaks)));
    }

    /**
     * **④ وسلسلةُ الاعتماد تصل** — فمهمّةٌ تنتظر مُخطّاةً لا تجري أبداً.
     *
     * وهذا أخبثُ الأعطال هنا: الزرُّ موجودٌ ويُضغَط، وتخرج الجولةُ خضراءَ
     * **و`apk` مكتوبٌ عليها `skipped`** — فيُقرأ نجاحاً ولا ملفَّ.
     * (القاعدة التاسعة: يُقاس أين ذهبت الضغطة لا هل أخطأت.)
     */
    /** @test */
    public function the_button_actually_reaches_the_build(): void
    {
        $jobs = $this->workflow()['jobs'];
        $blocked = [];

        $needs = (array) ($jobs['apk']['needs'] ?? []);
        $queue = $needs;

        while ($queue !== []) {
            $n = array_shift($queue);

            if (! isset($jobs[$n])) {
                $blocked[] = "  «$n» لا وجودَ لها";

                continue;
            }

            if (str_contains((string) ($jobs[$n]['if'] ?? ''), "!= 'workflow_dispatch'")) {
                $blocked[] = "  «$n» تُخطّى عند الضغط، و«apk» تنتظرها";
            }

            $queue = array_merge($queue, (array) ($jobs[$n]['needs'] ?? []));
        }

        $this->assertSame([], $blocked, sprintf(
            "**سلسلةُ البناء مقطوعة:**\n%s\n\n"
            .'فتُضغط الزرُّ وتخرج الجولةُ خضراءَ و«apk» مكتوبٌ عليها '
            .'`skipped` — نجاحٌ بلا ملفّ.',
            implode("\n", $blocked)));
    }
}
