<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-DOMAIN-001 — التحوّل إلى النطاق: ما لا يجوز أن يُنسى.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **التحذيرات المكتوبة لا تُنفّذ نفسها.**
 *
 * في `network_security_config.xml` سطرٌ مكتوبٌ منذ أشهر:
 *
 *     ⚠️⚠️ للتجربة فقط — قبل الإطلاق: انتقل إلى دومين + https،
 *     وأعِد usesCleartextTraffic="false"
 *
 * ولم يُنفَّذ. وهذا هو حالُ كلّ تعليقٍ يطلب فعلاً من إنسانٍ في المستقبل:
 * يُقرأ مرّةً حين يُكتب ثمّ لا يُقرأ أبداً.
 *
 * فبدل التذكير، **يُفرض بالبناء**: نسخة الإصدار تحمل ضبطاً خاصّاً يمنع
 * الاتّصال غير المشفَّر، ونسخة التطوير تُبقيه. ومن بنى للإصدار أغلق
 * الباب وإن لم يعرف أنّه مفتوح.
 *
 * وهذا الحارس يمنع أن يُحذف ذلك الضبط أو يُفرَّغ.
 */
class DomainCutoverGuardTest extends TestCase
{
    /** جذرُ تطبيق فلاتر — أو `null` إن كان النشر للخادم وحده. */
    private function flutterRoot(): ?string
    {
        $p = base_path('../02_flutter_app');

        return is_dir($p) ? $p : null;
    }

    /**
     * @test
     *
     * **نسخة الإصدار لا تسمح باتّصالٍ غير مشفَّر — لا في ضبط الشبكة ولا
     * في المانفست.**
     *
     * ولا يكفي أحدهما: بعض مكتبات الشبكة تقرأ سمة المانفست مباشرةً بدل
     * ضبط النظام. فيُغلَق البابان.
     */
    public function a_release_build_can_never_speak_plain_http(): void
    {
        $root = $this->flutterRoot();

        if ($root === null) {
            // «غير معروف» يُقال ولا يُقرأ نجاحاً (القاعدة السابعة).
            $this->markTestSkipped('تطبيق فلاتر غير موجودٍ في هذا النشر — لم يُفحص');
        }

        $config = $root . '/android/app/src/release/res/xml/network_security_config.xml';
        $manifest = $root . '/android/app/src/release/AndroidManifest.xml';

        $this->assertFileExists($config,
            'لا ضبط شبكةٍ خاصٌّ بالإصدار — فسيرث سماح التطوير بالاتّصال المكشوف');
        $this->assertFileExists($manifest,
            'لا مانفست إصدارٍ يُغلق usesCleartextTraffic');

        $this->assertStringContainsString('cleartextTrafficPermitted="false"',
            file_get_contents($config),
            'ضبط الإصدار يسمح بالاتّصال غير المشفَّر');

        $m = file_get_contents($manifest);
        $this->assertStringContainsString('android:usesCleartextTraffic="false"', $m);
        $this->assertStringContainsString('tools:replace="android:usesCleartextTraffic"', $m,
            'بلا tools:replace يفوز سطرُ main عند الدمج فتبقى الراية مفتوحة');
    }

    /**
     * @test
     *
     * **عنوان الخادم في موضعٍ واحد.**
     *
     * وعنوانٌ مبعثرٌ في عشرة ملفّات يجعل التحوّل إلى النطاق عمليّةَ بحثٍ
     * واستبدال — وكلُّ موضعٍ يُنسى يبقى ينادي الخادم القديم بعد إيقافه.
     */
    public function the_backend_address_lives_in_exactly_one_place(): void
    {
        $root = $this->flutterRoot();

        if ($root === null) {
            $this->markTestSkipped('تطبيق فلاتر غير موجودٍ في هذا النشر — لم يُفحص');
        }

        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib'));

        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'dart') {
                continue;
            }

