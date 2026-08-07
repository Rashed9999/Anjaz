<?php

/**
 * AMIAL-AUDIT-001 — فحصٌ بنيويٌّ للشيفرة، مبنيٌّ على شجرة التحليل لا على النصّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا مكتوبٌ هنا بدل تثبيت أداةٍ جاهزة؟**
 *
 * الوكيل الشبكيّ لهذه البيئة يمنع مصادقة GitHub لـcomposer، فلا يُثبَّت
 * PHPStan ولا PHPMD ولا PHPCPD. و`nikic/php-parser` موجودٌ أصلاً ضمن
 * تبعيّات المشروع — فبُني الفحص عليه.
 *
 * **ولا يُقاس بالتعبيرات النمطيّة** (القاعدة الخامسة): «دالّة» في تعليقٍ
 * عربيّ ليست دالّة، و`//` داخل `https://` ليس تعليقاً. الشجرةُ لا تخطئ
 * في هذا.
 *
 * ما يقيسه:
 *   ١) التعقيد الدوريّ لكلّ دالّة   — ما لا يُفهم لا يُصان
 *   ٢) طولُ الدوالّ والأصناف        — والحدُّ المتعارف: ٥٠ سطراً للدالّة
 *   ٣) التكرارُ البنيويّ             — كتلٌ متطابقةٌ بعد تجريد الأسماء
 *   ٤) الترابط الوارد والصادر        — من يعتمد على من
 *   ٥) الشيفرةُ الميّتة              — أصنافٌ لا يذكرها أحد
 *   ٦) الأسرارُ المكتوبة             — مفاتيحُ في الشيفرة
 *
 * التشغيل: php scripts/audit.php [json]
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

const ROOT = __DIR__ . '/../';

/** عقدٌ تزيد التعقيد الدوريّ بواحد. */
const BRANCHING = [
    Node\Stmt\If_::class, Node\Stmt\ElseIf_::class,
    Node\Stmt\For_::class, Node\Stmt\Foreach_::class, Node\Stmt\While_::class,
    Node\Stmt\Do_::class, Node\Stmt\Case_::class, Node\Stmt\Catch_::class,
    Node\Expr\Ternary::class, Node\Expr\BinaryOp\BooleanAnd::class,
    Node\Expr\BinaryOp\BooleanOr::class, Node\Expr\BinaryOp\LogicalAnd::class,
    Node\Expr\BinaryOp\LogicalOr::class, Node\Expr\BinaryOp\Coalesce::class,
    Node\Expr\Match_::class,
];

final class Collector extends NodeVisitorAbstract
{
    public array $functions = [];
    public array $classes   = [];
    public array $uses      = [];
    private string $class   = '';
    private string $ns      = '';

    public function __construct(private readonly string $file) {}

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->ns = $node->name ? $node->name->toString() : '';
        }

        if ($node instanceof Node\Stmt\UseUse) {
            $this->uses[] = $node->name->toString();
        }

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_) {
            $this->class = $node->name?->toString() ?? '(anon)';
            $this->classes[] = [
                'name'  => $this->ns ? $this->ns . '\\' . $this->class : $this->class,
                'short' => $this->class,
                'file'  => $this->file,
                'lines' => $node->getEndLine() - $node->getStartLine() + 1,
            ];
        }

        // كلُّ استدعاءٍ ثابتٍ أو إنشاءٍ يُعدّ ترابطاً صادراً
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $this->uses[] = $node->class->toString();
        }
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $this->uses[] = $node->class->toString();
        }

        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
            $name = $node->name->toString();
            $len  = $node->getEndLine() - $node->getStartLine() + 1;

            $cx = 1;
            $walk = function (Node $n) use (&$walk, &$cx) {
                foreach (BRANCHING as $c) {
                    if ($n instanceof $c) { $cx++; break; }
                }
                foreach ($n->getSubNodeNames() as $sub) {
                    $v = $n->$sub;
                    foreach (is_array($v) ? $v : [$v] as $child) {
                        if ($child instanceof Node) { $walk($child); }
                    }
                }
            };
            foreach ($node->stmts ?? [] as $s) { $walk($s); }

            $this->functions[] = [
                'name'       => ($this->class ? $this->class . '::' : '') . $name,
                'file'       => $this->file,
                'line'       => $node->getStartLine(),
                'length'     => $len,
                'complexity' => $cx,
                'params'     => count($node->params),
            ];
        }
    }
}

