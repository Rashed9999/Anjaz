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

    /**
     * AMIAL-LEDGER-DRIFT-CASE-001 — **الرقمُ يُعرَض ولا يُتابَع.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ما تطلبه الوثيقةُ نصّاً في المحور ٢٢:**
     *
     *     إذا `computed_balance ≠ current_balance` — **افتح Case.**
     *     لا تستخدم auto-update فقط. حدّد لماذا انحرف الـcached balance.
     *
     * وقِيس: ميزانُ المراجعة **يحسب الانحرافَ ويعرضه** (`drifted_accounts`
     * ولافتةٌ صفراءُ أعلى الصفحة) — **ولا يُفتح له شيء**. فهو رقمٌ يراه
     * من فتح الصفحة اليوم، ويختفي حين يُغلقها، ولا يُسأل عنه أحدٌ غداً.
     *
     * **والفرقُ بين «يُعرَض» و«يُتابَع» هو الفرقُ بين لوحةٍ ومركزِ رقابة.**
     * قضيّةٌ تحمل رقماً وتاريخَ أوّلِ ظهورٍ وعددَ مرّاتٍ وتتصعّد بالتكرار
     * تُجبر على جوابٍ؛ ورقمٌ في بطاقةٍ يُقرأ ويُنسى.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولا يُصلَح تلقائيّاً** — والوثيقةُ تقولها صراحةً. تحديثُ العمود
     * ليطابق السطورَ **يمحو الدليلَ ويترك السبب**: يعود الانحرافُ بعد
     * أسبوعٍ ولا يُعرف من أين جاء، لأنّ ما كان يدلّ عليه مُسح.
     *
     * @param  array<int,array{account_code:string,account_id:int,stored:string,computed:string,drift:string}>  $rows
     */
    public function recordLedgerDrift(array $rows): void
    {
        if (! Schema::hasTable('reconciliation_cases')) {
            return;
        }

        $seen = [];

        foreach ($rows as $row) {
            $accountId = (int) ($row['account_id'] ?? 0);

            if ($accountId < 1) {
                continue;
            }

            $seen[] = $accountId;
            $this->upsertLedgerDriftCase($row, $accountId);
        }

        // **وقضيّةٌ لم يعد فرقُها ظاهراً لا تُغلَق تلقائيّاً.** اختفاءُ
        // الفرق قد يكون إصلاحاً وقد يكون تعديلاً مباشراً على الجدول محا
        // الأثر. فتُوسَم `verifying` وينتظر مراجعاً — كما في المحافظ.
        ReconciliationCase::query()
            ->where('case_type', 'ledger_drift')
            ->whereIn('status', array_diff(ReconciliationCase::OPEN_STATUSES, ['verifying']))
            ->when($seen !== [], fn ($q) => $q->whereNotIn('ledger_account_id', $seen))
            ->update([
                'status' => 'verifying',
                'action_taken' => 'لم يظهر انحرافُ العمود في آخر تشغيل؛ '
                    . 'بانتظار تحقّقٍ ومراجعٍ قبل الإغلاق.',
                'updated_at' => now(),
            ]);
    }

    /** @param array<string,mixed> $row */
    private function upsertLedgerDriftCase(array $row, int $accountId): void
    {
        DB::transaction(function () use ($row, $accountId): void {
            $case = ReconciliationCase::query()
                ->where('case_type', 'ledger_drift')
                ->where('ledger_account_id', $accountId)
                ->whereIn('status', ReconciliationCase::OPEN_STATUSES)
                ->lockForUpdate()->first();

            $computed = (string) ($row['computed'] ?? '0');
            $stored = (string) ($row['stored'] ?? '0');
            $gap = (string) ($row['drift'] ?? bcsub($computed, $stored, 4));
            $now = now();

            $evidence = [
                'account_code' => (string) ($row['account_code'] ?? '—'),
                // **المحسوبُ هو المتوقَّع لا المخزَّن.** السطورُ هي الحقيقة،
                // والعمودُ نسخةٌ منها. وعكسُ الاتّجاه هنا يجعل التحقيقَ
                // يبحث عن خطأٍ في القيود بدل أن يبحث عمّا أفسد العمود.
                'expected_source' => 'مجموع سطور القيود',
                'actual_source' => 'ledger_accounts.current_balance',
            ];

            if (! $case) {
                ReconciliationCase::create([
                    'case_ulid' => (string) Str::ulid(),
                    'case_type' => 'ledger_drift',
                    'source' => 'nightly_ledger_drift',
                    'ledger_account_id' => $accountId,
                    'expected_amount' => $computed,
                    'actual_amount' => $stored,
                    'difference' => $gap,
                    'currency' => 'YER',
                    'status' => 'detected',
                    'severity' => 'warning',
                    'first_detected_at' => $now,
                    'last_detected_at' => $now,
                    'detection_count' => 1,
                    'assigned_team' => 'finance_reconciliation',
                    'root_cause' => 'cached_balance_drift',
                    'evidence' => ['initial' => $evidence],
                ]);

                return;
            }

            $count = (int) $case->detection_count + 1;
            $case->forceFill([
                'expected_amount' => $computed,
                'actual_amount' => $stored,
                'difference' => $gap,
                'last_detected_at' => $now,
                'detection_count' => $count,
                // **فرقٌ يتكرّر ليلةً بعد ليلةٍ ليس تقريباً** — يتصعّد.
                'severity' => $this->severityFor($count),
                'evidence' => array_merge((array) $case->evidence, ['last' => $evidence]),
            ])->save();
        });
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

    /**
     * Cash is reconciled by physical custody: branch safe and teller drawer.
     * Repeated observations attach to the same open case; this method never
     * changes cash, a till balance, or a journal.
     *
     * @param list<array<string,mixed>> $rows
     */
    public function recordCashResults(array $rows): void
    {
        if (!Schema::hasTable('reconciliation_cases')
            || !Schema::hasColumn('reconciliation_cases', 'shift_id')) {
            // During a rolling deploy the code can arrive before the migration.
            // Skipping this run is safer than persisting a case without its
            // custody dimension.
            return;
        }

        foreach ($rows as $row) {
            $dimension = (array) ($row['dimension'] ?? []);
            $branchId = (int) ($dimension['branch_id'] ?? 0);
            $kind = (string) ($row['kind'] ?? '');
            if ($branchId < 1 || !in_array($kind, ['branch_safe', 'teller_drawer'], true)) {
                continue;
            }

            // Drawer cases require the new dimension column. Until its
            // migration is applied we skip that dimension rather than guess.
            if ($kind === 'teller_drawer' && !Schema::hasColumn('reconciliation_cases', 'shift_id')) {
                continue;
            }

            DB::transaction(function () use ($row, $dimension, $branchId, $kind): void {
                $query = ReconciliationCase::query()
                    ->where('case_type', $kind === 'branch_safe' ? 'cash_till' : 'cash_drawer')
                    ->where('branch_id', $branchId)
                    ->whereIn('status', ReconciliationCase::OPEN_STATUSES)
                    ->lockForUpdate();

                if ($kind === 'branch_safe') {
                    $query->where('till_id', (int) ($dimension['till_id'] ?? 0));
                } else {
                    $query->where('shift_id', (int) ($dimension['shift_id'] ?? 0));
                }

                $case = $query->first();
                $expected = (string) ($row['expected'] ?? '0');
                $actual = (string) ($row['held'] ?? '0');
                $difference = (string) ($row['gap'] ?? '0');
                $evidence = ['last_cash_snapshot' => [
                    'kind' => $kind, 'dimension' => $dimension,
                    'expected' => $expected, 'actual' => $actual, 'difference' => $difference,
                ]];

                if (!$case) {
                    $case = ReconciliationCase::create([
                        'case_ulid' => (string) Str::ulid(),
                        'case_type' => $kind === 'branch_safe' ? 'cash_till' : 'cash_drawer',
                        'source' => 'nightly_cash_reconciliation',
                        'branch_id' => $branchId,
                        'till_id' => $kind === 'branch_safe' ? (int) ($dimension['till_id'] ?? 0) : null,
                        'shift_id' => $kind === 'teller_drawer' ? (int) ($dimension['shift_id'] ?? 0) : null,
                        'expected_amount' => $expected, 'actual_amount' => $actual, 'difference' => $difference,
                        'currency' => 'YER', 'status' => 'detected', 'severity' => 'high',
                        'first_detected_at' => now(), 'last_detected_at' => now(), 'detection_count' => 1,
                        'assigned_team' => 'finance_reconciliation',
                        'root_cause' => 'cash_custody_variance', 'evidence' => $evidence,
                    ]);
                    app(\App\Services\AuditService::class)->record([
                        'actor_type' => 'system', 'subject_type' => 'reconciliation_case',
                        'subject_id' => $case->case_ulid, 'action' => 'RECONCILIATION_CASE_OPENED',
                        'decision_code' => strtoupper($case->case_type), 'severity' => 'high',
                        'context' => ['branch_id' => $branchId, 'difference' => $difference],
                    ]);
                    return;
                }

                $count = (int) $case->detection_count + 1;
                $case->forceFill([
                    'expected_amount' => $expected, 'actual_amount' => $actual, 'difference' => $difference,
                    'last_detected_at' => now(), 'detection_count' => $count,
                    'severity' => $this->severityFor($count),
                    'evidence' => array_merge((array) $case->evidence, $evidence),
                ])->save();
                app(\App\Services\AuditService::class)->record([
                    'actor_type' => 'system', 'subject_type' => 'reconciliation_case',
                    'subject_id' => $case->case_ulid, 'action' => 'RECONCILIATION_CASE_SEEN_AGAIN',
                    'decision_code' => strtoupper($case->case_type), 'severity' => $case->severity,
                    'context' => ['branch_id' => $branchId, 'difference' => $difference, 'detections' => $count],
                ]);
            });
        }
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
                // **ليس لكلّ قضيّةٍ عميل.** قضيّةُ انحراف عمودٍ موضوعُها
                // حسابٌ في الدفتر، وقضيّةُ خزنةٍ موضوعُها درج. وصفٌّ يعرض
                // `#null` في عمود «العميل» يُقرأ عطلاً في الشاشة لا نوعاً
                // آخرَ من القضايا — **فيُشكّ في الأداة بدل أن يُحقَّق في
                // الفرق.** فيُرسَل معها ما يُسمّي موضوعَها أيّاً كان.
                'subject_label' => match ($case->case_type) {
                    'ledger_drift' => 'حساب: '.($case->ledgerAccountCode() ?? '#'.$case->ledger_account_id),
                    'cash_till', 'cash_drawer' => 'خزنة #'.($case->till_id ?? $case->branch_id ?? '—'),
                    default => $case->subject_user_id ? 'عميل #'.$case->subject_user_id : '—',
                },
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
