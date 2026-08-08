<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-TOKENS-001 · AMIAL-RENAME-001 — هويّةٌ واحدة، واسمٌ واحد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل الأوّل — علامةٌ بهويّتين، مقيسٌ لا مفترَض:**
 *
 *   التطبيق       : primary #053391 · background #F2F3F7
 *   لوحة الإدارة  : sidebar #0f2b46 · background #f4f6fa
 *
 * ليسا تدرّجاً من لونٍ واحد — **هما أزرقان مختلفان**. ولوحةُ الإدارة لم
 * تكن تقرأ نظامَ ألوانٍ إطلاقاً: أربعةَ عشرَ سطرَ CSS محشوّةً في القالب،
 * **تُنسخ ولا تُشتق** — وهو ما أنتج الأزرقين أصلاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل الثاني — اسمان لعلامةٍ واحدة:**
 *
 * الشعارُ `amyal` والنطاقُ `amial`. فبقيت في الشيفرة ٣٩٦٣ إشارةً بالياء،
 * **ومنها عناوينُ بريدٍ في وثائقَ قانونيّة** (`support@amyalpay.com`)
 * تشير إلى نطاقٍ **لا تملكه المنصّة**. فمن كتب إليها شاكياً لا يصل أحداً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ حارسٌ لا تصحيحٌ مرّةً واحدة:** التصحيحُ اليوم لا يمنع سطراً
 * يُكتب غداً بالياء، ولا لوناً يُغيَّر في سطحٍ ويُنسى في الآخر. وهذا
 * يسقط في `verify.sh` مع كلّ فحص.
 */
class BrandIdentityGuardTest extends TestCase
{
    private function appFile(string $rel): string
    {
        return base_path('../02_flutter_app/' . $rel);
    }

    /**
     * الألوانُ التي يجب أن تتطابق في السطحين.
     *
     * @return array<string,string> اسمُ التوكِن ⇒ ثابتُ Dart
     */
    private const SHARED = [
        'primary' => 'primary',
        'primary-dark' => 'primaryDark',
        'primary-light' => 'primaryLight',
        'yellow' => 'yellow',
        'red' => 'red',
        'background' => 'background',
    ];

    /**
     * @test
     *
     * **لونُ العلامة واحدٌ في التطبيق واللوحة — قيمةً قيمة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا الحارسُ يقرأ الملفّين ويقارن، فلا يعود يقع أن يُغيَّر لونٌ في
     * سطحٍ ويُنسى في الآخر. **والعكس:** غُيّر `--amial-primary` في CSS
     * وحده ⇒ يسقط باسم اللون والقيمتين.
     */
    public function the_brand_colours_match_across_app_and_admin(): void
    {
        $dart = file_get_contents($this->appFile('lib/theme/amial_colors.dart'));
        $css = file_get_contents(public_path('assets/css/amial-tokens.css'));

        $this->assertNotEmpty($dart, 'ملفُّ ألوان التطبيق فارغ');
        $this->assertNotEmpty($css, 'ملفُّ التوكِنز فارغ');

        $mismatch = [];

        foreach (self::SHARED as $token => $dartName) {
            // Color(0xFF053391)
            if (!preg_match('/' . preg_quote($dartName, '/') . '\s*=\s*Color\(0x[Ff]{2}([0-9A-Fa-f]{6})\)/', $dart, $d)) {
                $this->fail("لا ثابتَ اسمُه {$dartName} في ألوان التطبيق — الحارسُ يفحص شيئاً غير موجود");
            }

            if (!preg_match('/--amial-' . preg_quote($token, '/') . '\s*:\s*#([0-9A-Fa-f]{6})/', $css, $c)) {
                $this->fail("لا توكِنَ اسمُه --amial-{$token} في CSS");
            }

            if (strtolower($d[1]) !== strtolower($c[1])) {
                $mismatch[] = "{$token}: التطبيق #{$d[1]} · اللوحة #{$c[1]}";
            }
        }

        $this->assertSame([], $mismatch,
            'العلامةُ بهويّتين — ' . implode(' · ', $mismatch));
    }

