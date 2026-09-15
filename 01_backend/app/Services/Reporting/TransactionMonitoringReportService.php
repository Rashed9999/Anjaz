<?php

namespace App\Services\Reporting;

use App\Support\Money\Currencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-REPORTING-CENTER-003 — مراقبة الحركة والاستثناءات.
 *
 * الحجم المالي يأتي من Journal entries لا من جمع صفوف transactions التي
 * قد تمثل العملية الواحدة بعدة صفوف (مرسل/مستلم/رسوم). والاستثناءات تُقرأ
 * من مصادر حالتها الأصلية: pending_transfers، القيود العكسية، وAudit.
 */
class TransactionMonitoringReportService
{
    public function volume(?string $from = null, ?string $to = null): array
    {
        $from ??= now()->startOfMonth()->toDateString();
        $to ??= now()->toDateString();

        if (! Schema::hasTable('ledger_journal_entries')) {
            return $this->unavailable('transaction_volume', 'ledger_journal_entries');
        }

        $rows = DB::table('ledger_journal_entries')
            ->whereIn('status', ['posted', 'reversed'])
            ->whereBetween('posted_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->groupBy('currency', 'source_type', 'is_reversal')
            ->selectRaw("COALESCE(currency, ?) AS currency, source_type, is_reversal,
                COUNT(*) AS entries_count, COALESCE(SUM(total_amount),0) AS volume", [Currencies::BASE])
            ->orderBy('currency')->orderByDesc('volume')->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            $currency = (string) ($row->currency ?: Currencies::BASE);
            $bucket = &$byCurrency[$currency];
            $bucket ??= [
                'currency' => $currency,
                'original_entries' => 0,
                'reversal_entries' => 0,
                'gross_original_volume' => '0.0000',
                'reversal_volume' => '0.0000',
                'net_after_reversals' => '0.0000',
                'by_source' => [],
            ];

            $count = (int) $row->entries_count;
            $volume = (string) $row->volume;
            $isReversal = (bool) $row->is_reversal;
            if ($isReversal) {
                $bucket['reversal_entries'] += $count;
                $bucket['reversal_volume'] = bcadd($bucket['reversal_volume'], $volume, 4);
            } else {
                $bucket['original_entries'] += $count;
                $bucket['gross_original_volume'] = bcadd($bucket['gross_original_volume'], $volume, 4);
            }
            $bucket['by_source'][] = [
                'source_type' => (string) $row->source_type,
                'entries' => $count,
                'volume' => $volume,
                'is_reversal' => $isReversal,
            ];
        }
        unset($bucket);

        foreach ($byCurrency as &$bucket) {
            $bucket['net_after_reversals'] = bcsub(
                $bucket['gross_original_volume'], $bucket['reversal_volume'], 4,
            );
        }
        unset($bucket);
        ksort($byCurrency);

        return [
            'report' => 'transaction_volume',
            'from' => $from,
            'to' => $to,
            'basis' => 'ledger_journal_entries',
            'definition' => 'one journal entry is one accounting event; reversal volume is shown separately',
            'currency_policy' => 'separate_per_currency_no_conversion',
            'by_currency' => array_values($byCurrency),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function exceptions(?string $from = null, ?string $to = null, int $limit = 50): array
    {
        $from ??= now()->startOfMonth()->toDateString();
        $to ??= now()->toDateString();
        $limit = min(200, max(10, $limit));

        $pending = $this->pendingTransfers($from, $to, $limit);
        $reversals = $this->reversals($from, $to, $limit);
        $rejections = $this->rejections($from, $to, $limit);
        $approvals = $this->financialApprovals($from, $to, $limit);

        return [
            'report' => 'failed_reversed_pending',
            'from' => $from,
            'to' => $to,
            'definitions' => [
                'pending' => 'pending_transfers.status=holding; overdue means releasable_at has passed',
                'reversed' => 'ledger_journal_entries.is_reversal=1',
                'rejected' => 'financial audit decisions that are not success/holding/cancel states',
                'approvals' => 'maker-checker financial requests for treasury issuance',
            ],
            'pending_transfers' => $pending,
            'reversals' => $reversals,
            'rejections' => $rejections,
            'financial_approvals' => $approvals,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function pendingTransfers(string $from, string $to, int $limit): array
    {
        if (! Schema::hasTable('pending_transfers')) {
            return ['available' => false, 'reason' => 'pending_transfers missing'];
        }

        $base = DB::table('pending_transfers')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

        $summary = (clone $base)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS count, COALESCE(SUM(amount),0) AS amount')
            ->get()->map(fn ($r) => [
                'status' => (string) $r->status,
                'count' => (int) $r->count,
                'amount' => (string) $r->amount,
            ])->all();

        $overdue = (clone $base)
            ->where('status', 'holding')
            ->where('releasable_at', '<=', now())
            ->count();

        $recent = (clone $base)
            ->whereIn('status', ['holding', 'failed', 'cancelled'])
            ->orderByDesc('id')->limit($limit)
            ->get([
                'transfer_ulid', 'sender_user_id', 'recipient_user_id', 'amount', 'fee',
                'status', 'releasable_at', 'completed_at', 'cancelled_at',
                'cancellation_reason', 'created_at',
            ])->map(fn ($r) => [
                'transfer_ulid' => (string) $r->transfer_ulid,
                'sender_user_id' => (int) $r->sender_user_id,
                'recipient_user_id' => (int) $r->recipient_user_id,
                'amount' => (string) $r->amount,
                'fee' => (string) $r->fee,
                'status' => (string) $r->status,
                'releasable_at' => $r->releasable_at,
                'completed_at' => $r->completed_at,
                'cancelled_at' => $r->cancelled_at,
                'reason' => $r->cancellation_reason,
                'created_at' => $r->created_at,
            ])->all();

        return [
            'available' => true,
            'summary' => $summary,
            'overdue_holding' => $overdue,
            'recent' => $recent,
        ];
    }

    private function reversals(string $from, string $to, int $limit): array
    {
        if (! Schema::hasTable('ledger_journal_entries')) {
            return ['available' => false, 'reason' => 'ledger_journal_entries missing'];
        }

        $base = DB::table('ledger_journal_entries')
            ->where('is_reversal', 1)
            ->whereBetween('posted_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

        $byCurrency = (clone $base)->groupBy('currency')
            ->selectRaw('COALESCE(currency, ?) AS currency, COUNT(*) AS count, COALESCE(SUM(total_amount),0) AS amount', [Currencies::BASE])
            ->get()->map(fn ($r) => [
                'currency' => (string) $r->currency,
                'count' => (int) $r->count,
                'amount' => (string) $r->amount,
            ])->all();

        $recent = (clone $base)->orderByDesc('posted_at')->limit($limit)
            ->get(['entry_ulid', 'source_type', 'source_id', 'currency', 'total_amount', 'reverses_entry_id', 'posted_at'])
            ->map(fn ($r) => [
                'entry_ulid' => (string) $r->entry_ulid,
                'source_type' => (string) $r->source_type,
                'source_id' => $r->source_id,
                'currency' => (string) ($r->currency ?: Currencies::BASE),
                'amount' => (string) $r->total_amount,
                'reverses_entry_id' => $r->reverses_entry_id,
                'posted_at' => $r->posted_at,
            ])->all();

        return ['available' => true, 'by_currency' => $byCurrency, 'recent' => $recent];
    }

    private function rejections(string $from, string $to, int $limit): array
    {
        if (! Schema::hasTable('audit_decisions')) {
            return ['available' => false, 'reason' => 'audit_decisions missing'];
        }

        $successCodes = [
            'ALLOW', 'OK', 'TX_OK', 'SUCCESS', 'APPROVED', 'COMPLETED',
            'HOLDING', 'CANCELLED', 'RELEASED', 'SENT', 'DELIVERED',
        ];

        $base = DB::table('audit_decisions')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->whereNotIn('decision_code', $successCodes)
            ->where(function ($q): void {
                $q->whereNotNull('transaction_id')
                    ->orWhere('decision_code', 'like', 'TX_%')
                    ->orWhere('action', 'like', '%TRANSFER%')
                    ->orWhere('action', 'like', '%PAYMENT%')
                    ->orWhere('action', 'like', '%WITHDRAW%')
                    ->orWhere('action', 'like', '%SETTLEMENT%')
                    ->orWhere('action', 'like', '%TREASURY%');
            });

        $byCode = (clone $base)->groupBy('decision_code')
            ->selectRaw('decision_code, COUNT(*) AS count')
            ->orderByDesc('count')->limit(30)->get()
            ->map(fn ($r) => ['decision_code' => (string) $r->decision_code, 'count' => (int) $r->count])
            ->all();

        $recent = (clone $base)->orderByDesc('id')->limit($limit)
            ->get([
                'decision_id', 'actor_type', 'actor_user_id', 'subject_type', 'subject_id',
                'action', 'decision_code', 'reason', 'transaction_id', 'severity', 'created_at',
            ])->map(fn ($r) => [
                'decision_id' => (string) $r->decision_id,
                'actor_type' => $r->actor_type,
                'actor_user_id' => $r->actor_user_id,
                'subject_type' => $r->subject_type,
                'subject_id' => $r->subject_id,
                'action' => (string) $r->action,
                'decision_code' => (string) $r->decision_code,
                'reason' => $r->reason,
                'transaction_id' => $r->transaction_id,
                'severity' => $r->severity,
                'created_at' => $r->created_at,
            ])->all();

        return ['available' => true, 'count' => (clone $base)->count(), 'by_code' => $byCode, 'recent' => $recent];
    }

    private function financialApprovals(string $from, string $to, int $limit): array
    {
        if (! Schema::hasTable('approval_requests')) {
            return ['available' => false, 'reason' => 'approval_requests missing'];
        }

        $base = DB::table('approval_requests')
            ->where('action_type', 'treasury_issuance')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

        $summary = (clone $base)->groupBy('status')->selectRaw('status, COUNT(*) AS count')
            ->get()->map(fn ($r) => ['status' => (string) $r->status, 'count' => (int) $r->count])->all();

        $recent = (clone $base)->whereIn('status', ['pending', 'failed', 'rejected'])
            ->orderByDesc('id')->limit($limit)
            ->get(['request_number', 'status', 'maker_admin_id', 'checker_admin_id', 'reason', 'expires_at', 'created_at'])
            ->map(fn ($r) => [
                'request_number' => (string) $r->request_number,
                'status' => (string) $r->status,
                'maker_admin_id' => $r->maker_admin_id,
                'checker_admin_id' => $r->checker_admin_id,
                'reason' => $r->reason,
                'expires_at' => $r->expires_at,
                'created_at' => $r->created_at,
            ])->all();

        return ['available' => true, 'summary' => $summary, 'recent' => $recent];
    }

    private function unavailable(string $report, string $table): array
    {
        return [
            'report' => $report,
            'available' => false,
            'reason' => "Financial data unavailable: missing {$table}",
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
