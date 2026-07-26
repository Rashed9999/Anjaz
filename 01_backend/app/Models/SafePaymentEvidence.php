<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-SAFEPAY-EVIDENCE-001 — دليل مرفوع على عملية دفع آمن.
 *
 * لا `updated_at`: السجلّ يُكتب مرّة ولا يُعدَّل. دليل قابل للتعديل ليس
 * دليلاً.
 */
class SafePaymentEvidence extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'safe_payment_evidence';

    protected $fillable = [
        'safe_payment_id',
        'uploaded_by_user_id',
        'role',
        'stage',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'sha256',
        'ip_address',
        'user_agent',
        'note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SafePayment::class, 'safe_payment_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
