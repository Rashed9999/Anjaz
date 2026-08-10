<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AMIAL-RETAIL-VERTICAL-001 · المرحلة ٢ — العلامة التجارية. */
class MerchantBrand extends Model
{
    use SoftDeletes;

    protected $table = 'merchant_brands';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'name', 'code', 'country',
        'is_active', 'created_by',
    ];

    protected $casts = ['merchant_user_id' => 'integer', 'is_active' => 'boolean'];
}
