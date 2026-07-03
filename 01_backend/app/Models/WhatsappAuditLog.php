<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-WA-002 — سجل تدقيق قناة واتساب (Section 27).
 */
class WhatsappAuditLog extends Model
{
    protected $table = 'whatsapp_audit_logs';
    public    $timestamps = false;

    protected $fillable = [
        'whatsapp_number', 'user_id', 'event_type', 'intent',
        'outcome', 'risk_delta', 'metadata', 'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public const EVENT_COMMAND           = 'command';
    public const EVENT_TRANSFER_ATTEMPT  = 'transfer_attempt';
    public const EVENT_TRANSFER_SUCCESS  = 'transfer_success';
    public const EVENT_TRANSFER_FAILED   = 'transfer_failed';
    public const EVENT_LINK_ATTEMPT      = 'link_attempt';
    public const EVENT_LINK_SUCCESS      = 'link_success';
    public const EVENT_LINK_FAILED       = 'link_failed';
    public const EVENT_SECURITY_FLAG     = 'security_flag';

    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_FAILED  = 'failed';
    public const OUTCOME_BLOCKED = 'blocked';
}
