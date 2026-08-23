<?php

namespace App\Saher\Support;

/**
 * SAHER-DATA-001 — **فهرسُ المنادين: من يُنادي هذه الدالّة؟**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ فهرسٌ واحدٌ لا بحثٌ لكلّ دالّة:** الخدماتُ فيها مئاتُ الدوالّ،
 * والبحثُ عن كلٍّ على حدةٍ يقرأ الشجرةَ مئاتِ المرّات. فتُقرأ مرّةً
 * وتُعدّ الأسماءُ كلُّها في مرورٍ واحد.
 *
 * **وحدُّه يُقال ولا يُسكَت عنه:** يعدّ **الاسمَ** لا الاستدعاءَ المربوطَ
 * بصنفه. فدالّةُ `handle()` في خدمةٍ ستبدو منادَاةً آلافَ المرّات لأنّ
 * الاسمَ شائع. **وهذا يُنتج سلبيّاتٍ كاذبة — لا إيجابيّاتٍ كاذبة**:
 * أي أنّه يُسكت عن دوالَّ ميّتةٍ ولا يتّهم دالّةً حيّة. **وهذا هو
 * الاتّجاهُ الآمن**، فالاتّهامُ الكاذب يُنتج حذفَ شيفرةٍ عاملة.
 *
 * ولذلك كلُّ ما يُبنى عليه `SUSPECTED` أبداً — لا `PROVEN`.
 */
final class CallerIndex
{
    /** @var array<string,int> */
    private array $counts = [];

    /** @var array<string,list<string>> اسمُ الدالّة ← الملفّاتُ التي تناديها */
    private array $files = [];

    /** @param list<string> $roots مساراتٌ مطلقةٌ تُمسح */
    public function __construct(array $roots)
    {
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                $ext = $file->getExtension();

                if (! in_array($ext, ['php', 'blade'], true)
                    && ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $this->ingest($file->getPathname());
            }
        }
    }

    private function ingest(string $path): void
    {
        $src = @file_get_contents($path);

        if ($src === false) {
            return;
        }

        $names = [];

        // `->name(` و`::name(` — وهما صيغتا النداء في PHP وBlade معاً.
        if (preg_match_all('/(?:->|::)\s*([a-zA-Z_]\w*)\s*\(/', $src, $m)) {
            $names = $m[1];
        }

        // ══════════════════════════════════════════════════════════════
        // **والنداءُ باسمٍ نصّيّ يُعدّ نداءً.** وقِيس في هذا المشروع
        // ثلاثةُ مواضعَ في شيفرة الإنتاج:
        //
        //     SafePaymentService:953   $this->receipts->{$method}(…)
        //                              و`$method` = 'issueDebit'|'issueCredit'
        //     FeeSchemeController:531  $u->{$m}('platform.fees.update')
        //     HasEncryptedPII:93       $service->{$maskFn}($plainValue)
        //
        // فبلا هذا يُبلَّغ `issueDebit` «لا يناديها أحد» **وهي تُنادى في
        // كلّ إيصالِ دفعٍ آمن**. ومن صدّق التقريرَ حذفها. وأغلق هذا
        // التوسيعُ **١٣ اكتشافاً كاذباً** في جولةٍ واحدة.
        //
        // **والميلُ هنا مقصودٌ نحو الصمت.** نصٌّ مثل `'grant'` قد يكون
        // مفتاحَ صلاحيّةٍ لا نداءً، فيُعدّ نداءً فيُخفى عطلٌ حقيقيّ.
        // وذاك مقبولٌ وعكسُه ليس: **إخفاءُ ميّتٍ يكلّف سطراً باقياً،
        // وإظهارُ حيٍّ ميّتاً يكلّف حذفَ شيفرةٍ تعمل** — وهو ما يقوله
        // رأسُ هذا الملفّ: «الاتّجاهُ الآمن».
        //
        // ويُشترط ثلاثةُ محارفَ فصاعداً وبدايةٌ صغيرة — فـ`'id'` و`'ar'`
        // تُغرقان الفهرسَ بلا فائدة.
        // ══════════════════════════════════════════════════════════════
        if (preg_match_all('/[\'"]([a-z][a-zA-Z0-9_]{2,})[\'"]/', $src, $lit)) {
            $names = array_merge($names, $lit[1]);
        }

        if ($names === []) {
            return;
        }

        foreach (array_unique($names) as $name) {
            $this->counts[$name] = ($this->counts[$name] ?? 0) + 1;
            $this->files[$name][] = $path;
        }
    }

    /**
     * كم ملفّاً ينادي هذا الاسمَ — **مستثنىً منها ملفُّ التعريف نفسُه**.
     *
     * فالدالّةُ التي تنادي نفسَها أو تناديها أختُها في الصنف نفسِه
     * **ليست مبلوغةً من خارج**، وهو ما يُسأل عنه هنا.
     */
    public function callersOutside(string $name, string $declaringFile): int
    {
        $hits = $this->files[$name] ?? [];

        return count(array_filter($hits, fn ($f) => $f !== $declaringFile));
    }

    /**
     * أيناديها ملفُّها نفسُه؟
     *
     * ══════════════════════════════════════════════════════════════════
     * **الفرقُ بين «لا تعمل» و«مكشوفةٌ أكثرَ ممّا تحتاج».**
     *
     * `callersOutside` وحدَها كانت تُبلِّغ الاثنين باسمٍ واحد:
     * `SERVICE_METHOD_UNREACHED`. وقِيس على جولةٍ كاملة:
     *
     *     109 نتيجة  →  **55 منها تُنادى داخل صنفها** فهي تعمل
     *                   35 بصفرِ نداءٍ في المشروع كلِّه  ← الحقيقيّة
     *
     * أي أنّ **نصفَ التقرير اسمُه يكذب على ما وجده**. وستُّ أعطالٍ
     * حقيقيّةٍ كانت مدفونةً بينها، ولم تظهر إلّا بفرزٍ يدويٍّ خارج ساهر.
     *
     * **ومُبلِّغٌ نصفُه ضجيجٌ يُعوّد القارئَ على التجاهل يومَ يصدق** — وهي
     * القاعدةُ التي دفع المشروعُ ثمنَها في سلسلة التدقيق («كُسرت السلسلة
     * في ٧ مواضع» على صفوفٍ لم يمسَّها أحد).
     * ══════════════════════════════════════════════════════════════════
     */
    public function calledWithin(string $name, string $declaringFile): bool
    {
        foreach ($this->files[$name] ?? [] as $f) {
            if ($f === $declaringFile) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function callerFiles(string $name, string $declaringFile): array
    {
        return array_values(array_filter(
            $this->files[$name] ?? [],
            fn ($f) => $f !== $declaringFile,
        ));
    }
}
