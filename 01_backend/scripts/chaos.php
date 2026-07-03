<?php
/**
 * AMIAL-CHAOS-001 — Chaos: هل يصمد القلب المالي أمام انهيار MySQL المفاجئ؟
 *
 * محفظتان مجموعهما ثابت (2,000,000). تُشغَّل عاصفة تحويلات صفرية المجموع بينهما،
 * وأثناءها يُقتَل mysqld قتلاً قاسياً (kill -9) ثم يُعاد تشغيله. بفضل معاملات
 * InnoDB + القيد المزدوج، أيّ تحويل لم يُثبَّت لحظة الانهيار يُلغى بالكامل (لا نصف
 * قيد). بعد التعافي يجب أن يبقى: المجموع = 2,000,000 بالضبط، مدين = دائن، ولا سطر يتيم.
 *
 * الأوضاع: seed | run <n> | check
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LedgerService;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use Illuminate\Support\Facades\Artisan;

const A = 'USER_WALLET_1';
const B = 'USER_WALLET_2';
const TOTAL = '2000000';

$mode = $argv[1] ?? 'check';
$ledger = app(LedgerService::class);

switch ($mode) {
    case 'seed':
        Artisan::call('migrate:fresh', ['--force' => true]);
        $wa = $ledger->getOrCreateUserWallet(1);
        $wb = $ledger->getOrCreateUserWallet(2);
        $ledger->getOrCreateSystemAccount('CHAOS_CAPITAL', 'equity', 'رأس مال', 'debit');
        foreach ([A, B] as $w) {
            $ledger->post(
                sourceType: 'chaos_mint', sourceId: $w, description: "تمويل {$w}",
                lines: [
                    ['account' => 'CHAOS_CAPITAL', 'direction' => 'debit',  'amount' => '1000000'],
                    ['account' => $w,              'direction' => 'credit', 'amount' => '1000000'],
                ],
            );
        }
        echo "seeded A+B=" . bcadd(
            (string) LedgerAccount::where('account_code', A)->value('current_balance'),
            (string) LedgerAccount::where('account_code', B)->value('current_balance'), 4
        ) . "\n";
        break;

    case 'run':
        $n = (int) ($argv[2] ?? 100000);
        mt_srand(7);
        for ($i = 0; $i < $n; $i++) {
            [$from, $to] = mt_rand(0, 1) ? [A, B] : [B, A];
            $amt = (string) mt_rand(1, 50000);
            try {
                $ledger->post(
                    sourceType: 'chaos_xfer', sourceId: (string) $i,
                    description: "chaos {$i}",
                    lines: [
                        ['account' => $from, 'direction' => 'debit',  'amount' => $amt],
                        ['account' => $to,   'direction' => 'credit', 'amount' => $amt],
                    ],
                );
            } catch (\Throwable $e) {
                // رصيد غير كافٍ (متوقّع) أو انهيار الاتصال (الهدف) — نتوقّف عند الانهيار
                if (str_contains($e->getMessage(), 'gone away')
                    || str_contains($e->getMessage(), 'Connection refused')
                    || str_contains($e->getMessage(), 'server has')) {
                    fwrite(STDERR, "DB DOWN at op {$i} — stopping\n");
                    exit(7);
                }
            }
            if ($i % 5000 === 0) fwrite(STDERR, "…op {$i}\n");
        }
        echo "completed {$n}\n";
        break;

    case 'check':
        $a = (string) LedgerAccount::where('account_code', A)->value('current_balance');
        $b = (string) LedgerAccount::where('account_code', B)->value('current_balance');
        $sum = bcadd($a, $b, 4);
        $aId = LedgerAccount::where('account_code', A)->value('id');
        $bId = LedgerAccount::where('account_code', B)->value('id');
        $aComp = $ledger->computeBalanceFromLines($aId);
        $bComp = $ledger->computeBalanceFromLines($bId);
        $debit  = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $credit = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');
        $entries = LedgerJournalEntry::count();
        $lines   = LedgerEntryLine::count();

        $ok = (bccomp($sum, TOTAL, 4) === 0)          // المال محفوظ رغم الانهيار
            && (bccomp($a, $aComp, 4) === 0)          // لا انحراف كاش في A
            && (bccomp($b, $bComp, 4) === 0)          // ولا في B
            && (bccomp($debit, $credit, 4) === 0)     // الدفتر متوازن
            && ($entries * 2 === $lines);             // لا سطر يتيم (لا نصف قيد)

        echo "=== CHAOS RESULT (بعد انهيار MySQL وتعافيه) ===\n";
        echo "A={$a} [محسوب {$aComp}]  B={$b} [محسوب {$bComp}]\n";
        echo "المجموع (يجب=2000000): {$sum}\n";
        echo "الدفتر: مدين={$debit} دائن={$credit}\n";
        echo "قيود={$entries} أسطر={$lines} (يجب أسطر=قيود×2 — لا نصف قيد)\n";
        echo $ok ? "VERDICT: PASS ✓ لا مال ضائع، لا قيد ممزّق، الدفتر متوازن\n"
                 : "VERDICT: FAIL ✗\n";
        exit($ok ? 0 : 1);
}
