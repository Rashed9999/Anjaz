<?php

namespace App\Models\BusinessGateway;

use App\Models\PaymentRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — نيّة دفع من أميال أعمال.
 *
 * كل نيّة يقابلها طلب QR واحد في `payment_requests`، والمال يتحرّك حين يدفع
 * العميل ذلك الطلب عبر PaymentRequestService::settle() القائم. النيّة لا
 * تحمل رصيداً ولا تحرّكه؛ هي سجلّ «هذا الدفع يخصّ فاتورة المنشأة X».
 */
class BusinessPaymentIntent extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /** فاتورة بيع (الأصل) · سداد دين عميل لدى التاجر في أميال أعمال. */
    public const PURPOSE_SALE = 'sale';
    public const PURPOSE_DEBT_COLLECTION = 'debt_collection';

    /** من طلب النيّة: أميال أعمال (الكاشير) · العميل من تطبيقه. */
    public const ORIGIN_BUSINESS = 'business';
    public const ORIGIN_CUSTOMER_APP = 'customer_app';

    protected $table = 'business_payment_intents';

    protected $fillable = [
        'intent_ulid', 'business_link_id', 'business_reference', 'purpose', 'origin',
        'contact_ref', 'payer_user_id', 'payment_request_id',
        'amount', 'merchant_fee', 'currency', 'request_fingerprint', 'status', 'paid_transaction_id',
        'succeeded_at', 'expires_at', 'description', 'branch_ref', 'device_ref',
    ];

    protected $casts = [
        'business_link_id' => 'integer',
        'payment_request_id' => 'integer',
        'payer_user_id' => 'integer',
        'succeeded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(BusinessLink::class, 'business_link_id');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }
}
