<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettlementPartner extends Model
{
    protected $table = 'settlement_partners';

    protected $fillable = [
        'code', 'name_ar', 'name_en', 'type',
        'account_number', 'bank_name', 'iban', 'swift',
        'contact_phone', 'contact_name', 'currency',
        'is_active', 'notes',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public const TYPE_EXCHANGE = 'exchange';
    public const TYPE_BANK     = 'bank';
    public const TYPE_EWALLET  = 'ewallet';

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'destination_partner_id');
    }
}
