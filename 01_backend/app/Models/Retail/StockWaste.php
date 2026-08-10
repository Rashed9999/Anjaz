<?php

namespace App\Models\Retail;

use App\Models\MerchantProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٦ — هالكٌ **باعتماد**.
 *
 * ومن يُتلف بلا مُعتمِدٍ يُخرج بضاعةً بلا أثر: «انتهت صلاحيتها» جملةٌ
 * تُخرج من المستودع ما شاء صاحبُها.
 */
class StockWaste extends Model
{
    protected $table = 'stock_wastes';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'location_id', 'product_id', 'name',
        'quantity', 'reason', 'unit_cost', 'total_cost', 'status',
        'recorded_by', 'approved_by', 'approved_at', 'reject_reason',
        'note', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'location_id' => 'integer',
        'product_id' => 'integer',
        'approved_at' => 'datetime',
    ];

    public const REASONS = ['expired', 'damaged', 'theft', 'sample', 'production_loss', 'other'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantProduct::class, 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MerchantLocation::class, 'location_id');
    }

    public function reasonAr(): string
    {
        return match ($this->reason) {
            'expired' => 'انتهاء صلاحية',
            'damaged' => 'تلف',
            'theft' => 'سرقة',
            'sample' => 'عيّنة',
            'production_loss' => 'فاقد تصنيع',
            default => 'أخرى',
        };
    }
}
