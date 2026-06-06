<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantUsageCounter extends Model
{
    protected $table = 'merchant_usage_counters';

    protected $fillable = [
        'merchant_user_id', 'counter_type', 'period_key',
        'count', 'last_incremented_at',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'count' => 'integer',
        'last_incremented_at' => 'datetime',
    ];

    // ============ Counter Types ============
    public const TYPE_SALE_OPERATION = 'sale_operation';      // عملية بيع (للحدّ الشهري FREE)
    public const TYPE_INVOICE_CREATION = 'invoice_creation';  // فاتورة جملة

    public const ALL_TYPES = [
        self::TYPE_SALE_OPERATION,
        self::TYPE_INVOICE_CREATION,
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    /** يولّد period_key للشهر الحالي (YYYY-MM). */
    public static function currentMonthKey(): string
    {
        return now()->format('Y-m');
    }

    /** يولّد period_key للسنة الحالية. */
    public static function currentYearKey(): string
    {
        return now()->format('Y');
    }
}
