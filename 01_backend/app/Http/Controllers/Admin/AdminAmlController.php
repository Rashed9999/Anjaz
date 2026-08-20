<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aml\AmlAlert;
use App\Models\Aml\AmlFlaggedTransaction;
use App\Models\Aml\AmlRule;
use App\Models\Aml\AmlUserRiskProfile;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-AML-001 (v1.4) — admin endpoints لإدارة AML.
 */
class AdminAmlController extends Controller
{
    /**
     * AMIAL-AML-PANEL-001 — الصفحة التي كانت ناقصة.
     *
     * اثنتا عشرة نقطة نهاية بُنيت هنا وسُجّلت في `routes/admin/amial.php`،
     * ولم تُفتح واحدةٌ منها من متصفّح قطّ: كلّها تردّ JSON ولا صفحة تستدعيها،
     * ولا رابط في القائمة الجانبية يصل إليها.
     *
     * ونتيجة ذلك أنّ نظام مكافحة غسل الأموال كان **يعمل بلا مُشغِّل**: يرصد
     * ويعلّق ويُنبّه، والمعلَّق يبقى معلّقاً لأنّ أحداً لا يملك شاشةً يعتمد
     * منها أو يرفض. وهذا أسوأ من غيابه — عميلٌ تُجمَّد عمليته ولا أحد يراها.
     */
    public function page()
    {
        return view('admin-views.amial.aml.index');
    }

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
        $before = (bool) $rule->is_active;
        $rule->update([
            'is_active' => !$rule->is_active,
            'updated_by_admin_id' => $request->user()->id,
        ]);
        $this->auditRuleChange($request, $rule, 'AML_RULE_TOGGLED', [
            'is_active' => $before,
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
            // وضع الظل يُرصد ولا يمنع. كان يُضبط بأمر سطر أوامر وحده، فمن لا
            // يملك الخادم لا يملك إخراج قاعدةٍ من الظل — وهو قرار سياسة لا صيانة.
            'shadow_mode' => 'sometimes|boolean',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $rule = AmlRule::findOrFail($id);
        $before = $rule->only([
            'parameters', 'action_on_match', 'risk_score_contribution',
            'priority', 'is_active', 'shadow_mode',
        ]);
        $rule->fill(array_merge(
            $v->validated(),
            ['updated_by_admin_id' => $request->user()->id],
        ));
        $rule->save();
        $this->auditRuleChange($request, $rule, 'AML_RULE_UPDATED', $before);

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
        $before = (string) $profile->manual_override;

        $profile->update([
            'manual_override' => $request->input('override'),
            'override_reason' => $request->input('reason'),
            'override_admin_id' => $request->user()->id,
        ]);

        app(AuditService::class)->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'aml_risk_profile',
            'subject_id' => (string) $profile->id,
            'action' => 'AML_USER_OVERRIDE_SET',
            'decision_code' => strtoupper((string) $profile->manual_override),
            'reason' => (string) $request->input('reason'),
            'severity' => $profile->manual_override === 'blacklist' ? 'critical' : 'warning',
            'context' => [
                'customer_id' => $userId,
                'before_override' => $before,
                'after_override' => $profile->manual_override,
            ],
        ]);

