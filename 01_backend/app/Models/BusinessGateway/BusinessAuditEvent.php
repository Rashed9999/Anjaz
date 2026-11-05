<?php

namespace App\Models\BusinessGateway;

use Illuminate\Database\Eloquent\Model;

/** AMIAL-BUSINESS-AUDIT-001 — حدث تدقيق حساس وصل من أميال أعمال. */
class BusinessAuditEvent extends Model
{
    protected $table = 'business_audit_events';

    protected $fillable = ['business_link_id', 'event_ref', 'action', 'actor', 'context', 'source_hash', 'occurred_at'];

    protected $casts = ['actor' => 'array', 'context' => 'array', 'occurred_at' => 'datetime'];
}
