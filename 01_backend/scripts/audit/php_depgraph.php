<?php
/**
 * AMIAL-AUDIT-001 — مخطط اعتماديات الباكند (للقراءة فقط، لا يعدّل شيئاً).
 *
 * يبني رسماً موجّهاً: كل class → الأصناف التي يشير إليها (use / new / ::  / extends / implements).
 * نقاط الدخول = ما يستدعيه الإطار حتماً: routes، Providers، Kernel، Commands،
 * Jobs، Migrations، Middleware، Models (قد تُحمّل ديناميكياً). كل ما لا يمكن
 * الوصول إليه من نقاط الدخول = يتيم مرشّح للحذف (بعد مراجعة بشرية).
 *
 * الاستخدام: php scripts/audit/php_depgraph.php
 */

$root = dirname(__DIR__, 2);
$appDir = $root . '/app';

// 1) فهرسة كل class إلى ملفه
$fqcnToFile = [];
$fileToFqcn = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') continue;
    $src = file_get_contents($f->getPathname());
    if (preg_match('/namespace\s+([^;]+);/', $src, $ns)
        && preg_match('/(?:class|interface|trait|enum)\s+(\w+)/', $src, $cls)) {
        $fqcn = trim($ns[1]) . '\\' . $cls[1];
        $fqcnToFile[$fqcn] = $f->getPathname();
        $fileToFqcn[$f->getPathname()] = $fqcn;
    }
}

// 2) لكل ملف، استخرج الأصناف المشار إليها
$edges = [];       // fqcn => [referenced fqcns]
$referenced = [];  // fqcn => true إن أشار إليه أحد
foreach ($fileToFqcn as $file => $fqcn) {
    $src = file_get_contents($file);
    $refs = [];

    // use statements
    if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $src, $m)) {
        foreach ($m[1] as $u) $refs[ltrim($u, '\\')] = true;
    }
    // أي إشارة قصيرة لاسم صنف: ::class، new X، X::method، extends/implements،
    // وسوم أنواع في التواقيع (Type $x). نلتقط أي Token يبدأ بحرف كبير (PSR).
    // هذا يفرط في الربط قليلاً لكنه يمنع "يتيم كاذب" — أأمن في تدقيق حذف.
    if (preg_match_all('/\b([A-Z][A-Za-z0-9_]{2,})\b/', $src, $m)) {
        foreach ($m[1] as $short) $refs['SHORT:' . $short] = true;
    }
    $edges[$fqcn] = array_keys($refs);
}

// 3) حلّ SHORT: إلى FQCN عبر أسماء الأصناف القصيرة
$shortIndex = [];
foreach ($fqcnToFile as $fqcn => $file) {
    $short = preg_replace('/.*\\\\/', '', $fqcn);
    $shortIndex[$short][] = $fqcn;
}
$resolve = function (string $ref) use ($fqcnToFile, $shortIndex): array {
    if (str_starts_with($ref, 'SHORT:')) {
        $short = substr($ref, 6);
        return $shortIndex[$short] ?? [];
    }
    return isset($fqcnToFile[$ref]) ? [$ref] : [];
};

// 4) نقاط الدخول
$entryPoints = [];
foreach ($fqcnToFile as $fqcn => $file) {
    $rel = str_replace($appDir . '/', '', $file);
    $isEntry =
        str_starts_with($rel, 'Providers/') ||
        str_starts_with($rel, 'Console/') ||
        str_starts_with($rel, 'Http/Middleware/') ||
        str_starts_with($rel, 'Http/Controllers/') ||   // controllers مربوطة بـroutes
        str_starts_with($rel, 'Jobs/') ||
        str_starts_with($rel, 'Models/') ||              // قد تُحمّل ديناميكياً/علاقات
        str_starts_with($rel, 'Exceptions/') ||
        str_starts_with($rel, 'Observers/') ||
        str_starts_with($rel, 'Listeners/') ||
        str_starts_with($rel, 'Events/') ||
        str_contains($rel, 'Middleware');
    if ($isEntry) $entryPoints[$fqcn] = true;
}

