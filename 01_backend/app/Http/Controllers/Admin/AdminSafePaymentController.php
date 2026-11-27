<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SafePayment;
use App\Models\User;
use App\Services\SafePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1) — admin endpoints لإدارة النزاعات.
 *
 * **Permission required:** transactions.refund OR transactions.reverse
 *
 * في v1، الإدارة هي صاحبة القرار الوحيد للنزاعات (لا إفراج تلقائي).
 */
class AdminSafePaymentController extends Controller
{
    public function __construct(
        private readonly SafePaymentService $service,
    ) {}

    /** GET /admin/safe-payments?status=disputed&page=1 */
    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $query = SafePayment::query()->orderByDesc('disputed_at')->orderByDesc('id');

        $status = (string) $request->query('status', 'disputed_open');
        if ($status === 'disputed_open') {
            $query->needingAdminReview();
        } elseif ($status !== '') {
            $query->where('status', $status);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('payment_ulid', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('buyer', fn ($users) => $users
                        ->where('phone', 'like', "%{$search}%")
                        ->orWhere('f_name', 'like', "%{$search}%")
                        ->orWhere('l_name', 'like', "%{$search}%"))
                    ->orWhereHas('seller', fn ($users) => $users
                        ->where('phone', 'like', "%{$search}%")
                        ->orWhere('f_name', 'like', "%{$search}%")
                        ->orWhere('l_name', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }

        if ($request->query('export') === 'csv') {
            $rows = $query->with(['buyer:id,f_name,l_name,phone', 'seller:id,f_name,l_name,phone'])
                ->limit(5000)
                ->get();
            $filename = 'safe_payments_' . now()->format('Ymd_His') . '.csv';

            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                // UTF-8 BOM لفتح العربية بشكل سليم في Excel
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, [
                    'المرجع', 'العنوان', 'المشتري', 'جوال المشتري',
                    'البائع', 'جوال البائع', 'المبلغ', 'المبلغ المحجوز',
                    'الحالة', 'تاريخ الإنشاء', 'تاريخ النزاع', 'تاريخ الحسم',
                ]);
                foreach ($rows as $p) {
                    $buyerName = $p->buyer ? trim(($p->buyer->f_name ?? '') . ' ' . ($p->buyer->l_name ?? '')) : '';
                    $sellerName = $p->seller ? trim(($p->seller->f_name ?? '') . ' ' . ($p->seller->l_name ?? '')) : '';
                    fputcsv($out, [
                        $this->csvSafe((string) $p->payment_ulid),
                        $this->csvSafe((string) ($p->title ?? '')),
                        $this->csvSafe($buyerName),
                        $this->csvSafe((string) ($p->buyer->phone ?? '')),
                        $this->csvSafe($sellerName),
                        $this->csvSafe((string) ($p->seller->phone ?? '')),
                        (string) $p->amount,
                        (string) $p->held_amount,
                        (string) $p->status,
                        optional($p->created_at)->format('Y-m-d H:i:s') ?? '',
                        optional($p->disputed_at)->format('Y-m-d H:i:s') ?? '',
                        optional($p->admin_resolved_at)->format('Y-m-d H:i:s') ?? '',
                    ]);
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $items = $query->with(['buyer:id,f_name,l_name,phone', 'seller:id,f_name,l_name,phone'])
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        $open = SafePayment::needingAdminReview();

        return $this->ok([
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
            ],
            'summary' => [
                'open_count' => (clone $open)->count(),
                'open_held_amount' => (string) ((clone $open)->sum('held_amount') ?: '0'),
            ],
            'items' => $items->items(),
        ]);
    }

    public function show(Request $request, string $ulid): JsonResponse
    {
        $payment = SafePayment::where('payment_ulid', $ulid)
            ->with(['buyer', 'seller', 'events'])
            ->first();
        if (!$payment) return $this->error('NOT_FOUND', 'Not found', 404);

        // AMIAL-SAFEPAY-AUDIT-001: من فتح النزاع ومتى.
        //
        // القرار وحده لا يكفي للكشف عن التحايل: موظّف يفتح نزاعات ليست من
        // نصيبه، أو يفتح نزاعاً عشرين مرّة قبل أن يحسمه، أو يفتحه ولا يحسمه
        // ثم يحسمه زميله — كلّها أنماط لا تظهر إلا إذا سُجّلت القراءة أيضاً.
        app(\App\Services\AuditService::class)->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()?->id,
            'subject_type' => 'safe_payment',
            'subject_id' => (string) $payment->id,
            'action' => 'SAFE_PAYMENT_DISPUTE_VIEWED',
            'decision_code' => 'VIEWED',
            'severity' => 'notice',
            'context' => [
                'payment_ulid' => $payment->payment_ulid,
                'status' => $payment->status,
                'ip' => $request->ip(),
            ],
        ]);

        $disputeEvent = $payment->events
            ->where('event_type', 'buyer_disputed')
            ->sortByDesc('id')
            ->first();

        $canDecide = (bool) $request->user()?->hasPlatformPermission('platform.disputes.decide');

        $previousActors = [];
        $fourEyesBlocked = false;
        try {
            $previousActors = app(\App\Services\FourEyesService::class)
                ->actorsOn('safe_payment', $payment->id);
            $currentAdminId = $request->user()?->id;
            if ($currentAdminId !== null) {
                $fourEyesBlocked = \App\Models\AuditDecision::query()
                    ->where('subject_type', 'safe_payment')
                    ->where('subject_id', (string) $payment->id)
                    ->where('actor_user_id', $currentAdminId)
                    ->whereIn('action', SafePaymentService::FOUR_EYES_BLOCKING_ACTIONS)
                    ->exists();
            }
        } catch (\Throwable) {
            $previousActors = [];
        }

        return $this->ok([
            'payment' => $payment,
            // AMIAL-SAFEPAY-EVIDENCE-001: القرار المالي يُبنى على الأدلّة.
            // كانت الأدلّة تُرفع وتُخزَّن ولا تصل من يقرّر — فيحكم بين طرفين
            // بنصّ الشكوى وحده وهو أضعف ما في الملفّ.
            'evidence' => $this->evidenceForReview($payment),
            'dispute_reason' => $disputeEvent?->note,
            'dispute_reason_label' => $payment->dispute_reason_code
                ? (SafePaymentService::DISPUTE_REASONS[$payment->dispute_reason_code] ?? null)
                : null,
            'can_decide' => $canDecide,
            'previous_actors' => $previousActors,
            'four_eyes_blocked' => $fourEyesBlocked,
            'trust_summary' => [
                'buyer' => $this->trustSummary((int) $payment->buyer_user_id, 'buyer'),
                'seller' => $this->trustSummary((int) $payment->seller_user_id, 'seller'),
            ],
            'delivery_code_verified' => $payment->delivery_code_verified_at !== null,
            'delivery_code_attempts' => (int) $payment->delivery_code_attempts,
            'audit_trail' => $this->auditTrail($payment),
        ]);
    }

