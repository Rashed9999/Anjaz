<?php

namespace App\Services;

use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-LEDGER-001 (v1.7)
 *
 * LedgerService — محرك القيد المزدوج (double-entry).
 *
 * **الاستخدام الأساسي:**
 *   $ledger->post(
 *     sourceType: 'send_money',
 *     sourceId: $txId,
 *     description: 'تحويل من أحمد إلى محمد',
 *     lines: [
 *       ['account' => "USER_WALLET_{$sender->id}", 'direction' => 'debit', 'amount' => '100'],
 *       ['account' => "USER_WALLET_{$receiver->id}", 'direction' => 'credit', 'amount' => '100'],
 *     ],
 *   );
 *
 * **الضمانات:**
 *   - يتحقق أن مجموع debits = مجموع credits (التوازن)
 *   - يقفل الحسابات (lockForUpdate) لمنع race conditions
 *   - يسجل balance_before/after لكل سطر (snapshot)
 *   - idempotent (نفس idempotency_key لا يُسجَّل مرتين)
 *   - append-only (لا تعديل، لا حذف)
 *
 * **التصحيح:**
 *   $ledger->reverse($entryId, 'سبب التصحيح');
 *   → ينشئ قيداً عكسياً (لا يحذف الأصلي)
 */
class LedgerService
{
    /**
     * تسجيل journal entry بقيود متوازنة.
     *
     * @param array $lines [['account' => code, 'direction' => debit|credit, 'amount' => string, 'description' => ?], ...]
     * @throws RuntimeException عند عدم التوازن أو رصيد غير كافٍ
     */
    public function post(
        string $sourceType,
        ?string $sourceId,
        string $description,
        array $lines,
        ?string $idempotencyKey = null,
        ?int $createdByUserId = null,
        array $metadata = [],
        string $zoneCode = 'SOUTH',
        bool $allowNegative = false,
        bool $isReversal = false,
        ?int $reversesEntryId = null,
    ): LedgerJournalEntry {
        if (count($lines) < 2) {
            throw new RuntimeException('Journal entry requires at least 2 lines (double-entry)');
        }

        // فحص idempotency
        if ($idempotencyKey) {
            $existing = LedgerJournalEntry::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing; // تم تسجيله مسبقاً
            }
        }

        // التحقق من التوازن قبل أي شيء
        $this->assertBalanced($lines);