    /**
     * @test
     *
     * **ولا لونَ مكتوبٌ داخل قالب اللوحة.**
     *
     * فقيمةٌ تُكتب في القالب تهرب من الحارس أعلاه — **وهي بالضبط كيف
     * وُلد الأزرقان**: لونٌ نُسخ مرّةً ثمّ عاش وحده.
     */
    public function the_admin_layout_holds_no_raw_colour(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/admin/app.blade.php'));

        // نُزيل تعليقات Blade — فهي تذكر الألوان القديمة شرحاً لا تطبيقاً.
        $body = preg_replace('/\{\{--.*?--\}\}/s', '', $layout);

        preg_match_all('/#[0-9A-Fa-f]{3,8}\b/', $body, $m);

        $this->assertSame([], $m[0],
            'ألوانٌ مكتوبةٌ في القالب بدل التوكِنز: ' . implode(' · ', $m[0]));

        $this->assertStringContainsString('amial-tokens.css', $layout,
            'القالبُ لا يُحمّل التوكِنز');
    }

    /**
     * @test
     *
     * **ولا «amyal» بالياء في الشيفرة.**
     *
     * ويُستثنى معرّفُ التطبيق `com.amyalpay` وحده — تغييرُه يجعل جوجل
     * بلاي تراه تطبيقاً آخر فلا تُحدَّث النسخُ المثبَّتة، **وهو قرارُ
     * صاحب المشروع لا قرارُ شيفرة**. والاستثناءُ مكتوبٌ هنا لا متروكٌ
     * صمتاً.
     */
    public function no_amyal_spelling_remains_in_the_code(): void
    {
        $roots = [
            base_path('app'), base_path('resources'), base_path('routes'), base_path('config'),
            base_path('../02_flutter_app/lib'),
        ];

        $hits = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }

                if (!in_array($f->getExtension(), ['php', 'dart', 'js', 'css', 'md'], true)) {
                    continue;
                }

                $src = file_get_contents($f->getPathname());

                // **اسمُ مشروع Firebase مستثنىً** — `amyal-pay` موردٌ
                // حقيقيٌّ في حسابٍ خارجيّ، لا إملاءٌ في شيفرة. ولا يتغيّر
                // إلّا بإنشاء مشروعٍ جديدٍ هناك.
                $src = str_replace(['amyal-pay', 'com.amyalpay'], '', $src);

