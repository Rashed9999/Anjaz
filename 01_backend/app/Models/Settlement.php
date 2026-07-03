<?php

namespace App\Models;

use App\Models\Ledger\LedgerAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * USE-001 — طلب تسوية.
 *
 * ⚠️ AMIAL-FIX-USE-001: sourceAccount() يستخدم App\Models\Ledger\LedgerAccount
 * (لا App\Models\LedgerAccount كما كُتب أوّل مرّة — namespace خاطئ كان
 * سيُسبّب Class Not Found فور أوّل استدعاء لهذه العلاقة).
 */
class Settlement extends Model
{
    protected $table = 'settlements';

    protected $fillable = [
        'reference_number', 'ulid',
        'source_ledger_account_id', 'destination_partner_id',
        'destination_account_detail', 'currency', 'amount', 'status',
        'external_reference', 'external_bank_ref', 'attachment_path',
        'created_by', 'approved_by', 'rejected_by', 'completed_by', 'cancelled_by',
        'approved_at', 'rejected_at', 'completed_at', 'cancelled_at',
        'debit_journal_entry_id', 'reverse_journal_entry_id',
        'balance_before', 'balance_after',
        'notes', 'rejection_reason',
    ];

    protected $casts = [
        'amount'         => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after'  => 'decimal:4',
        'approved_at'    => 'datetime',
        'rejected_at'    => 'datetime',
        'completed_at'   => 'datetime',
        'cancelled_at'   => 'datetime',
    ];

    public const STATUS_DRAFT            = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_PROCESSING       = 'processing';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_REJECTED         = 'rejected';
    public const STATUS_CANCELLED        = 'cancelled';

    public const ALL_STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED, self::STATUS_PROCESSING,
        self::STATUS_COMPLETED, self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    public const IMMUTABLE_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $s) {
            if (empty($s->ulid)) {
                $s->ulid = (string) Str::ulid();
            }
        });
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'source_ledger_account_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(SettlementPartner::class, 'destination_partner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SettlementAuditLog::class, 'settlement_id')->orderBy('created_at');
    }

    public function isImmutable(): bool
    {
        return in_array($this->status, self::IMMUTABLE_STATUSES, true);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return match ($this->status) {
            self::STATUS_DRAFT            => in_array($newStatus, [self::STATUS_PENDING_APPROVAL, self::STATUS_CANCELLED]),
            self::STATUS_PENDING_APPROVAL => in_array($newStatus, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED]),
            self::STATUS_APPROVED         => in_array($newStatus, [self::STATUS_PROCESSING, self::STATUS_CANCELLED]),
            self::STATUS_PROCESSING       => in_array($newStatus, [self::STATUS_COMPLETED, self::STATUS_CANCELLED]),
            default                       => false,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT            => 'مسودّة',
            self::STATUS_PENDING_APPROVAL => 'بانتظار الاعتماد',
            self::STATUS_APPROVED         => 'معتمد',
            self::STATUS_PROCESSING       => 'قيد التنفيذ',
            self::STATUS_COMPLETED        => 'مكتمل',
            self::STATUS_REJECTED         => 'مرفوض',
            self::STATUS_CANCELLED        => 'ملغي',
            default                       => $this->status,
        };
    }
}
