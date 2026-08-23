<?php

namespace App\Saher\Collectors;

use App\Saher\Findings\Evidence;
use App\Saher\Findings\Finding;
use App\Saher\Support\CallerIndex;
use Illuminate\Support\Facades\DB;

/**
 * SAHER-DATA-002 — **صدقُ البيانات: صفٌّ يولد مرفوضاً، ودالّةٌ لا يناديها أحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي وُلد منه هذا الجامع** — سؤالُ صاحب المشروع بنصّه:
 *
 *     «أنشأتُ حساب عميلٍ برقم 783545525 لكنّه لا يستقبل التحويلات...
 *      ما السبب؟ ولماذا سوف تكتشف الخطأ وساهر لم يكتشفه؟»
 *
 * وقِيس، فكانت السلسلةُ مقطوعةً في حلقتين — **وكلتاهما خارجَ ما يراه أيُّ
 * جامعٍ كان يعمل يومَها**:
 *
 *   · `users.zone_code` قيمتُه الافتراضيّة `UNKNOWN`
 *   · وشرطُ الاستقبال `$zone !== 'SOUTH'` ⇒ **يرمي**
 *   · ⇒ **كلُّ حسابٍ يولد مرفوضاً** — وهو مقصودٌ («ممنوعٌ حتّى يثبت»)
 *   · و`assignOnRegistration()` التي تكتب الأثرَ **بلا مُنادٍ واحد**
 *   · و`zone_assignment_logs` **صفرُ صفوف** منذ بُني الجدول
 *
 * **فالعطلُ ليس في المنع بل في غياب المخرج**، ولا حارسَ في المشروع كلِّه
 * ينظر إلى هذا الشكل: جامعُ الحرّاس يقرأ المسارات، وجامعُ البوّابة يقرأ
 * الالتزامات. **ولا أحدَ يسأل: هل لهذه القيمة الافتراضيّة بابُ خروج؟**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وقاعدتان لا واحدة، لأنّ العطلَ كان اثنين:**
 *
 * ① `BORN_REJECTED` — قيمةٌ افتراضيّةٌ يرفضها شرطٌ في الشيفرة **ولا
 *    كاتبَ مبلوغٌ يُخرج الصفَّ منها**. والشرطُ الثاني هو ما يمنع الضجيج:
 *    «ممنوعٌ حتّى يثبت» تصميمٌ سليمٌ **ما دام الإثباتُ ممكناً**.
 *
 * ② `SERVICE_METHOD_UNREACHED` — دالّةٌ عامّةٌ في خدمةٍ لا يناديها ملفٌّ
 *    واحدٌ خارجَ ملفِّها. **وهو نمطُ العطل الأكثرُ تكراراً في هذا
 *    المشروع**: مبنيٌّ ولا يُوصَل إليه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثقتُه `SUSPECTED` أبداً ولا ترتفع.** الاستدعاءُ في PHP قد يكون
 * بانعكاسٍ أو باسمٍ يُبنى في وقت التشغيل، والفهرسُ يعدّ الأسماءَ لا
 * الأصنافَ الموصولة. **ودرجةُ ثقةٍ أعلى من الدليل تُنتج حذفَ شيفرةٍ
 * حيّة** — وهي أسوأ من ألّا يُبلَّغ أصلاً.
 */
class DataTruthCollector
{
    public const SOURCE = 'data_truth';

    /**
     * أسماءٌ لا تُسأل عن مُنادٍ — **يناديها الإطارُ لا شيفرتُنا**.
     *
     * وبلا هذه القائمة يخرج الجامعُ بعشراتِ الاكتشافات الكاذبة في أوّل
     * جولة، **فيُعوَّد القارئُ على التجاهل يومَ تصدق**.
     */
    private const FRAMEWORK_ENTRY_POINTS = [
        '__construct', '__invoke', '__get', '__set', '__call', '__callStatic',
        '__toString', 'handle', 'boot', 'register', 'rules', 'authorize',
        'messages', 'attributes', 'toArray', 'via', 'toMail', 'toDatabase',
        'toBroadcast', 'broadcastOn', 'failed', 'middleware', 'schedule',
        'up', 'down', 'run', 'definition', 'configure',
    ];

