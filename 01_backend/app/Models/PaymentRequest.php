<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-PAYMENT-REQUESTS-001 — طلب دفع.
 */
class PaymentRequest extends Model
{
    protected $table = 'payment_requests';

    protected $fillable = [
        'request_ulid', 'short_code',
        'requester_user_id', 'recipient_user_id', 'recipient_phone', 'recipient_name',
        'amount', 'note', 'share_method',
        'status', 'paid_by_user_id', 'paid_transaction_id', 'paid_at',
        'expires_at',
        'is_recurring', 'recurring_period', 'parent_request_id',
        'zone_code',
    ];

    protected $casts = [
        'requester_user_id' => 'integer',
        'recipient_user_id' => 'integer',
        'paid_by_user_id' => 'integer',
        'parent_request_id' => 'integer',
        'is_recurring' => 'boolean',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // AMIAL-REQUEST-DIRECT-001: 'declined' — المستلم يرفض. ويُفصل عن
    // 'cancelled' (الطالب يسحب طلبه): الطالب يحتاج أن يعرف أيّهما وقع،
    // فالمرفوض لا يُعاد إرساله والملغى قد يُعاد.
    public const STATUSES = ['pending', 'paid', 'cancelled', 'expired', 'declined'];
    public const PERIODS = ['daily', 'weekly', 'monthly'];

    /**
     * AMIAL-REQUEST-DIRECT-002 — **طريقةٌ ثالثة: مباشرةً إلى مشترك.**
     *
     * كانت الطريقتان `link` و`qr` — وكلتاهما تفترض أنّ الطالب يُخرج شيئاً
     * من التطبيق ليُوصله بيده. وهي الطريقةُ الصحيحة لمن ليس مشتركاً،
     * **والخطأُ أن تكون الطريقةَ الوحيدة**.
     *
     * فالمباشرُ يُعلَن في الصفّ نفسه: من يفتح طلباته يعرف أيُّها ذهب إلى
     * صاحبه وأيُّها ينتظر أن يُشارَك بيده.
     */
    public const SHARE_DIRECT = 'direct';
    public const SHARE_LINK = 'link';
    public const SHARE_QR = 'qr';

    public const SHARE_METHODS = [self::SHARE_DIRECT, self::SHARE_LINK, self::SHARE_QR];

    /** أوصِلَ إلى حسابٍ في أميال، أم ينتظر مشاركةً يدويّة؟ */
    public function isDirect(): bool
    {
        return $this->share_method === self::SHARE_DIRECT && $this->recipient_user_id !== null;
    }

    public function shareMethodAr(): string
    {
        return match ($this->share_method) {
            self::SHARE_DIRECT => 'وصل إلى حسابه',
            self::SHARE_QR => 'رمز QR',
            default => 'رابط يُشارَك',
        };
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'pending' && $this->expires_at?->isFuture() === true;
    }

    /**
     * الرابط الكامل المعروض للمشاركة.
     *
     * ══════════════════════════════════════════════════════════════════
     * **AMIAL-REQ-URL-001 — كان يشير إلى نطاقٍ لا نملكه.**
     *
     * الافتراضيّ كان `https://amial.pay`، و`app.public_url` **غير معرَّفٍ
     * في `config/app.php` إطلاقاً** — فالافتراضيّ هو ما يُستعمل دائماً.
     * والنطاق المملوك `amialpay.com`.
     *
     * فكلُّ من شارك طلبَ دفعٍ أرسل رابطاً **لا يفتح شيئاً**، وقد يُسجّله
     * غيرُنا غداً فيستقبل عملاءنا. ولا خطأ في أيّ سجلّ: الرابطُ يُنسخ
     * ويُرسل ويبدو سليماً.
     *
     * والقيمةُ تُقرأ الآن من `app.url` — المضبوطِ في البيئة والمحروسِ
     * بـ`AppUrlSanitizeTest` — فمصدرٌ واحدٌ لا اثنان يفترقان.
     */
    public function publicUrl(): string
    {
        $base = rtrim((string) config('app.public_url')
            ?: (string) config('app.url')
            ?: 'https://amialpay.com', '/');

        return "{$base}/req/{$this->short_code}";
    }
}
