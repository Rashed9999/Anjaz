<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\AuditDecision;
use App\Models\Branch;
use App\Models\PosUser;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use App\Support\AuditVocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-MERCHANT-AUDIT-001 — سجلّ التدقيق للتاجر (باقة التاجر برو فأعلى).
 *
 * يعرض قيود التدقيق غير القابلة للتعديل (append-only، مسلسلة بالهاش) التي
 * يكون التاجر طرفاً فيها — فاعلاً أو موضوعاً. للشفافية والامتثال.
 *
 *   GET /api/v1/amial/merchant/audit-log?limit=&severity=
 */
class MerchantAuditController extends Controller
{
    public function __construct(private FeatureAccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || $user->role !== A::ROLE_MERCHANT) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($user, A::F_AUDIT_LOG)) {
            return $this->error('FEATURE_LOCKED', 'سجلّ التدقيق متاح في باقة التاجر برو فأعلى', 402);
        }

        $limit = min((int) $request->query('limit', 100), 300);
        $severity = $request->query('severity');

        // الموظف يعمل باسم المنشأة، لذلك أثره جزء من سجلّ صاحبها. قصرُ
        // القراءة على user_id للمالك يجعل السجل يقول «لا شيء» وهو يبيع.
        $staffUserIds = PosUser::where('merchant_user_id', $user->id)
            ->whereNotNull('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $actorIds = array_values(array_unique(array_merge([(int) $user->id], $staffUserIds)));
        $staffNames = PosUser::where('merchant_user_id', $user->id)
            ->whereNotNull('user_id')->pluck('display_name', 'user_id')->all();

        $q = AuditDecision::query()
            ->where(function ($w) use ($user, $actorIds) {
                $w->whereIn('actor_user_id', $actorIds)
                  ->orWhere(function ($s) use ($user) {
                      $s->where('subject_type', 'user')->where('subject_id', $user->id);
                  });
            })
            ->orderByDesc('id')
            ->limit($limit);

        if ($severity && in_array($severity, ['info', 'warning', 'critical'], true)) {
            $q->where('severity', $severity);
        }

        $rows = $q->get();
        $branchIds = $rows->map(fn (AuditDecision $a) => $this->context($a)['branch_id'] ?? null)
            ->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->values();
        $branchNames = Branch::where('merchant_user_id', $user->id)
            ->whereIn('id', $branchIds)->pluck('name', 'id')->all();

        $entries = $rows->map(function (AuditDecision $a) use ($user, $staffNames, $branchNames) {
            $action = AuditVocabulary::action($a->action);
            $decision = AuditVocabulary::decisionCode($a->decision_code);
            $severity = AuditVocabulary::severity($a->severity);
            $context = $this->context($a);
            $branchId = isset($context['branch_id']) && is_numeric($context['branch_id'])
                ? (int) $context['branch_id'] : null;

            return [
                'id' => $a->id,
                'action' => $a->action,
                'action_label' => $action['label'],
                'action_translated' => $action['translated'],
                'decision_code' => $a->decision_code,
                'decision_label' => $decision['label'],
                'decision_tone' => $decision['tone'],
                'reason' => $this->reasonLabel($a->reason),
                'severity' => $a->severity ?? 'info',
                'severity_label' => $severity['label'],
                'actor_label' => (int) $a->actor_user_id === (int) $user->id
                    ? 'مالك المنشأة'
                    : ($staffNames[$a->actor_user_id] ?? AuditVocabulary::actorType($a->actor_type)),
                'branch' => $branchId === null ? null : [
                    'id' => $branchId,
                    'name' => $branchNames[$branchId] ?? 'فرع غير متاح',
                ],
                'details' => $this->details($context),
                'transaction_id' => $a->transaction_id,
                'created_at' => $a->created_at?->toIso8601String(),
            ];
        });

        return $this->ok(['entries' => $entries, 'count' => $entries->count()], 'OK', 'سجلّ التدقيق');
    }

    /** @return array<string,mixed> */
    private function context(AuditDecision $entry): array
    {
        $decoded = json_decode((string) $entry->getRawOriginal('context'), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int,array{label:string,value:string}> */
    private function details(array $context): array
    {
        $labels = [
            'branch_id' => 'معرّف الفرع', 'branch_name' => 'الفرع',
            'staff_id' => 'معرّف الموظف', 'employee_code' => 'رمز الموظف',
            'device_id' => 'معرّف الجهاز', 'pos_device_id' => 'معرّف جهاز نقطة البيع',
            'device_name' => 'اسم الجهاز', 'merchant_user_id' => 'معرّف التاجر',
            'amount' => 'المبلغ', 'currency' => 'العملة', 'reference' => 'المرجع',
            'request_id' => 'معرّف الطلب', 'request_path' => 'مسار الطلب',
            'ip_address' => 'عنوان الشبكة',
            'old_branch_id' => 'الفرع السابق', 'new_branch_id' => 'الفرع الجديد',
        ];
        $out = [];
        foreach ($context as $key => $value) {
            if (! isset($labels[$key]) || is_array($value) || is_object($value)) continue;
            $out[] = ['label' => $labels[$key], 'value' => (string) $value];
        }
        return $out;
    }

    private function reasonLabel(?string $reason): ?string
    {
        return match (trim((string) $reason)) {
            'requester cancelled pending money request' => 'ألغى صاحب الطلب طلب المال المعلّق',
            'withdraw_request' => 'طلب سحب قيد المراجعة',
            default => $reason,
        };
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }
}
