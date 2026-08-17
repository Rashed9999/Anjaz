<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-DEAD-STACK-001 — **سكربتٌ لا يصل المتصفّحَ صفحةٌ ميّتةٌ تردّ ٢٠٠.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **عطلان قِيسا في هذا المشروع، وكلاهما بسطرٍ واحد:**
 *
 * **① مكدَّسٌ لا يعرضه أحد.** `@push('script_2')` في صفحتين —
 * «مركز التحقّق» و«صحّة النظام» — والقالبُ الأمُّ يعرض `@stack('script')`
 * وحدَه. فالشيفرةُ تُدفَع إلى مكدَّسٍ **لا يُصيَّر أبداً**.
 *
 * ونتيجتُه في مركز التحقّق: كلُّ الشاشة تبقى على «جارٍ التحميل…» أبداً.
 * وفي صحّة النظام: قوائمُ تغيير حالة الأعطال لا تُرسل طلباً واحداً —
 * **يُغيّر المشرفُ الحالةَ فلا يتغيّر شيء ويظنّها عولجت**.
 *
 * **② سكربتٌ مضمَّنٌ بلا `nonce`.** و`SecurityHeaders` يضبط
 * `script-src 'self' 'nonce-…'`، فيرفضه المتصفّحُ **صامتاً**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا تمسك الاختباراتُ أيّاً منهما**: الصفحةُ تردّ ٢٠٠ في الحالتين.
 * ولا يمسكهما فحصُ تركيب الجافاسكربت: الشيفرةُ سليمةٌ نحويّاً وحسب.
 * ولا طبقةُ الضغط في البوّابة: هي تبني القالبَ **مباشرةً** فلا يمرّ
 * بالقالب الأمّ ولا بالوسيط.
 *
 * فالفحصُ الوحيدُ الممكن هو هذا: **مطابقةُ ما يُدفَع بما يُعرَض.**
 */
class InlineScriptDeliveryGuardTest extends TestCase
{
    /** القوالبُ الأمّ التي تُصيَّر منها الصفحات. */
    private const LAYOUTS = [
        'resources/views/layouts/admin/app.blade.php',
    ];

    /**
     * قوالبُ اللوحة — وهي وحدَها ما يُصيَّر داخل قالب الإدارة.
     *
     * @return array<int,string>
     */
    private function adminViews(): array
    {
        $out = [];
        $root = resource_path('views/admin-views');

        if (! is_dir($root)) {
            return $out;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    /** أسماءُ المكدَّسات التي يعرضها قالبٌ أمٌّ فعلاً. */
    private function renderedStacks(): array
    {
        $names = [];

        foreach (self::LAYOUTS as $rel) {
            $path = base_path($rel);

            if (! is_file($path)) {
                continue;
            }

            preg_match_all("~@stack\(\s*'([\w\-]+)'\s*\)~",
                (string) file_get_contents($path), $m);

            foreach ($m[1] ?? [] as $n) {
                $names[$n] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @test
     *
     * **① كلُّ مكدَّسٍ يُدفَع إليه يعرضه القالبُ الأمّ.**
     */
    public function every_pushed_stack_is_actually_rendered(): void
    {
        $rendered = $this->renderedStacks();

        $this->assertNotEmpty($rendered,
            'لم يُقرأ أيُّ `@stack` من القالب الأمّ — التعبيرُ لم يعد يطابق، '
            . '**والحارسُ يمرّ فارغاً**');

        $orphans = [];

        foreach ($this->adminViews() as $file) {
            // تعليقاتُ Blade تُنزع — شرحٌ يذكر `@push('script_2')` ليصف
            // ما أُصلح ليس دفعاً إليه.
            $src = (string) preg_replace('~\{\{--.*?--\}\}~s', '',
                (string) file_get_contents($file));

            preg_match_all("~@push\(\s*'([\w\-]+)'\s*\)~", $src, $m);

            foreach ($m[1] ?? [] as $stack) {
                if (! in_array($stack, $rendered, true)) {
                    $orphans[] = str_replace(resource_path('views').'/', '', $file)
                        ." → @push('{$stack}')";
                }
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "شيفرةٌ تُدفَع إلى مكدَّسٍ **لا يعرضه القالبُ الأمّ** — فلا تصل "
            . "المتصفّحَ إطلاقاً والصفحةُ تردّ ٢٠٠:\n  %s\n\n"
            . 'المكدَّساتُ المعروضة: %s',
            implode("\n  ", $orphans), implode('، ', $rendered)));
    }

    /**
     * @test
     *
     * **② وكلُّ سكربتٍ مضمَّنٍ في اللوحة موقَّعٌ بـnonce.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فسياسةُ المحتوى تمنع غيرَ الموقَّع، والمنعُ صامتٌ تماماً.
     */
    public function every_inline_admin_script_carries_a_csp_nonce(): void
    {
        $offenders = [];

        foreach ($this->adminViews() as $file) {
            $src = (string) preg_replace(
                ['~\{\{--.*?--\}\}~s', '~<!--.*?-->~s'], '',
                (string) file_get_contents($file));

            // `src=` مستثنىً: ملفٌّ خارجيٌّ من `'self'` يمرّ بلا توقيع.
            // و`type="application/json"` ليس سكربتاً يُنفَّذ.
            preg_match_all('~<script(?![^>]*\bsrc=)([^>]*)>~i', $src, $m);

            foreach ($m[1] ?? [] as $attrs) {
                if (preg_match('~type\s*=\s*"(application/json|text/template)"~i', $attrs)) {
                    continue;
                }

                if (! str_contains($attrs, 'nonce')) {
                    $offenders[] = str_replace(resource_path('views').'/', '', $file);
                }
            }
        }

        $offenders = array_values(array_unique($offenders));

        $this->assertSame([], $offenders, sprintf(
            "سكربتٌ مضمَّنٌ بلا `nonce` — **يمنعه المتصفّحُ صامتاً** فتموت "
            . "الشاشةُ وهي تردّ ٢٠٠:\n  %s\n\n"
            . 'الصيغة: <script nonce="{{ request()->attributes->get(\'csp_nonce\') }}">',
            implode("\n  ", $offenders)));
    }

    /**
     * @test
     *
     * **③ والقالبُ الأمُّ يعرض `script` بالاسم.**
     *
     * فحارسٌ يقرأ المعروضَ من الملفّ يمرّ لو حُذف المكدَّسُ كلُّه (‏لا
     * مدفوعَ ولا معروض) — **فيُطمئن على لوحةٍ بلا جافاسكربت إطلاقاً**.
     */
    public function the_admin_layout_still_renders_the_script_stack(): void
    {
        $this->assertContains('script', $this->renderedStacks(),
            'القالبُ الأمُّ لم يعد يعرض `@stack(\'script\')` — وكلُّ صفحةٍ '
            . 'تدفع إليه تصير بلا شيفرة');
    }
}
