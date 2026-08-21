<?php

/**
 * AMIAL-AXIS-MATRIX-001 — **مصفوفةُ المحاور: ما يُقاس وما لا يُقاس.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا هذا أوّلُ ما يُبنى في الجولة ①**
 *
 * أرشيفُ المهامّ ١٨ وثيقةً و١٨٧٩ محوراً. وفي الدفعة السابقة قيل «أُنجز
 * البعض» — وسجلُّ صاحب الدفعة نفسِه يقول «لا توجد مهمة مغلقة». والفرقُ
 * بينهما لم يكن كذباً: **لم تكن هناك أداةٌ تقيس**، فصار الإنجازُ ادّعاءً
 * من الطرفين. وهذا ما يبنيه هذا الملفّ: قناةَ قياسٍ بدل قناةِ ادّعاء.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما يمكن أن تفعله هذه الأداة أن تكذب.**
 *
 * مصفوفةٌ تكتب «مغلق» لأنّ صنفاً بالاسم موجودٌ في المستودع تُنتج تقريراً
 * أخضرَ فوق قطاعٍ لم يُبنَ. **وحارسٌ يكذب أسوأ من غيابه** — يُطمئن، ثمّ
 * يُرسل من يصدّقه خلف عملٍ ظنَّه منجَزاً.
 *
 * فتُفصَل الأداةُ إلى **ما تقيسه** و**ما يُصرَّح به**، ولا يُخلطان:
 *
 * ┌── ① الارتباطُ — يُقاس آليّاً، ولا رأيَ لأحدٍ فيه ────────────────┐
 * │  كلُّ محورٍ يُستخرَج منه ما سمّاه بين علامتَي `` ` `` — صنفاً أو    │
 * │  دالّةً أو مساراً أو صلاحيّةً أو جدولاً أو رمزَ حساب. ثمّ يُسأل     │
 * │  المستودعُ عن كلٍّ منها: أموجودٌ؟ وأيلمسه اختبار؟                │
 * │                                                                  │
 * │  مربوط  · جزئيّ · مفقود · بلا رمز                                │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * ┌── ② الإغلاقُ — يُصرَّح به في `docs/مصفوفة-المحاور.json` ──────────┐
 * │  ولا تكتبه الأداةُ من نفسها أبداً. ومع كلّ إغلاقٍ **دليلُه**:      │
 * │  اسمُ اختبارٍ يُثبته، وقياسٌ، ومَن أغلقه ومتى.                     │
 * │                                                                  │
 * │  **والأداةُ تُكذّب الإغلاقَ الكاذب**: إغلاقٌ يُشير إلى اختبارٍ لا  │
 * │  وجودَ له يُرفَع `إغلاقٌ بلا دليل` — وهو أعلى درجات الإنذار هنا.  │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * **والفرقُ بين «مربوط» و«مغلق» هو بيتُ القصيد:** «مربوط» تقول إنّ ما
 * سمّاه المحورُ موجودٌ في الشيفرة. **ولا تقول إنّ المطلوبَ نُفِّذ.** محورٌ
 * يطلب «أربع عيونٍ على التصحيح» قد يذكر `reconcileWalletBalance` وهي
 * موجودةٌ منذ سنة — فالارتباطُ تامٌّ والمطلوبُ لم يُبنَ. ولذلك الإغلاقُ
 * قرارٌ يُوقَّع، لا استنتاجٌ من وجود اسم.
 *
 * ══════════════════════════════════════════════════════════════════════
 * الاستعمال:
 *
 *   php scripts/axis-matrix.php                  # الملخّص
 *   php scripts/axis-matrix.php --doc=t04        # وثيقةٌ واحدة
 *   php scripts/axis-matrix.php --doc=t04 --full # كلّ محورٍ برموزه
 *   php scripts/axis-matrix.php --round=1        # وثائقُ جولةٍ بعينها
 *   php scripts/axis-matrix.php --json           # للأدوات
 *   php scripts/axis-matrix.php --check          # للبوّابة: يسقط على إغلاقٍ بلا دليل
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$docsDir = $root.'/docs/مهامّ';
$ledgerPath = $root.'/docs/مصفوفة-المحاور.json';

// ── وثائقُ كلّ جولة، كما في `docs/خطة-الجولات-الخمس.md` ──────────────
const ROUNDS = [
    1 => ['t04', 't09', 't01'],
    2 => ['t06', 't13', 't02'],
    3 => ['t08', 't18', 't15', 't17'],
    4 => ['t14', 't12', 't03', 't05'],
    5 => ['t16', 't10', 't07', 't11'],
];

const DOC_TITLES = [
    't01' => 'معماريّة الوكيل والفروع والتسويات',
    't02' => 'إغلاق شاشة النطاقات القديمة',
    't03' => 'مركز العملاء',
    't04' => 'مركز الدفتر والمصالحة',
    't05' => 'مكافحة غسل الأموال',
    't06' => 'حزمة الوكيل وفروعه',
    't07' => 'التجّار والقطاعات وWooCommerce',
    't08' => 'هويّة موظّفي المنصّة والأدوار و2FA',
    't09' => 'المركز الماليّ والخزينة والإصدار',
    't10' => 'حالة التشغيل والطوابير والمستندات',
    't11' => 'حدود بوت واتساب',
    't12' => 'استعادة الحساب وتغيير الهاتف',
    't13' => 'لوحة المناطق ونطاق التشغيل',
    't14' => 'مركز الدعم والتشغيل',
    't15' => 'توحيد لوحة التحقّق والهويّة KYC',
    't16' => 'الإعدادات والسياسات التشغيليّة',
    't17' => 'الأمن والرقابة والحوكمة',
    't18' => 'فصل بوّابة الإدارة عن بوّابة الموظّفين',
];

// ══════════════════════════════════════════════════════════════════════
// ١) استخراجُ المحاور من الوثائق
// ══════════════════════════════════════════════════════════════════════

/**
 * المحاورُ الأوّليّة: عناوينُ المستوى الأوّل المرقّمة `# N) …`.
 *
 * والعناوينُ الفرعيّةُ تحتها جزءٌ من متن المحور لا محاورُ مستقلّة —
 * فـ«## Wallet side» تحت «# 13) مطابقة المحافظ» وصفٌ لطرفٍ من المقارنة،
 * وعدُّها محوراً يضاعف الرقمَ بلا معنى.
 *
 * @return array<int,array{id:string,doc:string,number:int,title:string,body:string,line:int}>
 */