        return DB::transaction(function () use (
            $sourceType, $sourceId, $description, $lines,
            $idempotencyKey, $createdByUserId, $metadata, $zoneCode, $allowNegative,
            $isReversal, $reversesEntryId,
        ) {
            // إنشاء الرأس
            $totalDebit = $this->sumByDirection($lines, 'debit');
            $entry = LedgerJournalEntry::create([
                'entry_ulid' => (string) Str::ulid(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'description_ar' => $description,
                'total_amount' => $totalDebit,
                'is_reversal' => $isReversal,
                'reverses_entry_id' => $reversesEntryId,
                'status' => 'posted',
                'created_by_user_id' => $createdByUserId,
                'zone_code' => $zoneCode,
                'metadata' => $metadata,
                'posted_at' => now(),
                'created_at' => now(),
            ]);

            // تسجيل كل سطر مع قفل الحساب
            foreach ($lines as $line) {
                $account = LedgerAccount::where('account_code', $line['account'])
                    ->lockForUpdate()
                    ->first();

                if (!$account) {
                    throw new RuntimeException("Ledger account not found: {$line['account']}");
                }

                $amount = (string) $line['amount'];
                if (bccomp($amount, '0', 4) <= 0) {
                    throw new RuntimeException('Line amount must be positive');
                }

                $balanceBefore = (string) $account->current_balance;
                $balanceAfter = $account->applyDirection($balanceBefore, $line['direction'], $amount);

                // فحص الرصيد السالب (لحسابات الأصول مثل user wallets)
                if (!$allowNegative && in_array($account->account_type, ['asset', 'liability'], true)
                    && bccomp($balanceAfter, '0', 4) < 0) {
                    throw new RuntimeException(
                        "Insufficient balance in account {$account->account_code}: "
                        . "{$balanceBefore} cannot support {$line['direction']} of {$amount}"
                    );
                }

                LedgerEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'direction' => $line['direction'],
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description_ar' => $line['description'] ?? null,
                    'metadata' => $line['metadata'] ?? null,
                    'created_at' => now(),
                ]);

                // تحديث الرصيد المخزّن (cached)
                $account->current_balance = $balanceAfter;
                $account->save();
            }

            return $entry;
        });
    }

    /**
     * عكس قيد (التصحيح المحاسبي الصحيح — لا حذف).
     *
     * ينشئ entry جديد بقيود معكوسة (debit ↔ credit) ويربطه بالأصلي.
     */
    public function reverse(int $journalEntryId, string $reason, ?int $adminUserId = null): LedgerJournalEntry
    {
        return DB::transaction(function () use ($journalEntryId, $reason, $adminUserId) {
            $original = LedgerJournalEntry::with('lines')->lockForUpdate()->find($journalEntryId);
            if (!$original) {
                throw new RuntimeException('Original entry not found');
            }
            if ($original->status === 'reversed') {
                throw new RuntimeException('Entry already reversed');
            }
            if ($original->is_reversal) {
                throw new RuntimeException('Cannot reverse a reversal entry');
            }

            // بناء السطور المعكوسة
            $reversedLines = $original->lines->map(function (LedgerEntryLine $line) {
                return [
                    'account' => $line->account->account_code,
                    'direction' => $line->direction === 'debit' ? 'credit' : 'debit',
                    'amount' => (string) $line->amount,
                    'description' => 'عكس: ' . ($line->description_ar ?? ''),
                ];
            })->toArray();

            // تسجيل القيد العكسي
            $reversal = $this->post(
                sourceType: $original->source_type . '_reversal',
                sourceId: $original->source_id,
                description: "قيد عكسي للمعاملة {$original->entry_ulid}: {$reason}",
                lines: $reversedLines,
                createdByUserId: $adminUserId,
                metadata: ['reverses' => $original->id, 'reason' => $reason],
                zoneCode: $original->zone_code,
                allowNegative: true, // العكس قد يُنزل رصيد مؤقتاً
                isReversal: true,
                reversesEntryId: $original->id,
            );

            // علم الأصلي كـ reversed
            $original->status = 'reversed';
            $original->reversed_by_entry_id = $reversal->id;
            $original->save();

            return $reversal;
        });
    }

    /**
     * إنشاء أو جلب حساب user wallet.
     */
    public function getOrCreateUserWallet(int $userId, string $zoneCode = 'SOUTH'): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['account_code' => "USER_WALLET_{$userId}"],
            [
                'account_type' => 'liability', // MERGE-FIX: محفظة المستخدم = التزام على المنصّة
                'name_ar' => "محفظة المستخدم {$userId}",
                'owner_user_id' => $userId,
                'owner_type' => 'user',
                'normal_balance' => 'credit', // liability => credit-normal (credit=دخول/زيادة)
                'current_balance' => '0',
                'zone_code' => $zoneCode,
            ],
        );
    }

    /**
     * جلب حساب نظام (PLATFORM_FEE, ESCROW_HOLD, ...).
     */
    public function getOrCreateSystemAccount(string $code, string $type, string $name, string $normal = 'credit'): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['account_code' => $code],
            [
                'account_type' => $type,
                'name_ar' => $name,
                'owner_type' => 'platform',
                'normal_balance' => $normal,
                'current_balance' => '0',
            ],
        );
    }

    /**
     * رصيد حساب من القيود (للتحقق من صحة الـ cached balance).
     */
    public function computeBalanceFromLines(int $accountId): string
    {
        $account = LedgerAccount::findOrFail($accountId);

        $debits = (string) LedgerEntryLine::where('account_id', $accountId)
            ->where('direction', 'debit')->sum('amount');
        $credits = (string) LedgerEntryLine::where('account_id', $accountId)
            ->where('direction', 'credit')->sum('amount');

        return $account->normal_balance === 'debit'
            ? bcsub($debits, $credits, 4)
            : bcsub($credits, $debits, 4);
    }

    // ============================================================
    private function assertBalanced(array $lines): void
    {
        $totalDebit = $this->sumByDirection($lines, 'debit');
        $totalCredit = $this->sumByDirection($lines, 'credit');

        if (bccomp($totalDebit, $totalCredit, 4) !== 0) {
            throw new RuntimeException(
                "Unbalanced journal entry: debits ({$totalDebit}) != credits ({$totalCredit})"
            );
        }
        if (bccomp($totalDebit, '0', 4) <= 0) {
            throw new RuntimeException('Journal entry total must be positive');
        }
    }

    private function sumByDirection(array $lines, string $direction): string
    {
        $sum = '0';
        foreach ($lines as $line) {
            if ($line['direction'] === $direction) {
                $sum = bcadd($sum, (string) $line['amount'], 4);
            }
        }
        return $sum;
    }
}
