<?php

namespace App\Models\Retail;

use App\Models\MerchantProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٢ — باركودٌ واحدٌ من عدّة.
 *
 * **وكرتونٌ فيه ٢٤ يُمسح فيضيف ٢٤ حبّة** لا حبّةً واحدة — و`pack_size`
 * هي الفرقُ بين مخزونٍ يُقرأ ومخزونٍ ينحرف كلَّ يومٍ بأربعٍ وعشرين.
 */
class ProductBarcode extends Model
{
    protected $table = 'product_barcodes';

    protected $fillable = [
        'merchant_user_id', 'product_id', 'barcode', 'unit_id',
        'pack_size', 'is_primary',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'product_id' => 'integer',
        'unit_id' => 'integer',
        'is_primary' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantProduct::class, 'product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MerchantUnit::class, 'unit_id');
    }
}
