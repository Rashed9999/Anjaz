<?php
/**
 * AMIAL-SOAK-001 — اختبار تحمّل مصغّر (#2).
 *
 * يشغّل تحويلات متواصلة على مدى فترة، ويسجّل كل جولة: الذاكرة المستهلكة،
 * الإنتاجية (عملية/ثانية)، وزمن الجولة. الهدف كشف: تسريب ذاكرة (نموّ غير
 * محدود)، أو تدهور أداء (بطء متزايد)، مع بقاء الدفتر متوازناً طوال الوقت.
 *
 * بيئة عقدة واحدة: مصغّر مقابل 30 دقيقة×5000 مستخدم الحقيقي، لكنه يكفي لكشف
 * التسريب والتدهور (وهما ما يظهر تدريجياً، لا في الاختبارات القصيرة).
 *
 * الاستخدام: SOAK_ROUNDS=40 SOAK_PER_ROUND=500 php scripts/soak.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LedgerService;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use Illuminate\Support\Facades\Artisan;

$ledger = app(LedgerService::class);
$rounds   = (int) (getenv('SOAK_ROUNDS') ?: 40);
$perRound = (int) (getenv('SOAK_PER_ROUND') ?: 500);

Artisan::call('migrate:fresh', ['--force' => true]);
$ledger->getOrCreateSystemAccount('SOAK_CAP', 'equity', 'رأس مال', 'debit');
$wallets = [];
for ($i = 1; $i <= 40; $i++) {
    $w = $ledger->getOrCreateUserWallet(2000 + $i);
    $wallets[] = $w->account_code;
    $ledger->post('soak_mint', (string) $i, 'mint', [
        ['account' => 'SOAK_CAP', 'direction' => 'debit',  'amount' => '1000000'],
        ['account' => $w->account_code, 'direction' => 'credit', 'amount' => '1000000'],
    ]);
}
$nw = count($wallets);
$total = bcmul('1000000', (string) $nw, 4);

mt_srand(99);
$mem = [];
$tput = [];
echo "جولة | ذاكرة(MB) | عملية/ث | متوازن؟\n";
echo "─────┼───────────┼─────────┼────────\n";
for ($r = 1; $r <= $rounds; $r++) {
    $t0 = microtime(true);
    for ($k = 0; $k < $perRound; $k++) {
        $from = $wallets[mt_rand(0, $nw - 1)];
        $to   = $wallets[mt_rand(0, $nw - 1)];
        if ($from === $to) continue;
        try {
            $ledger->post('soak_xfer', "{$r}_{$k}", 'xfer', [
                ['account' => $from, 'direction' => 'debit',  'amount' => (string) mt_rand(1, 20000)],
                ['account' => $to,   'direction' => 'credit', 'amount' => (string) mt_rand(1, 20000)],
            ]);
        } catch (\Throwable $e) { /* رصيد غير كافٍ متوقّع */ }
    }
    $dt = microtime(true) - $t0;
    $memMb = round(memory_get_usage(true) / 1048576, 1);
    $ops = round($perRound / $dt);
    $mem[] = $memMb; $tput[] = $ops;

    // توازن الدفتر (كل جولات)
    $d = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
    $c = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');
    $bal = bccomp($d, $c, 4) === 0 ? '✓' : '✗';

    if ($r % 5 === 0 || $r === 1) {
        printf("%4d │ %9.1f │ %7d │   %s\n", $r, $memMb, $ops, $bal);
    }
    if ($bal === '✗') { echo "✗ اختلّ التوازن في الجولة {$r}!\n"; exit(1); }
}

// حفظ المال النهائي
$walletSum = '0';
foreach ($wallets as $code) {
    $walletSum = bcadd($walletSum, (string) LedgerAccount::where('account_code', $code)->value('current_balance'), 4);
}

// تحليل: تسريب ذاكرة = نموّ الذاكرة من أوّل ربع لآخر ربع؛ تدهور = هبوط الإنتاجية
$memStart = $mem[0];
$memEnd   = end($mem);
$memGrowth = round($memEnd - $memStart, 1);
$tputStart = array_sum(array_slice($tput, 0, 5)) / min(5, count($tput));
$tputEnd   = array_sum(array_slice($tput, -5)) / min(5, count($tput));
$degrade = $tputStart > 0 ? round((1 - $tputEnd / $tputStart) * 100, 1) : 0;

echo "\n═══ التحليل ═══\n";
echo "جولات={$rounds} × {$perRound} = " . ($rounds * $perRound) . " عملية\n";
echo "الذاكرة: بداية={$memStart}MB نهاية={$memEnd}MB نموّ={$memGrowth}MB\n";
echo "الإنتاجية: بداية=" . round($tputStart) . " نهاية=" . round($tputEnd) . " عملية/ث (تدهور={$degrade}%)\n";
echo "حفظ المال: مجموع المحافظ={$walletSum} المتوقّع={$total} " . (bccomp($walletSum, $total, 4) === 0 ? '✓' : '✗') . "\n";

$leak = $memGrowth > 20;      // نموّ >20MB عبر الجولات = مؤشّر تسريب
$degraded = $degrade > 40;    // هبوط >40% في الإنتاجية = تدهور
$moneyOk = bccomp($walletSum, $total, 4) === 0;

echo "\n";
echo $leak ? "⚠ مؤشّر تسريب ذاكرة ({$memGrowth}MB)\n" : "✓ لا تسريب ذاكرة ملحوظ\n";
echo $degraded ? "⚠ تدهور أداء ({$degrade}%)\n" : "✓ الأداء مستقرّ\n";
echo $moneyOk ? "✓ المال محفوظ طوال الاختبار\n" : "✗ فقد مال!\n";
echo (!$leak && !$degraded && $moneyOk) ? "VERDICT: PASS ✓\n" : "VERDICT: راجع أعلاه\n";
exit((!$leak && !$degraded && $moneyOk) ? 0 : 1);