    /**
     * سجلّ التدقيق لهذا النزاع — يُعرض للموظّف وهو يقرّر.
     *
     * عرضه أمامه ليس تكميلاً للمعلومة بل ردعٌ في محلّه: من يرى أن فتحه
     * للملفّ مُسجَّل باسمه ووقته، وأن قراره سيُقيَّد ببصمات الأدلّة التي
     * أمامه، يتصرّف تصرّف من يعلم أنه مرئيّ. سجلٌّ لا يراه أحد لا يردع أحداً.
     */
    private function auditTrail(SafePayment $payment): array
    {
        $rows = \App\Models\AuditDecision::where('subject_type', 'safe_payment')
            ->where('subject_id', (string) $payment->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['actor_type', 'actor_user_id', 'action',
                   'decision_code', 'reason', 'severity', 'created_at']);

        // أسماء الفاعلين باستعلام واحد لا باستعلام لكل سطر.
        $names = User::whereIn('id', $rows->pluck('actor_user_id')->filter()->unique())
            ->pluck('f_name', 'id');

        return $rows->map(fn ($row) => [
            'action' => $row->action,
            'decision_code' => $row->decision_code,
            'actor_type' => $row->actor_type,
            'actor_user_id' => $row->actor_user_id,
            'actor_name' => $names[$row->actor_user_id] ?? null,
            'reason' => $row->reason,
            'severity' => $row->severity,
            'at' => optional($row->created_at)->format('Y-m-d H:i'),
        ])->all();
    }

    /**
     * أدلّة العملية مجموعةً بالمرحلة، مع التحقّق من سلامة كل ملفّ.
     *
     * التحقّق يُجرى هنا لا في الواجهة: من يوقّع قراراً مالياً يجب أن يُقال له
     * صراحةً إن كانت بصمة الملفّ لم تعد تطابق المسجَّلة وقت الرفع.
     */
    private function evidenceForReview(SafePayment $payment): array
    {
        $svc = app(\App\Services\SafePaymentEvidenceService::class);

        return \App\Models\SafePaymentEvidence::where('safe_payment_id', $payment->id)
            ->orderBy('created_at')
            ->get()
            ->map(function (\App\Models\SafePaymentEvidence $e) use ($svc) {
                return $svc->present($e) + [
                    'url' => route('admin.amial.safe-payments.evidence-file', ['id' => $e->id]),
                    'integrity_ok' => $svc->verifyIntegrity($e),
                    'note' => $e->note,
                ];
            })
            ->groupBy('stage')
            ->toArray();
    }

