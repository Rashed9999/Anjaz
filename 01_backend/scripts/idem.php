<?php
/**
 * AMIAL-IDEM-001 — exactly-once على idempotency تحت تزامن حقيقي (#2 من الثلاثة).
 *
 * يعيد إنتاج مسار الحماية المالي الفعلي (كما في EnforceIdempotency middleware):
 *   begin(key) → إن كان 'new' نفّذ القيد المحاسبي ثمّ complete(key).
 * محفظة مموّلة بمبلغ يكفي خصماً واحداً؛ عشرات العمّال يرسلون *نفس* مفتاح
 * الـIdempotency في اللحظة نفسها. يجب أن يُخصَم مرّة واحدة فقط — البقيّة تحصل
 * على in_progress/replay ولا تُحرّك فلساً.
 *
 * الأوضاع: setup | worker <startEpoch> | replay | conflict | check
 * يُشغَّل مع DB_DATABASE=amial_conc
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LedgerService;
use App\Services\IdempotencyService;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

const KEY    = 'idem-fixed-key-0001';
const WALLET = 'USER_WALLET_1';
const SINK   = 'IDEM_SINK';
const AMOUNT = '1000';
const ENDPOINT = 'POST api/v1/amial/pay';

$ledger = app(LedgerService::class);
$idem   = app(IdempotencyService::class);
$mode = $argv[1] ?? 'check';

/** ينفّذ خصماً واحداً محكوماً بـidempotency (نفس منطق الـmiddleware). */
function chargeOnce(IdempotencyService $idem, LedgerService $ledger, string $key, array $body, string $wallet = WALLET): string
{
    try {
        $check = $idem->begin(key: $key, userId: 1, endpoint: ENDPOINT, requestBody: $body);
    } catch (\App\Exceptions\DuplicateTransactionException $e) {
        return 'CONFLICT';
    }
    if ($check['status'] !== 'new') {
        return 'SKIPPED:' . $check['status'];
    }
    // status===new → نفّذ الحركة المالية ثمّ سجّل النتيجة
    $entry = $ledger->post(
        sourceType: 'idem_pay', sourceId: $key, description: 'دفع محكوم بمفتاح',
        lines: [
            ['account' => $wallet, 'direction' => 'debit',  'amount' => AMOUNT],
            ['account' => SINK,    'direction' => 'credit', 'amount' => AMOUNT],
        ],
    );
    $idem->complete(key: $key, userId: 1, endpoint: ENDPOINT, httpStatus: 200,
        responseBody: ['success' => true, 'transaction_id' => $entry->id], transactionId: (string) $entry->id);
    return 'CHARGED';
}

switch ($mode) {
    case 'setup':
        Artisan::call('migrate:fresh', ['--force' => true]);
        $ledger->getOrCreateSystemAccount(SINK, 'liability', 'حوض idem', 'credit');
        $ledger->getOrCreateSystemAccount('IDEM_CAP', 'equity', 'رأس مال', 'debit');
        // ثلاث محافظ مموّلة بخصم واحد: 1=التزامن، 2=الإعادة، 3=التعارض
        foreach ([1, 2, 3] as $u) {
            $w = $ledger->getOrCreateUserWallet($u);
            $ledger->post('idem_mint', (string) $u, 'تمويل', [
                ['account' => 'IDEM_CAP',         'direction' => 'debit',  'amount' => AMOUNT],
                ['account' => $w->account_code,   'direction' => 'credit', 'amount' => AMOUNT],
            ]);
        }
        echo "seeded wallet1=" . LedgerAccount::where('account_code', WALLET)->value('current_balance') . "\n";
        break;

    case 'worker':
        $start = (float) ($argv[2] ?? 0);
        while (microtime(true) < $start) usleep(300);
        echo chargeOnce($idem, $ledger, KEY, ['amount' => AMOUNT, 'to' => 'merchant']) . "\n";
        break;

    case 'replay': // نفس المفتاح تتابعياً مرّتين → الثانية لا تُخصَم (محفظة 2)
        $a = chargeOnce($idem, $ledger, 'seq-key-000000001', ['amount' => AMOUNT], 'USER_WALLET_2');
        $b = chargeOnce($idem, $ledger, 'seq-key-000000001', ['amount' => AMOUNT], 'USER_WALLET_2');
        $w2 = (string) LedgerAccount::where('account_code', 'USER_WALLET_2')->value('current_balance');
        $ok = $a === 'CHARGED' && str_starts_with($b, 'SKIPPED') && $w2 === '0.0000';
        echo "first={$a} second={$b} wallet2={$w2} → " . ($ok ? 'PASS ✓ (خصم واحد)' : 'FAIL ✗') . "\n";
        break;

    case 'conflict': // نفس المفتاح، جسم مختلف → 409 conflict، لا خصم (محفظة 3)
        $a = chargeOnce($idem, $ledger, 'conf-key-00000001', ['amount' => AMOUNT], 'USER_WALLET_3');
        $b = chargeOnce($idem, $ledger, 'conf-key-00000001', ['amount' => '999999'], 'USER_WALLET_3');
        $w3 = (string) LedgerAccount::where('account_code', 'USER_WALLET_3')->value('current_balance');
        $ok = $a === 'CHARGED' && $b === 'CONFLICT' && $w3 === '0.0000';
        echo "first={$a} second={$b} wallet3={$w3} → " . ($ok ? 'PASS ✓ (جسم مختلف مرفوض)' : 'FAIL ✗') . "\n";
        break;

    case 'check':
        $wallet = (string) LedgerAccount::where('account_code', WALLET)->value('current_balance');
        // خصوم مفتاح التزامن تحديداً (source_id = KEY) — لا نخلط مع replay/conflict
        $charges = LedgerJournalEntry::where('source_type', 'idem_pay')->where('source_id', KEY)->count();
        $keyRows = IdempotencyKey::where('key', KEY)->count();
        $debit  = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $credit = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');

        $ok = ($wallet === '0.0000')
            && ($charges === 1) && ($keyRows === 1) && (bccomp($debit, $credit, 4) === 0);

        echo "=== IDEMPOTENCY RESULT (التزامن) ===\n";
        echo "خصوم بمفتاح التزامن (يجب=1): {$charges}\n";
        echo "صفوف مفتاح idempotency (يجب=1): {$keyRows}\n";
        echo "رصيد محفظة التزامن (يجب=0): {$wallet}\n";
        echo "الدفتر كلّه متوازن: مدين={$debit} دائن={$credit}\n";
        echo $ok ? "VERDICT: PASS ✓ خصم واحد فقط رغم الطلبات المتزامنة\n" : "VERDICT: FAIL ✗\n";
        exit($ok ? 0 : 1);
}