function extractAxes(string $doc, string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $axes = [];
    $current = null;

    foreach ($lines as $i => $line) {
        // ══════════════════════════════════════════════════════════════
        // **مستوى العنوان لا يُحدِّد كونَه محوراً — ترقيمُه يفعل.**
        //
        // وقع هذا العطلُ مرّتين على هذه الأداة نفسِها:
        //
        //   ① المرشِّحُ يقبل `)` ولا يقبل `.` — فأخرج `t01` صفرَ محاورٍ
        //      من اثنين وخمسين.
        //   ② المرشِّحُ يقبل `#` وحدَه ولا يقبل `##` — فأخرج `t06` و`t02`
        //      **صفرَ محاورٍ من مئةٍ وستّةٍ وثمانين**، وهما وثيقتان من
        //      ثلاثٍ في الجولة الثانية.
        //
        // وفي الحالتين **لا رسالةَ خطأ**: وثيقةٌ كاملةٌ تختفي من التقرير
        // بصمت، والمجموعُ يبدو سليماً. وهو بعينه صنفُ العطل الذي بُنيت
        // هذه الأداةُ لتكشفه، واقعاً عليها مرّتين.
        //
        // فالعلاجُ ليس توسيعَ المرشِّح وحدَه — **بل حارسٌ يسقط إن أخرجت
        // وثيقةٌ صفراً** (`--check` أدناه)، لأنّ التوسيعَ يشيخ مع أوّل
        // وثيقةٍ تُكتب بنمطٍ ثالث.
        // ══════════════════════════════════════════════════════════════
        if (preg_match('~^\#{1,3}\s+(\d+)[.)]\s*(.+)$~u', $line, $m)) {
            if ($current !== null) {
                $axes[] = $current;
            }

            $current = [
                'id' => sprintf('%s-%02d', $doc, (int) $m[1]),
                'doc' => $doc,
                'number' => (int) $m[1],
                'title' => trim($m[2]),
                'body' => '',
                'line' => $i + 1,
            ];

            continue;
        }

        if ($current !== null) {
            $current['body'] .= $line."\n";
        }
    }

    if ($current !== null) {
        $axes[] = $current;
    }

    return $axes;
}

