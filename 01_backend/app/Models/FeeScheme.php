<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-FEE-ENGINE-001 — نسخة رسم لعملية مالية.
 *
 * append-only: لا تُعدَّل النسخة بعد تفعيلها؛ تُنشأ نسخة جديدة تُلغيها.
 */
class FeeScheme extends Model
{
    protected $table = 'fee_schemes';

    protected $fillable = [
        'code', 'label', 'zone_code', 'applies_to',
        'fee_type', 'percent_rate', 'fixed_amount', 'min_fee', 'max_fee',
        'agent_commission_percent', 'agent_commission_fixed', 'bearer',
        'version', 'is_active', 'effective_from', 'effective_to',
        'notes', 'created_by',
    ];

    protected $casts = [
        // نُبقي الأرقام كنصوص (string) عبر MoneyService — لا float.
        'is_active' => 'boolean',
        'version' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'created_by' => 'integer',
    ];

    /** الأكواد المعتمدة للعمليات. */
    public const CODES = [
        'SEND_MONEY', 'CASH_OUT', 'CASH_IN', 'MERCHANT_QR', 'MERCHANT_POS',
        'SAFE_PAYMENT', 'BILL_PAY', 'SPLIT_BILL', 'REFUND', 'FAMILY_FUND_CONTRIB',
    ];

    public const FEE_TYPES = ['percent', 'fixed', 'percent_plus_fixed'];
    public const APPLIES_TO = ['customer', 'merchant', 'agent'];
    public const BEARERS = ['sender', 'receiver', 'merchant'];

    public function changeLogs()
    {
        return $this->hasMany(FeeChangeLog::class, 'fee_scheme_id');
    }
}
