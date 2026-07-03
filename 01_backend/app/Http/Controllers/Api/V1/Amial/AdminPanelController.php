<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\FuelVarianceRecord;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\FuelShiftService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * CRITICAL-001-ADMIN — لوحة الإدارة الموحّدة.
 *
 * Endpoints:
 *   GET  /admin/merchants                   ← قائمة تجار + خطط + توثيق
 *   GET  /admin/dashboard                   ← إحصائيات
 *   GET  /admin/variances/pending           ← العجز/الفائض قيد المراجعة
 *   POST /admin/variances/{id}/resolve      ← حلّ variance
 *   POST /admin/merchants/{id}/verify       ← توثيق سريع (موافقة/رفض)
 */
class AdminPanelController extends Controller
{
    public function __construct(
        private readonly FuelShiftService $shiftSvc,
    ) {}

    /** قائمة التجار مع تفاصيل ملخّصة. */
    public function listMerchants(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $q = MerchantProfile::with('user:id,f_name,l_name,phone,zone_code');

        if ($request->filled('plan')) $q->where('subscription_plan', $request->query('plan'));
        if ($request->filled('business_type')) $q->where('business_type', $request->query('business_type'));
        if ($request->filled('verification_status')) $q->where('verification_status', $request->query('verification_status'));
        if ($request->filled('search')) {
            $s = $request->query('search');
            $q->whereHas('user', fn($u) => $u->where('phone', 'like', "%{$s}%")
                ->orWhere('f_name', 'like', "%{$s}%"));
        }

        $page = $q->orderByDesc('id')->paginate((int)$request->query('per_page', 20));

        return $this->ok([
            'merchants' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Dashboard: إحصائيات سريعة. */
    public function dashboard(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $totalMerchants = MerchantProfile::count();
        $pendingVerification = MerchantProfile::where('verification_status', 'pending_review')->count();
        $pendingVariances = FuelVarianceRecord::where('resolution_status', 'pending')->count();

        // توزيع التجار على الخطط
        $byPlan = MerchantProfile::selectRaw('subscription_plan, COUNT(*) as c')
            ->groupBy('subscription_plan')->get()
            ->mapWithKeys(fn($r) => [$r->subscription_plan => $r->c])->toArray();

        // توزيع التجار على business_types
        $byBiz = MerchantProfile::selectRaw('business_type, COUNT(*) as c')
            ->whereNotNull('business_type')->groupBy('business_type')->get()
            ->mapWithKeys(fn($r) => [$r->business_type => $r->c])->toArray();

        return $this->ok([
            'totals' => [
                'merchants' => $totalMerchants,
                'pending_verification' => $pendingVerification,
                'pending_variances' => $pendingVariances,
            ],
            'distribution' => [
                'by_plan' => $byPlan,
                'by_business_type' => $byBiz,
            ],
        ]);
    }

    /** Variance Records قيد المراجعة. */
    public function pendingVariances(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $variances = FuelVarianceRecord::where('resolution_status', 'pending')
            ->with('shift:id,shift_ulid,station_id,opened_at,closed_at,variance,actual_cash,expected_cash')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return $this->ok(['variances' => $variances]);
    }

    /** حلّ variance. */
    public function resolveVariance(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $v = Validator::make($request->all(), [
            'resolution' => 'required|in:accepted,covered_by_employee,charged_to_petty_cash,written_off',
            'note' => 'sometimes|nullable|string|max:1000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $record = FuelVarianceRecord::find($id);
        if (!$record) return $this->error('NOT_FOUND', 'السجل غير موجود', 404);

        try {
            $resolved = $this->shiftSvc->resolveVariance(
                $record,
                $request->user()->id,
                $request->input('resolution'),
                $request->input('note'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }

        return $this->ok(['variance' => $resolved], 'RESOLVED', 'تم الحلّ');
    }

    /** توثيق سريع للتاجر. */
    public function verifyMerchant(Request $request, int $merchantProfileId): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $v = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject,resubmission_required',
            'note' => 'sometimes|nullable|string|max:1000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $merchant = MerchantProfile::find($merchantProfileId);
        if (!$merchant) return $this->error('NOT_FOUND', 'التاجر غير موجود', 404);

        $action = $request->input('action');
        $status = match($action) {
            'approve' => 'verified',
            'reject' => 'rejected',
            'resubmission_required' => 'resubmission_required',
        };

        $merchant->update([
            'verification_status' => $status,
            'verified_by_admin_id' => $request->user()->id,
            'verified_at' => $action === 'approve' ? now() : null,
        ]);

        return $this->ok(['merchant' => $merchant->fresh()], 'VERIFIED', 'تم التحديث');
    }

    // ============ Helpers ============

    private function isAdmin(?User $user): bool
    {
        if (!$user) return false;
        return (int) ($user->type ?? -1) === 0
            || in_array($user->role, [A::ROLE_ADMIN, 'super_admin'], true); // AMIAL-FIX(C1): لا النوع 1 (وكيل)
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ], $status);
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
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