// ══════════════════════════════════════════════════════════════════════
// ٢) استخراجُ الرموز التي سمّاها المحور
// ══════════════════════════════════════════════════════════════════════

/**
 * ما يُقاس هو ما وضعه صاحبُ الوثيقة **بين علامتَي `` ` ``** — فذلك
 * إشارتُه إلى أنّ هذا اسمٌ في الشيفرة لا كلمةٌ في جملة.
 *
 * ويُصنَّف الرمزُ بشكله لا بالظنّ:
 *
 * | الشكل | الصنف | أين يُبحث عنه |
 * |---|---|---|
 * | `SomethingService` · `FooController` | صنف | `app/**` |
 * | `camelCase()` | دالّة | `function camelCase` |
 * | `platform.x.y` | صلاحيّة | الشيفرة والهجرات والبذور |
 * | `SCREAMING_SNAKE` | رمزُ حسابٍ أو ثابت | الشيفرة كلُّها |
 * | `snake_case` بلا أقواس | جدولٌ أو عمود | الهجرات والشيفرة |
 * | `amial:command` | أمرُ artisan | `$signature` |
 *
 * وما لا يقع في شكلٍ منها يُطرَح — فنصٌّ إنجليزيٌّ حرٌّ بين علامتين
 * (‏`auto-fix` مثلاً) ليس رمزاً يُبحث عنه، **وعدُّه رمزاً يُنتج «مفقوداً»
 * كاذباً** يُفزع من يقرأ التقرير.
 *
 * @return array<int,array{symbol:string,kind:string}>
 */
function extractSymbols(string $body): array
{
    preg_match_all('~`([^`\n]{2,80})`~u', $body, $m);

    $out = [];
    $seen = [];

    foreach ($m[1] ?? [] as $raw) {
        $s = trim($raw);

        // أرقامٌ ومبالغُ وأمثلةُ عرض — ليست رموزاً.
        if ($s === '' || preg_match('~^[\d\s,.\-+٪%]+$~u', $s)) {
            continue;
        }

        // جملٌ فيها فراغات (‏«Total Debit = Total Credit») وصفٌ لا رمز.
        if (str_contains($s, ' ')) {
            continue;
        }

        // عربيّةٌ بين علامتين: تسميةُ عرضٍ لا معرِّفُ شيفرة.
        if (preg_match('~\p{Arabic}~u', $s)) {
            continue;
        }

        $kind = classifySymbol($s);

        if ($kind === null) {
            continue;
        }

        $key = $kind.':'.$s;

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $out[] = ['symbol' => $s, 'kind' => $kind];
    }

    return $out;
}

function classifySymbol(string $s): ?string
{
    $bare = rtrim($s, '()');

    if (preg_match('~^amial:[a-z0-9:\-]+$~', $s)) {
        return 'command';
    }

    if (preg_match('~^platform\.[a-z_]+\.[a-z_]+$~', $s)) {
        return 'permission';
    }

    if (preg_match('~^[A-Z][A-Za-z0-9]*(Service|Controller|Command|Job|Middleware|Policy|Repository|Test|Trait|Observer|Exception|Request|Resource)$~', $bare)) {
        return 'class';
    }

    if (preg_match('~^[A-Z][A-Z0-9_]{3,}$~', $bare)) {
        return 'constant';
    }

    if (str_ends_with($s, '()') && preg_match('~^[a-z][A-Za-z0-9_]{2,}$~', $bare)) {
        return 'function';
    }

    // `snake_case` بحرفين على الأقلّ ومقطعين — جدولٌ أو عمود.
    if (preg_match('~^[a-z][a-z0-9]*(_[a-z0-9]+)+$~', $bare)) {
        return 'table_or_column';
    }

    return null;
}

// ══════════════════════════════════════════════════════════════════════
// ٣) سؤالُ المستودع — أموجودٌ؟ وأيلمسه اختبار؟
// ══════════════════════════════════════════════════════════════════════

final class RepoIndex
{
    /** @var array<string,string> نصُّ كلّ ملفٍّ مفهرَس */
    private array $app = [];

    private array $tests = [];

    private array $routes = [];

    private array $migrations = [];

