<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OPS-CONSOLE-001 — تذكرة خدمة عملاء.
 */
class SupportTicket extends Model
{
    public const STATUSES = ['open', 'investigating', 'waiting_customer', 'resolved', 'closed'];
    public const CATEGORIES = [
        'missing_transfer', 'wrong_recipient', 'forgot_pin', 'fraud_suspect',
        'account_access', 'balance_issue', 'other',
    ];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = [
        'ticket_number', 'user_id', 'opened_by_admin_id', 'assigned_admin_id',
        'transaction_ref', 'category', 'priority', 'status',
        'subject', 'description', 'resolution_note', 'resolved_at', 'closed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'opened_by_admin_id' => 'integer',
        'assigned_admin_id' => 'integer',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class, 'ticket_id')->orderBy('id');
    }

    /** يولّد رقم تذكرة تسلسلياً آمناً ضد السباق (داخل معاملة). */
    public static function nextTicketNumber(): string
    {
        $max = (int) DB::table('support_tickets')
            ->selectRaw("MAX(CAST(SUBSTRING(ticket_number, 5) AS UNSIGNED)) AS m")
            ->lockForUpdate()
            ->value('m');

        return 'TKT-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }
}
