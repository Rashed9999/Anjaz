<?php

namespace App\Services\Ocr;

interface OcrDriverInterface
{
    /** هل المحرّك متاحٌ فعلاً على هذا الخادم؟ */
    public function available(): bool;

    /** يقرأ ملفّاً على القرص ويُعيد النصّ ودرجة الثقة. */
    public function read(string $absolutePath): OcrResult;

    public function name(): string;
}
