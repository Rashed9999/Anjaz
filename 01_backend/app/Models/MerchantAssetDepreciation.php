<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantAssetDepreciation extends Model
{
    protected $fillable = [
        'entry_ulid', 'merchant_user_id', 'asset_id', 'period', 'amount',
        'accumulated_after', 'book_value_after', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'asset_id' => 'integer',
        'amount' => 'decimal:4',
        'accumulated_after' => 'decimal:4',
        'book_value_after' => 'decimal:4',
        'posted_by' => 'integer',
        'posted_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MerchantFixedAsset::class, 'asset_id');
    }
}
