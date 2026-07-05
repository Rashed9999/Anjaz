<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-OPS-CONSOLE-001 — حدث في الخط الزمني لتذكرة دعم.
 */
class SupportTicketEvent extends Model
{
    protected $fillable = [
        'ticket_id', 'admin_id', 'event_type', 'old_value', 'new_value', 'note',
    ];

    protected $casts = [
        'ticket_id' => 'integer',
        'admin_id' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