                if (stripos($src, 'amyal') !== false) {
                    $hits[] = str_replace(base_path('..') . '/', '', $f->getPathname());
                }
            }
        }

        $this->assertSame([], $hits,
            'بقيت «amyal» بالياء في: ' . implode(' · ', array_slice($hits, 0, 8)));
    }

    /**
     * @test
     *
     * **واسمُ حزمة التطبيق بالألف.**
     */
    public function the_flutter_package_is_named_with_an_i(): void
    {
        $pubspec = file_get_contents($this->appFile('pubspec.yaml'));

        $this->assertMatchesRegularExpression('/^name:\s*amial_pay\s*$/m', $pubspec,
            'اسمُ الحزمة ما زال amyal_pay');
    }

    /**
     * @test
     *
     * **ومعرّفُ التطبيق واحدٌ في كلّ منصّة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `applicationId` و`namespace` في Gradle، و`package` في Kotlin،
     * و`PRODUCT_BUNDLE_IDENTIFIER` في iOS، و`package_name` في
     * `google-services.json` — **خمسةُ مواضعَ لقيمةٍ واحدة**. واختلافُ
     * واحدٍ منها لا يُنتج خطأ تصريف: يُنتج تطبيقاً لا تصله إشعارات، أو
     * بناءً يفشل بعد ستّ دقائق برسالةٍ لا تدلّ على موضعها.
     */
    public function the_application_id_is_identical_everywhere(): void
    {
        $gradle = file_get_contents($this->appFile('android/app/build.gradle.kts'));

        if (!preg_match('/applicationId\s*=\s*"([^"]+)"/', $gradle, $m)) {
            $this->fail('لا applicationId في build.gradle.kts');
        }

        $id = $m[1];

        $this->assertStringStartsWith('com.amialpay', $id, 'معرّفُ التطبيق ما زال بالياء');

        preg_match('/namespace\s*=\s*"([^"]+)"/', $gradle, $ns);
        $this->assertSame($id, $ns[1] ?? '', 'namespace يخالف applicationId');

        $kt = $this->appFile('android/app/src/main/kotlin/' . str_replace('.', '/', $id) . '/MainActivity.kt');
        $this->assertFileExists($kt, "مجلّدُ Kotlin لا يطابق المعرّف — متوقَّع {$kt}");

        $this->assertStringContainsString("package {$id}", file_get_contents($kt),
            'حزمةُ Kotlin تخالف مسارَ مجلّدها');

        $ios = file_get_contents($this->appFile('ios/Runner.xcodeproj/project.pbxproj'));
        $this->assertStringNotContainsString('com.amyalpay', $ios, 'معرّفُ iOS ما زال بالياء');

        $gs = json_decode(file_get_contents($this->appFile('android/app/google-services.json')), true);
        $pkg = $gs['client'][0]['client_info']['android_client_info']['package_name'] ?? null;

        $this->assertSame($id, $pkg,
            'package_name في google-services.json يخالف applicationId — البناءُ يفشل والإشعاراتُ تتوقّف');
    }

    /**
     * @test
     *
     * **واسمُ مشروع Firebase لا يُمَسّ بإعادة تسمية العلامة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا حارسٌ وُلد من خطئي أنا:** أعدتُ التسمية آليّاً فغيّرتُ
     * `projectId: 'amyal-pay'` إلى `'amial-pay'` في `firebase_options.dart`
     * — **ومشروعُ Firebase اسمُه `amyal-pay`**. فصار الملفّان متعارضين،
     * والتطبيقُ يُشير إلى مشروعٍ لا وجود له.
     *
     * فاسمُ المشروع ليس إملاءً في شيفرة: هو **اسمُ موردٍ حقيقيّ** في
     * حسابٍ خارجيّ، ولا يتغيّر إلّا بإنشاء مشروعٍ جديدٍ في Firebase.
     */
    public function the_firebase_project_id_matches_the_real_project(): void
    {
        $dart = file_get_contents($this->appFile('lib/firebase_options.dart'));
        $gs = json_decode(file_get_contents($this->appFile('android/app/google-services.json')), true);

        $real = $gs['project_info']['project_id'] ?? null;

        $this->assertNotNull($real, 'لا project_id في google-services.json');

        preg_match("/projectId:\s*'([^']+)'/", $dart, $m);

        $this->assertSame($real, $m[1] ?? '',
            'projectId في Dart يخالف مشروع Firebase — التطبيقُ يُشير إلى مشروعٍ لا وجود له');

        preg_match("/storageBucket:\s*'([^']+)'/", $dart, $b);

        $this->assertSame($gs['project_info']['storage_bucket'] ?? '', $b[1] ?? '',
            'storageBucket يخالف google-services.json');
    }

    /**
     * @test
     *
     * **ووثائقُ القانون لا تُحيل إلى نطاقٍ لا تملكه المنصّة.**
     *
     * فمن كتب إلى `support@amyalpay.com` شاكياً **لا يصل أحداً** —
     * والنطاقُ المملوك `amialpay.com`. وهذه وثائقُ مُلزِمةٌ يقرأها عميل.
     */
    public function the_legal_documents_point_at_the_owned_domain(): void
    {
        $dir = resource_path('legal');

        if (!is_dir($dir)) {
            $this->markTestSkipped('لا وثائقَ قانونيّة في هذا التثبيت');
        }

        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }

            if (stripos(file_get_contents($f->getPathname()), 'amyalpay') !== false) {
                $bad[] = $f->getFilename();
            }
        }

        $this->assertSame([], $bad,
            'وثائقُ قانونيّةٌ تُحيل إلى نطاقٍ غير مملوك: ' . implode(' · ', $bad));
    }
}
