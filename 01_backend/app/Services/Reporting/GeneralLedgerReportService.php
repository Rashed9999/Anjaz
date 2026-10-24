<?php

namespace App\Services\Reporting;

use App\Support\Money\Currencies;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * AMIAL-REPORTING-CENTER-005 — دفتر الأستاذ القابل للتتبّع.
 *
 * كل صف يعرض رأس القيد وسُطوره والحسابات التي كوّنته. لا تعديل هنا؛
 * الدفتر append-only، والتصحيح بقيد عكسي فقط.
 */
class GeneralLedgerReportService
{
    /** @return array<string,mixed> */
    public function report(array $filters = []): array
    {
        $this->assertAvailable();
        $limit = min(100, max(10, (int) ($filters['limit'] ?? 30)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = DB::table('ledger_journal_entries as e');
        $this->applyFilters($query, $filters);

        $total = (clone $query)->count();
        $entries = $query
            ->orderByDesc('e.posted_at')
            ->orderByDesc('e.id')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get([
                'e.id', 'e.entry_ulid', 'e.source_type', 'e.source_id',
                'e.idempotency_key', 'e.description_ar', 'e.total_amount',
                'e.currency', 'e.is_reversal', 'e.reverses_entry_id',
                'e.reversed_by_entry_id', 'e.status', 'e.created_by_user_id',
                'e.zone_code', 'e.posted_at',
            ]);

        $entryIds = $entries->pluck('id')->map(fn ($id) => (int) $id)->all();
        $linesByEntry = $entryIds === [] ? collect() : DB::table('ledger_entry_lines as l')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.journal_entry_id', $entryIds)
            ->orderBy('l.id')
            ->get([
                'l.journal_entry_id', 'l.id', 'l.direction', 'l.amount',
                'l.balance_before', 'l.balance_after', 'l.description_ar',
                'a.id as account_id', 'a.account_code', 'a.name_ar',
                'a.account_type', 'a.normal_balance', 'a.currency',
            ])->groupBy('journal_entry_id');

        $items = $entries->map(function ($entry) use ($linesByEntry): array {
            $lines = ($linesByEntry[(int) $entry->id] ?? collect())->map(fn ($line) => [
                'line_id' => (int) $line->id,
                'account_id' => (int) $line->account_id,
                'account_code' => (string) $line->account_code,
                'account_name' => (string) $line->name_ar,
                'account_type' => (string) $line->account_type,
                'normal_balance' => (string) $line->normal_balance,
                'currency' => (string) ($line->currency ?: Currencies::BASE),
                'direction' => (string) $line->direction,
                'amount' => (string) $line->amount,
                'balance_before' => (string) $line->balance_before,
                'balance_after' => (string) $line->balance_after,
                'description' => $line->description_ar,
            ])->all();

            $debit = '0.0000';
            $credit = '0.0000';
            foreach ($lines as $line) {
                if ($line['direction'] === 'debit') {
                    $debit = bcadd($debit, $line['amount'], 4);
                } else {
                    $credit = bcadd($credit, $line['amount'], 4);
                }
            }

            return [
                'id' => (int) $entry->id,
                'entry_ulid' => (string) $entry->entry_ulid,
                'source_type' => (string) $entry->source_type,
                'source_id' => $entry->source_id,
                'idempotency_key' => $entry->idempotency_key,
                'description' => $entry->description_ar,
                'currency' => (string) ($entry->currency ?: Currencies::BASE),
                'status' => (string) $entry->status,
                'is_reversal' => (bool) $entry->is_reversal,
                'reverses_entry_id' => $entry->reverses_entry_id,
                'reversed_by_entry_id' => $entry->reversed_by_entry_id,
                'created_by_user_id' => $entry->created_by_user_id,
                'zone_code' => $entry->zone_code,
                'posted_at' => (string) $entry->posted_at,
                'debit_total' => $debit,
                'credit_total' => $credit,
                'balanced' => bccomp($debit, $credit, 4) === 0,
                'lines' => $lines,
                'audit_events' => $this->auditCount($entry),
            ];
        })->all();

        return [
            'report' => 'general_ledger',
            'basis' => 'ledger_journal_entries + ledger_entry_lines',
            'immutable' => true,
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'currency' => $filters['currency'] ?? null,
                'source_type' => $filters['source_type'] ?? null,
                'account_code' => $filters['account_code'] ?? null,
                'search' => $filters['search'] ?? null,
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $total === 0 ? 0 : (int) ceil($total / $limit),
            ],
            'items' => $items,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query->whereIn('e.status', ['posted', 'reversed']);
        if (! empty($filters['from'])) {
            $query->where('e.posted_at', '>=', $filters['from'] . ' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('e.posted_at', '<=', $filters['to'] . ' 23:59:59');
        }
        if (! empty($filters['currency'])) {
            $query->where('e.currency', strtoupper((string) $filters['currency']));
        }
        if (! empty($filters['source_type'])) {
            $query->where('e.source_type', (string) $filters['source_type']);
        }
        if (! empty($filters['account_code'])) {
            $accountCode = (string) $filters['account_code'];
            $query->whereExists(function ($sub) use ($accountCode): void {
                $sub->selectRaw('1')
                    ->from('ledger_entry_lines as fl')
                    ->join('ledger_accounts as fa', 'fa.id', '=', 'fl.account_id')
                    ->whereColumn('fl.journal_entry_id', 'e.id')
                    ->where('fa.account_code', $accountCode);
            });
        }
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->where('e.entry_ulid', 'like', '%' . $search . '%')
                    ->orWhere('e.source_id', 'like', '%' . $search . '%')
                    ->orWhere('e.idempotency_key', 'like', '%' . $search . '%')
                    ->orWhere('e.description_ar', 'like', '%' . $search . '%');
            });
        }
    }

    private function auditCount(object $entry): int
    {
        if (! Schema::hasTable('audit_decisions')) {
            return 0;
        }
        return DB::table('audit_decisions')
            ->where(function ($q) use ($entry): void {
                $q->where('transaction_id', (string) $entry->entry_ulid)
                    ->orWhere('subject_id', (string) $entry->entry_ulid);
                if ($entry->source_id !== null && $entry->source_id !== '') {
                    $q->orWhere('transaction_id', (string) $entry->source_id)
                        ->orWhere('subject_id', (string) $entry->source_id);
                }
                if ($entry->idempotency_key !== null && $entry->idempotency_key !== '') {
                    $q->orWhere('idempotency_key', (string) $entry->idempotency_key);
                }
            })->count();
    }

    private function assertAvailable(): void
    {
        foreach (['ledger_accounts', 'ledger_journal_entries', 'ledger_entry_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("General ledger unavailable: missing {$table}");
            }
        }
    }
}
