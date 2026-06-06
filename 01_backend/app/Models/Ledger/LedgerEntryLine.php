<?php

namespace App\Models\Ledger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * AMIAL-LEDGER-001 (v1.7)
 *
 * Entry Line — سطر debit أو credit. FULLY IMMUTABLE (append-only).
 *
 * لا تحديث ولا حذف على الإطلاق. هذا هو السجل المحاسبي الأصلي.
 */
class LedgerEntryLine extends Model
{
    protected $table = 'ledger_entry_lines';
    public $timestamps = false;

    protected $fillable = [
        'journal_entry_id', 'account_id',
        'direction', 'amount',
        'balance_before', 'balance_after',
        'description_ar', 'metadata', 'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerJournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    public function isDebit(): bool { return $this->direction === 'debit'; }
    public function isCredit(): bool { return $this->direction === 'credit'; }

    // ====== Total immutability ======
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Ledger entry lines are immutable and cannot be modified.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Ledger entry lines cannot be deleted. They are the permanent accounting record.'
            );
        });
    }
}