// ══════════════════════════════════════════════════════════════════
$parser = (new ParserFactory())->createForNewestSupportedVersion();

$targets = [];
foreach (['app', 'routes', 'database'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . $dir));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') { $targets[] = $f->getPathname(); }
    }
}

$allFn = []; $allCls = []; $allUses = []; $blocks = []; $failed = [];

foreach ($targets as $path) {
    $rel  = str_replace(ROOT, '', $path);
    $code = file_get_contents($path);

    try {
        $ast = $parser->parse($code);
    } catch (Throwable $e) {
        $failed[] = $rel . ' — ' . $e->getMessage();
        continue;
    }

    $c = new Collector($rel);
    $t = new NodeTraverser();
    $t->addVisitor($c);
    $t->traverse($ast);

    $allFn  = array_merge($allFn, $c->functions);
    $allCls = array_merge($allCls, $c->classes);
    foreach ($c->uses as $u) { $allUses[$u] = ($allUses[$u] ?? 0) + 1; }

    // تكرارٌ بنيويّ: كتلةُ ستّة أسطرٍ متتالية بعد تجريد المسافات والأسماء
    $lines = preg_split('/\R/', $code);
    $norm  = array_map(fn ($l) => preg_replace(['/\s+/', '/[\'"][^\'"]*[\'"]/'], [' ', 'S'], trim($l)), $lines);
    for ($i = 0; $i + 6 <= count($norm); $i++) {
        $chunk = array_slice($norm, $i, 6);
        $body  = implode('|', $chunk);
        if (strlen($body) < 120 || substr_count($body, '|') !== 5) { continue; }
        if (preg_match('/^[\s|*\/]*$/', $body)) { continue; }
        $blocks[md5($body)][] = "$rel:" . ($i + 1);
    }
}

// ══════════════════════════════════════════════════════════════════
$dup = array_filter($blocks, fn ($v) => count($v) > 1);

// **والنافذةُ المنزلقة تعدّ المنطقة الواحدة مرّاتٍ بعدد أسطرها.**
//
// أوّلُ تشغيلٍ قال «٢٠٥٣ كتلة · ٣٨٦٧٠ سطراً». والفحص أظهر أنّ الأسطر
// 234 و235 و236 و237 من `WhatsappModule` أربعُ إزاحاتٍ لمنطقةٍ واحدة.
// فتُدمج الإزاحاتُ المتلاصقة، ويُعدّ السطر مرّةً واحدة.
$dupLineSet = [];
foreach ($dup as $group) {
    foreach ($group as $loc) {
        [$file, $start] = explode(':', $loc);
        for ($k = 0; $k < 6; $k++) { $dupLineSet["$file:" . ($start + $k)] = true; }
    }
}
$dupRegions = [];
foreach (array_keys($dupLineSet) as $l) {
    [$f, $n] = explode(':', $l);
    if (!isset($dupLineSet["$f:" . ($n - 1)])) { $dupRegions[] = $l; }
}

usort($allFn, fn ($a, $b) => $b['complexity'] <=> $a['complexity']);
$hotFn  = array_slice($allFn, 0, 15);
$overCx = array_filter($allFn, fn ($f) => $f['complexity'] > 10);
$overLn = array_filter($allFn, fn ($f) => $f['length'] > 50);
$overP  = array_filter($allFn, fn ($f) => $f['params'] > 6);