    /**
     * أعمدةٌ قيمتُها الافتراضيّةُ صفرٌ أو فراغٌ أو رقم — **لا تُسأل**.
     *
     * فالسؤالُ عن «بابِ خروجٍ من القيمة الافتراضيّة» يعني شيئاً حين تكون
     * القيمةُ **حالةً** (`UNKNOWN` · `pending`)، ولا يعني شيئاً حين تكون
     * رصيداً ابتدائيّاً أو عدّاداً.
     */
    private const IGNORED_DEFAULTS = ['', '0', '1', 'NULL', 'CURRENT_TIMESTAMP'];

    /** @return array{findings:list<Finding>, assets_seen:int} */
    public function collect(): array
    {
        $index = new CallerIndex([
            app_path(), base_path('routes'), resource_path('views'),
        ]);

        $findings = [];
        $seen = 0;

        [$methodFindings, $methodsSeen] = $this->unreachedServiceMethods($index);
        [$columnFindings, $columnsSeen] = $this->columnsBornRejected($index);

        $findings = array_merge($methodFindings, $columnFindings);
        $seen = $methodsSeen + $columnsSeen;

        return ['findings' => $findings, 'assets_seen' => $seen];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① دالّةٌ عامّةٌ في خدمةٍ ولا مُنادي لها
    // ══════════════════════════════════════════════════════════════════

    /** @return array{0:list<Finding>, 1:int} */
    private function unreachedServiceMethods(CallerIndex $index): array
    {
        $findings = [];
        $seen = 0;

        foreach ($this->phpFilesIn(app_path('Services')) as $path) {
            $src = (string) @file_get_contents($path);

            if ($src === '') {
                continue;
            }

            // **ولا يُقرأ اسمُ الدالّة بتعبيرٍ نمطيٍّ فوق تعليق.** توقيعُ
            // الدالّة قد يرد في تعليقٍ يشرحها — وهو بعينه ما جعل حارساً
            // في هذا المشروع يمرّ والنداءُ محذوف. فتُقرأ من الرموز.
            foreach ($this->publicMethodsOf($src) as [$name, $line]) {
                if (in_array($name, self::FRAMEWORK_ENTRY_POINTS, true)) {
                    continue;
                }

                $seen++;

                if ($index->callersOutside($name, $path) > 0) {
                    continue;
                }

                $findings[] = $this->unreachedMethod($path, $name, $line);
            }
        }

        return [$findings, $seen];
    }

    private function unreachedMethod(string $path, string $name, int $line): Finding
    {
        $rel = str_replace(base_path() . '/', '', $path);
        $class = basename($path, '.php');

        return (new Finding(
            ruleId: 'SAHER.DATA.SERVICE_METHOD_UNREACHED',
            sourceCode: self::SOURCE,
            category: 'correctness',
            title: 'دالّةٌ عامّةٌ في خدمةٍ لا يناديها أحد',
            severity: 'MEDIUM',

            // **ولا ترتفع.** الاستدعاءُ قد يكون بانعكاسٍ أو باسمٍ يُبنى
            // في وقت التشغيل — وثقةٌ أعلى من الدليل تُنتج حذفَ شيفرةٍ حيّة.
            confidence: 'SUSPECTED',
            assetKey: $class . '::' . $name,
            assetType: 'method',
            expected: 'دالّةٌ عامّةٌ في خدمةٍ يناديها مسارٌ أو أمرٌ أو خدمةٌ أخرى',
            actual: 'صفرُ ملفّاتٍ تنادي `' . $name . '(` خارجَ ملفِّها',
            impact: '**مبنيٌّ ولا يُوصَل إليه** — وهو نمطُ العطل الأكثرُ '
                . 'تكراراً في هذا المشروع. وآخرُ صورِه كلّفت حساباً حقيقيّاً: '
                . '`assignOnRegistration` بقيت بلا مُنادٍ منذ v2.0، فوُلد كلُّ '
                . 'حسابٍ ممنوعاً بلا سطرٍ يقول لماذا.',
            suggestedAction: 'يُوصَل النداءُ من حيث ينبغي، أو تُحذف الدالّةُ '
                . 'صراحةً. **وإن كانت تُنادى بانعكاسٍ فيُكتب ذلك ويُكتَم '
                . 'الاكتشافُ بسببه** — فكتمٌ مُعلَّلٌ أثرٌ، والصمتُ ليس أثراً.',
            filePath: $rel,
            lineStart: $line,
            symbol: $class . '::' . $name,
        ))->withEvidence(
            new Evidence(
                'CODE_LINE',
                'موضعُ التعريف',
                $rel . ':' . $line . "\npublic function {$name}(…)",
                $rel,
            ),
            Evidence::absence(
                'ما بُحث عنه ولم يوجد',
                "أيُّ ملفٍّ في app/ أو routes/ أو resources/views/ يحوي "
                    . "`->{$name}(` أو `::{$name}(` — خارجَ {$rel}",
                'CallerIndex',
            ),
        );
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② قيمةٌ افتراضيّةٌ يرفضها شرطٌ ولا بابَ خروجٍ منها
    // ══════════════════════════════════════════════════════════════════

    /** @return array{0:list<Finding>, 1:int} */
    private function columnsBornRejected(CallerIndex $index): array
    {
        $findings = [];
        $columns = $this->columnsWithStringDefaults();
        $sources = $this->phpFilesIn(app_path());

        foreach ($columns as $column => $default) {
            foreach ($sources as $path) {
                $src = (string) @file_get_contents($path);

                if ($src === '' || ! str_contains($src, $column)) {
                    continue;
                }

                $required = $this->rejectingLiteralFor($src, $column);

                if ($required === null || $required === $default) {
                    continue;
                }

                // **والشرطُ الثاني هو ما يمنع الضجيج.** «ممنوعٌ حتّى
                // يثبت» تصميمٌ سليمٌ **ما دام الإثباتُ ممكناً** — فلا
                // يُبلَّغ إلّا حين لا يوجد كاتبٌ مبلوغٌ يُخرج الصفَّ منها.
                if ($this->hasReachedWriter($column, $index, $sources)) {
                    continue;
                }

                $findings[] = $this->bornRejected($column, $default, $required, $path);

                break;
            }
        }

        return [$findings, count($columns)];
    }

    private function bornRejected(
        string $column, string $default, string $required, string $path,
    ): Finding {
        $rel = str_replace(base_path() . '/', '', $path);

        return (new Finding(
            ruleId: 'SAHER.DATA.BORN_REJECTED',
            sourceCode: self::SOURCE,
            category: 'correctness',
            title: 'قيمةٌ افتراضيّةٌ يرفضها شرطٌ في الشيفرة ولا بابَ خروجٍ منها',
            severity: 'HIGH',
            confidence: 'SUSPECTED',
            assetKey: $column,
            assetType: 'column',
            expected: "صفٌّ يولد بـ`{$default}` يجد مساراً مبلوغاً يُحوّله "
                . "إلى `{$required}` — وإلّا فالمنعُ أبديّ",
            actual: "القاعدةُ تكتب `{$default}` افتراضاً، والشيفرةُ في "
                . "{$rel} ترفض كلَّ ما ليس `{$required}` — **ولا كاتبَ "
                . "مبلوغٌ لهذا العمود**",
            impact: '**كلُّ صفٍّ جديدٍ يولد مرفوضاً بلا مخرج.** وهذا بعينه '
                . 'ما وقع على `users.zone_code`: كلُّ حسابٍ يولد `UNKNOWN` '
                . 'فلا يستقبل تحويلاً، ولا سطرَ في أيّ سجلٍّ يقول لماذا. '
                . 'وصل العطلُ عبر شاشة صاحب المشروع لا عبر أيّ جهازِ رصد.',
            suggestedAction: 'إمّا أن يُوصَل مسارٌ يحسم القيمة (ويُكتب أثرُه)، '
                . 'وإمّا أن تُغيَّر القيمةُ الافتراضيّة. **والرسالةُ في '
                . 'الحالتين تقول أيَّ رفضٍ هو** — «لم يُوثَّق بعد» غيرُ '
                . '«خارج النطاق»، والفرقُ هو كلُّ ما يحتاجه القارئ.',
            filePath: $rel,
            symbol: $column,
        ))->withEvidence(
            new Evidence(
                'DB_ROW',
                'القيمةُ الافتراضيّةُ كما تقولها القاعدةُ نفسُها',
                "column:  {$column}\ndefault: {$default}",
                'information_schema.columns',
            ),
            new Evidence(
                'CODE_LINE',
                'الشرطُ الذي يرفضها',
                "{$rel}\n… !== '{$required}' ⇒ رفض",
                $rel,
            ),
            Evidence::absence(
                'ما بُحث عنه ولم يوجد',
                "كاتبٌ لـ`{$column}` في خدمةٍ أو متحكّمٍ يناديه أحد",
                'CallerIndex + مسحُ الإسنادات',
            ),
        );
    }

    // ══════════════════════════════════════════════════════════════════
    //  أدواتُ القياس
    // ══════════════════════════════════════════════════════════════════

    /**
     * القيمُ الافتراضيّةُ **من القاعدة نفسِها لا من الهجرات**.
     *
     * فالهجرةُ تقول ما نوى كاتبُها، والقاعدةُ تقول ما وقع. وبينهما
     * هجرةٌ لم تُشغَّل، أو عمودٌ عُدّل يدويّاً. **والرقمُ يُحسب من مصدره.**
     *
     * @return array<string,string>
     */
    private function columnsWithStringDefaults(): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND COLUMN_DEFAULT IS NOT NULL
                AND DATA_TYPE IN (?, ?, ?)',
            ['varchar', 'char', 'enum'],
        );

        $out = [];

        foreach ($rows as $r) {
            $default = trim((string) $r->COLUMN_DEFAULT, "'");

            if (in_array($default, self::IGNORED_DEFAULTS, true)
                || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]{2,}$/', $default)) {
                continue;
            }

            // اسمُ العمود وحدَه — فالشيفرةُ تناديه بالخاصّيّة لا بالجدول.
            $out[(string) $r->COLUMN_NAME] = $default;
        }

