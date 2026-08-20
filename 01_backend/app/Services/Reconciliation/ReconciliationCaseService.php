<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * يحوّل ناتج المطابقة الليلي إلى قضايا قابلة للمتابعة.
 *
 * لا يصلح فرقاً ولا ينشئ قيد EXTERNAL_ADJUSTMENT. وظيفته الوحيدة أن يحفظ
 * الحقيقة: أي محفظة اختلفت، منذ متى، وكم مرّة، وبأي فرضية أولية.
 */
class ReconciliationCaseService
{
    /** @param list<array<string,mixed>> $rows */
    public function recordWalletResults(array $rows): void
    {
        if (!Schema::hasTable('reconciliation_cases')) {
            return;
        }

        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId < 1) {
                continue;
            }
            if (!($row['diverged'] ?? false)) {
                $this->markVerifying($userId);
                continue;
            }

            $this->recordWalletDivergence($row);
        }
    }

    /** @param array<string,mixed> $row */
    private function recordWalletDivergence(array $row): void
    {
        $userId = (int) $row['user_id'];

        DB::transaction(function () use ($row, $userId): void {
            $case = ReconciliationCase::query()
                ->where('case_type', 'wallet')
                ->where('subject_user_id', $userId)
                ->whereIn('status', ReconciliationCase::OPEN_STATUSES)
                ->lockForUpdate()->first();

            $expected = (string) ($row['wallet_balance'] ?? '0');
            $actual = (string) ($row['ledger_balance'] ?? '0');
            $gap = (string) ($row['gap'] ?? '0');
            $now = now();

            if (!$case) {
                ReconciliationCase::create([
                    'case_ulid' => (string) Str::ulid(),
                    'case_type' => 'wallet',
                    'source' => 'nightly_wallet_reconciliation',
                    'subject_user_id' => $userId,
                    'expected_amount' => $expected,
                    'actual_amount' => $actual,
                    'difference' => $gap,
                    'currency' => 'YER',
                    'status' => 'detected',
                    'severity' => 'warning',
                    'first_detected_at' => $now,
                    'last_detected_at' => $now,
                    'detection_count' => 1,
                    'assigned_team' => 'finance_reconciliation',
                    'root_cause' => $this->classify($row),
                    'evidence' => ['initial_row' => $this->evidence($row)],
                ]);
                return;
            }

            $count = (int) $case->detection_count + 1;
            $case->forceFill([
                'expected_amount' => $expected,
                'actual_amount' => $actual,
                'difference' => $gap,
                'last_detected_at' => $now,
                'detection_count' => $count,
                'severity' => $this->severityFor($count),
                'root_cause' => $case->root_cause ?: $this->classify($row),
                'evidence' => array_merge((array) $case->evidence, ['last_row' => $this->evidence($row)]),
            ])->save();
        });
    }

    private function markVerifying(int $userId): void
    {
        ReconciliationCase::query()
            ->where('case_type', 'wallet')->where('subject_user_id', $userId)
            ->whereIn('status', array_diff(ReconciliationCase::OPEN_STATUSES, ['verifying']))
            ->update([
                'status' => 'verifying',
                'action_taken' => 'لم يظهر الفرق في آخر تشغيل؛ بانتظار تحقق ومراجع قبل الإغلاق.',
                'updated_at' => now(),
            ]);
    }

    /** @param array<string,mixed> $row */
    private function classify(array $row): string
    {
        if (!empty($row['missing_wallet'])) {
            return 'journal_without_wallet';
        }
        if (bccomp((string) ($row['ledger_balance'] ?? '0'), '0', 4) === 0) {
            return 'wallet_without_journal';
        }

        return 'amount_mismatch';
    }

    private function severityFor(int $runs): string
    {
        return $runs >= 3 ? 'critical' : ($runs === 2 ? 'high' : 'warning');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function evidence(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'user_id', 'wallet_balance', 'ledger_balance', 'gap', 'missing_wallet',
        ]));
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 100): array
    {
        if (!Schema::hasTable('reconciliation_cases')) {
            return [];
        }

        return ReconciliationCase::query()->orderByDesc('last_detected_at')
            ->limit(min(max($limit, 1), 200))->get()
            ->map(fn (ReconciliationCase $case) => [
                'case_ulid' => $case->case_ulid,
                'case_type' => $case->case_type,
                'subject_user_id' => $case->subject_user_id,
                'expected_amount' => (string) $case->expected_amount,
                'actual_amount' => (string) $case->actual_amount,
                'difference' => (string) $case->difference,
                'status' => $case->status,
                'severity' => $case->severity,
                'root_cause' => $case->root_cause,
                'first_detected_at' => $case->first_detected_at?->toIso8601String(),
                'last_detected_at' => $case->last_detected_at?->toIso8601String(),
                'detection_count' => (int) $case->detection_count,
            ])->all();
    }
}