// أضِف أي class مذكور في ملفات routes/config كنقطة دخول
$routeFiles = glob($root . '/routes/*.php');
foreach ((glob($root . '/routes/**/*.php') ?: []) as $g) $routeFiles[] = $g;
$routeSrc = '';
foreach ($routeFiles as $rf) $routeSrc .= file_get_contents($rf) . "\n";
foreach ($shortIndex as $short => $fqcns) {
    if (preg_match('/\b' . preg_quote($short, '/') . '\b/', $routeSrc)) {
        foreach ($fqcns as $fq) $entryPoints[$fq] = true;
    }
}

// 5) BFS من نقاط الدخول
$reachable = [];
$queue = array_keys($entryPoints);
while ($queue) {
    $cur = array_shift($queue);
    if (isset($reachable[$cur])) continue;
    $reachable[$cur] = true;
    foreach ($edges[$cur] ?? [] as $ref) {
        foreach ($resolve($ref) as $target) {
            if (!isset($reachable[$target])) $queue[] = $target;
        }
    }
}

// 6) اليتامى = غير قابلة للوصول
$orphans = [];
foreach ($fqcnToFile as $fqcn => $file) {
    if (!isset($reachable[$fqcn])) {
        $orphans[$fqcn] = str_replace($root . '/', '', $file);
    }
}

// 7) كشف الدوائر (DFS)
$circular = [];
$color = []; // 0=white,1=gray,2=black
$stack = [];
$dfs = function ($node) use (&$dfs, &$color, &$stack, &$circular, $edges, $resolve) {
    $color[$node] = 1;
    $stack[] = $node;
    foreach ($edges[$node] ?? [] as $ref) {
        foreach ($resolve($ref) as $t) {
            if (($color[$t] ?? 0) === 1) {
                $idx = array_search($t, $stack, true);
                if ($idx !== false) {
                    $cycle = array_slice($stack, $idx);
                    if (count($cycle) > 1) $circular[] = $cycle;
                }
            } elseif (($color[$t] ?? 0) === 0) {
                $dfs($t);
            }
        }
    }
    array_pop($stack);
    $color[$node] = 2;
};
foreach (array_keys($fqcnToFile) as $fqcn) {
    if (($color[$fqcn] ?? 0) === 0) $dfs($fqcn);
}

// تقرير
echo "=== PHP DEPENDENCY GRAPH ===\n";
echo "classes: " . count($fqcnToFile) . " | entry points: " . count($entryPoints)
   . " | reachable: " . count($reachable) . "\n\n";

echo "=== ORPHANS (" . count($orphans) . ") — غير قابلة للوصول من أي نقطة دخول ===\n";
ksort($orphans);
foreach ($orphans as $fqcn => $rel) echo "  {$rel}\n";

$isModel = fn($fqcn) => str_contains((string)$fqcn, '\\Models\\');
echo "\n=== CIRCULAR DEPENDENCIES ===\n";
$seen = [];
$modelCycles = 0; $realCycles = [];
foreach ($circular as $c) {
    $shorts = array_map(fn($x) => preg_replace('/.*\\\\/', '', (string)$x), $c);
    $key = implode('|', $shorts);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $allModels = true;
    foreach ($c as $node) if (!$isModel($node)) { $allModels = false; break; }
    if ($allModels) { $modelCycles++; continue; }
    $realCycles[] = implode(' → ', $shorts) . ' → (عودة)';
}
echo "Model<->Model (علاقات Eloquent طبيعية): {$modelCycles}\n";
echo "دوائر خارج الـModels (تستحق مراجعة): " . count($realCycles) . "\n";
foreach ($realCycles as $rc) echo "  {$rc}\n";
echo "\nDONE\n";
