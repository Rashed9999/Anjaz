<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-BRAND-ROUTING-001 — يمنع رجوع التطبيق إلى الشعار المسطّح القديم.
 *
 * المشكلة لم تكن غياب ملفات الهوية الجديدة؛ كانت الشاشات تستدعي
 * assets/image/logo.png مباشرةً فتتجاوز كل إصلاحات الشفافية والهوية.
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
                $sources .= file_get_contents($file->getPathname());
            }
        }

        $this->assertStringNotContainsString(
            "'assets/image/logo.png'",
            $sources,
            'عاد مسار شعار قديم إلى Dart. استخدم AmialBrandLogo أو assets/branding الشفافة.',
        );
    }

    /** @test */
    public function canonical_brand_widget_uses_only_official_transparent_layers(): void
    {
        $widget = file_get_contents(
            base_path('../02_flutter_app/lib/common/widgets/amial_brand_logo.dart'),
        );

        foreach ([
            'assets/branding/icon_foreground.png',
            'assets/brand/logo_wordmark.png',
            'assets/brand/logo_swoosh.png',
            'assets/brand/logo_latin.png',
            'assets/brand/logo_tagline.png',
        ] as $asset) {
            $this->assertStringContainsString($asset, $widget);
        }
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
