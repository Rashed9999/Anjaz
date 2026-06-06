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
