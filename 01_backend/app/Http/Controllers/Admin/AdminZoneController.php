<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ZoneAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-ZONE-ASSIGN-001 (v2.0) — admin zone management.
 */
class AdminZoneController extends Controller
{
    public function __construct(
        private readonly ZoneAssignmentService $zoneService,
        private readonly AuditService $audit,
    ) {}

    /** POST /admin/amial/zone/assign */
    public function assign(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'zone' => 'required|in:SOUTH,NORTH,MIDDLE,OTHER,UNKNOWN',
            'reason' => 'required|string|min:5|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $user = User::findOrFail($request->input('user_id'));

        $actor = $request->user();
        abort_unless($actor, 401);

        try {
            $zone = $this->zoneService->assignByAdmin(
                $user,
                $request->input('zone'),
                $actor->id,
                $request->input('reason'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('ASSIGN_FAILED', $e->getMessage(), 422);
        }

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'ZONE_CHANGED',
            'decision_code' => 'ZONE_SET',
            'reason' => (string) $request->input('reason'),
            'zone_code' => $zone,
            'severity' => 'notice',
            'context' => ['assignment_method' => 'admin_decision'],
        ]);

        return $this->ok(['user_id' => $user->id, 'zone' => $zone], 'ASSIGNED', 'تم إسناد المنطقة');
    }

    /** POST /admin/amial/zone/assign-from-kyc */
    public function assignFromKyc(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'city' => 'required|string|min:2|max:100',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $user = User::findOrFail($request->input('user_id'));
        $actor = $request->user();
        abort_unless($actor, 401);
        $zone = $this->zoneService->assignFromKyc(
            $user, $request->input('city'), $actor->id,
        );

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'ZONE_ASSIGNED_FROM_KYC',
            'decision_code' => 'ZONE_KYC_SET',
            'reason' => 'إسناد من محافظة موثقة في KYC',
            'zone_code' => $zone,
            'severity' => 'notice',
            'context' => ['assignment_method' => 'kyc_verification'],
        ]);

        return $this->ok([
            'user_id' => $user->id,
            'city' => $request->input('city'),
            'detected_zone' => $zone,
        ], 'ASSIGNED', "تم تحديد المنطقة: {$zone}");
    }

    /** GET /admin/amial/zone/logs/{userId} */
    public function logs(Request $request, int $userId): JsonResponse
    {
        $logs = DB::table('zone_assignment_logs')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $this->ok(['logs' => $logs]);
    }

    /** GET /admin/amial/zone/stats */
    public function stats(): JsonResponse
    {
        $distribution = DB::table('users')
            ->select('zone_code', DB::raw('count(*) as total'))
            ->groupBy('zone_code')
            ->get();

        return $this->ok(['distribution' => $distribution]);
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
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
