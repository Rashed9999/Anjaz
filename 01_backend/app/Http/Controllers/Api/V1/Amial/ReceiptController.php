<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function download(Request $request, int $id): JsonResponse|StreamedResponse
    {
        // AMIAL-FIX(PDF): كان يبثّ ملفّاً مُولّداً مسبقاً على القرص، لكن تخزين
        // Railway مؤقّت (ephemeral) — يختفي الملفّ بعد إعادة النشر (أو لم تُشغَّل
        // مهمّة التوليد أصلاً) فينقطع الاتصال أثناء الاستلام
        // (Connection closed while receiving data). الحلّ: نولّد الـ PDF عند
        // الطلب مباشرةً (نفس مسار invoice، بلا اعتماد على ملفّ مُخزَّن).
        return $this->invoice($request, $id);
    }

    /**
     * AMIAL-THERMAL-001 — GET /api/v1/amial/receipts/{id}/thermal?size=58|80
     * يُصيّر إيصالاً حرارياً (58مم/80مم) عند الطلب لطابعات POS. متزامن (سريع).
     */
    public function thermal(Request $request, int $id): JsonResponse|StreamedResponse
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

        if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return $this->error('PDF_UNAVAILABLE', 'PDF engine not installed', 500);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('receipts.thermal', [
            'receipt' => $receipt,
            'user' => $receipt->user,
            'counterparty' => $receipt->counterparty,
            'qrUrl' => $qrUrl,
            'width' => $width,
            'widthMm' => $widthMm,
        ])->setPaper([0, 0, $width, 900]); // ارتفاع سخيّ (رول حراري يُقصّ)

        $receipt->incrementDownloadCount();

        return new StreamedResponse(function () use ($pdf) {
            // AMIAL-FIX(PDF): تفريغ أي مُخرَجات عالقة (تحذيرات PHP…) قبل بثّ الـ PDF
            // حتى لا تُفسد بايتات الترويسة %PDF فيتعذّر فتح الملفّ.
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo $pdf->output();
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$receipt->receipt_number}_{$widthMm}mm.pdf\"",
        ]);
    }

    /**
     * AMIAL-INVOICE-A4-001 — GET /api/v1/amial/receipts/{id}/invoice
     * يُصيّر فاتورة رسمية بمقاس A4 (وثيقة رسمية: ترويسة، بنود، إجماليات،
     * مبلغ كتابةً، ختم/توقيع، QR). متزامن.
     */
    public function invoice(Request $request, int $id): JsonResponse|StreamedResponse
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

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('receipts.a4-invoice', [
            'receipt' => $receipt,
            'user' => $receipt->user,
            'counterparty' => $receipt->counterparty,
            'qrUrl' => $qrUrl,
            'businessName' => $businessName,
            'amountWords' => $this->amountInArabicWords((float) $receipt->net_amount),
            'items' => $items,
        ])->setPaper('a4', 'portrait');

        $receipt->incrementDownloadCount();

        return new StreamedResponse(function () use ($pdf) {
            // AMIAL-FIX(PDF): تفريغ أي مُخرَجات عالقة قبل بثّ الـ PDF (انظر thermal).
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo $pdf->output();
        }, 200, [
            'Content-Type' => 'application/pdf',
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
        // Sanitize
        if (!preg_match('/^[A-Z2-9]{16}$/', $code)) {
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
