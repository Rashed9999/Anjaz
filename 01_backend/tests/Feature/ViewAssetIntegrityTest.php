<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-VIEW-ASSET-001 — قالبٌ يطلب ملفّاً غير موجود يبدو سليماً في كلّ اختبار.
 *
 * **العطل الذي أنشأ هذا الحارس.** كانت بوّابة الوكيل تحمّل:
 *
 *     asset('public/assets/admin/css/theme.min.css')
 *     asset('public/assets/admin/js/theme.min.js')
 *
 * ولا وجود لأيٍّ منهما في المستودع — بل لا وجود لمجلّد `public/assets` كلّه
 * (لوحة الإدارة تحمّل Bootstrap من CDN). فكانت الصفحة تُعرَض بلا تنسيقٍ
 * أصلاً: نصٌّ خامٌ بنقاطٍ سوداء، وتبويباتٌ لا تتبدّل، ونوافذُ لا تُفتح —
 * لأنّ سكربت Bootstrap لم يُحمَّل أيضاً.
 *
 * **ولماذا لم يمسكه شيء.** الصفحة تردّ 200 كاملةً. وكلّ تأكيدٍ على محتواها
 * يمرّ لأنّ الوسوم كلّها موجودة. الفشل يقع في المتصفّح وحده حين يطلب ملفّاً
 * فيردّ 404 — ولا يصل ذلك إلى أيّ اختبار خادمٍ مهما كثرت الاختبارات.
 *
 * فالطبقة الناقصة ليست اختباراً أعمق للصفحة، بل فحصٌ لما تَعِد الصفحةُ
 * بتحميله. وهذا ما يفعله هذا الحارس: يمرّ على كلّ القوالب، ويجمع كلّ
 * `asset()` تشير إلى ملفٍّ محلّيّ، ويطلب وجوده على القرص.
 */
class ViewAssetIntegrityTest extends TestCase
{
    /**
     * ما يُتجاوَز — والسبب مكتوب.
     *
     * @var array<string>
     */
    private const SKIP_FRAGMENTS = [
        '$',    // مسارٌ يُبنى في وقت التشغيل (متغيّر أو تعبير) لا يُفحص هنا
        '{{',
        '://',  // عنوانٌ خارجيّ — لا يُفحص على القرص
    ];

    /** @test */
    public function no_blade_template_loads_an_asset_that_does_not_exist(): void
    {
        $missing = [];

        foreach ($this->bladeFiles() as $file) {
            $blade = (string) file_get_contents($file);

            // نُزيل تعليقات Blade أوّلاً: تعليقٌ يشرح مساراً قديماً ليس
            // تحميلاً له. (وهذا الدرس دُفع ثمنُه في حارسٍ آخر مرّ للسبب
            // الخطأ لأنّ الكلمة وردت في تعليق.)
            $blade = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $blade);

            if (!preg_match_all("~asset\(\s*'([^']+)'\s*\)~", $blade, $m)) {
                continue;
            }

            foreach ($m[1] as $path) {
                foreach (self::SKIP_FRAGMENTS as $frag) {
                    if (str_contains($path, $frag)) {
                        continue 2;
                    }
                }

                // `asset('public/x')` تُخدَم من `public/public/x` أو من
                // `public/x` حسب ضبط النشر — فيُقبل الاثنان، ويُبلَّغ عمّا
                // لا يوجد في أيٍّ منهما.
                $candidates = [
                    base_path($path),
                    public_path($path),
                    public_path(preg_replace('~^public/~', '', $path)),
                ];

                foreach ($candidates as $c) {
                    if (file_exists($c)) {
                        continue 2;
                    }
                }

                $missing[] = '  • ' . str_replace(resource_path('views/'), '', $file)
                    . "\n      يطلب: {$path}";
            }
        }

