<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Reporting\CustomerLedgerReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-CUSTOMER-REPORTS-003 — ملخص التقارير من الخادم.
 *
 * هذا المسار لا يرسل قائمة محدودة ثم يطلب من الهاتف جمعها. الخادم يجمع
 * كامل الدفتر للفترة، بدقة DECIMAL، ويعيد العملات منفصلة.
 */
class CustomerReportController extends Controller
{
    public function __construct(
        private readonly CustomerLedgerReportService $reports,
        private readonly AuditService $audit,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = $request->user();
        $from = $request->query('from') ?: null;
        $to = $request->query('to') ?: now()->toDateString();
        $payload = $this->reports->summary((int) $user->id, $from, $to);

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => (int) $user->id,
            'subject_type' => 'customer_report',
            'subject_id' => (string) $user->id,
            'action' => 'CUSTOMER_REPORT_SUMMARY_VIEWED',
            'decision_code' => 'ALLOW',
            'severity' => 'info',
            'context' => [
                'from' => $from,
                'to' => $to,
                'currencies' => count($payload['by_currency'] ?? []),
            ],
        ]);

        return response()->json([
            'success' => true,
            'meta' => $payload,
        ]);
    }
}
