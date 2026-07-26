<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SafePayment;
use App\Services\SafePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1) — admin endpoints لإدارة النزاعات.
 *
 * **Permission required:** transactions.refund OR transactions.reverse
 *
 * في v1، الإدارة هي صاحبة القرار الوحيد للنزاعات (لا إفراج تلقائي).
 */
class AdminSafePaymentController extends Controller
{
    public function __construct(
        private readonly SafePaymentService $service,
    ) {}

    /** GET /admin/safe-payments?status=disputed&page=1 */
    public function index(Request $request): JsonResponse
    {
        $query = SafePayment::query()->orderByDesc('disputed_at')->orderByDesc('id');

        if ($request->query('status') === 'disputed_open') {
            $query->needingAdminReview();
        } elseif ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        $items = $query->with(['buyer:id,f_name,l_name,phone', 'seller:id,f_name,l_name,phone'])
            ->paginate(20);

        return $this->ok([
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
            ],
            'items' => $items->items(),
        ]);
    }

    public function show(Request $request, string $ulid): JsonResponse
    {
        $payment = SafePayment::where('payment_ulid', $ulid)
            ->with(['buyer', 'seller', 'events'])
            ->first();
        if (!$payment) return $this->error('NOT_FOUND', 'Not found', 404);

        return $this->ok([
            'payment' => $payment,
            // AMIAL-SAFEPAY-EVIDENCE-001: القرار المالي يُبنى على الأدلّة.
            // كانت الأدلّة تُرفع وتُخزَّن ولا تصل من يقرّر — فيحكم بين طرفين
            // بنصّ الشكوى وحده وهو أضعف ما في الملفّ.
            'evidence' => $this->evidenceForReview($payment),
            'dispute_reason_label' => $payment->dispute_reason_code
                ? (SafePaymentService::DISPUTE_REASONS[$payment->dispute_reason_code] ?? null)
                : null,
            'delivery_code_verified' => $payment->delivery_code_verified_at !== null,
            'delivery_code_attempts' => (int) $payment->delivery_code_attempts,
        ]);
    }

    /**
     * أدلّة العملية مجموعةً بالمرحلة، مع التحقّق من سلامة كل ملفّ.
     *
     * التحقّق يُجرى هنا لا في الواجهة: من يوقّع قراراً مالياً يجب أن يُقال له
     * صراحةً إن كانت بصمة الملفّ لم تعد تطابق المسجَّلة وقت الرفع.
     */
    private function evidenceForReview(SafePayment $payment): array
    {
        $svc = app(\App\Services\SafePaymentEvidenceService::class);

        return \App\Models\SafePaymentEvidence::where('safe_payment_id', $payment->id)
            ->orderBy('created_at')
            ->get()
            ->map(function (\App\Models\SafePaymentEvidence $e) use ($svc) {
                return $svc->present($e) + [
                    'url' => route('admin.amial.safe-payments.evidence-file', ['id' => $e->id]),
                    'integrity_ok' => $svc->verifyIntegrity($e),
                    'note' => $e->note,
                ];
            })
            ->groupBy('stage')
            ->toArray();
    }

    /** GET /admin/amial/safe-payments/evidence/{id}/file — الملفّ للمراجع. */
    public function evidenceFile(Request $request, int $id)
    {
        $evidence = \App\Models\SafePaymentEvidence::find($id);
        if (!$evidence) return $this->error('NOT_FOUND', 'الدليل غير موجود', 404);

        $contents = app(\App\Services\SafePaymentEvidenceService::class)->read($evidence);
        if ($contents === null) {
            return $this->error('FILE_MISSING', 'تعذّر قراءة الملفّ من القرص', 404);
        }

        return response($contents, 200, [
            'Content-Type' => $evidence->mime,
            'Content-Length' => (string) strlen($contents),
            // أدلّة نزاع: لا تُخزَّن في وسيط مشترك ولا تُفهرس.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /admin/safe-payments/{ulid}/release */
    public function resolveRelease(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:5000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'Not found', 404);

        try {
            $payment = $this->service->adminResolveRelease($payment, $request->user(), $request->input('reason'));
        } catch (\RuntimeException $e) {
            return $this->error('RESOLVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['payment' => $payment], 'RESOLVED_RELEASE', 'تم الإفراج للبائع');
    }

    public function resolveRefund(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:5000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'Not found', 404);

        try {
            $payment = $this->service->adminResolveRefund($payment, $request->user(), $request->input('reason'));
        } catch (\RuntimeException $e) {
            return $this->error('RESOLVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['payment' => $payment], 'RESOLVED_REFUND', 'تم الاسترداد للمشتري');
    }

    public function resolvePartial(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'buyer_refund_amount' => 'required|numeric|min:0',
            'reason' => 'required|string|min:10|max:5000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'Not found', 404);

        try {
            $payment = $this->service->adminResolvePartial(
                $payment, $request->user(),
                (string)$request->input('buyer_refund_amount'),
                $request->input('reason'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('RESOLVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['payment' => $payment], 'RESOLVED_PARTIAL', 'تم الاسترداد الجزئي');
    }

    // ============================================================
    private function ok(array $meta, string $code = 'OK', string $message = 'OK'): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(),
            'meta' => (object)[],
        ], 422);
    }
}
