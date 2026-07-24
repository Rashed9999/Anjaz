<?php

namespace App\Console\Commands;

use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-LEDGER-RECON-001 — ضابط القيد المزدوج.
 *
 *   php artisan ledger:reconcile [--json] [--top=10]
 *
 * يفحص ثلاث دعائم:
 *   1) التوازن الكلي: مجموع المدين == مجموع الدائن عبر كل سطور الدفتر.
 *   2) انحراف المحافظ: رصيد EMoney مقابل رصيد حساب الدفتر لكل مستخدم له حساب.
 *   3) قيود مكسورة: قيود مجموع سطورها لا يساوي صفراً (مدين-دائن).
 */
class LedgerReconcile extends Command
{
    protected $signature = 'ledger:reconcile {--json} {--top=10}';
    protected $description = 'تسوية دفتر القيد المزدوج مقابل محافظ EMoney';

    public function handle(): int
    {
        $out = [];

        // 1) التوازن الكلي
        $sums = DB::table('ledger_entry_lines')
            ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END) as debits,
                         SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END) as credits")
            ->first();
        $debits = (string) ($sums->debits ?? '0');
        $credits = (string) ($sums->credits ?? '0');
        $out['global'] = [
            'debits' => $debits,
            'credits' => $credits,
            'balanced' => bccomp($debits, $credits, 4) === 0,
        ];

        // 2) قيود مكسورة (غير متوازنة داخلياً)
        $broken = DB::table('ledger_entry_lines')
            ->select('journal_entry_id')
            ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) as diff")
            ->groupBy('journal_entry_id')
            ->havingRaw('ABS(SUM(CASE WHEN direction = \'debit\' THEN amount ELSE -amount END)) > 0.0001')
            ->count();
        $out['broken_entries'] = $broken;

        // 3) انحراف المحافظ (لمن لديه حساب دفتري)
        $drifts = [];
        LedgerAccount::where('owner_type', 'user')->whereNotNull('owner_user_id')
            ->chunkById(500, function ($accounts) use (&$drifts) {
                foreach ($accounts as $acc) {
                    $ledgerBal = (string) DB::table('ledger_entry_lines')
                        ->where('account_id', $acc->id)
                        ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) as bal")
                        ->value('bal');
                    $wallet = EMoney::where('user_id', $acc->owner_user_id)->first();
                    $walletBal = (string) ($wallet->current_balance ?? '0');
                    if (bccomp($ledgerBal, $walletBal, 2) !== 0) {
                        $drifts[] = [
                            'user_id' => $acc->owner_user_id,
                            'wallet' => $walletBal,
                            'ledger' => $ledgerBal,
                            'diff' => bcsub($walletBal, $ledgerBal, 4),
                        ];
                    }
                }
            });
        usort($drifts, fn ($a, $b) => abs((float) $b['diff']) <=> abs((float) $a['diff']));
        $out['wallet_drift'] = [
            'accounts_checked' => LedgerAccount::where('owner_type', 'user')->count(),
            'drifted' => count($drifts),
            'top' => array_slice($drifts, 0, (int) $this->option('top')),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $g = $out['global'];
            $this->info('— التوازن الكلي —');
            $this->line("  مدين: {$g['debits']} | دائن: {$g['credits']} | " . ($g['balanced'] ? '✅ متوازن' : '❌ غير متوازن'));
            $this->info("— قيود مكسورة داخلياً: {$broken} " . ($broken === 0 ? '✅' : '❌'));
            $w = $out['wallet_drift'];
            $this->info("— انحراف المحافظ: {$w['drifted']} من {$w['accounts_checked']} حساباً");
            foreach ($w['top'] as $d) {
                $this->line("  user {$d['user_id']}: محفظة {$d['wallet']} / دفتر {$d['ledger']} (فرق {$d['diff']})");
            }
            $this->comment('ملاحظة: الانحراف متوقَّع للمسارات غير الموصولة بعد — الهدف تقليصه لصفر تدريجياً.');
        }

        // فشل الأمر فقط عند كسر جوهر القيد المزدوج (لا عند انحراف التغطية)
        return ($out['global']['balanced'] && $broken === 0) ? self::SUCCESS : self::FAILURE;
    }
}
