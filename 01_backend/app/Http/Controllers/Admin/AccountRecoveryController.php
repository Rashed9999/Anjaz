<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountRecoveryRequest;
use App\Services\AccountRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-RECOVERY-001 — Admin endpoints
 *
 *   GET  /api/v1/admin/recovery/requests           — قائمة الطلبات
 *   GET  /api/v1/admin/recovery/requests/{ulid}    — تفاصيل
 *   POST /api/v1/admin/recovery/requests/{ulid}/approve
 *   POST /api/v1/admin/recovery/requests/{ulid}/reject
 */
class AccountRecoveryController extends Controller
{
    public function __construct(
        private readonly AccountRecoveryService $service,
    ) {}

    // ============================================================
    // AMIAL-ADMIN-001 (v0.8): Web wrappers (Blade views)
    // ============================================================

    public function webIndex(Request $request)
    {
        $status = $request->query('status', 'pending_review');
        $query = AccountRecoveryRequest::with(['user', 'reviewer'])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->paginate(20)->withQueryString();

        return view('admin-views.amial.recovery.index', ['requests' => $requests]);
    }

    public function webShow(string $ulid)
    {
        $req = AccountRecoveryRequest::with(['user', 'reviewer'])
            ->where('request_ulid', $ulid)
            ->first();

        if (!$req) {
            abort(404, translate('Recovery request not found'));
        }

        return view('admin-views.amial.recovery.show', ['req' => $req]);
    }

    public function webApprove(Request $request, string $ulid)
    {
        $req = AccountRecoveryRequest::where('request_ulid', $ulid)->first();
        if (!$req) {
            abort(404);
        }

        $v = Validator::make($request->all(), [
            'admin_notes' => 'sometimes|string|max:2000',
        ]);
        if ($v->fails()) {
            return back()->withErrors($v);
        }

        $ok = $this->service->adminApprove(
            $req,
            adminId: auth('admin')->id() ?? auth()->id() ?? 1,
            adminNotes: $request->input('admin_notes'),
        );

        if (!$ok) {
            return back()->with('warning', translate('Request not in pending_review state'));
        }

        return back()->with('success', translate('Recovery approved. 7-day security hold applied.'));
    }

    public function webReject(Request $request, string $ulid)
    {
        $req = AccountRecoveryRequest::where('request_ulid', $ulid)->first();
        if (!$req) {
            abort(404);
        }

        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:5|max:2000',
        ]);
        if ($v->fails()) {
            return back()->withErrors($v);
        }

        $ok = $this->service->adminReject(
            $req,
            adminId: auth('admin')->id() ?? auth()->id() ?? 1,
            reason: $request->input('reason'),
        );

        if (!$ok) {
            return back()->with('warning', translate('Request not in pending_review state'));
        }

        return back()->with('success', translate('Recovery rejected'));
    }

    // ============================================================
    // JSON endpoints (v0.7-A — kept for API consumers)
    // ============================================================

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending_review');

        $requests = AccountRecoveryRequest::with(['user', 'reviewer'])
            ->when($status, fn($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20);

        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => 'Recovery requests',
            'errors' => (object)[],
            'meta' => [
                'pagination' => [
                    'total' => $requests->total(),
                    'per_page' => $requests->perPage(),
                    'current_page' => $requests->currentPage(),
                    'last_page' => $requests->lastPage(),
                ],
                'items' => $requests->items(),
            ],
        ]);
    }

    public function show(string $ulid): JsonResponse
    {
        $req = AccountRecoveryRequest::with(['user', 'reviewer'])
            ->where('request_ulid', $ulid)
            ->first();

        if (!$req) {
            return new JsonResponse([
                'success' => false, 'code' => 'NOT_FOUND',
                'message' => 'Request not found',
                'errors' => (object)[], 'meta' => (object)[],
            ], 404);
        }

        return new JsonResponse([
            'success' => true, 'code' => 'OK',
            'message' => 'Recovery request details',
            'errors' => (object)[],
            'meta' => $req->toArray(),
        ]);
    }

    public function approve(Request $request, string $ulid): JsonResponse
    {
        $req = AccountRecoveryRequest::where('request_ulid', $ulid)->first();
        if (!$req) {
            return new JsonResponse([
                'success' => false, 'code' => 'NOT_FOUND',
                'message' => 'Request not found',
                'errors' => (object)[], 'meta' => (object)[],
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'admin_notes' => 'sometimes|string|max:2000',
        ]);
        if ($validator->fails()) {
            return new JsonResponse([
                'success' => false, 'code' => 'VALIDATION_FAILED',
                'message' => 'Invalid input',
                'errors' => $validator->errors(), 'meta' => (object)[],
            ], 422);
        }

        $ok = $this->service->adminApprove(
            $req,
            adminId: $request->user()->id,
            adminNotes: $request->input('admin_notes'),
        );

        if (!$ok) {
            return new JsonResponse([
                'success' => false, 'code' => 'INVALID_STATE',
                'message' => 'Request not in pending_review state',
                'errors' => (object)[], 'meta' => (object)[],
            ], 409);
        }

        $req->refresh();
        return new JsonResponse([
            'success' => true,
            'code' => 'RECOVERY_HOLD_APPLIED',
            'message' => 'Recovery approved; security hold applied',
            'errors' => (object)[],
            'meta' => $req->toArray(),
        ]);
    }

    public function reject(Request $request, string $ulid): JsonResponse
    {
        $req = AccountRecoveryRequest::where('request_ulid', $ulid)->first();
        if (!$req) {
            return new JsonResponse([
                'success' => false, 'code' => 'NOT_FOUND',
                'message' => 'Request not found',
                'errors' => (object)[], 'meta' => (object)[],
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:5|max:2000',
        ]);
        if ($validator->fails()) {
            return new JsonResponse([
                'success' => false, 'code' => 'VALIDATION_FAILED',
                'message' => 'Reason is required',
                'errors' => $validator->errors(), 'meta' => (object)[],
            ], 422);
        }

        $ok = $this->service->adminReject(
            $req,
            adminId: $request->user()->id,
            reason: $request->input('reason'),
        );

        if (!$ok) {
            return new JsonResponse([
                'success' => false, 'code' => 'INVALID_STATE',
                'message' => 'Request not in pending_review state',
                'errors' => (object)[], 'meta' => (object)[],
            ], 409);
        }

        $req->refresh();
        return new JsonResponse([
            'success' => true,
            'code' => 'RECOVERY_REJECTED',
            'message' => 'Recovery rejected',
            'errors' => (object)[],
            'meta' => $req->toArray(),
        ]);
    }
}
