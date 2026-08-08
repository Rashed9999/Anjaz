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
     * ══════════════════════════════════════════════════════════════════
     * **وهذا الحارسُ كان يدّعي أكثرَ ممّا يفحص — وهو ما حذّرت منه القاعدة
     * الثانية بعينها.**
     *
     * كان يمسح `app` و`resources` و`routes` و`config` و`lib` وحدها،
     * وامتداداتِ php/dart/js/css/md وحدها. **فجذرُ المستودع خارجَه،
     * وYAML وShell وENV خارجَه.** ويمرّ باسمه الواسع بينما ٢٧ ملفّاً
     * متتبَّعاً تحمل الكلمةَ بالياء.
     *
     * وثمنُه لم يكن إملائيّاً: `demo.sh` كان يطبع `admin@amyal.pay`
     * **وبريدُ الأدمن في البذور `admin@amial.pay`** — بيانُ دخولٍ خاطئٌ
     * يُعطى لمن يشغّل العرض التجريبيّ، ولا خطأ في أيّ سجلّ.
     *
     * ══════════════════════════════════════════════════════════════════
     * **والاستثناءاتُ الثلاثة مكتوبةٌ بسببها، لا متروكةٌ صمتاً:**
     *
     *   `com.amyalpay`  — معرّفُ التطبيق. تغييرُه يجعل جوجل بلاي تراه
     *                     تطبيقاً آخر فلا تُحدَّث النسخُ المثبَّتة.
     *   `amyal-pay`     — اسمُ مشروع Firebase. موردٌ حقيقيٌّ في حسابٍ
     *                     خارجيّ، لا يتغيّر إلّا بإنشاء مشروعٍ جديد.
     *   `amyal_webhook` — رمزُ تحقّق ويبهوك واتساب. **قيمةٌ مطابقةٌ لما
     *                     في لوحة Meta**، وتغييرُها هنا يكسر الويبهوك.
     *
     * وثلاثتُها موردٌ خارجيّ لا إملاءٌ في شيفرة — والفرقُ بينهما هو ما
     * كلّف هذا المشروع جولةً كاملة حين غيّرت إعادةُ تسميةٍ آليّة
     * `projectId` إلى مشروعٍ لا وجود له.
     */
    public function no_amyal_spelling_remains_in_the_code(): void
    {
        $roots = [
            base_path('app'), base_path('resources'), base_path('routes'), base_path('config'),
            base_path('database'), base_path('scripts'), base_path('assets'),
            base_path('public/assets'),
            base_path('../02_flutter_app/lib'),
            base_path('../02_flutter_app/android/app/src'),

            // **وجذرُ المستودع نفسُه** — وفيه `codemagic.yaml` الذي يبني
            // التطبيق، وكان خارج كلّ مسحٍ سابق.
            base_path('..'),
        ];

        // ملفّاتٌ تُقرأ أو تُشغَّل — لا صورٌ ولا حزم.
        $exts = ['php', 'dart', 'js', 'css', 'md', 'yaml', 'yml', 'sh',
                 'xml', 'json', 'kts', 'gradle', 'env', 'example', 'demo'];

        // **وما لا يُمسح يُقال لماذا:**
        //   `docs/` و`CHANGELOG` — سجلٌّ تاريخيٌّ يصف ما كان. تغييرُه
        //     يُزوّر التاريخ، والاسمُ القديم فيه صحيحٌ في موضعه.
        //   `vendor` و`node_modules` و`build` — ليست شيفرتَنا.
        //   وهذا الملفُّ نفسُه — يذكر الكلمةَ في استثناءاته، فلو مسح
        //     نفسَه لسقط أبداً. (حارسٌ يقتل نفسَه لا يحرس.)
        //   `*_NOTES.md` — ملاحظاتُ جولاتٍ سابقة، تقتبس عناوينَ وثائقَ
        //     كُتبت بالاسم القديم. وتغييرُ اقتباسٍ يُحرّفه.
        $skip = ['/vendor/', '/node_modules/', '/.git/', '/build/', '/storage/',
                 '/docs/', '/dist/', '/.dart_tool/', '/ios/Pods/',
                 'CHANGELOG', 'CHANGES_', '_NOTES.md', 'BrandIdentityGuardTest.php'];

        $hits = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            // **تُقلَّم المجلّداتُ أثناء النزول لا بعده.** فبلا تقليمٍ ينزل
            // الماسحُ إلى `vendor` و`build` كاملَين ثمّ يرمي نتائجَهما —
            // ثلاثون ثانيةً في كلّ فحص، وحارسٌ بطيءٌ يُعطَّل.
            $prune = static function (\SplFileInfo $f) use ($skip): bool {
                if (!$f->isDir()) {
                    return true;
                }

                foreach ($skip as $s) {
                    if (str_contains($f->getPathname(), rtrim($s, '/'))) {
                        return false;
                    }
                }

                return true;
            };

            $it = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    $prune,
                ),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }

                $path = $f->getPathname();

                foreach ($skip as $s) {
                    if (str_contains($path, $s)) {
                        continue 2;
                    }
                }

                // الامتدادُ أو اسمٌ بلا امتدادٍ يبدأ بنقطة (`.env.example`).
                if (!in_array($f->getExtension(), $exts, true)) {
                    continue;
                }

                $src = file_get_contents($path);

                // **ثلاثةُ مواردَ خارجيّةٍ تُستثنى** — مذكورةٌ بأسبابها في
                // توثيق هذه الدالّة أعلاه. وهي أسماءُ أشياءَ حقيقيّةٍ في
                // حساباتٍ خارجيّة، لا إملاءٌ في شيفرة.
                //
                // **ورابعٌ مؤقّت:** `amyal-android-release` معرّفُ سير
                // العمل في `codemagic.yaml`. وقد يكون مربوطاً في لوحة
                // Codemagic عند صاحب المشروع، فتغييرُه من هنا قد يقطع
                // بناءَه. **يُستثنى بعلمه لا صمتاً**، ويُرفع الاستثناءُ
                // متى أكّد أنّ لا ربطَ يعتمد عليه.
                $src = str_replace(
                    ['amyal-pay', 'com.amyalpay', 'amyal_webhook', 'amyal-android-release'],
                    '',
                    $src,
                );

                if (stripos($src, 'amyal') !== false) {
                    // المساراتُ تُوحَّد ثمّ تُنزَع تكراراتُها: الجذورُ
                    // متداخلة (`scripts` داخل `..`)، فالملفُّ الواحد
                    // يُمسح مرّتين ويُذكر مرّتين في تقريرٍ يُقرأ.
                    $hits[(string) realpath($path)] =
                        str_replace(base_path('..') . '/', '', (string) realpath($path));
                }
            }
        }

        $hits = array_values($hits);
        sort($hits);

        $this->assertSame([], $hits, sprintf(
            "بقيت «amyal» بالياء في %d ملفّاً:\n  %s",
            count($hits),
            implode("\n  ", array_slice($hits, 0, 12)),
        ));
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

        $this->assertStringNotContainsString('amyal', $id, 'معرّفُ التطبيق ما زال بالياء');

        preg_match('/namespace\s*=\s*"([^"]+)"/', $gradle, $ns);
        $this->assertSame($id, $ns[1] ?? '', 'namespace يخالف applicationId');

        $kt = $this->appFile('android/app/src/main/kotlin/' . str_replace('.', '/', $id) . '/MainActivity.kt');
        $this->assertFileExists($kt, "مجلّدُ Kotlin لا يطابق المعرّف — متوقَّع {$kt}");

        $this->assertStringContainsString("package {$id}", file_get_contents($kt),
            'حزمةُ Kotlin تخالف مسارَ مجلّدها');

        $ios = file_get_contents($this->appFile('ios/Runner.xcodeproj/project.pbxproj'));
        $this->assertStringNotContainsString('com.amyalpay', $ios, 'معرّفُ iOS ما زال بالياء');

        // **والملفُّ قد يحمل أكثرَ من عميل** — القديمُ يبقى فيه بلا ضرر.
        // فيُبحث عن العميل المطابق، لا يُقرأ الأوّلُ ويُفترض.
        $gs = json_decode(file_get_contents($this->appFile('android/app/google-services.json')), true);

        $packages = array_map(
            fn ($c) => $c['client_info']['android_client_info']['package_name'] ?? '',
            $gs['client'] ?? [],
        );

        $this->assertContains($id, $packages,
            "لا عميلَ في google-services.json لحزمة «{$id}» — البناءُ يفشل عند مُلحق google-services. "
            . 'المسجَّل: ' . implode(' · ', $packages));
    }

    /**
     * @test
     *
     * **ومعرّفُ Firebase في Dart يخصّ حزمتَنا لا حزمةً أخرى.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا أصمتُ عطلٍ في السلسلة كلِّها.**
     *
     * `google-services.json` يحمل عميلين — القديمَ والجديد — ولكلٍّ
     * `mobilesdk_app_id` مختلف. فلو بقي `appId` في Dart على القديم:
     * البناءُ ينجح، والتطبيقُ يعمل، **ولا يصل إشعارٌ واحد** — لأنّ رمز
     * FCM يُسجَّل تحت تطبيقٍ غير الذي يعمل. ولا خطأَ في أيّ سجلّ.
     *
     * وقد وقع فعلاً: بعد تغيير الحزمة بقي `appId` على `…3c054b`
     * المرتبط بـ`com.amyalpay.app`. كُشف بالقياس لا بالتصريف.
     */
    public function the_firebase_app_id_belongs_to_our_package(): void
    {
        $gradle = file_get_contents($this->appFile('android/app/build.gradle.kts'));
        preg_match('/applicationId\s*=\s*"([^"]+)"/', $gradle, $m);
        $id = $m[1] ?? '';

        $gs = json_decode(file_get_contents($this->appFile('android/app/google-services.json')), true);

        $expected = null;

        foreach ($gs['client'] ?? [] as $c) {
            if (($c['client_info']['android_client_info']['package_name'] ?? '') === $id) {
                $expected = $c['client_info']['mobilesdk_app_id'] ?? null;
            }
        }

        $this->assertNotNull($expected, "لا عميلَ لحزمة «{$id}» في google-services.json");

        $dart = file_get_contents($this->appFile('lib/firebase_options.dart'));
        preg_match("/appId:\s*'([^']+)'/", $dart, $a);

        $this->assertSame($expected, $a[1] ?? '',
            'appId في Dart يخصّ عميلاً آخر — الإشعاراتُ لا تصل ولا خطأَ في أيّ سجلّ');
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