    /** GET /admin/amial/safe-payments/evidence/{id}/file — الملفّ للمراجع. */
    public function evidenceFile(Request $request, int $id)
    {
        $evidence = \App\Models\SafePaymentEvidence::find($id);
        if (!$evidence) return $this->error('NOT_FOUND', 'الدليل غير موجود', 404);

        $contents = app(\App\Services\SafePaymentEvidenceService::class)->read($evidence);
        if ($contents === null) {
            return $this->error('FILE_MISSING', 'تعذّر قراءة الملفّ من القرص', 404);
        }

        // صور بضاعة الناس وفواتيرهم ومحادثاتهم. الوصول إليها مشروع للمراجع
        // ومشروط بأثر: من سحب ملفّاً بمعرّفه دون أن يفتح نزاعه أصلاً ظهر
        // في السجلّ وحده بلا فتحٍ يسبقه.
        app(\App\Services\AuditService::class)->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()?->id,
            'subject_type' => 'safe_payment',
            'subject_id' => (string) $evidence->safe_payment_id,
            'action' => 'SAFE_PAYMENT_EVIDENCE_VIEWED',
            'decision_code' => 'VIEWED',
            'severity' => 'notice',
            'context' => [
                'evidence_id' => $evidence->id,
                'stage' => $evidence->stage,
                'uploaded_by_role' => $evidence->role,
                'fingerprint' => substr((string) $evidence->sha256, 0, 12),
                'ip' => $request->ip(),
            ],
        ]);

        return response($contents, 200, [
            'Content-Type' => $evidence->mime,
            'Content-Length' => (string) strlen($contents),
            // أدلّة نزاع: لا تُخزَّن في وسيط مشترك ولا تُفهرس.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /admin/safe-payments/{ulid}/release */
    public function resolveRelease(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:5000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'Not found', 404);

        try {
            $payment = $this->service->adminResolveRelease($payment, $request->user(), $request->input('reason'));
        } catch (\DomainException $e) {
            // AMIAL-FOUR-EYES-001: منعٌ مقصود لا عطل — والرسالة تقول ذلك
            // صراحةً كي لا يُعاد المحاولة ولا يُظنّ الخلل في النظام.
            return $e->getMessage() === 'FOUR_EYES_VIOLATION'
                ? $this->error('FOUR_EYES_VIOLATION',
                    \App\Services\FourEyesService::message(), 403)
                : $this->error('RESOLVE_FAILED', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('RESOLVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['payment' => $payment], 'RESOLVED_RELEASE', 'تم الإفراج للبائع');
    }

    public function resolveRefund(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:5000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'Not found', 404);

        try {
            $payment = $this->service->adminResolveRefund($payment, $request->user(), $request->input('reason'));
        } catch (\DomainException $e) {
            // AMIAL-FOUR-EYES-001: منعٌ مقصود لا عطل — والرسالة تقول ذلك
            // صراحةً كي لا يُعاد المحاولة ولا يُظنّ الخلل في النظام.
            return $e->getMessage() === 'FOUR_EYES_VIOLATION'
                ? $this->error('FOUR_EYES_VIOLATION',
                    \App\Services\FourEyesService::message(), 403)
                : $this->error('RESOLVE_FAILED', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('RESOLVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['payment' => $payment], 'RESOLVED_REFUND', 'تم الاسترداد للمشتري');
    }

    public function resolvePartial(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'buyer_refund_amount' => 'required|numeric|min:0',
            'reason' => 'required|string|min:10|max:5000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'Not found', 404);

        try {
            $payment = $this->service->adminResolvePartial(
                $payment, $request->user(),
                (string)$request->input('buyer_refund_amount'),
                $request->input('reason'),
            );
        } catch (\DomainException $e) {
            // AMIAL-FOUR-EYES-001: منعٌ مقصود لا عطل — والرسالة تقول ذلك
            // صراحةً كي لا يُعاد المحاولة ولا يُظنّ الخلل في النظام.
            return $e->getMessage() === 'FOUR_EYES_VIOLATION'
                ? $this->error('FOUR_EYES_VIOLATION',
                    \App\Services\FourEyesService::message(), 403)
                : $this->error('RESOLVE_FAILED', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('RESOLVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['payment' => $payment], 'RESOLVED_PARTIAL', 'تم الاسترداد الجزئي');
    }

    private function trustSummary(int $userId, string $role): array
    {
        $column = $role === 'buyer' ? 'buyer_user_id' : 'seller_user_id';
        $base = SafePayment::where($column, $userId);

        $completed = (clone $base)->where('status', 'released_to_seller')->count();
        $disputed = (clone $base)->where('is_disputed', true)->count();
        $total = (clone $base)->count();
        $user = User::find($userId);

        return [
            'role' => $role,
            'completed_deals' => $completed,
            'disputed_deals' => $disputed,
            'total_deals' => $total,
            'dispute_rate' => $total > 0 ? round($disputed / $total * 100, 1) : 0.0,
            'member_since' => optional($user?->created_at)->format('Y-m'),
            'badge' => match (true) {
                $total === 0 => 'جديد',
                $disputed === 0 && $completed >= 10 => 'موثوق',
                $total > 0 && ($disputed / $total) > 0.25 => 'نزاعات متكرّرة',
                default => 'عادي',
            },
        ];
    }

    private function csvSafe(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
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
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(),
            'meta' => (object)[],
        ], 422);
    }
}