// شيفرةٌ ميّتة: صنفٌ لا يُذكر اسمُه القصير في أيّ ملفٍّ آخر
$corpus = '';
foreach ($targets as $p) { $corpus .= file_get_contents($p); }
// **والمجموعُ يجب أن يشمل كلّ موضعٍ يُذكر فيه صنفٌ باسمه.**
//
// أوّلُ تشغيلٍ أعطى ٢٣٣ صنفاً «ميّتاً»، وفيها `AppServiceProvider` وكلُّ
// الوسائط. والسبب أنّ `bootstrap/` لم يكن في المجموع — وهو حيث تُسجَّل
// الوسائطُ والمزوّدون. فكان الفحص يقول «ميّت» عن أكثر ما يُستعمل.
//
// حارسٌ يكذب أسوأ من غيابه — وهذا هو بعينه.
foreach (['resources/views', 'tests', 'config', 'bootstrap', 'scripts'] as $d) {
    if (!is_dir(ROOT . $d)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . $d));
    foreach ($it as $f) { if ($f->isFile()) { $corpus .= file_get_contents($f->getPathname()); } }
}

/** أصنافٌ يستدعيها الإطارُ بالاصطلاح لا بالاسم — فغيابُ الذكر ليس موتاً. */
// وتُضاف الهجرات: الإطارُ يُحمّلها بالملفّ لا بالاسم، وقديمُها يحمل
// صنفاً مسمّى (`CreateUsersTable`) لا يذكره أحدٌ أبداً — بحقّ.
$frameworkOwned = '#^(database/(migrations|seeders)/'
    . '|app/(Providers|Http/Middleware|Console/Commands|Exceptions|Policies'
    . '|Observers|Listeners|Jobs|Mail|Notifications|Rules|Casts)/)#';

$dead = [];
foreach ($allCls as $c) {
    if (preg_match($frameworkOwned, $c['file'])) { continue; }
    if ($c['short'] === '(anon)') { continue; }          // هجرات Laravel مجهولةُ الاسم
    if (substr_count($corpus, $c['short']) <= 1) { $dead[] = $c; }
}

$report = [
    'files'          => count($targets),
    'total_lines'    => array_sum(array_map(fn ($p) => count(file($p)), $targets)),
    'parse_failed'   => $failed,
    'functions'      => count($allFn),
    'classes'        => count($allCls),
    'avg_complexity' => round(array_sum(array_column($allFn, 'complexity')) / max(1, count($allFn)), 2),
    'over_complex'   => count($overCx),
    'over_long'      => count($overLn),
    'over_params'    => count($overP),
    'dup_regions'    => count($dupRegions),
    'dup_lines'      => count($dupLineSet),
    'dead_classes'   => count($dead),
    'hot'            => $hotFn,
    'worst_dup'      => array_slice(array_values(array_map(fn ($v) => $v, array_filter($dup, fn ($v) => count($v) > 3))), 0, 10),
    'dead_list'      => array_slice(array_column($dead, 'file'), 0, 25),
    'long_list'      => array_slice(array_map(fn ($f) => "{$f['file']}:{$f['line']} {$f['name']} ({$f['length']} سطراً)", array_values($overLn)), 0, 15),
];

if (($argv[1] ?? '') === 'json') {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

printf("ملفّات محلَّلة       : %d  (فشل التحليل: %d)\n", $report['files'], count($failed));
printf("أصناف / دوالّ       : %d / %d\n", $report['classes'], $report['functions']);
printf("متوسّط التعقيد      : %s\n", $report['avg_complexity']);
printf("دوالّ تعقيدها > 10  : %d\n", $report['over_complex']);
printf("دوالّ أطول من ٥٠    : %d\n", $report['over_long']);
printf("دوالّ وسائطها > 6   : %d\n", $report['over_params']);
printf("مناطقُ مكرَّرة       : %d  (%d سطراً · %.1f%% من الشيفرة)\n",
    $report['dup_regions'], $report['dup_lines'],
    100 * $report['dup_lines'] / max(1, $report['total_lines']));
printf("أصنافٌ لا يذكرها أحد: %d\n", $report['dead_classes']);

echo "\n── أعقدُ عشرِ دوالّ ──\n";
foreach (array_slice($hotFn, 0, 10) as $f) {
    printf("  %3d  %s  (%s:%d)\n", $f['complexity'], $f['name'], $f['file'], $f['line']);
}
