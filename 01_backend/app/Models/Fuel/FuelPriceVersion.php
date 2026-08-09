<?php

namespace App\Models\Fuel;

use App\Models\FuelProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نسخةُ سعر — **لا يُكتب فوق سعرٍ قديم**.
 *
 * فمن يريد أن يعرف سعرَ الأمس يجد نسخته بتاريخِ سريانها ومن اعتمدها
 * ولماذا. والعمليّاتُ التاريخيّة سليمةٌ أصلاً لأنّ `fuel_sales` تلتقط
 * السعرَ لحظةَ البيع — الناقصُ كان **الحكمَ المسبق**.
 */
class FuelPriceVersion extends Model
{
    protected $table = 'fuel_price_versions';

    protected $fillable = [
        'fuel_product_id', 'station_id', 'price_per_liter',
        'effective_from', 'effective_to', 'status',
        'created_by_user_id', 'approved_by_user_id', 'approved_at', 'reason',
    ];

    protected $casts = [
        'fuel_product_id' => 'integer',
        'station_id' => 'integer',
        'price_per_liter' => 'decimal:4',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public const STATUSES = ['pending_approval', 'active', 'superseded', 'rejected'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
