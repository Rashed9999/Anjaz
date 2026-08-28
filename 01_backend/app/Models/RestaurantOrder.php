<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOrder extends Model
{
    protected $table = 'restaurant_orders';

    protected $fillable = [
        'merchant_user_id', 'table_id', 'order_no', 'status', 'items',
        'subtotal', 'total', 'notes', 'opened_by', 'opened_at', 'closed_by_user_id', 'closed_at',
        'sale_ulid', 'zone_code',
    ];

    protected $casts = [
        'table_id' => 'integer',
        'opened_by' => 'integer',
        'closed_by_user_id' => 'integer',
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public const ACTIVE = ['open', 'preparing', 'ready', 'served'];
}
