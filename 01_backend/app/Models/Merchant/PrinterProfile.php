<?php

namespace App\Models\Merchant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Branch;
use App\Models\User;

class PrinterProfile extends Model
{
    protected $table = 'merchant_printer_profiles';

    protected $fillable = [
        'profile_ulid', 'merchant_user_id', 'branch_id', 'pos_device_id',
        'name', 'printer_type', 'connection_type', 'paper_size',
        'endpoint_hash', 'endpoint_hint', 'is_default', 'status',
        'capabilities', 'settings', 'last_seen_at', 'last_success_at',
        'last_failure_at',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'branch_id' => 'integer',
        'pos_device_id' => 'integer',
        'is_default' => 'boolean',
        'capabilities' => 'array',
        'settings' => 'array',
        'last_seen_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class, 'printer_profile_id');
    }
}