        $this->assertSame([], $missing,
            "قوالب تطلب ملفّاتٍ غير موجودة — الصفحة تردّ 200 وتظهر مكسورةً في المتصفّح:\n"
            . implode("\n", $missing)
            . "\n\nإمّا يُضاف الملفّ، وإمّا يُستبدَل بمصدرٍ موجود (CDN أو ملفّ آخر).",
        );
    }

    /**
     * @test
     *
     * بوّابة الوكيل تحمّل Bootstrap فعلاً — تنسيقاً **وسكربتاً**.
     *
     * التنسيق وحده يجعل الصفحة تبدو سليمةً وأزرارُها لا تعمل: التبويبات
     * والنوافذ في Bootstrap جافاسكربت لا CSS. وهذا بالضبط ما وُصف بأنّ
     * «بعض الأزرار لا تعمل».
     */
    public function the_agent_portal_actually_loads_a_css_and_a_js_framework(): void
    {
        foreach (['login', 'dashboard'] as $view) {
            $blade = (string) file_get_contents(
                resource_path("views/agent-views/{$view}.blade.php"),
            );
            $blade = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', $blade);

            $this->assertMatchesRegularExpression(
                '~<link[^>]+bootstrap[^>]+\.css~i', $blade,
                "قالب «{$view}» لا يحمّل تنسيقاً — يُعرَض نصّاً خاماً",
            );
        }

        $dashboard = (string) file_get_contents(
            resource_path('views/agent-views/dashboard.blade.php'),
        );

        $this->assertMatchesRegularExpression(
            '~<script[^>]+bootstrap[^>]+\.js~i', $dashboard,
            'لوحة الوكيل لا تحمّل سكربت Bootstrap — التبويبات لن تتبدّل والنوافذ لن تُفتح',
        );
    }

    /**
     * @test
     *
     * لا إطار واجهةٍ يُحمَّل من شبكةٍ خارجيّة.
     *
     * **السبب تشغيليّ لا تفضيليّ.** صرّاف شركة الصرافة يعمل طول اليوم على
     * شبكةٍ يمنيّة. وإطارٌ يأتي من CDN يعني أنّ الشبّاك يتوقّف كلّما تعذّر
     * الوصول إليه — انقطاعُ خطٍّ دوليّ أو حجبٌ أو بطء. والصرّاف حينها يرى
     * صفحةً بلا تنسيقٍ وأزراراً لا تعمل، ولا يعرف أنّ العلّة في الشبكة.
     *
     * وهذا **وقع فعلاً** أثناء بناء هذه الميزة: ردّ `cdn.jsdelivr.net` بـ403
     * من خلف وسيط، فظهرت اللوحة خاماً تماماً كما ظهرت لصاحب المشروع من
     * العطل الأصليّ. الأثر واحدٌ والسبب مختلف — ولا يفرّق بينهما مستعمل.
     *
     * وأثرٌ ثانٍ: الاختبار الأوّل يفحص وجود الملفّات على القرص، والعنوان
     * الخارجيّ لا يُفحَص. فبقاؤه يعني حارساً لا يرى أهمّ ملفَّين في الواجهة.
     */
    public function no_view_loads_a_ui_framework_from_an_external_cdn(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $blade = (string) preg_replace(
                '/\{\{--.*?--\}\}/s', ' ', (string) file_get_contents($file),
            );

            if (preg_match_all(
                '~(?:href|src)\s*=\s*"(https?://[^"]*(?:bootstrap|jquery|cdn\.jsdelivr|cdnjs|unpkg)[^"]*)"~i',
                $blade, $m,
            )) {
                foreach ($m[1] as $url) {
                    $offenders[] = '  • ' . str_replace(resource_path('views/'), '', $file)
                        . "\n      {$url}";
                }
            }
        }

        $this->assertSame([], $offenders,
            "قوالب تحمّل إطار واجهةٍ من شبكةٍ خارجيّة:\n" . implode("\n", $offenders)
            . "\n\nتُنقل النسخة إلى public/assets/vendor/ وتُحمَّل بـasset().",
        );
    }

    /** @return array<string> */
    private function bladeFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }
}
