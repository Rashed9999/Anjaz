<?php
/**
 * AMIAL-CONCURRENCY-001 — إثبات عدم البيع المزدوج (Oversell) تحت تزامن حقيقي.
 *
 * يُشغَّل كعمليات نظام مستقلّة متوازية (لا خيوط PHPUnit وهمية). محفظة واحدة
 * مموّلة بمبلغ يكفي لسحب واحد فقط؛ عشرات العمّال يحاولون سحب كامل الرصيد في
 * نفس اللحظة. القفل الصفّي (lockForUpdate) + فحص الرصيد السالب في LedgerService
 * يجب أن يسمح بنجاح واحد فقط — لا سالب، لا صرف مزدوج.
 *
 * الأوضاع: migrate | seed | worker <id> <startEpoch> | check
 * يُشغَّل مع DB_DATABASE=amial_conc لعزله عن قاعدة التطوير.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LedgerService;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use Illuminate\Support\Facades\Artisan;

const PRICE  = '1000';
const WALLET = 'USER_WALLET_1';
const SINK   = 'CONC_SINK';

$mode = $argv[1] ?? 'check';
$ledger = app(LedgerService::class);

switch ($mode) {
    case 'migrate':
        Artisan::call('migrate:fresh', ['--force' => true]);
        echo "migrated\n";
        break;

    case 'seed':
        // محفظة مموّلة بمبلغ يكفي سحباً واحداً فقط + حوض استقبال
        $wallet = $ledger->getOrCreateUserWallet(1);
        $ledger->getOrCreateSystemAccount(SINK, 'liability', 'حوض التزامن', 'credit');
        $capital = $ledger->getOrCreateSystemAccount('CONC_CAPITAL', 'equity', 'رأس مال', 'debit');
        $ledger->post(
            sourceType: 'conc_mint', sourceId: '1', description: 'تمويل محفظة التزامن',
            lines: [
                ['account' => 'CONC_CAPITAL', 'direction' => 'debit',  'amount' => PRICE],
                ['account' => WALLET,         'direction' => 'credit', 'amount' => PRICE],
            ],
        );
        echo "seeded wallet=" . LedgerAccount::where('account_code', WALLET)->value('current_balance') . "\n";
        break;

    case 'worker':
        $id    = (int) ($argv[2] ?? 0);
        $start = (float) ($argv[3] ?? 0);
        // بوّابة تزامن: انتظر لحظة الانطلاق المشتركة لأقصى تنافس على القفل
        while (microtime(true) < $start) {
            usleep(500);
        }
        try {
            $ledger->post(
                sourceType: 'conc_withdraw', sourceId: (string) $id,
                description: "سحب متزامن #{$id}",
                lines: [
                    ['account' => WALLET, 'direction' => 'debit',  'amount' => PRICE],
                    ['account' => SINK,   'direction' => 'credit', 'amount' => PRICE],
                ],
            );
            echo "OK {$id}\n";
        } catch (\Throwable $e) {
            echo "FAIL {$id} " . str_replace("\n", ' ', $e->getMessage()) . "\n";
        }
        break;

    case 'check':
        $wallet = (string) LedgerAccount::where('account_code', WALLET)->value('current_balance');
        $sink   = (string) LedgerAccount::where('account_code', SINK)->value('current_balance');
        $wins   = LedgerJournalEntry::where('source_type', 'conc_withdraw')->count();
        $debit  = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $credit = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');
        $walletComputed = $ledger->computeBalanceFromLines(
            LedgerAccount::where('account_code', WALLET)->value('id')
        );

        $ok = ($wallet === '0.0000')
            && ($sink === PRICE . '.0000')
            && ($wins === 1)
            && (bccomp($debit, $credit, 4) === 0)
            && (bccomp($wallet, $walletComputed, 4) === 0);

        echo "=== CONCURRENCY RESULT ===\n";
        echo "عمليات سحب ناجحة (يجب=1): {$wins}\n";
        echo "رصيد المحفظة (يجب=0): {$wallet}   [محسوب من القيود: {$walletComputed}]\n";
        echo "الحوض (يجب=1000): {$sink}\n";
        echo "الدفتر: مدين={$debit} دائن={$credit}\n";
        echo $ok ? "VERDICT: PASS ✓ لا بيع مزدوج، لا رصيد سالب\n" : "VERDICT: FAIL ✗\n";
        exit($ok ? 0 : 1);
}
