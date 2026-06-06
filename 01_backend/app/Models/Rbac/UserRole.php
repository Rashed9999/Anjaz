<?php

namespace App\Models\Rbac;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRole extends Model
{
    protected $table = 'rbac_user_roles';
    public $timestamps = false; // assigned_at + revoked_at manual

    protected $fillable = [
        'user_id',
        'role_id',
        'assigned_by_user_id',
        'assigned_at',
        'revoked_at',
        'revoke_reason',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'role_id' => 'integer',
        'assigned_by_user_id' => 'integer',
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function role(): BelongsTo { return $this->belongsTo(Role::class, 'role_id'); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by_user_id'); }

    public function scopeActive($q) { return $q->whereNull('revoked_at'); }
    public function isActive(): bool { return $this->revoked_at === null; }
}
