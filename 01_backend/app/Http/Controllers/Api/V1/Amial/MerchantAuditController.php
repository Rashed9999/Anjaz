<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\AuditDecision;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
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

        $q = AuditDecision::query()
            ->where(function ($w) use ($user) {
                $w->where('actor_user_id', $user->id)
                  ->orWhere(function ($s) use ($user) {
                      $s->where('subject_type', 'user')->where('subject_id', $user->id);
                  });
            })
            ->orderByDesc('id')
            ->limit($limit);

        if ($severity && in_array($severity, ['info', 'warning', 'critical'], true)) {
            $q->where('severity', $severity);
        }

        $entries = $q->get()->map(fn (AuditDecision $a) => [
            'id' => $a->id,
            'action' => $a->action,
            'action_label' => $this->actionLabel($a->action),
            'decision_code' => $a->decision_code,
            'reason' => $a->reason,
            'severity' => $a->severity ?? 'info',
            'transaction_id' => $a->transaction_id,
            'created_at' => $a->created_at?->toIso8601String(),
        ]);

        return $this->ok(['entries' => $entries, 'count' => $entries->count()], 'OK', 'سجلّ التدقيق');
    }

    private function actionLabel(?string $action): string
    {
        return match ($action) {
            'SEND_MONEY_COMPLETED' => 'تحويل صادر',
            'MERCHANT_PAYMENT' => 'استلام دفعة',
            'ADMIN_CREATE_USER' => 'إنشاء حساب بواسطة الإدارة',
            'ADMIN_CHANGE_PLAN' => 'تغيير الباقة',
            'WITHDRAW_COMPLETED' => 'سحب مكتمل',
            default => $action ?? '—',
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
