<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Models\ReportExport;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMIAL-REPORTS-001 (v2.11) — طلب وتحميل التقارير.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $service) {}

    /** POST /api/v1/amial/reports/request */
    public function request(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'report_type' => 'required|in:merchant_ledger,user_transactions,platform_performance,aml_compliance,agent_settlement',
            'format' => 'sometimes|in:csv,pdf,excel',
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $user = $request->user();
        $reportType = $request->input('report_type');

        // صلاحيات: التقارير الإدارية للأدمن فقط
        $adminOnly = ['platform_performance', 'aml_compliance', 'agent_settlement'];
        if (in_array($reportType, $adminOnly, true) && (int)($user->type ?? 2) !== 0) {
            return $this->error('FORBIDDEN', 'هذا التقرير للإدارة فقط', 403);
        }

        $params = array_filter([
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'merchant_user_id' => $reportType === 'merchant_ledger' ? $user->id : null,
        ]);

        $requesterType = match ((int)($user->type ?? 2)) {
            0 => 'admin', 3 => 'merchant', default => 'user',
        };

        $export = $this->service->request($user, $reportType,
            $request->input('format', 'csv'), $params, $requesterType);

        // أرسل للـ queue (الخلفية)
        GenerateReportJob::dispatch($export->id);

        return $this->ok([
            'export_ulid' => $export->export_ulid,
            'status' => $export->status,
            'message' => 'يُولَّد التقرير في الخلفية. تابع الحالة ثم حمّله عند الجاهزية.',
        ], 'REPORT_REQUESTED');
    }

    /** GET /api/v1/amial/reports/{ulid}/status */
    public function status(Request $request, string $ulid): JsonResponse
    {
        $export = ReportExport::where('export_ulid', $ulid)
            ->where('requested_by_user_id', $request->user()->id)->first();
        if (!$export) return $this->error('NOT_FOUND', 'التقرير غير موجود', 404);

        return $this->ok([
            'export_ulid' => $export->export_ulid,
            'status' => $export->status,
            'row_count' => $export->row_count,
            'is_ready' => $export->isReady(),
            'error' => $export->error_message,
        ]);
    }

    /** GET /api/v1/amial/reports/{ulid}/download */
    public function download(Request $request, string $ulid): JsonResponse|StreamedResponse
    {
        $export = ReportExport::where('export_ulid', $ulid)
            ->where('requested_by_user_id', $request->user()->id)->first();
        if (!$export) return $this->error('NOT_FOUND', 'التقرير غير موجود', 404);

        if (!$export->isReady()) {
            return $this->error('NOT_READY', 'التقرير غير جاهز أو منتهي الصلاحية', 422);
        }

        $export->increment('download_count');

        $ext = $export->format === 'pdf' ? 'pdf' : 'csv';
        $filename = "{$export->report_type}_{$export->export_ulid}.{$ext}";

        return Storage::disk('local')->download($export->file_path, $filename);
    }

    /** GET /api/v1/amial/reports — قائمة تقارير المستخدم */
    public function index(Request $request): JsonResponse
    {
        $reports = ReportExport::where('requested_by_user_id', $request->user()->id)
            ->orderByDesc('created_at')->limit(50)
            ->get(['export_ulid', 'report_type', 'format', 'status', 'row_count', 'created_at', 'expires_at']);

        return $this->ok(['reports' => $reports]);
    }

    // ============================================================
    private function ok(array $meta, string $code = 'OK'): JsonResponse
    {
        return new JsonResponse(['success' => true, 'code' => $code,
            'message' => 'OK', 'errors' => (object)[], 'meta' => $meta]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => $code,
            'message' => $message, 'errors' => (object)[], 'meta' => (object)[]], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[]], 422);
    }
}
