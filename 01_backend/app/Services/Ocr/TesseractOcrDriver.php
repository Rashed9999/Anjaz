<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * AMIAL-KYC-OCR-001 — محرّك Tesseract.
 *
 * **لماذا يُشغَّل كعمليةٍ خارجية لا عبر امتداد PHP:** امتدادات الربط
 * بـTesseract تُحمَّل داخل عملية PHP نفسها، فانهيارُ المحرّك على صورةٍ
 * مشوّهة يُسقط الطلب كلّه. والعملية المنفصلة تموت وحدها ويبقى الرفع قائماً
 * — والعميل الذي رفع صورته لا يجب أن يُقابَل بخطأ ٥٠٠ لأنّ محرّكاً تعثّر.
 *
 * **ودرجة الثقة تُقرأ من المحرّك لا تُقدَّر.** `tesseract` يُخرج ثقةً لكلّ
 * كلمة في وضع `tsv`، فتُستخرَج ويُحسب متوسّطها المرجَّح. وتقديرُها من طول
 * النصّ أو عدد الحروف — وهو ما يُفعل أحياناً — يُنتج رقماً يبدو دقيقاً ولا
 * يعني شيئاً.
 */
class TesseractOcrDriver implements OcrDriverInterface
{
    private ?bool $availableCache = null;

    public function __construct(
        private readonly string $binary,
        private readonly string $languages,
        private readonly int $timeout,
    ) {
    }

    public function name(): string
    {
        return 'tesseract';
    }

    public function available(): bool
    {
        if ($this->availableCache !== null) {
            return $this->availableCache;
        }

        try {
            $p = new Process([$this->binary, '--version']);
            $p->setTimeout(5);
            $p->run();

            return $this->availableCache = $p->isSuccessful();
        } catch (\Throwable) {
            return $this->availableCache = false;
        }
    }

    public function read(string $absolutePath): OcrResult
    {
        if (!$this->available()) {
            return OcrResult::unavailable(
                "محرّك القراءة «{$this->binary}» غير مثبَّت على الخادم",
            );
        }

        if (!is_readable($absolutePath)) {
            return OcrResult::failed('تعذّرت قراءة الملفّ', $this->name());
        }

        // `tsv` لا `txt`: الأوّل يُخرج ثقةً لكلّ كلمة، والثاني نصّاً مجرّداً
        // بلا مقياسٍ لجودته. ومنه يُشتقّ النصّ والثقة معاً بتشغيلةٍ واحدة.
        $process = new Process([
            $this->binary, $absolutePath, 'stdout',
            '-l', $this->languages,
            '--psm', '6',        // كتلة نصّ موحّدة — يناسب بطاقات الهوية
            'tsv',
        ]);
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return OcrResult::failed(
                "تجاوزت القراءة {$this->timeout} ثانية — الصورة كبيرة أو معطوبة",
                $this->name(),
            );
        } catch (\Throwable $e) {
            Log::warning('OCR process failed', ['error' => $e->getMessage()]);

            return OcrResult::failed('تعذّر تشغيل محرّك القراءة', $this->name());
        }

        if (!$process->isSuccessful()) {
            return OcrResult::failed(
                trim($process->getErrorOutput()) ?: 'خرج المحرّك بحالة فشل',
                $this->name(),
            );
        }

        return $this->parseTsv($process->getOutput());
    }

    /**
     * @return OcrResult
     */
    private function parseTsv(string $tsv): OcrResult
    {
        $lines = preg_split('/\R/', trim($tsv)) ?: [];

        if (count($lines) < 2) {
            return OcrResult::failed('لم يُخرج المحرّك نصّاً', $this->name());
        }

        $header = str_getcsv(array_shift($lines), "\t");
        $confIdx = array_search('conf', $header, true);
        $textIdx = array_search('text', $header, true);

        if ($confIdx === false || $textIdx === false) {
            return OcrResult::failed('صيغة ناتج غير متوقّعة', $this->name());
        }

        $words = [];
        $confSum = 0.0;
        $confCount = 0;

        foreach ($lines as $line) {
            $cols = str_getcsv($line, "\t");
            $text = trim((string) ($cols[$textIdx] ?? ''));
            $conf = (float) ($cols[$confIdx] ?? -1);

            if ($text === '') {
                continue;
            }

            $words[] = $text;

            // ‎-1 تعني «ليست كلمة» (فاصل سطر أو كتلة). إدخالُها في المتوسّط
            // يسحبه إلى الأسفل بلا معنى، فتبدو قراءةٌ سليمة ضعيفة.
            if ($conf >= 0) {
                $confSum += $conf;
                $confCount++;
            }
        }

        if ($words === []) {
            return OcrResult::failed('الصورة لا تحتوي نصّاً مقروءاً', $this->name());
        }

        $avg = $confCount > 0 ? round($confSum / $confCount, 2) : 0.0;

        return new OcrResult(
            status: OcrResult::STATUS_SUCCESS,
            rawText: implode(' ', $words),
            confidence: $avg,
            engine: $this->name() . ' (' . $this->languages . ')',
        );
    }
}
