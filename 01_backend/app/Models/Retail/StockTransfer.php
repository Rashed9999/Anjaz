<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٤ — تحويلٌ بين موقعين، **بمراحله**.
 *
 * وبلا مراحلَ يختفي المخزون من فرعٍ ولا يظهر في آخر، والفرقُ يُقرأ سرقة.
 */
class StockTransfer extends Model
{
    protected $table = 'stock_transfers';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'code', 'from_location_id', 'to_location_id',
        'status', 'requested_by', 'approved_by', 'shipped_by', 'received_by',
        'requested_at', 'approved_at', 'shipped_at', 'received_at',
        'note', 'cancel_reason', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'from_location_id' => 'integer',
        'to_location_id' => 'integer',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'shipped_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public const DRAFT = 'draft';
    public const REQUESTED = 'requested';
    public const APPROVED = 'approved';
    public const SHIPPED = 'shipped';
    public const RECEIVED = 'received';
    public const PARTIALLY_RECEIVED = 'partially_received';
    public const CANCELLED = 'cancelled';

    /** الانتقالاتُ المسموحة — **ولا يُقفز عن مرحلة**. */
    public const FLOW = [
        self::DRAFT => [self::REQUESTED, self::CANCELLED],
        self::REQUESTED => [self::APPROVED, self::CANCELLED],
        self::APPROVED => [self::SHIPPED, self::CANCELLED],
        self::SHIPPED => [self::RECEIVED, self::PARTIALLY_RECEIVED],
        self::RECEIVED => [],
        self::PARTIALLY_RECEIVED => [],
        self::CANCELLED => [],
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'transfer_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(MerchantLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(MerchantLocation::class, 'to_location_id');
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::FLOW[$this->status] ?? [], true);
    }

    /** **البضاعةُ في الطريق** — خرجت ولم تصل، وهي حالةٌ تُقال لا تُخفى. */
    public function isInTransit(): bool
    {
        return $this->status === self::SHIPPED;
    }

    public function statusAr(): string
    {
        return match ($this->status) {
            self::DRAFT => 'مسودّة',
            self::REQUESTED => 'مطلوب',
            self::APPROVED => 'معتمَد',
            self::SHIPPED => 'في الطريق',
            self::RECEIVED => 'مستلَم',
            self::PARTIALLY_RECEIVED => 'مستلَم جزئياً',
            default => 'ملغى',
        };
    }
}
