<?php

namespace App\Services\Ocr;

/**
 * AMIAL-KYC-OCR-001 — ناتج قراءةٍ واحدة.
 *
 * **`status` ليس تفصيلاً.** ثلاث حالاتٍ تبدو واحدة للمراجع — لا بيانات
 * مستخرجة — وهي مختلفة تماماً في ما تعنيه وما يُفعل حيالها:
 *
 *   • `failed` — شُغِّل المحرّك ولم يُخرج شيئاً: **الوثيقة رديئة**، تُطلب
 *     صورةٌ أوضح من العميل.
 *   • `unavailable` — المحرّك غير مثبَّت: **عطلٌ في الخادم**. لا شأن للعميل
 *     به، ولا يُصلَح بطلب صورةٍ أخرى، ويجب أن يظهر لفريق التشغيل لا أن
 *     يُبتلع في مراجعةٍ يدوية إلى الأبد.
 *   • `low_confidence` — قُرئ بضباب: يُعرَض النصّ ولا تُملأ الحقول.
 */
final class OcrResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_LOW_CONFIDENCE = 'low_confidence';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly string $status,
        public readonly string $rawText = '',
        public readonly float $confidence = 0.0,
        public readonly ?string $engine = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function unavailable(string $why): self
    {
        return new self(self::STATUS_UNAVAILABLE, error: $why);
    }

    public static function failed(string $why, ?string $engine = null): self
    {
        return new self(self::STATUS_FAILED, engine: $engine, error: $why);
    }

    public function usable(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_LOW_CONFIDENCE], true);
    }
}