            // عنوانٌ رقميّ صريحٌ داخل نصّ — لا في تعليق.
            //
            // **و`(?<!:)` ليست زينة.** كتبتُ `!//.*$!m` أوّلاً فأكلت
            // `//` من داخل `http://…` نفسه — فصار الحارس يمسح ما جاء
            // ليبحث عنه، ويمرّ دائماً وهو لا يفحص شيئاً. وهي القاعدة
            // الخامسة بعينها: تعبيرٌ نمطيٌّ جشعٌ يُنتج عطلاً مكان عطل.
            //
            // والمحدِّد `~` لا `!`: علامةُ التعجّب داخل `(?<!` تُقرأ
            // نهايةَ النمط فيُرفض بـ«Unknown modifier».
            $code = preg_replace('~(?<!:)//.*$~m', '', file_get_contents($f->getPathname()));

            if (preg_match('~https?://\d{1,3}(\.\d{1,3}){3}~', $code)) {
                $hits[] = str_replace($root . '/', '', $f->getPathname());
            }
        }

        // بعد التحوّل لم يبقَ في التطبيق عنوانٌ رقميّ أصلاً — ولا حتّى في
        // `app_constants.dart`. فلا يُشترط وجودُه في موضعٍ واحد بل **غيابُه
        // من كلّ موضع**: الشرطُ صار أضيق لا أوسع.
        $this->assertSame([], $hits,
            "عنوان خادمٍ رقميٌّ مزروعٌ في: " . implode('، ', $hits)
            . "\nوكلّ موضعٍ يُنسى يبقى ينادي الخادم القديم بعد إيقافه.");
    }

    /**
     * @test
     *
     * **الافتراضيّ نطاقٌ مشفَّر — لا عنوانٌ يُمرَّر وقت البناء.**
     *
     * وهذا ما يمنع أخطر صمتٍ في الملفّ: نسخةُ الإصدار تمنع الاتّصال غير
     * المشفَّر عمداً، فبناءٌ نُسي فيه `--dart-define` يُنتج تطبيقاً يُثبَّت
     * ويفتح ويسقط في **كلّ** شاشةٍ بـ«تعذّر الاتّصال» — بلا خطأٍ في أيّ
     * سجلّ، ولا شيء يدلّ على السبب.
     *
     * فالافتراضيّ نفسه يعمل، والتمريرُ يصير اختياراً لا شرطاً.
     */
    public function the_default_backend_address_is_the_secure_domain(): void
    {
        $root = $this->flutterRoot();

        if ($root === null) {
            $this->markTestSkipped('تطبيق فلاتر غير موجودٍ في هذا النشر — لم يُفحص');
        }

        $code = file_get_contents($root . '/lib/util/app_constants.dart');

        $this->assertMatchesRegularExpression(
            "~String\.fromEnvironment\(\s*'BASE_URL'\s*,\s*defaultValue:\s*productionDomain\s*\)~",
            $code,
            'الافتراضيّ ليس النطاق المعتمَد — فبناءٌ بلا --dart-define يُنتج نسخةً لا تتّصل.');

        // ونفيٌ صريح: لا HTTP في الافتراضيّ مهما تغيّرت صياغته.
        if (preg_match("~static const String baseUrl =(.+?);~s", $code, $m)) {
            $this->assertStringNotContainsString('http://', $m[1],
                'الافتراضيّ يتّصل نصّاً صريحاً — كلماتُ سرّ العملاء تمرّ مكشوفة.');
        }
    }

    /**
     * @test
     *
     * النطاق المعتمَد مكتوبٌ في الشيفرة — فالتحوّل تمريرُ متغيّرٍ لا بحثٌ
     * عن الاسم في محادثة.
     */
    public function the_production_domain_is_recorded_in_the_app(): void
    {
        $root = $this->flutterRoot();

        if ($root === null) {
            $this->markTestSkipped('تطبيق فلاتر غير موجودٍ في هذا النشر — لم يُفحص');
        }

        $code = file_get_contents($root . '/lib/util/app_constants.dart');

        $this->assertStringContainsString("productionDomain = 'https://amialpay.com'", $code);
        // ويُقاس التشفير من العنوان نفسه لا من رايةٍ تُضبط بيد.
        $this->assertStringContainsString('isSecureBackend', $code);
    }
}
