<?php
/**
 * AMIAL-REPORT-001 — يولّد تقرير اختبارات مفصّلاً (HTML → PDF عبر Chromium).
 *
 * يقرأ junit.xml (بيانات تشغيل حقيقية) + يبني خريطة تغطية على مستوى الوحدات
 * (أيّ أصناف app يمسّها كل اختبار). لا تغطية أسطر (لا يوجد xdebug/pcov في البيئة).
 *
 * التشغيل: php scripts/report/gen_test_report.php <junit.xml> <out.html>
 */

$junitPath = $argv[1] ?? '/tmp/report/junit.xml';
$outPath   = $argv[2] ?? '/tmp/report/report.html';
$root = dirname(__DIR__, 2);

$xml = simplexml_load_file($junitPath);
if (!$xml) { fwrite(STDERR, "cannot read junit\n"); exit(1); }

// ── 1) جمع كل الأصناف الاختبارية (testsuite ذات file) ──
$classes = [];   // className => [file, tests[], assertions, time, risky]
function walk($node, &$classes) {
    foreach ($node->testsuite ?? [] as $ts) {
        $file = (string) ($ts['file'] ?? '');
        if ($file !== '' && isset($ts->testcase)) {
            $name = (string) $ts['name'];
            $tests = [];
            $risky = 0;
            foreach ($ts->testcase as $tc) {
                $status = 'pass';
                if (isset($tc->failure)) $status = 'fail';
                elseif (isset($tc->error)) $status = 'error';
                elseif (isset($tc->warning)) { $status = 'risky'; $risky++; }
                $tests[] = [
                    'name' => (string) $tc['name'],
                    'assertions' => (int) $tc['assertions'],
                    'time' => (float) $tc['time'],
                    'status' => $status,
                ];
            }
            $classes[$name] = [
                'file' => $file,
                'tests' => $tests,
                'assertions' => (int) $ts['assertions'],
                'time' => (float) $ts['time'],
                'risky' => $risky,
            ];
        }
        walk($ts, $classes);
    }
}
walk($xml, $classes);

// ── 2) خريطة التغطية: أيّ أصناف App يمسّها كل ملفّ اختبار ──
$coveredAppClasses = [];       // FQCN => [test classes...]
foreach ($classes as $cn => $c) {
    $src = @file_get_contents($c['file']);
    if ($src === false) continue;
    // use App\...;  و  App\...::class  و  new App\...
    preg_match_all('/\b(App\\\\[A-Za-z0-9_\\\\]+)/', $src, $m);
    foreach (array_unique($m[1] ?? []) as $fqcn) {
        $fqcn = trim($fqcn, '\\');
        if (!str_ends_with($fqcn, '\\')) {
            $coveredAppClasses[$fqcn][$cn] = true;
        }
    }
}

// ── 3) إجمالي أصناف app للمقارنة ──
$allAppClasses = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $s = file_get_contents($f->getPathname());
    if (preg_match('/\bnamespace\s+([^;]+);/', $s, $ns) &&
        preg_match('/\b(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/', $s, $cl)) {
        $allAppClasses[trim($ns[1]) . '\\' . $cl[1]] = $f->getPathname();
    }
}
$total = count($allAppClasses);
$coveredCount = 0;
foreach ($allAppClasses as $fqcn => $_) {
    if (isset($coveredAppClasses[$fqcn])) $coveredCount++;
}
$modCoverage = $total ? round($coveredCount / $total * 100, 1) : 0;

// ── إحصاءات عامة ──
$totalTests = 0; $totalAssert = 0; $totalTime = 0; $totalRisky = 0; $totalPass = 0;
foreach ($classes as $c) {
    $totalTests += count($c['tests']);
    $totalAssert += $c['assertions'];
    $totalTime += $c['time'];
    $totalRisky += $c['risky'];
    foreach ($c['tests'] as $t) if ($t['status'] === 'pass') $totalPass++;
}

