<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AMIAL-PRODUCT-ATTRIBUTES-001 — قيمةُ سمة: «أحمر» · «L». */
class MerchantAttributeTerm extends Model
{
    protected $table = 'merchant_attribute_terms';

    protected $fillable = [
        'attribute_id', 'merchant_user_id', 'value', 'slug', 'color_hex', 'sort_order',
    ];

    protected $casts = [
        'attribute_id' => 'integer',
        'merchant_user_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(MerchantAttribute::class, 'attribute_id');
    }
}
