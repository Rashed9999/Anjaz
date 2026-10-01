<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Merchant\MerchantOverrideService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * جسرُ اعتمادٍ صريح بين نقطة البيع ومالك المنشأة.
 *
 * الموظف يطلب الإذن من مسار الفعل نفسه، والمالك يرى الطلب هنا ويمنحه
 * لمرّة واحدة. التنفيذ يبقى بيد الموظف بعد الاعتماد، فلا تتحول لوحة
 * المالك إلى منفّذٍ بالنيابة ولا يضيع «من فعل» في سجل التدقيق.
 */
class WebApprovalController extends Controller
{
    public function __construct(private readonly MerchantOverrideService $overrides) {}

    public function index(Request $request): JsonResponse
    {
        $owner = $request->user('merchant_web');

        $rows = DB::table('merchant_permission_overrides')
            ->where('merchant_user_id', $owner->id)
            ->whereIn('status', ['pending', 'granted'])
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $users = User::whereIn(
            'id', $rows->pluck('requested_by_user_id')->filter()->unique(),
        )->get()->keyBy('id');

        $pos = PosUser::where('merchant_user_id', $owner->id)
            ->whereIn('user_id', $rows->pluck('requested_by_user_id')->filter()->unique())
            ->get()
            ->keyBy('user_id');

        $items = $rows->map(function ($row) use ($users, $pos): array {
            $user = $users->get((int) $row->requested_by_user_id);
            $employee = $pos->get((int) $row->requested_by_user_id);
            $name = $employee?->display_name ?: trim(
                (string) ($user?->f_name ?? '') . ' ' . (string) ($user?->l_name ?? '')
            );

            return [
                'id' => (int) $row->id,
                'status' => (string) $row->status,
                'permission_code' => (string) $row->permission_code,
                'permission_label' => $this->permissionLabel((string) $row->permission_code),
                'amount' => $row->max_amount === null ? null : (string) $row->max_amount,
                'reason' => (string) $row->reason,
                'requested_by_user_id' => (int) $row->requested_by_user_id,
                'requested_by_name' => $name !== '' ? $name : 'موظف نقطة بيع',
                'employee_code' => $employee?->pos_number,
                'expires_at' => $row->expires_at,
                'created_at' => $row->created_at,
                'granted_by_user_id' => $row->granted_by_user_id === null
                    ? null : (int) $row->granted_by_user_id,
            ];
        })->values();

        return $this->ok(['approvals' => $items]);
    }

    public function grant(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'note' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) {
            return $this->error('VALIDATION', $v->errors()->first(), 422);
        }

        try {
            $this->overrides->grant(
                $request->user('merchant_web'),
                $id,
                $request->input('note'),
            );
        } catch (DomainException $e) {
            return $this->error('APPROVAL_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(
            ['approval' => ['id' => $id, 'status' => 'granted']],
            'APPROVAL_GRANTED',
            'تم منح الإذن لمرة واحدة. على الموظف إعادة تنفيذ العملية.'
        );
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);
        if ($v->fails()) {
            return $this->error('VALIDATION', $v->errors()->first(), 422);
        }

        try {
            $this->overrides->reject(
                $request->user('merchant_web'),
                $id,
                (string) $request->input('reason'),
            );
        } catch (DomainException $e) {
            return $this->error('REJECTION_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(
            ['approval' => ['id' => $id, 'status' => 'rejected']],
            'APPROVAL_REJECTED',
            'تم رفض طلب الإذن.'
        );
    }

    private function permissionLabel(string $permission): string
    {
        return match ($permission) {
            'retail.return.create' => 'إنشاء مرتجع مبيعات',
            'retail.waste.record' => 'تسجيل هالك مخزون',
            'fuel.sale.cancel' => 'إلغاء بيع وقود',
            default => $permission,
        };
    }

    private function ok(
        array $meta,
        string $code = 'OK',
        string $message = 'OK',
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => (object) [],
        ], $status);
    }
}