    private array $views = [];

    public function __construct(private readonly string $root)
    {
        $this->app = $this->slurp($root.'/app', ['php']);
        $this->app += $this->slurp($root.'/database/seeders', ['php']);
        $this->app += $this->slurp($root.'/config', ['php']);
        $this->tests = $this->slurp($root.'/tests', ['php']);
        $this->routes = $this->slurp($root.'/routes', ['php']);
        $this->migrations = $this->slurp($root.'/database/migrations', ['php']);
        $this->views = $this->slurp($root.'/resources/views', ['php']);
    }

    /** @return array<string,string> */
    private function slurp(string $dir, array $exts): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if (! $f->isFile()) {
                continue;
            }

            foreach ($exts as $ext) {
                if (str_ends_with($f->getFilename(), '.'.$ext)) {
                    $out[$f->getPathname()] = (string) file_get_contents($f->getPathname());
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * أين يوجد هذا الرمز — في الشيفرة، وفي الاختبارات؟
     *
     * @return array{in_code:bool,in_tests:bool,where:array<int,string>}
     */
    public function locate(string $symbol, string $kind): array
    {
        $needle = $this->needleFor($symbol, $kind);
        $where = [];
        $inCode = false;

        foreach ([$this->app, $this->routes, $this->migrations, $this->views] as $bucket) {
            foreach ($bucket as $path => $src) {
                if ($this->hit($src, $needle, $kind, $symbol)) {
                    $inCode = true;
                    $where[] = $this->relative($path);

                    if (count($where) >= 4) {
                        break 2;
                    }
                }
            }
        }

        $inTests = false;

        foreach ($this->tests as $path => $src) {
            if ($this->hit($src, $needle, $kind, $symbol)) {
                $inTests = true;
                $where[] = $this->relative($path);
                break;
            }
        }

        return ['in_code' => $inCode, 'in_tests' => $inTests, 'where' => $where];
    }

    private function needleFor(string $symbol, string $kind): string
    {
        $bare = rtrim($symbol, '()');

        return match ($kind) {
            // **الدالّةُ تُطلب بتعريفها لا بذكرها.** ولولا ذلك لعُدّت
            // موجودةً لأنّ اسمَها ورد في تعليق.
            'function' => 'function '.$bare,
            default => $bare,
        };
    }

    private function hit(string $src, string $needle, string $kind, string $symbol): bool
    {
        if ($kind === 'class') {
            // الصنفُ يُعدّ موجوداً بتعريفه، لا بذكره في `use` أو تعليق.
            return (bool) preg_match('~\bclass\s+'.preg_quote(rtrim($symbol, '()'), '~').'\b~', $src);
        }

        return str_contains($src, $needle);
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->root, '', $path), '/');
    }

    public function hasTest(string $name): bool
    {
        foreach ($this->tests as $path => $src) {
            if (str_ends_with($path, '/'.$name.'.php')) {
                return true;
            }
        }

        return false;
    }

    public function testHasMethod(string $file, string $method): bool
    {
        foreach ($this->tests as $path => $src) {
            if (str_ends_with($path, '/'.$file.'.php')) {
                return str_contains($src, 'function '.$method);
            }
        }

        return false;
    }
}

// ══════════════════════════════════════════════════════════════════════
// ٤) الحكم
// ══════════════════════════════════════════════════════════════════════

/**
 * @param  array<int,array{symbol:string,kind:string,in_code:bool,in_tests:bool}>  $symbols
 */
function bindingOf(array $symbols): string
{
    if ($symbols === []) {
        return 'بلا رمز';
    }

    $found = 0;
    $tested = 0;

    foreach ($symbols as $s) {
        if ($s['in_code']) {
            $found++;
        }

        if ($s['in_tests']) {
            $tested++;
        }
    }

    if ($found === 0) {
        return 'مفقود';
    }

    if ($found < count($symbols)) {
        return 'جزئيّ';
    }

    return $tested > 0 ? 'مربوط' : 'بلا اختبار';
}

// ══════════════════════════════════════════════════════════════════════
// ٥) التشغيل
// ══════════════════════════════════════════════════════════════════════

