<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-DONATIONS-001 (v1.2)
 *
 * تسوية شهرية لمنظمة خيرية.
 *
 * كل تسوية تجمع كل donations في فترة معينة لمنظمة معينة،
 * تحسب الـ payable_amount (donations - fees)، تنتظر admin
 * أن يحوّل المبلغ بنكياً ويسجل ذلك.
 */
class CharitySettlement extends Model
{
    protected $table = 'charity_settlements';

    protected $fillable = [
        'settlement_ulid', 'org_id',
        'period_start', 'period_end',
        'donation_count', 'campaign_count',
        'total_donations', 'total_platform_fees', 'payable_amount',
        'status', 'transferred_at',
        'bank_transfer_reference', 'transfer_notes',
        'report_pdf_path',
        'generated_by_admin_id', 'transferred_by_admin_id',
        // AMIAL-CHARITY-PAYOUT-001 — قناةُ الصرف ومَن قبض ورقمُ القيد.
        // **وبلا هذه الأسطر تُبتلع القيمُ في `update()` بصمت**: يُدان
        // الدفترُ ويُشحن الرصيد، وتبقى التسوية تقول «بنك» بلا مستلم.
        'payout_method', 'payout_user_id', 'payout_journal_entry_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'donation_count' => 'integer',
        'campaign_count' => 'integer',
        'total_donations' => 'decimal:4',
        'total_platform_fees' => 'decimal:4',
        'payable_amount' => 'decimal:4',
        'transferred_at' => 'datetime',
        'generated_by_admin_id' => 'integer',
        'transferred_by_admin_id' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(CharityOrganization::class, 'org_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_admin_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by_admin_id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'settlement_id');
    }

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isTransferred(): bool { return $this->status === 'transferred'; }
}