        return $out;
    }

    /**
     * الحرفُ الذي يشترطه شرطُ رفضٍ على هذا العمود — أو `null`.
     *
     * تُلتقط صورتان: مقارنةٌ مباشرةٌ على الخاصّيّة، ومقارنةٌ على متغيّرٍ
     * أُسند إليها. **والكمّيّاتُ محدودةٌ عمداً** — القاعدةُ الخامسة:
     * تعبيرٌ نمطيٌّ جشعٌ يبتلع ما ليس له.
     */
    private function rejectingLiteralFor(string $src, string $column): ?string
    {
        // ① مباشرةً: `->zone_code !== 'SOUTH'`
        if (preg_match('/->\s*' . preg_quote($column, '/') . '\s*!==\s*\'([^\']{1,40})\'/', $src, $m)) {
            return $m[1];
        }

        // ② عبر متغيّر: `$zone = $x->zone_code ?? '…';` ثمّ `$zone !== 'SOUTH'`
        if (! preg_match_all(
            '/\$(\w{1,40})\s*=\s*[^;\n]{0,120}->\s*' . preg_quote($column, '/') . '\b[^;\n]{0,80};/',
            $src, $assigns,
        )) {
            return null;
        }

        foreach (array_unique($assigns[1]) as $var) {
            if (preg_match('/\$' . preg_quote($var, '/') . '\s*!==\s*\'([^\']{1,40})\'/', $src, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * هل يوجد كاتبٌ لهذا العمود **يناديه أحد**؟
     *
     * الكاتبُ في متحكّمٍ أو أمرٍ مبلوغٌ بطبعه (مسارٌ أو مجدوِل). والكاتبُ
     * في خدمةٍ يُسأل عن مُنادي الدالّة التي تحويه.
     *
     * @param list<string> $sources
     */
    private function hasReachedWriter(string $column, CallerIndex $index, array $sources): bool
    {
        $pattern = '/->\s*' . preg_quote($column, '/') . '\s*=(?!=)/';

        foreach ($sources as $path) {
            $src = (string) @file_get_contents($path);

            if ($src === '' || ! preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            if (str_contains($path, '/Http/Controllers/')
                || str_contains($path, '/Console/Commands/')) {
                return true;
            }

            $line = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
            $method = $this->enclosingPublicMethod($src, $line);

            if ($method === null) {
                continue;
            }

            if ($index->callersOutside($method, $path) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * الدوالُّ العامّةُ في ملفّ — **تُقرأ من الرموز لا من نصّ الملفّ**،
     * فالتوقيعُ قد يرد في تعليقٍ يشرحه.
     *
     * @return list<array{0:string,1:int}>
     */
    private function publicMethodsOf(string $src): array
    {
        $tokens = token_get_all($src);
        $out = [];
        $visibility = 'public';
        $isStaticOrAbstract = false;

        foreach ($tokens as $i => $t) {
            if (! is_array($t)) {
                continue;
            }

            if (in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                $visibility = strtolower($t[1]);

                continue;
            }

            if ($t[0] === T_ABSTRACT) {
                $isStaticOrAbstract = true;

                continue;
            }

            if ($t[0] !== T_FUNCTION) {
                continue;
            }

            // ══════════════════════════════════════════════════════
            // **اسمُ الدالّة ما يلي `function` مباشرةً — لا أوّلُ نصٍّ
            // بعدها.**
            //
            // كان المسحُ يقفز حتّى خمسةَ رموزٍ بحثاً عن أوّل `T_STRING`.
            // **والإغلاقُ بلا اسم**: `function (int $a) {…}` — فيلتقط
            // `int`، أي **نوعَ أوّل مُعامِل**. وكذلك `function (Promotion
            // $p)` تُقرأ دالّةً اسمُها `Promotion`.
            //
            // وقِيس فأنتج ستّةَ أسماءٍ كاذبةٍ في تقرير «شيفرةٌ ميّتة»:
            // `LedgerEntryLine` · `PaymentRequest` · `Promotion` · `int`
            // · `AgentShift` · `AgentBranch`. **واسمٌ مخترَعٌ في تقريرِ
            // موتٍ يُرسل من يقرؤه يحذف ما لا وجودَ له.**
            //
            // فيُقبَل الرمزُ إن جاء بعد `function` وفراغٍ لا غير، ويُردّ
            // ما جاء بعد `(`.
            // ══════════════════════════════════════════════════════
            $j = $i + 1;

            while (isset($tokens[$j]) && is_array($tokens[$j])
                && in_array($tokens[$j][0], [T_WHITESPACE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                $j++;
            }

            if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                if ($visibility === 'public' && ! $isStaticOrAbstract) {
                    $out[] = [$tokens[$j][1], $tokens[$j][2]];
                }
            }

            $visibility = 'public';
            $isStaticOrAbstract = false;
        }

        return $out;
    }

    private function enclosingPublicMethod(string $src, int $line): ?string
    {
        $best = null;

        foreach ($this->publicMethodsOf($src) as [$name, $at]) {
            if ($at <= $line && ($best === null || $at > $best[1])) {
                $best = [$name, $at];
            }
        }

        return $best[0] ?? null;
    }

    /** @return list<string> */
    private function phpFilesIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        sort($out);

        return $out;
    }
}
