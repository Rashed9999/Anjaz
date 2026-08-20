<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-KYC-DOCS-001 — مستند هويّة واحد في دورة حياته.
 *
 * الملفّ نفسه مشفَّر في التخزين؛ هذا الصفّ يحمل مساره وحالته ومن راجعه.
 * ولا يُعرَض `encrypted_path` في أي استجابة: من يملك المسار يملك الملفّ إن
 * تسرّب مفتاح، والمسار لا يفيد الواجهة في شيء.
 */
class KycDocument extends Model
{
    protected $fillable = [
        'user_id', 'doc_type', 'encrypted_path', 'original_mime', 'size_bytes',
        'content_sha256', 'status', 'reviewed_by', 'reviewed_at',
        'rejection_reason', 'document_expires_at',
        // AMIAL-KYC-OCR-001
        'ocr_status', 'ocr_extracted', 'verified_fields',
        'ocr_confidence', 'ocr_engine', 'ocr_ran_at', 'ocr_findings',
    ];

    /**
     * المُشفَّران يُخفيان كالمسار.
     *
     * `ocr_extracted` و`verified_fields` يحملان الاسم ورقم الهوية. وهما
     * مشفَّران في الجدول، لكنّ نموذجاً يُسلسَل إلى JSON في ردٍّ ما يُخرجهما
     * كنصٍّ مشفَّر إلى العميل — ولا فائدة في ذلك إلّا تسريبُ حجمِ البيانات
     * وشكلِها. يُقرآن عبر `KycOcrService` وحده.
     */
    protected $hidden = ['encrypted_path', 'ocr_extracted', 'verified_fields'];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'document_expires_at' => 'date',
        'size_bytes' => 'integer',
        'ocr_confidence' => 'decimal:2',
        'ocr_ran_at' => 'datetime',
        'ocr_findings' => 'array',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    /** كان معتمداً، لكن طلب إعادة التحقق جعله غير صالح لقرارٍ جديد. */
    public const STATUS_SUPERSEDED = 'superseded';

    public const TYPE_ID_FRONT = 'national_id_front';
    public const TYPE_ID_BACK = 'national_id_back';
    public const TYPE_PASSPORT = 'passport';
    public const TYPE_SELFIE = 'selfie';
    public const TYPE_ADDRESS_PROOF = 'address_proof';

    public const ALL_TYPES = [
        self::TYPE_ID_FRONT, self::TYPE_ID_BACK, self::TYPE_PASSPORT,
        self::TYPE_SELFIE, self::TYPE_ADDRESS_PROOF,
    ];

    public const TYPE_LABELS = [
        self::TYPE_ID_FRONT => 'بطاقة الهوية — الوجه',
        self::TYPE_ID_BACK => 'بطاقة الهوية — الظهر',
        self::TYPE_PASSPORT => 'جواز السفر',
        self::TYPE_SELFIE => 'صورة شخصية حيّة',
        self::TYPE_ADDRESS_PROOF => 'إثبات عنوان',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * مستندٌ معتمَد لكنّ وثيقته انتهت لا يوثّق شيئاً.
     *
     * ويُفصل عن `status` عمداً: الحالة تصف قرار المراجع، والانتهاء يصف
     * الوثيقة نفسها. وخلطُهما يجعل مستنداً سليماً يظهر «مرفوضاً» بمرور الوقت،
     * فيُعاد فحصه بلا سبب.
     */
    public function isUsable(): bool
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }

        return $this->document_expires_at === null
            || $this->document_expires_at->isFuture();
    }
}