$opts = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('~^--([a-z\-]+)(?:=(.*))?$~', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

if (! is_dir($docsDir)) {
    fwrite(STDERR, "لا مجلَّد وثائقَ في {$docsDir}\n");
    exit(2);
}

$ledger = is_file($ledgerPath)
    ? (array) json_decode((string) file_get_contents($ledgerPath), true)
    : ['closed' => []];
$closed = (array) ($ledger['closed'] ?? []);

$wanted = null;

if (isset($opts['doc'])) {
    $wanted = [(string) $opts['doc']];
} elseif (isset($opts['round'])) {
    $wanted = ROUNDS[(int) $opts['round']] ?? null;

    if ($wanted === null) {
        fwrite(STDERR, "لا جولةَ بهذا الرقم\n");
        exit(2);
    }
}

$index = new RepoIndex($root);
$docs = [];

foreach (glob($docsDir.'/t*.md') ?: [] as $path) {
    $doc = basename($path, '.md');

    if ($wanted !== null && ! in_array($doc, $wanted, true)) {
        continue;
    }

    $docs[$doc] = extractAxes($doc, $path);
}

ksort($docs);

$report = [];
$liars = [];

foreach ($docs as $doc => $axes) {
    foreach ($axes as $axis) {
        $symbols = extractSymbols($axis['body']);

        foreach ($symbols as $i => $s) {
            $loc = $index->locate($s['symbol'], $s['kind']);
            $symbols[$i] += $loc;
        }

        $binding = bindingOf($symbols);
        $decl = $closed[$axis['id']] ?? null;
        $closure = 'مفتوح';

        if ($decl !== null) {
            // **تكذيبُ الإغلاق.** إغلاقٌ يُشير إلى اختبارٍ لا وجودَ له
            // ليس إغلاقاً — هو ادّعاءٌ مكتوب. ويُرفَع بأعلى صوت.
            $evidence = (array) ($decl['evidence'] ?? []);
            $missing = [];

            foreach ($evidence as $test) {
                [$file, $method] = array_pad(explode('::', (string) $test, 2), 2, null);

                if (! $index->hasTest($file)) {
                    $missing[] = $test.' (لا ملفَّ اختبارٍ بهذا الاسم)';

                    continue;
                }

                if ($method !== null && ! $index->testHasMethod($file, $method)) {
                    $missing[] = $test.' (الملفُّ موجودٌ ولا دالّةَ بهذا الاسم فيه)';
                }
            }

            if ($evidence === []) {
                $missing[] = 'لا دليلَ مذكورٌ إطلاقاً';
            }

            if ($missing !== []) {
                $closure = 'إغلاقٌ بلا دليل';
                $liars[] = ['axis' => $axis['id'], 'title' => $axis['title'], 'missing' => $missing];
            } else {
                $closure = 'مغلق';
            }
        }

        $report[] = [
            'id' => $axis['id'],
            'doc' => $doc,
            'title' => $axis['title'],
            'line' => $axis['line'],
            'symbols' => $symbols,
            'binding' => $binding,
            'closure' => $closure,
            'note' => $decl['note'] ?? null,
        ];
    }
}

if (isset($opts['json'])) {
    echo json_encode([
        'generated_at' => date('c'),
        'axes' => $report,
        'unproven_closures' => $liars,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";

    exit($liars === [] ? 0 : 1);
}

if (isset($opts['check'])) {
    // ══════════════════════════════════════════════════════════════════
    // **وثيقةٌ تُخرج صفرَ محاورٍ عمىً لا فراغ.**
    //
    // وقع مرّتين: `t01` (نمطُ `.` بدل `)`) ثمّ `t06` و`t02` (نمطُ `##`
    // بدل `#`). وفي الحالتين **مرّ التقريرُ سليماً** — لأنّ صفراً يُجمع
    // كصفر، والمجموعُ يبدو متّسقاً. فاختفت مئةٌ وستّةٌ وثمانون محوراً
    // بلا سطرٍ واحدٍ ينبّه.
    //
    // وتوسيعُ المرشِّح وحدَه علاجٌ يشيخ مع أوّل وثيقةٍ بنمطٍ ثالث.
    // **فالحدُّ هنا: لا وثيقةَ بصفر.**
    // ══════════════════════════════════════════════════════════════════
    $blind = [];

    foreach (array_keys(DOC_TITLES) as $doc) {
        $path = $root.'/docs/مهامّ/'.$doc.'.md';

        if (! is_file($path)) {
            continue;
        }

        if (extractAxes($doc, $path) === []) {
            $blind[] = $doc.' — '.DOC_TITLES[$doc];
        }
    }

    if ($blind !== []) {
        echo "  \033[31m✗\033[0m وثائقُ تُخرج صفرَ محاور — المرشِّحُ لا يرى نمطَ ترقيمها:\n";

        foreach ($blind as $b) {
            echo "      {$b}\n";
        }

        echo "\n  وصفرٌ ها هنا عمىً لا فراغ: الوثيقةُ تختفي من كلّ تقريرٍ\n";
        echo "  بصمت، والمجموعُ يبدو سليماً. يُوسَّع المرشِّحُ في extractAxes().\n";

        exit(1);
    }

    if ($liars === []) {
        echo "  \033[32m✓\033[0m لا إغلاقَ بلا دليل (".count($report)." محوراً مفحوصاً · 18 وثيقةً مرئيّة)\n";
        exit(0);
    }

    echo "  \033[31m✗\033[0m إغلاقٌ يُدَّعى ولا دليلَ عليه:\n";

    foreach ($liars as $l) {
        echo "      {$l['axis']} — {$l['title']}\n";

        foreach ($l['missing'] as $m) {
            echo "        · {$m}\n";
        }
    }

    exit(1);
}

// ── العرض ───────────────────────────────────────────────────────────
$byDoc = [];

foreach ($report as $r) {
    $byDoc[$r['doc']][] = $r;
}

$colour = [
    'مغلق' => "\033[32m",
    'مربوط' => "\033[36m",
    'بلا اختبار' => "\033[33m",
    'جزئيّ' => "\033[33m",
    'مفقود' => "\033[31m",
    'بلا رمز' => "\033[90m",
    'إغلاقٌ بلا دليل' => "\033[41;97m",
];

$totals = [];

echo "\n\033[1mمصفوفةُ المحاور — ما يُقاس\033[0m\n";
echo str_repeat('═', 70), "\n";

foreach ($byDoc as $doc => $rows) {
    $counts = [];

    foreach ($rows as $r) {
        $state = $r['closure'] === 'مفتوح' ? $r['binding'] : $r['closure'];
        $counts[$state] = ($counts[$state] ?? 0) + 1;
        $totals[$state] = ($totals[$state] ?? 0) + 1;
    }

    printf("\n\033[1m%s\033[0m  %s  \033[90m(%d محوراً)\033[0m\n",
        $doc, DOC_TITLES[$doc] ?? '—', count($rows));

    foreach ($counts as $state => $n) {
        printf("    %s%-16s\033[0m %3d\n", $colour[$state] ?? '', $state, $n);
    }

    if (isset($opts['full'])) {
        foreach ($rows as $r) {
            $state = $r['closure'] === 'مفتوح' ? $r['binding'] : $r['closure'];
            printf("      %s%-14s\033[0m %-9s %s\n",
                $colour[$state] ?? '', $state, $r['id'], mb_substr($r['title'], 0, 46));

            foreach ($r['symbols'] as $s) {
                printf("            %s %s \033[90m(%s)\033[0m%s\n",
                    $s['in_code'] ? "\033[32m●\033[0m" : "\033[31m○\033[0m",
                    $s['symbol'], $s['kind'],
                    $s['in_tests'] ? " \033[36m[مُختبَر]\033[0m" : '');
            }
        }
    }
}

echo "\n", str_repeat('═', 70), "\n\033[1mالمجموع\033[0m  ", count($report), " محوراً\n";

foreach ($totals as $state => $n) {
    printf("    %s%-16s\033[0m %4d   %s%%\n", $colour[$state] ?? '', $state, $n,
        number_format($n * 100 / max(1, count($report)), 1));
}

echo "\n\033[90mو«مربوط» تعني أنّ ما سمّاه المحورُ موجودٌ في الشيفرة — \033[0m\n";
echo "\033[90m**ولا تعني أنّ المطلوبَ نُفِّذ**. الإغلاقُ يُصرَّح به بدليلٍ\033[0m\n";
echo "\033[90mفي docs/مصفوفة-المحاور.json، وتُكذّبه هذه الأداةُ إن كان بلا دليل.\033[0m\n";

exit($liars === [] ? 0 : 1);
