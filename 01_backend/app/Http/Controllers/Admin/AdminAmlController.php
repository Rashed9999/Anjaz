<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aml\AmlAlert;
use App\Models\Aml\AmlFlaggedTransaction;
use App\Models\Aml\AmlRule;
use App\Models\Aml\AmlUserRiskProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-AML-001 (v1.4) — admin endpoints لإدارة AML.
 */
class AdminAmlController extends Controller
{
    // ============ Rules ============
    public function indexRules(): JsonResponse
    {
        $rules = AmlRule::orderBy('priority')->paginate(20);
        return $this->ok([
            'pagination' => $this->pag($rules),
            'items' => $rules->items(),
        ]);
    }

    public function showRule(int $id): JsonResponse
    {
        $rule = AmlRule::findOrFail($id);
        return $this->ok(['rule' => $rule]);
    }

    public function toggleRule(Request $request, int $id): JsonResponse
    {
        $rule = AmlRule::findOrFail($id);
        $rule->update([
            'is_active' => !$rule->is_active,
            'updated_by_admin_id' => $request->user()->id,
        ]);
        return $this->ok(['rule' => $rule], 'TOGGLED',
            $rule->is_active ? 'تم تفعيل القاعدة' : 'تم إيقاف القاعدة');
    }

    public function updateRule(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'parameters' => 'sometimes|array',
            'action_on_match' => 'sometimes|in:allow,flag,hold,block',
            'risk_score_contribution' => 'sometimes|numeric|min:0|max:100',
            'priority' => 'sometimes|integer|min:0|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $rule = AmlRule::findOrFail($id);
        $rule->fill(array_merge(
            $v->validated(),
            ['updated_by_admin_id' => $request->user()->id],
        ));
        $rule->save();

        return $this->ok(['rule' => $rule], 'UPDATED', 'تم تحديث القاعدة');
    }

    // ============ Flagged Transactions ============
    public function indexFlagged(Request $request): JsonResponse
    {
        $query = AmlFlaggedTransaction::with(['actor:id,phone_masked,f_name', 'counterparty:id,phone_masked,f_name']);
        if ($status = $request->query('status')) {
            $query->where('current_status', $status);
        }
        if ($severity = $request->query('min_risk_score')) {
            $query->where('total_risk_score', '>=', $severity);
        }
        $items = $query->orderByDesc('id')->paginate(20);
        return $this->ok([
            'pagination' => $this->pag($items),
            'items' => $items->items(),
        ]);
    }

    public function showFlagged(string $ulid): JsonResponse
    {
        $flag = AmlFlaggedTransaction::with(['actor', 'counterparty'])
            ->where('flag_ulid', $ulid)
            ->first();
        if (!$flag) return $this->error('NOT_FOUND', 'Not found', 404);

        // اجلب profile للمستخدم
        $profile = AmlUserRiskProfile::find($flag->actor_user_id);

        return $this->ok([
            'flagged' => $flag,
            'user_profile' => $profile,
        ]);
    }

    public function approveFlagged(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'note' => 'required|string|min:10|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $flag = AmlFlaggedTransaction::where('flag_ulid', $ulid)->first();
        if (!$flag) return $this->error('NOT_FOUND', 'Not found', 404);
        if (!$flag->isPending()) return $this->error('NOT_PENDING', 'Not in pending status', 422);

        $flag->update([
            'current_status' => 'approved_by_admin',
            'reviewed_by_admin_id' => $request->user()->id,
            'reviewed_at' => now(),
            'review_decision_note' => $request->input('note'),
            // ملاحظة: لا ننفذ المعاملة المالية تلقائياً هنا.
            // العملية الحالية تم رفضها بالفعل. الـ approve هنا للـ audit فقط.
            // إن أراد المستخدم إعادة المحاولة، عليه إعادة الإرسال.
        ]);

        return $this->ok(['flagged' => $flag], 'APPROVED', 'تم قبول المعاملة');
    }

    public function rejectFlagged(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'note' => 'required|string|min:10|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $flag = AmlFlaggedTransaction::where('flag_ulid', $ulid)->first();
        if (!$flag) return $this->error('NOT_FOUND', 'Not found', 404);
        if (!$flag->isPending()) return $this->error('NOT_PENDING', 'Not in pending status', 422);

        $flag->update([
            'current_status' => 'rejected_by_admin',
            'reviewed_by_admin_id' => $request->user()->id,
            'reviewed_at' => now(),
            'review_decision_note' => $request->input('note'),
        ]);

        return $this->ok(['flagged' => $flag], 'REJECTED', 'تم رفض المعاملة');
    }

    // ============ Alerts ============
    public function indexAlerts(Request $request): JsonResponse
    {
        $query = AmlAlert::query();
        if ($status = $request->query('status', 'open')) {
            $query->where('status', $status);
        }
        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }
        $items = $query->orderByDesc('id')->paginate(20);
        return $this->ok([
            'pagination' => $this->pag($items),
            'items' => $items->items(),
        ]);
    }

    public function resolveAlert(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'note' => 'required|string|min:5|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $alert = AmlAlert::where('alert_ulid', $ulid)->first();
        if (!$alert) return $this->error('NOT_FOUND', 'Not found', 404);

        $alert->update([
            'status' => 'resolved',
            'resolved_by_admin_id' => $request->user()->id,
            'resolved_at' => now(),
            'resolution_note' => $request->input('note'),
        ]);

        return $this->ok(['alert' => $alert], 'RESOLVED', 'تم حل التنبيه');
    }

    // ============ User Risk Profiles ============
    public function showUserProfile(int $userId): JsonResponse
    {
        $profile = AmlUserRiskProfile::with('user:id,phone_masked,f_name,l_name')
            ->find($userId);
        if (!$profile) return $this->error('NOT_FOUND', 'Profile not found', 404);

        // آخر 20 evaluations
        $recentEvals = \DB::table('aml_rule_evaluations')
            ->where('actor_user_id', $userId)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $this->ok([
            'profile' => $profile,
            'recent_evaluations' => $recentEvals,
        ]);
    }

    public function setUserOverride(Request $request, int $userId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'override' => 'required|in:none,whitelist,blacklist',
            'reason' => 'required|string|min:10|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $profile = AmlUserRiskProfile::firstOrCreate(
            ['user_id' => $userId],
            ['current_risk_score' => 0, 'risk_level' => 'low', 'manual_override' => 'none'],
        );

        $profile->update([
            'manual_override' => $request->input('override'),
            'override_reason' => $request->input('reason'),
            'override_admin_id' => $request->user()->id,
        ]);

        return $this->ok(['profile' => $profile], 'OVERRIDE_SET', 'تم تطبيق الإعداد');
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

    private function pag($items): array
    {
        return [
            'total' => $items->total(),
            'per_page' => $items->perPage(),
            'current_page' => $items->currentPage(),
        ];
    }
}
