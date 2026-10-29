<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-WRONG-TRANSFER-001 — دعوى تحويلٍ إلى الرقم الخطأ.
 *
 * والأطوارُ الخمسة:
 *
 *   `open`       فُتحت ولم يُحجَز شيءٌ بعد (لا رصيدَ لدى المستلِم)
 *   `holding`    حُجز المبلغُ أو بعضُه — والساعةُ تجري
 *   `recovered`  استُرِدّ كاملاً
 *   `rejected`   رُفضت — ويُفرَج عن الحجز
 *   `expired`    مضت المهلةُ بلا قرار — **ويُفرَج عن الحجز**
 */
class WrongTransferClaim extends Model
{
    public const OPEN = 'open';

    public const HOLDING = 'holding';

    public const RECOVERED = 'recovered';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    /** الحالتان اللتان يمنع القيدُ تكرارَهما على عمليّةٍ واحدة. */
    public const LIVE = [self::OPEN, self::HOLDING];

    protected $fillable = [
        'claim_ulid', 'transaction_id', 'claimant_user_id', 'recipient_user_id',
        'amount', 'claimed_intended_phone', 'status',
        'held_amount', 'recovered_amount', 'receivable_amount', 'receivable_settled',
        'risk_score', 'risk_signals', 'hold_expires_at',
        'opened_by', 'resolved_by', 'resolution_note', 'resolved_at',
    ];

    protected $casts = [
        'risk_signals' => 'array',
        'hold_expires_at' => 'datetime',
        'resolved_at' => 'datetime',
        'risk_score' => 'integer',
    ];

    /** ما بقي على المستلِم — **يُحسب ولا يُقرأ من عمود**. */
    public function outstanding(): string
    {
        return bcsub((string) $this->receivable_amount, (string) $this->receivable_settled, 4);
    }

    public function claimant()
    {
        return $this->belongsTo(User::class, 'claimant_user_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
