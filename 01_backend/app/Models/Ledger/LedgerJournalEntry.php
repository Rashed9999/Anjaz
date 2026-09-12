<?php

namespace App\Models\Ledger;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * AMIAL-LEDGER-001 (v1.7)
 *
 * Journal Entry — رأس المعاملة المحاسبية. APPEND-ONLY.
 *
 * بمجرد posted، لا يُعدَّل ولا يُحذف. التصحيح بقيد عكسي فقط.
 */
class LedgerJournalEntry extends Model
{
    protected $table = 'ledger_journal_entries';
    public $timestamps = false; // نستخدم posted_at + created_at يدوياً

    protected $fillable = [
        'entry_ulid', 'source_type', 'source_id', 'idempotency_key',
        'description_ar', 'total_amount',
        // AMIAL-MULTI-CURRENCY-002 — **غيابُه عن هذه القائمة لا يُخرج خطأً.**
        // `create()` يُسقط ما ليس فيها **صامتاً**، فيقع العمودُ على افتراضيّ
        // القاعدة (`YER`) — وكلُّ قيدِ دولارٍ يُسجَّل «ريالاً» وهو يوازن
        // ويُقرأ ويمرّ. قِيس فوقع: قيدُ صرفٍ بأربعين دولاراً حمل `YER`.
        'currency',
        'is_reversal', 'reverses_entry_id',
        'status', 'reversed_by_entry_id',
        'created_by_user_id', 'zone_code', 'metadata',
        'posted_at', 'created_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:4',
        'is_reversal' => 'boolean',
        'metadata' => 'array',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerEntryLine::class, 'journal_entry_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(LedgerJournalEntry::class, 'reverses_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopePosted(Builder $q): Builder
    {
        return $q->where('status', 'posted');
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    // ====== Immutability enforcement ======
    protected static function booted(): void
    {
        static::updating(function (LedgerJournalEntry $entry) {
            // نسمح فقط بتحديث status + reversed_by_entry_id (عند الـ reversal)
            $dirty = $entry->getDirty();
            $allowed = ['status', 'reversed_by_entry_id'];
            foreach (array_keys($dirty) as $field) {
                if (!in_array($field, $allowed, true)) {
                    throw new RuntimeException(
                        "Ledger journal entries are immutable. Cannot modify '{$field}'. Use a reversing entry."
                    );
                }
            }
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Ledger journal entries cannot be deleted. Use a reversing entry.'
            );
        });
    }
}
