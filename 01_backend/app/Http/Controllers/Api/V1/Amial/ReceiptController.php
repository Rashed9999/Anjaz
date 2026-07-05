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
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$receipt) {
            return $this->error('RECEIPT_NOT_FOUND', 'Receipt not found', 404);
        }

        if (!$receipt->isReady()) {
            return $this->error(
                'RECEIPT_NOT_READY',
                'Receipt PDF is still being generated. Try again in a few seconds.',
                202, // Accepted but not ready
            );
        }

        if (!Storage::disk('local')->exists($receipt->pdf_storage_path)) {
            return $this->error('RECEIPT_FILE_MISSING', 'PDF file is missing from storage', 500);
        }

        // تتبع التحميل
        $receipt->incrementDownloadCount();

        return Storage::disk('local')->download(
            $receipt->pdf_storage_path,
            "{$receipt->receipt_number}.pdf",
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$receipt->receipt_number}.pdf\"",
            ],
        );
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
            echo $pdf->output();
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$receipt->receipt_number}_{$widthMm}mm.pdf\"",
        ]);
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