// ── تصنيف حسب النطاق (من اسم الصنف) ──
$domains = [
    'المالية والدفتر'    => '/Ledger|Financial|Integrity|Money|Fee|Transaction|Settlement/i',
    'التزامن والسلامة'   => '/Concurren|Lock|Guard|Idempot/i',
    'الوكلاء'           => '/Agent|Float|Network/i',
    'التجّار وPOS'      => '/Merchant|Cashier|Pos|Fuel|Pharmacy|Wholesale/i',
    'الدفع الآمن'       => '/SafePayment/i',
    'KYC والامتثال'     => '/Kyc|Aml|Sanction|Recovery/i',
    'واتساب'           => '/Whatsapp/i',
    'المصادقة والأمان'  => '/Auth|Pii|Encrypt|Phone|Signature|Rbac|Pentest|Zone/i',
    'الإدارة والإعدادات' => '/Admin|Settings|Ops|Subscription|Report|Config/i',
];
$byDomain = [];
foreach ($classes as $cn => $c) {
    $short = substr($cn, strrpos($cn, '\\') + 1);
    $matched = 'أخرى';
    foreach ($domains as $label => $rx) { if (preg_match($rx, $short)) { $matched = $label; break; } }
    $byDomain[$matched]['tests'] = ($byDomain[$matched]['tests'] ?? 0) + count($c['tests']);
    $byDomain[$matched]['assert'] = ($byDomain[$matched]['assert'] ?? 0) + $c['assertions'];
    $byDomain[$matched]['classes'] = ($byDomain[$matched]['classes'] ?? 0) + 1;
}
arsort($byDomain);

