<?php

namespace App\Models\Retail;

use App\Models\MerchantProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AMIAL-RETAIL-VERTICAL-001 · المرحلة ٤ — سطرُ تحويل. */
class StockTransferItem extends Model
{
    protected $table = 'stock_transfer_items';

    protected $fillable = [
        'transfer_id', 'product_id', 'name', 'requested_quantity',
        'shipped_quantity', 'received_quantity', 'unit_cost', 'variance_reason',
    ];

    protected $casts = ['transfer_id' => 'integer', 'product_id' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantProduct::class, 'product_id');
    }

    /**
     * الفرقُ بين المرسَل والمستلَم — **و`null` تعني «لم يُستلَم بعد»**
     * لا «لا فرق» (القاعدة ٧).
     */
    public function shortage(): ?string
    {
        if ($this->received_quantity === null || $this->shipped_quantity === null) {
            return null;
        }

        return bcsub((string) $this->shipped_quantity, (string) $this->received_quantity, 3);
    }
}
