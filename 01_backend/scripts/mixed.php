<?php
/**
 * AMIAL-MIXED-001 — محاكاة حِمل مختلط على عقدة واحدة (بديل #10).
 *
 * بدل عدّة خوادم، نُشغّل *ثلاثة تدفّقات مالية حقيقية مختلفة* في آنٍ واحد على
 * نفس الدفتر المشترك، ونتحقّق أنّ الدفتر يبقى متوازناً 100% عبرها *مجتمعةً*:
 *   - transfer : تحويلات مباشرة (LedgerService)
 *   - topup    : شحن رصيد وكيل (AgentNetworkService: requestTopup + approveSettlement)
 *   - settle   : دورة تسوية كاملة (UniversalSettlementService: create→…→complete)
 * الثلاثة تتنافس على حسابات النظام المشتركة (CASH_RESERVE، SUSPENSE، محفظة الإيراد).
 *
 * الأوضاع: setup | transfer <n> <start> | topup <n> <start> | settle <n> <start> | check
 * يُشغَّل مع DB_DATABASE=amial_conc
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\EMoney;
use App\Models\AgentProfile;
use App\Models\Settlement;
use App\Models\SettlementPartner;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Services\LedgerService;
use App\Services\AgentNetworkService;
use App\Services\UniversalSettlementService;
use Illuminate\Support\Facades\Artisan;

$ledger   = app(LedgerService::class);
$agentSvc = app(AgentNetworkService::class);
$setSvc   = app(UniversalSettlementService::class);
$mode = $argv[1] ?? 'check';

function waitStart(float $s): void { while (microtime(true) < $s) usleep(300); }

switch ($mode) {
    case 'setup':
        Artisan::call('migrate:fresh', ['--force' => true]);

        // أدمن + معتمِد منفصل (فصل المهام C2)
        $admin    = User::factory()->create(['type' => 0, 'role' => 'super_admin']);
        $approver = User::factory()->create(['type' => 0, 'role' => 'super_admin']);
        file_put_contents('/tmp/mixed_ids.json', json_encode([
            'admin' => $admin->id, 'approver' => $approver->id,
        ]));

        // 5 وكلاء (ملف + محفظة EMoney)
        for ($i = 1; $i <= 5; $i++) {
            $a = User::factory()->create(['type' => 1, 'zone_code' => 'SOUTH']);
            AgentProfile::create([
                'user_id' => $a->id, 'agent_level' => 'independent', 'status' => 'active',
                'daily_cash_in_limit' => '100000000', 'single_transaction_limit' => '50000000',
                'min_float_balance' => '5000', 'commission_rate' => '0.50',
            ]);
            EMoney::create(['user_id' => $a->id, 'current_balance' => '0']);
        }

        // شريك تسوية + محفظة إيراد مموّلة
        SettlementPartner::create([
            'code' => 'MIX_EX', 'name_ar' => 'صرافة', 'type' => SettlementPartner::TYPE_EXCHANGE,
            'currency' => 'YER', 'is_active' => true,
        ]);
        $rev = $ledger->getOrCreateSystemAccount(
            UniversalSettlementService::REVENUE_WALLET_CODE, 'liability', 'إيراد', 'credit'
        );
        $ledger->getOrCreateSystemAccount('MIX_CAP', 'equity', 'رأس مال', 'debit');
        $ledger->post('mix_mint', 'rev', 'تمويل الإيراد', [
            ['account' => 'MIX_CAP',            'direction' => 'debit',  'amount' => '1000000000'],
            ['account' => $rev->account_code,   'direction' => 'credit', 'amount' => '1000000000'],
        ]);

        // 10 محافظ تحويل مموّلة
        for ($i = 1; $i <= 10; $i++) {
            $w = $ledger->getOrCreateUserWallet(5000 + $i);
            $ledger->post('mix_mint', "w{$i}", 'تمويل', [
                ['account' => 'MIX_CAP',        'direction' => 'debit',  'amount' => '10000000'],
                ['account' => $w->account_code, 'direction' => 'credit', 'amount' => '10000000'],
            ]);
        }
        echo "setup done (2 admins, 5 agents, 1 partner, revenue+10 wallets)\n";
        break;

    case 'transfer':
        $n = (int) $argv[2]; waitStart((float) $argv[3]);
        $codes = LedgerAccount::where('account_code', 'like', 'USER_WALLET_%')->pluck('account_code')->all();
        $nw = count($codes); mt_srand(11);
        $err = 0;
        for ($i = 0; $i < $n; $i++) {
            $f = $codes[mt_rand(0, $nw - 1)]; $t = $codes[mt_rand(0, $nw - 1)];
            if ($f === $t) { $i--; continue; }
            $amt = (string) mt_rand(1, 500);
            try {
                $ledger->post('mix_transfer', null, 'x', [
                    ['account' => $f, 'direction' => 'debit',  'amount' => $amt],
                    ['account' => $t, 'direction' => 'credit', 'amount' => $amt],
                ]);
            } catch (\Throwable $e) { $err++; }
        }
        echo "transfer done n={$n} err={$err}\n";
        break;

    case 'topup':
        $n = (int) $argv[2]; waitStart((float) $argv[3]);
        $ids = json_decode(file_get_contents('/tmp/mixed_ids.json'), true);
        $admin = User::find($ids['admin']);
        $agents = User::where('type', 1)->pluck('id')->all();
        mt_srand(22); $err = 0;
        for ($i = 0; $i < $n; $i++) {
            try {
                $agent = User::find($agents[mt_rand(0, count($agents) - 1)]);
                $s = app(AgentNetworkService::class)->requestTopup($agent, (string) mt_rand(100, 5000));
                app(AgentNetworkService::class)->approveSettlement($s, $admin);
            } catch (\Throwable $e) { $err++; }
        }
        echo "topup done n={$n} err={$err}\n";
        break;

    case 'settle':
        $n = (int) $argv[2]; waitStart((float) $argv[3]);
        $ids = json_decode(file_get_contents('/tmp/mixed_ids.json'), true);
        $admin = User::find($ids['admin']); $approver = User::find($ids['approver']);
        $rev = LedgerAccount::where('account_code', UniversalSettlementService::REVENUE_WALLET_CODE)->first();
        $partner = SettlementPartner::where('code', 'MIX_EX')->first();
        $svc = app(UniversalSettlementService::class);
        $err = 0;
        for ($i = 0; $i < $n; $i++) {
            try {
                $s = $svc->create($rev->id, $partner->id, (string) mt_rand(100, 2000), $admin);
                $s = $svc->submit($s, $admin);
                $s = $svc->approve($s, $approver);
                $s = $svc->startProcessing($s, $approver);
                $svc->complete($s, $approver, 'EXT-' . $i . '-' . mt_rand(1000, 9999));
            } catch (\Throwable $e) { $err++; }
        }
        echo "settle done n={$n} err={$err}\n";
        break;

    case 'check':
        $debit  = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $credit = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');
        $balanced = bccomp($debit, $credit, 4) === 0;

        // كل حساب: الرصيد المخزّن = المُعاد حسابه من القيود
        $drift = [];
        foreach (LedgerAccount::all() as $acc) {
            $computed = app(LedgerService::class)->computeBalanceFromLines($acc->id);
            if (bccomp((string) $acc->current_balance, $computed, 4) !== 0) {
                $drift[] = "{$acc->account_code}: مخزّن={$acc->current_balance} محسوب={$computed}";
            }
        }
        $transfers = LedgerEntryLine::whereHas('journalEntry', fn ($q) => $q)->count();
        $entries = \App\Models\Ledger\LedgerJournalEntry::count();
        $byType = \App\Models\Ledger\LedgerJournalEntry::selectRaw('source_type, COUNT(*) c')
            ->groupBy('source_type')->pluck('c', 'source_type')->all();

        echo "=== MIXED-WORKLOAD RESULT ===\n";
        echo "قيود حسب التدفّق: ";
        foreach ($byType as $t => $c) echo "{$t}={$c} ";
        echo "\nإجمالي القيود={$entries}\n";
        echo "الدفتر: مدين={$debit} دائن={$credit} → " . ($balanced ? 'متوازن ✓' : 'غير متوازن ✗') . "\n";
        echo "حسابات بانحراف كاش: " . count($drift) . "\n";
        foreach ($drift as $d) echo "   ✗ {$d}\n";

        $ok = $balanced && empty($drift);
        echo $ok
            ? "VERDICT: PASS ✓ الدفتر متوازن عبر كل التدفّقات المتزامنة، لا انحراف\n"
            : "VERDICT: FAIL ✗\n";
        exit($ok ? 0 : 1);
}