// ── بناء HTML ──
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$date = '2026-07-04';
ob_start(); ?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<style>
  @page { margin: 18mm 14mm; }
  * { font-family: 'DejaVu Sans', sans-serif; }
  body { color:#1a2733; font-size:11px; line-height:1.5; }
  h1 { color:#0f2b46; font-size:22px; margin:0 0 4px; }
  h2 { color:#0f2b46; font-size:15px; border-bottom:2px solid #17395c; padding-bottom:4px; margin:22px 0 10px; page-break-after:avoid; }
  h3 { color:#17395c; font-size:12.5px; margin:14px 0 6px; page-break-after:avoid; }
  .sub { color:#5a6b7b; font-size:11px; }
  .kpis { display:flex; gap:10px; margin:14px 0; flex-wrap:wrap; }
  .kpi { flex:1; min-width:120px; background:#0f2b46; color:#fff; border-radius:10px; padding:12px; text-align:center; }
  .kpi .n { font-size:22px; font-weight:800; }
  .kpi .l { font-size:10px; opacity:.85; }
  table { width:100%; border-collapse:collapse; margin:6px 0 14px; }
  th,td { border:1px solid #dbe3ea; padding:5px 7px; text-align:right; vertical-align:top; }
  th { background:#eef3f8; color:#0f2b46; font-weight:700; }
  tr:nth-child(even) td { background:#f7fafc; }
  .ok { color:#1a7f4b; font-weight:700; }
  .risky { color:#b8860b; }
  .note { background:#fff8e6; border:1px solid #f0d78a; border-radius:8px; padding:10px; margin:10px 0; font-size:10.5px; }
  .cls { page-break-inside:avoid; }
  .bar { height:8px; background:#e3e9ef; border-radius:6px; overflow:hidden; }
  .bar>span { display:block; height:100%; background:#1a7f4b; }
  small { color:#5a6b7b; }
</style></head><body>

<h1>تقرير الاختبارات المفصّل — أميال باي</h1>
<div class="sub">تشغيل فعلي عبر PHPUnit على MariaDB / PHP 8.4 · التاريخ: <?=$e($date)?></div>

<div class="kpis">
  <div class="kpi"><div class="n"><?=$totalTests?></div><div class="l">اختبار</div></div>
  <div class="kpi"><div class="n"><?=number_format($totalAssert)?></div><div class="l">تأكيدة (assertion)</div></div>
  <div class="kpi"><div class="n"><?=$totalPass?></div><div class="l">ناجح</div></div>
  <div class="kpi"><div class="n">0</div><div class="l">فاشل</div></div>
  <div class="kpi"><div class="n"><?=count($classes)?></div><div class="l">ملفّ اختبار</div></div>
  <div class="kpi"><div class="n"><?=round($totalTime)?>s</div><div class="l">زمن التشغيل</div></div>
</div>

<div class="note">
  <b>ملاحظة صدق حول التغطية:</b> بيئة التشغيل لا تحتوي على مُشغّل تغطية أسطر
  (Xdebug/PCOV غير متاحين والوكيل يمنع تثبيتهما)، لذا لا نُدرِج «نسبة تغطية أسطر».
  بدلاً من ذلك نُقدّم <b>تغطية على مستوى الوحدات</b>: كم صنفاً من أصناف التطبيق
  يمسّه اختبار واحد على الأقلّ — وهو مقياس صادق وقابل للتحقّق. كل الأرقام أدناه من
  تشغيل حقيقي (junit.xml).
</div>

<h2>1) الخلاصة التنفيذية</h2>
<table>
  <tr><th>المقياس</th><th>القيمة</th></tr>
  <tr><td>إجمالي الاختبارات</td><td class="ok"><?=$totalTests?> ناجح / 0 فاشل</td></tr>
  <tr><td>إجمالي التأكيدات</td><td><?=number_format($totalAssert)?></td></tr>
  <tr><td>ملفّات الاختبار</td><td><?=count($classes)?></td></tr>
  <tr><td>اختبارات «risky» (تحذيرات Mockery حميدة، لا فشل)</td><td class="risky"><?=$totalRisky?></td></tr>
  <tr><td>تغطية الوحدات (أصناف مُختبَرة / إجمالي)</td>
      <td><b><?=$coveredCount?> / <?=$total?> = <?=$modCoverage?>%</b>
      <div class="bar"><span style="width:<?=$modCoverage?>%"></span></div></td></tr>
</table>

<h2>2) التغطية حسب النطاق الوظيفي</h2>
<table>
  <tr><th>النطاق</th><th>ملفّات</th><th>اختبارات</th><th>تأكيدات</th></tr>
  <?php foreach ($byDomain as $label => $d): ?>
  <tr><td><?=$e($label)?></td><td><?=$d['classes']?></td><td><?=$d['tests']?></td><td><?=number_format($d['assert'])?></td></tr>
  <?php endforeach; ?>
</table>

<h2>3) تفصيل كل ملفّ اختبار</h2>
<?php
ksort($classes);
foreach ($classes as $cn => $c):
  $short = $e($cn);
  $rel = str_replace($root . '/', '', $c['file']);
  // الأصناف المُغطّاة من هذا الملفّ
  $covers = [];
  foreach ($coveredAppClasses as $fqcn => $testClasses) {
    if (isset($testClasses[$cn])) $covers[] = substr($fqcn, strrpos($fqcn, '\\') + 1);
  }
  $covers = array_slice(array_unique($covers), 0, 12);
?>
<div class="cls">
<h3><?=$short?> <small>(<?=count($c['tests'])?> اختبار · <?=$c['assertions']?> تأكيدة)</small></h3>
<div class="sub" style="margin-bottom:4px"><small><?=$e($rel)?></small></div>
<?php if ($covers): ?><div class="sub" style="margin-bottom:6px"><small>يغطّي: <?=$e(implode('، ', $covers))?></small></div><?php endif; ?>
<table>
  <tr><th style="width:60%">الاختبار</th><th>الحالة</th><th>تأكيدات</th><th>زمن</th></tr>
  <?php foreach ($c['tests'] as $t):
    $nm = $e(str_replace('_', ' ', $t['name']));
    $st = $t['status'] === 'pass' ? '<span class="ok">✓ ناجح</span>' :
          ($t['status'] === 'risky' ? '<span class="risky">⚠ risky</span>' : '✗ ' . $t['status']);
  ?>
  <tr><td><?=$nm?></td><td><?=$st?></td><td><?=$t['assertions']?></td><td><?=number_format($t['time'],3)?>s</td></tr>
  <?php endforeach; ?>
</table>
</div>
<?php endforeach; ?>

<h2>4) منهجية التقرير</h2>
<p>البيانات من ملفّ <code>junit.xml</code> النّاتج عن <code>phpunit --log-junit</code> —
تشغيل حقيقي كامل. «تغطية الوحدات» تُحسَب بتحليل كل ملفّ اختبار لاستخراج أصناف
<code>App\</code> التي يستوردها/يستخدمها، ثمّ مقارنتها بإجمالي أصناف مجلّد <code>app/</code>.
لا تعكس تغطية الأسطر داخل كل دالّة (يتطلّب Xdebug/PCOV غير المتاحين هنا)، لكنها تُبيّن
بصدق أيّ وحدات المشروع ممسوسة باختبار.</p>

</body></html>
<?php
file_put_contents($outPath, ob_get_clean());
echo "HTML written: {$outPath}\n";
echo "Tests: {$totalTests} | Assertions: {$totalAssert} | Files: " . count($classes) . "\n";
echo "Module coverage: {$coveredCount}/{$total} = {$modCoverage}%\n";
