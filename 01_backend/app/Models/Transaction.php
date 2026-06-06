<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * AMIAL-REFACTOR-CORE-001 — REPLACE
 *
 * تغييرات عن الأصلي:
 *  - $fillable موسّع: idempotency_key, amount, decision_code, decision_reason,
 *    zone_code, request_zone, counterparty_zone
 *  - $casts: float → decimal:4
 *  - scope `byIdempotencyKey` للبحث السريع
 *  - relation مع Dispute (موجود في الأصلي، نبقيه)
 */
class Transaction extends Model
{
    protected $casts = [
        'user_id' => 'integer',
        'transaction_id' => 'string',
        'ref_trans_id' => 'string',
        'transaction_type' => 'string',
        // AMIAL-REFACTOR-CORE-001: decimal بدل float
        'debit' => 'decimal:4',
        'credit' => 'decimal:4',
        'charge' => 'decimal:4',
        'balance' => 'decimal:4',
        'amount' => 'decimal:4',
        'from_user_id' => 'integer',
        'to_user_id' => 'integer',
        'bonus_id' => 'integer',
        // الأعمدة الجديدة
        'idempotency_key' => 'string',
        'decision_code' => 'string',
        'decision_reason' => 'string',
        'zone_code' => 'string',
        'request_zone' => 'string',
        'counterparty_zone' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'user_id',
        'ref_trans_id',
        'transaction_type',
        'debit',
        'credit',
        'charge',
        'balance',
        'amount',
        'from_user_id',
        'to_user_id',
        'bonus_id',
        'note',
        'transaction_id',
        // AMIAL-REFACTOR-CORE-001 — جديد
        'idempotency_key',
        'decision_code',
        'decision_reason',
        'zone_code',
        'request_zone',
        'counterparty_zone',
        // AMIAL-FEE-ENGINE-001 — snapshot النسخة المستخدَمة
        'fee_scheme_id',
        'fee_scheme_version',
        // AMIAL-MERCHANT-PAY-001 — نسبة العملية لموظف POS
        'pos_user_id',
        // AMIAL-SPLIT-BILL-001 — ربط الدفعة بالفاتورة المقسّمة
        'split_bill_id',
        'split_participant_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class);
    }

    /* ========= Scopes (نحافظ على الأصلية) ========= */

    public function scopeNotAdmin(Builder $query): Builder
    {
        return $query->whereHas('user', function ($q) {
            $q->where('type', '!=', 0);
        });
    }

    public function scopeAgent(Builder $query): Builder
    {
        return $query->whereHas('user', function ($q) {
            $q->where('type', 1);
        });
    }

    public function scopeCustomer(Builder $query): Builder
    {
        return $query->whereHas('user', function ($q) {
            $q->where('type', 2);
        });
    }

    public function scopeMerchant(Builder $query): Builder
    {
        return $query->whereHas('user', function ($q) {
            $q->where('type', 3);
        });
    }

    /* ========= Scopes جديدة ========= */

    /**
     * AMIAL-REFACTOR-CORE-001: البحث عن transaction بـ idempotency_key.
     * يستخدم للـ trace/debugging و idempotent replay.
     */
    public function scopeByIdempotencyKey(Builder $query, string $key): Builder
    {
        return $query->where('idempotency_key', $key);
    }

    /**
     * AMIAL-ZONE-001 (preparation): محصور في zone واحدة.
     */
    public function scopeInZone(Builder $query, string $zoneCode): Builder
    {
        return $query->where('zone_code', $zoneCode);
    }
}
