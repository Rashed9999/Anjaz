<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-FUND-FAMILY-001
 *
 * append-only — لا UPDATE (إلا approval) ولا DELETE.
 */
class FamilyFundTransaction extends Model
{
    protected $table = 'family_fund_transactions';

    public $timestamps = false; // فقط created_at

    protected $fillable = [
        'tx_ulid',
        'fund_id',
        'user_id',
        'tx_type',
        'amount',
        'balance_before',
        'balance_after',
        'wallet_transaction_id',
        'beneficiary_user_id',
        'note',
        'attachment_path',
        'status',
        'approved_by_user_id',
        'approved_at',
        'created_at',
    ];

    protected $casts = [
        'fund_id' => 'integer',
        'user_id' => 'integer',
        'amount' => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'beneficiary_user_id' => 'integer',
        'approved_by_user_id' => 'integer',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(FamilyFund::class, 'fund_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    /** Override: السماح فقط بـ update فيلد الـ approval */
    public function update(array $attributes = [], array $options = [])
    {
        if ($this->exists) {
            $allowedKeys = ['status', 'approved_by_user_id', 'approved_at'];
            $extra = array_diff(array_keys($attributes), $allowedKeys);
            if (!empty($extra)) {
                throw new \RuntimeException(
                    'FamilyFundTransaction is append-only; allows update only on approval fields. Got: '
                    . implode(', ', $extra)
                );
            }
        }
        return parent::update($attributes, $options);
    }

    public function delete()
    {
        throw new \RuntimeException('FamilyFundTransaction is append-only. Delete is not allowed.');
    }
}
