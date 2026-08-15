<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-DB-UP-001 — **٢١٧٣ اختباراً «فاشلاً» ولا واحدَ منها مكسور.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **التشخيص، مقيساً لا مفترَضاً:**
 *
 *   إقلاعُ الحاوية : 2026-08-15 01:48:42
 *   وقبله بساعاتٍ  : خمسةُ التزاماتٍ بين 20:56 و 00:22
 *   `claude`       : بدأ بعد الإقلاع بثلاثين ثانية
 *
 * **فالحاويةُ تُستأنف من جديد.** والآلةُ كلُّها تذهب وتعود — ولذلك لم
 * يترك «الموتُ» أثراً في سجلّ، ولذلك ذهب `mariadbd-safe` مع `mariadbd`
 * (وهو الذي يُعيده عند الانهيار)، ولذلك كانت الذاكرةُ ١٣ جيجا حرّةً.
 *
 * **وأربعُ فرضيّاتٍ نُقضت قبل هذه:** قتلُ مجموعةِ عمليّات · OOM · انهيارُ
 * الخادم وحدَه · سكربتُ الفوضى (موجودٌ ويقتل بـkill -9، لكنّ البوّابةَ لا
 * تستدعيه).
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والسببُ الحقيقيّ ليس أنّها تموت — بل أنّ لا أحدَ يُعيدها.**
 *
 * `.claude/hooks/session-start.sh` مبنيٌّ لذلك ولا يُطلَق هنا: جذرُ
 * المشروع `/home/user` لا `/home/user/Anjaz`، فلا تُقرأ إعداداتُه.
 * **وهو نفسُ عطل فهرس المهارات** — خطّافٌ مسجَّلٌ حيث لا يُقرأ.
 *
 * فمن يحتاجُها يُنهضها. وهذا الحارسُ يمنع فكَّ ذلك الوصل.
 */
class DatabaseUptimeGuardTest extends TestCase
{
    private function script(): string
    {
        return base_path('scripts/ensure-db.sh');
    }

    /** @test */
    public function the_reviver_exists_and_is_executable(): void
    {
        $this->assertFileExists($this->script(),
            'مُنهِضُ قاعدة البيانات اختفى — فتعود البوّابةُ تكذب بـ٢١٧٣ عطلاً وهميّاً');

        $this->assertTrue(is_executable($this->script()),
            'المُنهِضُ غيرُ قابلٍ للتنفيذ');
    }

    /**
     * @test
     *
     * **وهو موصولٌ بالبوّابة — قبل كلّ طبقةٍ تحتاج قاعدة.**
     *
     * حارسٌ خارج `verify.sh` يُنسى. (وهذا نمطُ العطل الأكثر تكراراً في
     * المشروع: مبنيٌّ ولا يُوصَل إليه.)
     */
    public function the_gate_calls_it_before_anything_that_needs_a_database(): void
    {
        $gate = file_get_contents(base_path('scripts/verify.sh'));

        $this->assertStringContainsString('ensure-db.sh', $gate,
            'البوّابةُ لا تُنهض قاعدةَ البيانات — فتقرأ سقوطَها نتيجةَ شيفرة');

        // وقبل الطبقة الرابعة (جدولُ المسارات) — أوّلُ ما يلمس قاعدة.
        $atEnsure = strpos($gate, 'ensure-db.sh');
        $atRoutes = strpos($gate, 'جدول المسارات');

        $this->assertNotFalse($atEnsure);
        $this->assertNotFalse($atRoutes);

        $this->assertLessThan($atRoutes, $atEnsure,
            'الإنهاضُ يقع بعد أوّل طبقةٍ تحتاج قاعدة — فتسقط قبل أن تنهض');
    }

    /**
     * @test
     *
     * **والبوّابةُ تفرّق بين «شيفرةٌ مكسورة» و«القاعدةُ سقطت».**
     *
     * ══════════════════════════════════════════════════════════════════
     * التقريرُ الحرفيُّ «٢١٧٣ اختباراً فشل» صحيحٌ وكاذبٌ معاً: نعم سقطت،
     * ولا، ليس لعطلٍ في الشيفرة. **وحارسٌ يكذب يُرسل من يصدّقه خلف عطلٍ
     * لا وجودَ له** — وقد أرسلني ثلاثَ مرّات.
     */
    public function a_dead_database_is_named_not_reported_as_broken_code(): void
    {
        $gate = file_get_contents(base_path('scripts/verify.sh'));

        $this->assertStringContainsString('سقطت أثناء الجولة', $gate,
            'البوّابةُ ما زالت تقرأ انقطاعَ الاتّصال «اختباراتٍ ساقطة»');
    }

    /**
     * @test
     *
     * **وقناةُ الأثر مفتوحة — لا يُشخَّص ما لا يُرى.**
     *
     * ماتت ثلاثَ مرّاتٍ ولم يُعرف السبب، لأنّ `skip_log_error` مضبوط ولا
     * خادمَ syslog ولا مقبسَ `/dev/log`. فما يكتبه `logger` يضيع.
     *
     * وهذا الفحصُ **يُخطّى صراحةً** إن لم تكن MariaDB مثبَّتةً محلّيّاً —
     * فبيئةُ التطوير عند غيري قد تختلف، ولا يُسقَط التزامٌ لذلك.
     */
    public function the_error_log_channel_is_open(): void
    {
        $conf = '/etc/mysql/mariadb.conf.d/99-amial-forensics.cnf';

        if (! is_dir('/etc/mysql/mariadb.conf.d')) {
            $this->markTestSkipped('MariaDB غير مثبَّتةٍ محلّيّاً — قناةُ الأثر غيرُ مفحوصة.');
        }

        $this->assertFileExists($conf,
            "قناةُ أثر MariaDB مغلقةٌ ثانيةً — موتٌ بلا سجلٍّ لا يُشخَّص.\n"
            . 'أعِد الملفّ من 01_backend/scripts/db-forensics.cnf');

        $src = file_get_contents($conf);

        $this->assertStringContainsString('log_error', $src,
            'الملفُّ موجودٌ بلا log_error — قناةٌ مفتوحةٌ على لا شيء');
    }
}
