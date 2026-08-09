<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelSale extends Model
{
    protected $table = 'fuel_sales';

    protected $fillable = [
        'sale_ulid', 'merchant_user_id', 'pos_user_id',
        'station_id', 'shift_id', 'pump_id', 'nozzle_id', 'tank_id', 'fuel_product_id',
        'sale_type', 'liters', 'price_per_liter', 'total_amount',
        'payment_method', 'paid_transaction_id',
        'company_account_id', 'company_card_id',
        'vehicle_plate', 'driver_name',
        'meter_reading_before', 'meter_reading_after',
        'status', 'notes', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'pos_user_id' => 'integer',
        'station_id' => 'integer',
        'shift_id' => 'integer',
        'pump_id' => 'integer',
        'nozzle_id' => 'integer',
        'tank_id' => 'integer',
        'fuel_product_id' => 'integer',
        'company_account_id' => 'integer',
        'liters' => 'decimal:4',
        'price_per_liter' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'meter_reading_before' => 'decimal:3',
        'meter_reading_after' => 'decimal:3',
    ];

    public const SALE_TYPES = ['by_liters', 'by_amount'];
    public const PAYMENT_METHODS = ['cash', 'amial_pay', 'company_card'];
    public const STATUSES = ['completed', 'refunded', 'voided'];

    public function pump(): BelongsTo
    {
        return $this->belongsTo(FuelPump::class, 'pump_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }

    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(FuelCompanyAccount::class, 'company_account_id');
    }
}
