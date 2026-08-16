<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AMIAL-PROD-READINESS-007 — **صفحةُ تعافٍ تُقرأ ساعةَ الكارثة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس في تدقيق الجاهزيّة:**
 *
 *   $ grep -rlin 'RPO\|RTO' docs/
 *   (صفرُ ملفّات)
 *
 * سكربتاتُ التعافي مبنيّةٌ كلُّها — `backup.sh` و`restore.sh` و`run_dr.sh`
 * — **ولا صفحةَ تقول متى يُستدعى أيٌّ منها.** ومن يفتح الطرفيّةَ والخادمُ
 * ساقطٌ لا يقرأ رأسَ سكربتٍ ليعرف ترتيبَ الخطوات.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ حارسٌ على وثيقة:** لأنّ الوثيقةَ تشيخ بصمت. سكربتٌ يُعاد تسميتُه
 * أو أمرٌ يُحذف لا يُسقط بناءً ولا اختباراً — **يُسقط الصفحةَ وحدَها،
 * يومَ يُحتاج إليها.**
 *
 * **وأمسك هذا الحارسُ عطلَه في أوّل تشغيل**: كُتب في الصفحة
 * `php artisan amial:ledger-verify` — **ولا وجودَ لهذا الأمر**. الاسمُ
 * الحقيقيُّ `ledger:reconcile`. أي أنّ الصفحةَ في نسختها الأولى كانت
 * تُملي على من يستعيد قاعدةً أمراً يردّ `Command not found`.
 */
class DisasterRecoveryDocGuardTest extends TestCase
{
    private const DOC = 'docs/التعافي.md';

    private function doc(): string
    {
        $path = base_path(self::DOC);

        $this->assertFileExists($path,
            'صفحةُ التعافي مفقودة — والسكربتاتُ وحدَها لا تقول ترتيبَ الخطوات');

        return (string) file_get_contents($path);
    }

    /**
     * @test
     *
     * **الرقمان يُقالان صراحةً.** «نأخذ نسخاً احتياطيّة» ليست خطّةَ تعافٍ:
     * الخطّةُ أن يُعرف **كم يُفقَد** و**كم يستغرق العَود**.
     */
    public function the_page_states_both_recovery_numbers(): void
    {
        $doc = $this->doc();

        foreach (['RPO', 'RTO'] as $metric) {
            $this->assertStringContainsString($metric, $doc,
                "صفحةُ التعافي لا تذكر {$metric} — فلا يُعرف حدُّ الخسارة");
        }
    }

    /**
     * @test
     *
     * **وكلُّ سكربتٍ تذكره موجودٌ فعلاً.**
     *
     * صفحةٌ تحيل إلى `scripts/restore.sh` بعد إعادة تسميته تُقرأ ساعةَ
     * الكارثة — وهو أسوأُ وقتٍ لاكتشاف ذلك.
     */
    public function every_script_the_page_names_exists(): void
    {
        preg_match_all('~scripts/[A-Za-z0-9_\-]+\.(sh|php|py|mjs)~', $this->doc(), $m);

        $this->assertNotEmpty($m[0], 'الصفحةُ لا تذكر أمراً واحداً — فهي شرحٌ لا خطّة');

        foreach (array_unique($m[0]) as $script) {
            $this->assertFileExists(base_path($script),
                "صفحةُ التعافي تُحيل إلى «{$script}» ولا وجودَ له");
        }
    }

    /**
     * @test
     *
     * **وكلُّ أمرِ artisan تذكره مسجَّلٌ فعلاً.**
     *
     * وهذا ما أمسكه الحارسُ في أوّل تشغيل — التفصيلُ في رأس الملفّ.
     */
    public function every_artisan_command_the_page_names_is_registered(): void
    {
        preg_match_all('~php artisan ([a-z0-9]+:[a-z0-9\-]+)~', $this->doc(), $m);

        $this->assertNotEmpty($m[1], 'الصفحةُ لا تذكر أمرَ artisan واحداً');

        $registered = array_keys(Artisan::all());

        foreach (array_unique($m[1]) as $cmd) {
            $this->assertContains($cmd, $registered,
                "صفحةُ التعافي تُملي «php artisan {$cmd}» ولا وجودَ لهذا الأمر — "
                . 'فمن يستعيد قاعدةً يقرأ `Command not found`');
        }
    }

    /**
     * @test
     *
     * **ومفاتيحُ التشفير تُسمّى بأسمائها.**
     *
     * نسخةُ القاعدةِ تحمل بياناتِ العملاء **مشفَّرةً**، والمفتاحُ ليس فيها.
     * فمن استعاد نسخةً بلا `AMIAL_PII_ENCRYPTION_KEY` استعاد ملفَّ ضجيج.
     * **وهذه أخطرُ حقيقةٍ في الصفحة كلِّها** — فتُسمَّى لا يُلمَّح إليها.
     */
    public function the_page_names_the_keys_without_which_a_backup_is_noise(): void
    {
        $doc = $this->doc();

        foreach (['AMIAL_PII_ENCRYPTION_KEY', 'AMIAL_PII_BLIND_INDEX_KEY', 'APP_KEY'] as $key)
        {
            $this->assertStringContainsString($key, $doc,
                "صفحةُ التعافي لا تذكر «{$key}» — فقد يُستعاد ما لا يُقرأ");
        }
    }

    /**
     * @test
     *
     * **ويُوصَل إليها.** (القاعدةُ الثانيةَ عشرة: مبنيٌّ ولا يُوصَل إليه.)
     *
     * وثيقةٌ في `docs/` لا يذكرها شيءٌ يُقرأ = وثيقةٌ لا وجودَ لها. فيُشترط
     * ذكرُها في `CLAUDE.md` — وهو **الملفُّ الوحيدُ المضمونُ قراءتُه** في
     * أوّل كلّ جلسة.
     */
    public function the_page_is_reachable_from_what_is_actually_read(): void
    {
        $this->assertStringContainsString('التعافي.md', (string) file_get_contents(base_path('CLAUDE.md')),
            'صفحةُ التعافي غيرُ مذكورةٍ في CLAUDE.md — فلا يُوصَل إليها');
    }
}
