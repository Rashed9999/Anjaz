<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AMIAL-TREASURY-ISSUANCE-001
 *
 * The only service allowed to create platform float. A balance increment is
 * not an accounting event by itself: the matching cash reserve and the audit
 * evidence are both part of the event.
 */
class PlatformTreasuryService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array{transaction_id:?string,entry:LedgerJournalEntry,duplicate:bool} */
    public function issueAdminFloat(
        string|int|float $amount,
        ?User $actor,
        string $reference,
        string $reason,
        ?string $idempotencyKey = null,
    ): array {
        $amount = MoneyService::normalize($amount);
        if (! MoneyService::isPositive($amount)) {
            throw new RuntimeException('مبلغ الإصدار يجب أن يكون أكبر من صفر');
        }

        $reference = trim($reference);
        $reason = trim($reason);
        if ($reference === '' || $reason === '') {
            throw new RuntimeException('مرجع الإثبات وسبب الإصدار إلزاميان');
        }

        // A retry or double click must return the original event, never mint
        // again.  The ledger column is 80 chars while external references and
        // HTTP idempotency keys may be longer, so store a fixed-size digest.
        $key = 'treasury:' . hash('sha256', $reference);

        return DB::transaction(function () use ($amount, $actor, $reference, $reason, $key, $idempotencyKey) {
            $existing = LedgerJournalEntry::where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return [
                    'transaction_id' => $existing->metadata['legacy_transaction_id'] ?? null,
                    'entry' => $existing,
                    'duplicate' => true,
                ];
            }

            $adminId = Helpers::get_admin_id();
            $operationalWallet = EMoney::where('user_id', $adminId)->lockForUpdate()->first();
            if (! $operationalWallet) {
                throw new RuntimeException('محفظة الإدارة غير موجودة');
            }

            // A unique ledger row cannot be locked before it exists.  The
            // operational wallet is the shared serialisation point for every
            // issuance, so re-check after acquiring that lock to close the
            // "both requests saw no row" race.
            $existing = LedgerJournalEntry::where('idempotency_key', $key)->first();
            if ($existing) {
                return [
                    'transaction_id' => $existing->metadata['legacy_transaction_id'] ?? null,
                    'entry' => $existing,
                    'duplicate' => true,
                ];
            }

            $adminWallet = $this->ledger->getOrCreateUserWallet($adminId);
            $ledgerBefore = $this->ledger->computeBalanceFromLines($adminWallet->id);
            if (bccomp((string) $operationalWallet->current_balance, $ledgerBefore, 4) !== 0) {
                throw new RuntimeException(
                    'لا يمكن إصدار رصيد فوق انحراف قائم: طابق محفظة الإدارة مع الدفتر أولاً'
                );
            }

            $legacyTransactionId = Helpers::make_transaction([
                'from_user_id' => $adminId,
                'to_user_id' => $adminId,
                'user_id' => $adminId,
                'type' => 'credit',
                'transaction_type' => CASH_IN,
                'ref_trans_id' => null,
                'amount' => $amount,
                'note' => "إصدار خزينة: {$reference} — {$reason}",
            ]);

            if (! $legacyTransactionId) {
                throw new RuntimeException('تعذّر تسجيل حركة المحفظة');
            }

            $cashReserve = $this->ledger->getOrCreateSystemAccount(
                'TREASURY_CASH_RESERVE', 'asset', 'احتياطي نقد الخزينة الموثق', 'debit',
            );

            $entry = $this->ledger->post(
                sourceType: 'treasury_issuance',
                sourceId: $legacyTransactionId,
                description: "إصدار رصيد خزينة: {$reference}",
                lines: [
                    ['account' => $cashReserve->account_code, 'direction' => 'debit', 'amount' => $amount,
                        'description' => 'نقد وارد للخزينة'],
                    ['account' => $adminWallet->account_code, 'direction' => 'credit', 'amount' => $amount,
                        'description' => 'رصيد الإدارة المُصدر'],
                ],
                idempotencyKey: $key,
                createdByUserId: $actor?->id,
                metadata: [
                    'reference' => $reference,
                    'reason' => $reason,
                    'legacy_transaction_id' => $legacyTransactionId,
                    'actor_user_id' => $actor?->id,
                    'issued_at' => now()->toIso8601String(),
                    // The business reference is the ledger identity.  Keep a
                    // non-sensitive fingerprint of a transport retry key for
                    // incident correlation without letting it mint a second
                    // issuance for the same proof reference.
                    'request_idempotency_hash' => $idempotencyKey
                        ? hash('sha256', $idempotencyKey) : null,
                ],
            );

            $this->audit->record([
                'actor_type' => 'admin', 'actor_user_id' => $actor?->id,
                'subject_type' => 'treasury_issuance', 'subject_id' => $entry->entry_ulid,
                'action' => 'TREASURY_FLOAT_ISSUED', 'decision_code' => 'POSTED',
                'reason' => $reason, 'transaction_id' => $legacyTransactionId,
                'severity' => 'critical',
                'context' => [
                    'amount' => $amount, 'reference' => $reference,
                    'ledger_entry_ulid' => $entry->entry_ulid, 'idempotency_key' => $key,
                ],
            ]);

            return ['transaction_id' => $legacyTransactionId, 'entry' => $entry, 'duplicate' => false];
        });
    }
}
