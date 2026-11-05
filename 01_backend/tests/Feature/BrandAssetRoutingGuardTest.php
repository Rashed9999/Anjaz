<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-BRAND-ROUTING-001 — يمنع رجوع التطبيق إلى الشعار المسطّح القديم.
 *
 * المشكلة لم تكن غياب ملفات الهوية الجديدة؛ كانت بعض الشاشات تتجاوز
 * حزمة الهوية الرسمية وتطلب ملف legacy مباشرةً.
 */
class BrandAssetRoutingGuardTest extends TestCase
{
    /** @test */
    public function flutter_code_never_routes_branding_to_the_legacy_flat_logo(): void
    {
        $lib = base_path('../02_flutter_app/lib');
        $sources = '';

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'dart') {
                $source = file_get_contents($file->getPathname());
                // التعليقات ليست تنفيذًا؛ لا نسمح لها أن تصنع إيجابية كاذبة.
                $source = preg_replace('~/\*.*?\*/~s', '', $source) ?? $source;
                $source = preg_replace('~//.*$~m', '', $source) ?? $source;
                $sources .= $source;
            }
        }

        $this->assertStringNotContainsString(
            'assets/image/logo.png',
            $sources,
            'عاد مسار شعار legacy إلى Dart. استخدم AmialBrandLogo أو assets/branding الشفافة.',
        );
    }

    /**
     * @test
     *
     * ══════════════════════════════════════════════════════════════════
     * **AMIAL-BRAND-LOCKUP-001 — نُقض شرطُ الطبقات الأربع، ويُقال لماذا.**
     *
     * كان هذا الفحصُ يشترط أن يذكر الودجت **الطبقاتِ الأربعَ** كلَّها،
     * لأنّ النسخةَ الكاملةَ كانت تُركَّب منها بمقاساتٍ وفواصلَ مكتوبةٍ
     * يدويّاً. **وقد نُسخ ذلك بقرارٍ صريحٍ من صاحب المشروع**: أرسل
     * الشعارَ النهائيَّ ملفّاً واحداً وقال «غيّره إلى هذا في أغلب أماكن
     * التطبيق، ما عدا شعارات فتح بداية التطبيق».
     *
     * **ولم يُحذَف الفحصُ ولم يُضعَّف** — العطلُ الذي وُلد له قائمٌ ويُحرَس
     * في الحالة الأولى (لا رجوعَ إلى `assets/image/logo.png` المسطّح).
     * وهذه صارت تشترط ما هو صحيحٌ اليوم: **الأصلُ الرسميُّ الواحد**،
     * **وغيابُ الطبقات** — فرجوعُها يعني رجوعَ التركيب اليدويّ الذي
     * يُنتج شعاراً قريباً من الأصل لا الأصلَ نفسَه.
     *
     * والطبقاتُ نفسُها لم تُحذَف من المشروع: شاشةُ الافتتاح تحرّكها كلاًّ
     * على حدة، وهي المستثناةُ نصّاً. يحرسها
     * `test/brand_lockup_is_the_single_logo_test.dart`.
     */
    public function canonical_brand_widget_uses_the_single_official_lockup(): void
    {
        $widget = file_get_contents(
            base_path('../02_flutter_app/lib/common/widgets/amial_brand_logo.dart'),
        );

        // **الأصلان الرسميّان وحدَهما**: الرمزُ المربّع، والشعارُ الكامل.
        foreach ([
            'assets/branding/icon_foreground.png',
            'assets/brand/logo_lockup.png',
        ] as $asset) {
            $this->assertStringContainsString($asset, $widget,
                "الودجت لا يستعمل الأصلَ الرسميّ {$asset}");
        }

        $code = preg_replace('~^\s*///?.*$~m', '', $widget) ?? $widget;

        foreach ([
            'assets/brand/logo_wordmark.png',
            'assets/brand/logo_swoosh.png',
            'assets/brand/logo_latin.png',
            'assets/brand/logo_tagline.png',
        ] as $layer) {
            $this->assertStringNotContainsString($layer, $code,
                "عاد التركيبُ اليدويُّ من {$layer} — فالمعروضُ شعارٌ قريبٌ "
                .'من الأصل لا الأصلُ الذي أقرّه صاحبُ المشروع، وفرقُ '
                .'التباعد يتراكم عبر أربع طبقاتٍ ولا شيءَ يكشفه');
        }

        // **والملفُّ موجودٌ فعلاً** — مسارٌ مكتوبٌ لأصلٍ غائبٍ يُنتج
        // مربّعاً رماديّاً في كلّ شاشة، والتصريفُ عنه راضٍ.
        $this->assertFileExists(
            base_path('../02_flutter_app/assets/brand/logo_lockup.png'));
    }

    /** @test */
    public function backend_brand_source_is_the_same_clean_wordmark_shipped_to_flutter(): void
    {
        $backend = base_path('assets/branding/source/wordmark.png');
        $flutter = base_path('../02_flutter_app/assets/branding/wordmark.png');

        $this->assertFileExists($backend);
        $this->assertFileExists($flutter);
        $this->assertSame(
            hash_file('sha256', $flutter),
            hash_file('sha256', $backend),
            'مصدر backend انحرف عن نسخة Flutter الشفافة النظيفة.',
        );
    }

    /** @test */
    public function transparent_symbol_source_is_kept_as_an_official_brand_asset(): void
    {
        $source = base_path('assets/branding/source/icon_symbol_transparent.png');
        $flutter = base_path('../02_flutter_app/assets/branding/icon_foreground.png');

        $this->assertFileExists($source);
        $this->assertFileExists($flutter);
        $this->assertSame(hash_file('sha256', $flutter), hash_file('sha256', $source));
    }
}
