<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-FEE-ENGINE-001 — سجل تدقيق تغييرات الرسوم (append-only).
 */
class FeeChangeLog extends Model
{
    protected $table = 'fee_change_logs';

    public $timestamps = false; // created_at فقط، يُضبط يدوياً

    protected $fillable = [
        'fee_scheme_id', 'code', 'action',
        'old_values', 'new_values', 'admin_id', 'ip', 'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'fee_scheme_id' => 'integer',
        'admin_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function scheme()
    {
        return $this->belongsTo(FeeScheme::class, 'fee_scheme_id');
    }
}
