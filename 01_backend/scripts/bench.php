<?php
/**
 * AMIAL-BENCH-001 — منحنى نقطة الانهيار (بديل #7 على عقدة واحدة).
 *
 * لا يعطي أرقام إنتاج مطلقة (بلا عنقود)، لكنه يجد «ركبة» المنحنى: نرفع التزامن
 * تدريجياً ونقيس الإنتاجية + الكمون (p50/p95/p99) + معدّل الأخطاء حتى تتوقّف
 * الإنتاجية عن الصعود ويقفز الكمون — أي أين تشبع العقدة (CPU/أقفال/اتصالات).
 *
 * الأوضاع: seed <wallets> | worker <ops> <startEpoch> <latFile> | -
 * يُشغَّل مع DB_DATABASE=amial_conc
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LedgerService;
use Illuminate\Support\Facades\Artisan;
use App\Models\Ledger\LedgerAccount;

$ledger = app(LedgerService::class);
$mode = $argv[1] ?? '-';

switch ($mode) {
    case 'seed':
        $n = (int) ($argv[2] ?? 200);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $ledger->getOrCreateSystemAccount('BENCH_CAP', 'equity', 'رأس مال', 'debit');
        for ($i = 1; $i <= $n; $i++) {
            $w = $ledger->getOrCreateUserWallet(3000 + $i);
            $ledger->post('bench_mint', (string) $i, 'mint', [
                ['account' => 'BENCH_CAP',       'direction' => 'debit',  'amount' => '100000000'],
                ['account' => $w->account_code,  'direction' => 'credit', 'amount' => '100000000'],
            ]);
        }
        echo "seeded {$n} wallets\n";
        break;

    case 'worker':
        $ops   = (int) $argv[2];
        $start = (float) $argv[3];
        $latF  = $argv[4];
        // خزّن أكواد المحافظ مسبقاً (خارج القياس)
        $codes = LedgerAccount::where('account_code', 'like', 'USER_WALLET_%')
            ->pluck('account_code')->all();
        $nw = count($codes);
        mt_srand((int) ($start * 1000) % 100000 + (int) ($argv[5] ?? 0));

        while (microtime(true) < $start) usleep(200);

        $lat = [];
        $err = 0;
        for ($i = 0; $i < $ops; $i++) {
            $from = $codes[mt_rand(0, $nw - 1)];
            $to   = $codes[mt_rand(0, $nw - 1)];
            if ($from === $to) { $i--; continue; }
            $amt = (string) mt_rand(1, 1000); // نفس المبلغ للطرفين (قيد متوازن)
            $t0 = microtime(true);
            try {
                $ledger->post('bench_xfer', null, 'x', [
                    ['account' => $from, 'direction' => 'debit',  'amount' => $amt],
                    ['account' => $to,   'direction' => 'credit', 'amount' => $amt],
                ]);
            } catch (\Throwable $e) { $err++; }
            $lat[] = round((microtime(true) - $t0) * 1000, 3);
        }
        file_put_contents($latF, implode("\n", $lat));
        echo "done ops={$ops} err={$err}\n";
        break;
}
