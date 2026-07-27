<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMIAL-RECEIPTS-001 (v0.9-A)
 *
 * APIs:
 *   GET  /api/v1/amial/receipts                    — قائمة إيصالات المستخدم
 *   GET  /api/v1/amial/receipts/{id}               — تفاصيل إيصال
 *   GET  /api/v1/amial/receipts/{id}/download      — تحميل PDF
 *   GET  /v/{verification_code}                    — تحقق عام (لا مصادقة)
 */
class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->query('type');

        $query = Receipt::forUser($user->id)->orderByDesc('issued_at');
        if ($type) {
            $query->where('receipt_type', $type);
        }

        $receipts = $query->paginate(20);

        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => 'Receipts list',
            'errors' => (object)[],
            'meta' => [
                'pagination' => [
                    'total' => $receipts->total(),
                    'per_page' => $receipts->perPage(),
                    'current_page' => $receipts->currentPage(),
                ],
                'items' => $receipts->items(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$receipt) {
            return $this->error('RECEIPT_NOT_FOUND', 'Receipt not found', 404);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => 'Receipt details',
            'errors' => (object)[],
            'meta' => $receipt->toArray(),
        ]);
    }

    /**
     * GET /api/v1/amial/receipts/{id}/download
     * يعيد PDF stream.
     */
    public function download(Request $request, int $id): \Symfony\Component\HttpFoundation\Response
    {
        // AMIAL-PDF-PIPELINE-001 — «ولّد مرّة، اخدم كثيراً».
        //
        // كان هذا المسار يُعيد التصيير في **كل** طلب، لأن الملف المخزَّن لم يكن
        // يُنتَج أصلاً: المهمّة تُرسَل إلى طابور `receipts` والعامل يستمع إلى
        // `default,notifications` فقط. فبقي كل تنزيل تصييراً كاملاً داخل الطلب —
        // بطيء على خادم صغير، فيُقطع الاتصال على شبكة جوّال
        // (Connection closed while receiving data).
        //
        // هذا ما تفعله الشركات: المستند يُولَّد مرّة عند إنشاء العملية ويُخزَّن،
        // ثم يُخدَم كقراءة ملف — أجزاء من الثانية. وإن غاب الملف (نشر جديد على
        // تخزين مؤقّت) نُولّده **ونحفظه** فتكون المرّة التالية فورية.
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$receipt) {
            return $this->error('RECEIPT_NOT_FOUND', 'Receipt not found', 404);
        }

        $path = $receipt->pdf_storage_path;
        if ($path && Storage::disk('local')->exists($path)) {
            $receipt->incrementDownloadCount();

            return response(Storage::disk('local')->get($path), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Length' => (string) Storage::disk('local')->size($path),
                'Content-Disposition' => "inline; filename=\"{$receipt->receipt_number}.pdf\"",
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        // لا ملف بعد — ولّده الآن **بشكل متزامن** واحفظه للمرّات القادمة.
        try {
            (new \App\Jobs\GeneratePdfReceiptJob($receipt->id))->handle();
            $receipt->refresh();
            $path = $receipt->pdf_storage_path;
            if ($path && Storage::disk('local')->exists($path)) {
                $receipt->incrementDownloadCount();

                return response(Storage::disk('local')->get($path), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Length' => (string) Storage::disk('local')->size($path),
                    'Content-Disposition' => "inline; filename=\"{$receipt->receipt_number}.pdf\"",
                    'Cache-Control' => 'private, max-age=86400',
                ]);
            }
        } catch (\Throwable $e) {
            // AMIAL-PDF-VISIBILITY-001: كان تحذيراً بنصّ الرسالة وحدها.
            //
            // وذلك يعني أن أخطر فشل في هذا المسار — تعذّر تصيير الإيصال —
            // يُبتلع بلا نوع ولا ملفّ ولا سطر ولا أثر استدعاء. فيبقى السؤال
            // «لماذا لا يُحمَّل الإيصال؟» بلا جواب في السجلّ، وقد بقي كذلك
            // فعلاً حتى كشفه تسجيلُ شاشة من جهاز المستخدم.
            \Illuminate\Support\Facades\Log::error('Receipt PDF generation failed', [
                'receipt_id' => $receipt->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
            ]);
        }

        // آخر ملاذ: مسار الفاتورة القديم (تصيير في الذاكرة بلا تخزين).
        return $this->invoice($request, $id);
    }

    /**
     * AMIAL-THERMAL-001 — GET /api/v1/amial/receipts/{id}/thermal?size=58|80
     * يُصيّر إيصالاً حرارياً (58مم/80مم) عند الطلب لطابعات POS. متزامن (سريع).
     */
    public function thermal(Request $request, int $id): \Symfony\Component\HttpFoundation\Response
    {
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$receipt) {
            return $this->error('RECEIPT_NOT_FOUND', 'Receipt not found', 404);
        }

        // العرض: 58مم≈164pt، 80مم≈226pt (72pt/بوصة، 25.4مم/بوصة)
        $sizeMm = (int) $request->query('size', 58);
        $widthMm = in_array($sizeMm, [58, 80], true) ? $sizeMm : 58;
        $width = (int) round($widthMm / 25.4 * 72);

        // QR للتحقّق (SVG data-URI — بلا imagick)
        $qrUrl = null;
        try {
            $verifyUrl = rtrim((string) config('app.url'), '/') . '/v/' . $receipt->verification_code;
            $svg = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(120)->margin(0)->generate($verifyUrl);
            $qrUrl = 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable $e) { /* بلا QR — نعرض الرمز نصّاً فقط */ }

        // AMIAL-FIX(PDF-RTL): mPDF بدل DomPDF — تشكيل الحروف العربية وترتيبها.
        $html = view('receipts.thermal', [
            'receipt' => $receipt,
            'user' => $receipt->user,
            'counterparty' => $receipt->counterparty,
            'qrUrl' => $qrUrl,
            'width' => $width,
            'widthMm' => $widthMm,
        ])->render();

        $content = \App\Support\ArabicPdf::render($html, [
            'format' => \App\Support\ArabicPdf::thermalFormat($widthMm),
            'margin' => 3,
        ]);

        $receipt->incrementDownloadCount();

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => "inline; filename=\"{$receipt->receipt_number}_{$widthMm}mm.pdf\"",
        ]);
    }

    /**
     * AMIAL-INVOICE-A4-001 — GET /api/v1/amial/receipts/{id}/invoice
     * يُصيّر فاتورة رسمية بمقاس A4 (وثيقة رسمية: ترويسة، بنود، إجماليات،
     * مبلغ كتابةً، ختم/توقيع، QR). متزامن.
     */
    public function invoice(Request $request, int $id): \Symfony\Component\HttpFoundation\Response
    {
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$receipt) {
            return $this->error('RECEIPT_NOT_FOUND', 'Receipt not found', 404);
        }

        // QR للتحقّق (SVG data-URI — بلا imagick)
        $qrUrl = null;
        try {
            $verifyUrl = rtrim((string) config('app.url'), '/') . '/v/' . $receipt->verification_code;
            $svg = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(120)->margin(0)->generate($verifyUrl);
            $qrUrl = 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable $e) { /* بلا QR — نعرض الرمز نصّاً فقط */ }

        // اسم النشاط
        $businessName = 'أميال باي';
        try {
            $bn = \App\CentralLogics\Helpers::get_business_settings('business_name');
            if (is_string($bn) && $bn !== '') {
                $businessName = $bn;
            }
        } catch (\Throwable $e) { /* افتراضي */ }

        // بنود الفاتورة إن وُجدت في metadata.items
        $items = [];
        $md = $receipt->metadata;
        if (is_array($md) && !empty($md['items']) && is_array($md['items'])) {
            $items = $md['items'];
        }

        if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return $this->error('PDF_UNAVAILABLE', 'PDF engine not installed', 500);
        }

        // AMIAL-FIX(PDF-FINAL): نخزّن ناتج التصيير مؤقّتاً (15 دقيقة) بمفتاح مرتبط
        // بآخر تعديل للإيصال. الإيصال ثابت المحتوى، فالتصيير مرّة واحدة يكفي —
        // وإن أسقط الخادمُ الاتصالَ أثناء الإرسال (شبكة بطيئة)، تصل محاولة
        // التطبيق التالية فوراً من الكاش بلا إعادة تصيير ثقيل.
        $cacheKey = "receipt_pdf_a4:{$receipt->id}:" . (optional($receipt->updated_at)->timestamp ?? 0);
        $content = Cache::get($cacheKey);

        if ($content === null) {
            // AMIAL-FIX(PDF-RTL): كان DomPDF يعرض العربية أحرفاً منفصلة ومقلوبة
            // (لا يدعم تشكيل الحروف ولا bidi). mPDF يشكّل ويصل ويعيد الترتيب.
            $html = view('receipts.a4-invoice', [
                'receipt' => $receipt,
                'user' => $receipt->user,
                'counterparty' => $receipt->counterparty,
                'qrUrl' => $qrUrl,
                'businessName' => $businessName,
                'amountWords' => $this->amountInArabicWords((float) $receipt->net_amount),
                'items' => $items,
            ])->render();

            // هامش 0 هنا: القالب يضبط @page margin بنفسه — وإضافة هامش ثانٍ
            // تُضاعفه فيفيض المحتوى إلى صفحة إضافية فارغة.
            $content = \App\Support\ArabicPdf::render($html, ["format" => "A4", "margin" => 14]);

            try {
                Cache::put($cacheKey, $content, now()->addMinutes(15));
            } catch (\Throwable $e) { /* الكاش تحسين لا شرط */ }
        }

        $receipt->incrementDownloadCount();

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => "inline; filename=\"INV-{$receipt->receipt_number}.pdf\"",
        ]);
    }

    /**
     * يحوّل مبلغاً رقمياً إلى نص عربي (ريال يمني + فلوس).
     * تغطية عملية حتى المليارات؛ الكسر يُقرّب لخانتين.
     */
    private function amountInArabicWords(float $amount): string
    {
        $whole = (int) floor($amount);
        $fraction = (int) round(($amount - $whole) * 100);

        $words = $this->intToArabicWords($whole) . ' ريال يمني';
        if ($fraction > 0) {
            $words .= ' و' . $this->intToArabicWords($fraction) . ' فلساً';
        }

        return 'فقط ' . $words . ' لا غير.';
    }

    /** تحويل عدد صحيح إلى كلمات عربية. */
    private function intToArabicWords(int $n): string
    {
        if ($n === 0) {
            return 'صفر';
        }

        $ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة',
            'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر',
            'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
        $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
        $hundreds = ['', 'مئة', 'مئتان', 'ثلاثمئة', 'أربعمئة', 'خمسمئة', 'ستمئة', 'سبعمئة', 'ثمانمئة', 'تسعمئة'];
        $scales = ['', 'ألف', 'مليون', 'مليار', 'تريليون'];

        // معالجة كل مجموعة من 3 خانات
        $groups = [];
        while ($n > 0) {
            $groups[] = $n % 1000;
            $n = intdiv($n, 1000);
        }

        $parts = [];
        for ($g = count($groups) - 1; $g >= 0; $g--) {
            $val = $groups[$g];
            if ($val === 0) {
                continue;
            }

            $chunk = [];
            $h = intdiv($val, 100);
            $rem = $val % 100;
            if ($h > 0) {
                $chunk[] = $hundreds[$h];
            }
            if ($rem > 0) {
                if ($rem < 20) {
                    $chunk[] = $ones[$rem];
                } else {
                    $t = intdiv($rem, 10);
                    $o = $rem % 10;
                    if ($o > 0) {
                        $chunk[] = $ones[$o] . ' و' . $tens[$t];
                    } else {
                        $chunk[] = $tens[$t];
                    }
                }
            }

            $chunkStr = implode(' و', $chunk);

            // أسماء المقاييس (ألف/مليون...) — تبسيط عملي دون تصريف كامل
            if ($g > 0) {
                if ($g === 1) {
                    // ألف/ألفان/آلاف
                    if ($val === 1) {
                        $chunkStr = 'ألف';
                    } elseif ($val === 2) {
                        $chunkStr = 'ألفان';
                    } elseif ($val >= 3 && $val <= 10) {
                        $chunkStr .= ' آلاف';
                    } else {
                        $chunkStr .= ' ألف';
                    }
                } else {
                    $chunkStr .= ' ' . $scales[$g];
                }
            }

            $parts[] = $chunkStr;
        }

        return implode(' و', $parts);
    }

    /**
     * GET /v/{code}  (public, no auth)
     * تحقق من صحة إيصال عبر QR scan.
     */
    public function verifyPublic(string $code): JsonResponse
    {
        // AMIAL-RECEIPT-NUMBERS-001: يُقبل الشكلان — رقميّ (الجديد) وBase32
        // (القديم). ونسمح بالمسافات والشَرطات لأن العميل يكتب الكود كما
        // يراه مجموعاً على الورقة: «1234 5678 9012 3456».
        $code = preg_replace('/[\s\-]+/', '', strtoupper($code));

        if (!preg_match('/^([0-9]{16}|[A-Z2-9]{16})$/', $code)) {
            return $this->error('INVALID_CODE', 'Verification code format invalid', 400);
        }

        $result = $this->service->verifyByCode($code);
        if (!$result) {
            return $this->error('RECEIPT_NOT_FOUND', 'No valid receipt for this code', 404);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'VERIFICATION_OK',
            'message' => 'Receipt is authentic',
            'errors' => (object)[],
            'meta' => $result,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object)[],
            'meta' => (object)[],
        ], $status);
    }
}