        return $this->ok(['profile' => $profile], 'OVERRIDE_SET', 'تم تطبيق الإعداد');
    }

    // ============ لوحة المؤشّرات + التبويبات ٢ و٣ و٦ ============

    public function dashboard(): JsonResponse
    {
        return $this->ok(app(\App\Services\AmlDashboardService::class)->metrics());
    }

    /** التبويب ٢ — مراقبة العمليات الكبيرة. */
    public function largeTransactions(): JsonResponse
    {
        return $this->ok([
            'items' => app(\App\Services\AmlDashboardService::class)->largeTransactionList(),
        ]);
    }

    /** التبويب ٣ — كشف تقسيم العمليات. */
    public function structuring(): JsonResponse
    {
        return $this->ok([
            'items' => app(\App\Services\AmlDashboardService::class)->structuringList(),
        ]);
    }

    /** التبويب ٦ — فحص العقوبات. */
    public function sanctions(Request $request): JsonResponse
    {
        return $this->ok([
            'items' => app(\App\Services\AmlDashboardService::class)
                ->sanctionList($request->query('review_status')),
        ]);
    }

    public function reviewSanction(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'decision' => 'required|in:dismissed,confirmed',
            'note' => 'required|string|min:10|max:2000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $out = app(\App\Services\AmlDashboardService::class)->reviewSanctionMatch(
                $id, $request->user(), $request->input('decision'), $request->input('note'),
            );
        } catch (\DomainException $e) {
            return $this->error('REJECTED', $e->getMessage(), 422);
        }

        return $this->ok($out, 'REVIEWED', 'سُجّل القرار في المطابقة');
    }

    // ============ مركز التحقيقات (الفصل ١٠ — التبويب ٧) ============

    public function indexInvestigations(Request $request): JsonResponse
    {
        return $this->ok([
            'items' => app(\App\Services\AmlInvestigationService::class)
                ->queue($request->query('status', 'open')),
        ]);
    }

    public function showInvestigation(int $id): JsonResponse
    {
        $inv = \App\Models\Aml\AmlInvestigation::find($id);
        if (!$inv) return $this->error('NOT_FOUND', 'القضية غير موجودة', 404);

        return $this->ok(app(\App\Services\AmlInvestigationService::class)->detail($inv));
    }

    /** فتح قضية من عملية معلّقة — الجسر الذي كان مفقوداً بين الرصد والتحقيق. */
    public function openInvestigation(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'flag_ulid' => 'required_without:user_id|string|size:26',
            'user_id' => 'required_without:flag_ulid|integer',
            'priority' => 'sometimes|in:low,medium,high,critical',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $svc = app(\App\Services\AmlInvestigationService::class);

        try {
            if ($request->filled('flag_ulid')) {
                $flag = AmlFlaggedTransaction::where('flag_ulid', $request->input('flag_ulid'))->first();
                if (!$flag) return $this->error('NOT_FOUND', 'العملية غير موجودة', 404);
                $inv = $svc->openFromFlagged($flag, $request->user(),
                    $request->input('priority', 'high'));
            } else {
                $inv = $svc->open((int) $request->input('user_id'), $request->user(),
                    'manual', null, $request->input('priority', 'medium'));
            }
        } catch (\DomainException $e) {
            return $this->error('REJECTED', $e->getMessage(), 422);
        }

        return $this->ok(['investigation' => $inv], 'OPENED',
            'فُتحت القضية ' . $inv->case_number);
    }

    public function investigationAction(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'action' => 'required|string',
            'reason' => 'required|string|min:10|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $inv = \App\Models\Aml\AmlInvestigation::find($id);
        if (!$inv) return $this->error('NOT_FOUND', 'القضية غير موجودة', 404);

        try {
            $inv = app(\App\Services\AmlInvestigationService::class)->takeAction(
                $inv, $request->user(), $request->input('action'), $request->input('reason'),
            );
        } catch (\DomainException $e) {
            return $this->error('REJECTED', $e->getMessage(), 422);
        }

        return $this->ok(['investigation' => $inv], 'DONE', 'نُفِّذ الإجراء وسُجِّل في ملفّ القضية');
    }

    public function investigationEvidence(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), ['note' => 'required|string|min:10|max:2000']);
        if ($v->fails()) return $this->validationError($v);

        $inv = \App\Models\Aml\AmlInvestigation::find($id);
        if (!$inv) return $this->error('NOT_FOUND', 'القضية غير موجودة', 404);

        try {
            app(\App\Services\AmlInvestigationService::class)
                ->addEvidence($inv, $request->user(), $request->input('note'));
        } catch (\DomainException $e) {
            return $this->error('REJECTED', $e->getMessage(), 422);
        }

        return $this->ok([], 'ADDED', 'أُضيف الدليل إلى الخطّ الزمنيّ');
    }

    public function closeInvestigation(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'decision' => 'required|string',
            'reason' => 'required|string|min:20|max:2000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $inv = \App\Models\Aml\AmlInvestigation::find($id);
        if (!$inv) return $this->error('NOT_FOUND', 'القضية غير موجودة', 404);

        try {
            $inv = app(\App\Services\AmlInvestigationService::class)->close(
                $inv, $request->user(), $request->input('decision'), $request->input('reason'),
            );
        } catch (\DomainException $e) {
            return $this->error('REJECTED', $e->getMessage(), 422);
        }

        return $this->ok(['investigation' => $inv], 'CLOSED', 'أُغلقت القضية');
    }

    public function reopenInvestigation(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:10|max:500']);
        if ($v->fails()) return $this->validationError($v);

        $inv = \App\Models\Aml\AmlInvestigation::find($id);
        if (!$inv) return $this->error('NOT_FOUND', 'القضية غير موجودة', 404);

        try {
            $inv = app(\App\Services\AmlInvestigationService::class)
                ->reopen($inv, $request->user(), $request->input('reason'));
        } catch (\DomainException $e) {
            return $this->error('REJECTED', $e->getMessage(), 422);
        }

        return $this->ok(['investigation' => $inv], 'REOPENED', 'أُعيد فتح القضية');
    }

    // ============ التقارير التنظيمية (الفصل ١٠ — التبويب ٨) ============

    public function indexReports(Request $request): JsonResponse
    {
        $svc = app(\App\Services\AmlRegulatoryReportService::class);

        return $this->ok([
            'items' => $svc->listReports($request->query('type'), $request->query('status')),
            'summary' => $svc->pendingSummary(),
            'ctr_threshold' => $svc->ctrThreshold(),
        ]);
    }

    public function generateStr(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), ['narrative' => 'required|string|min:50|max:5000']);
        if ($v->fails()) return $this->validationError($v);

        $inv = \App\Models\Aml\AmlInvestigation::find($id);
        if (!$inv) return $this->error('NOT_FOUND', 'القضية غير موجودة', 404);

        try {
            $report = app(\App\Services\AmlRegulatoryReportService::class)
                ->generateStr($inv, $request->user(), $request->input('narrative'));
        } catch (\DomainException $e) {
            return $this->error('REJECTED', $e->getMessage(), 422);
        }

        return $this->ok(['report' => $report], 'GENERATED',
            'وُلِّد بلاغ الاشتباه ' . $report->report_number);
    }

    public function generateCtr(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'amount' => 'required|numeric',
            'transaction_ulid' => 'sometimes|nullable|string|size:26',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $report = app(\App\Services\AmlRegulatoryReportService::class)->generateCtr(
                (int) $request->input('user_id'),
                (string) $request->input('amount'),
                $request->user(),
                $request->input('transaction_ulid'),
            );
        } catch (\DomainException $e) {
            return $this->error('REJECTED', $e->getMessage(), 422);
        }

        return $this->ok(['report' => $report], 'GENERATED',
            'وُلِّد بلاغ العملة ' . $report->report_number);
    }

    public function submitReport(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'external_reference' => 'required|string|min:3|max:120',
            'note' => 'sometimes|nullable|string|max:1000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $report = \App\Models\Aml\AmlRegulatoryReport::find($id);
        if (!$report) return $this->error('NOT_FOUND', 'البلاغ غير موجود', 404);

        try {
            $report = app(\App\Services\AmlRegulatoryReportService::class)->markSubmitted(
                $report, $request->user(),
                $request->input('external_reference'), $request->input('note'),
            );
        } catch (\DomainException $e) {
            return $this->error('REJECTED', $e->getMessage(), 422);
        }

        return $this->ok(['report' => $report], 'SUBMITTED', 'سُجّل إرسال البلاغ بمرجع الجهة');
    }

    // ============================================================
    /** تغييرات سياسة AML تظل قابلة للمراجعة، لا مجرّد updated_at. */
    private function auditRuleChange(Request $request, AmlRule $rule, string $action, array $before): void
    {
        app(AuditService::class)->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'aml_rule',
            'subject_id' => (string) $rule->id,
            'action' => $action,
            'decision_code' => (string) $rule->code,
            'severity' => in_array($rule->action_on_match, ['hold', 'block'], true)
                ? 'critical' : 'warning',
            'context' => [
                'before' => $before,
                'after' => $rule->only([
                    'parameters', 'action_on_match', 'risk_score_contribution',
                    'priority', 'is_active', 'shadow_mode',
                ]),
            ],
        ]);
    }

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
