<?php

namespace App\Models\Merchant;

use Illuminate\Database\Eloquent\Model;

/** رمز تفعيل قصير لجهاز POS؛ لا يُخزَّن الرمز الخام. */
class PosDeviceActivation extends Model
{
    protected $table = 'merchant_pos_device_activations';

    protected $fillable = [
        'merchant_user_id', 'branch_id', 'created_by_user_id', 'code_hash',
        'display_name', 'expires_at', 'consumed_at', 'consumed_by_device_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'merchant_user_id' => 'integer',
        'branch_id' => 'integer',
        'created_by_user_id' => 'integer',
        'consumed_by_device_id' => 'integer',
    ];
}
