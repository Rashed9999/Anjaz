<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Support\Access\AccessConstants as A;
use App\Models\ApprovalRequest;
use App\Models\AuditDecision;
use App\Models\Dispute;
use App\Models\EMoney;
use App\Models\Receipt;
use App\Models\SecurityAlert;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\InsiderWatchService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OPS-CONSOLE-001 — منصة عمليات الموظفين (Customer Operations Console).
 *
 * ما يحتاجه موظف خدمة العملاء في بنك عند اتصال العميل:
 *   1. بحث موحّد: هاتف / رقم حساب / رقم عملية / رقم إيصال / اسم.
 *   2. ملف العميل 360°: رصيد، آخر عمليات، KYC، حالة PIN، جلسات، آخر نشاط.
 *   3. فحص عملية: حالتها، أطرافها، خطّها الزمني (إيصال/نزاع/قرارات تدقيق).
 *   4. إجراءات تحقّق: تجميد مؤقت، إعادة تعيين PIN، إلغاء الجلسات، طلب KYC.
 *      كل إجراء يتطلب سبباً ويُسجَّل في سجل التدقيق.
 *   5. تذاكر النزاعات: كل بلاغ = تذكرة برقم + مسؤول + خط زمني.
 *   6. لوحة مراقبة النظام: عمليات الآن، الفاشلة، الطوابير، POS النشطة.
 */
class SupportConsoleController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ApprovalService $approvals,
        private readonly InsiderWatchService $watch,
    ) {}

    // ==================== 1) البحث الموحّد ====================

    /**
     * GET /admin/support/search?q=
     * يبحث في: هواتف (بكل الصيغ)، معرّف مستخدم، أسماء، رقم عملية، رقم إيصال.
     */
    public function search(Request $request): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->error('QUERY_TOO_SHORT', 'أدخل حرفين على الأقل للبحث', 422);
        }

        $users = collect();
        $transactions = collect();
        $receipts = collect();

        // — رقم عملية (ULID/ref) أو رقم إيصال أو كود تحقّق
        //
        // AMIAL-RECEIPT-NUMBERS-001: العميل يقرأ الرقم كما هو مطبوع —
        // مجموعاً بمسافات «260726 481037». البحث بالنصّ الخام كان يفشل
        // فيظنّ الموظّف أن العملية غير موجودة. نطبّع قبل المطابقة، ونطابق
        // المخزَّن مطبّعاً أيضاً كي تُوجد الأشكال القديمة والجديدة معاً.
        $normalized = \App\Support\ReadableCode::normalize($q);

        if ($normalized !== '' && preg_match('/^[A-Z0-9]{6,40}$/', $normalized)) {
            $transactions = Transaction::where('transaction_id', $normalized)
                ->orWhere('ref_trans_id', $normalized)
                ->limit(5)->get();

            $receipts = Receipt::where('receipt_number', $q)
                ->orWhere('receipt_number', $normalized)
                ->orWhere('verification_code', $normalized)
                ->orWhere('verification_code', strtoupper($q))
                ->limit(5)->get();
        }

        // — هاتف بكل الصيغ المكافئة
        $phoneDigits = preg_replace('/\D+/', '', $q);
        if (strlen($phoneDigits) >= 7) {
            $users = User::whereIn('phone', Phone::variants($phoneDigits))->limit(10)->get();
        }

        // — معرّف مستخدم رقمي
        if ($users->isEmpty() && ctype_digit($q)) {
            $users = User::where('id', (int) $q)->get();
        }

        // — اسم (جزئي)
        if ($users->isEmpty() && $transactions->isEmpty() && $receipts->isEmpty()) {
            $users = User::where('f_name', 'like', "%{$q}%")
                ->orWhere('l_name', 'like', "%{$q}%")
                ->limit(10)->get();
        }

        // AMIAL-INSIDER-001: كل بحث مسجَّل باسم الموظف + تقييم شذوذ
        $this->watch->logSearch($request->user()->id, $q, $users->count() + $transactions->count() + $receipts->count());

        return $this->ok([
            'query' => $q,
            'users' => $users->map(fn($u) => $this->userSummary($u))->values(),
            'transactions' => $transactions->map(fn($t) => $this->txSummary($t))->values(),
            'receipts' => $receipts->map(fn($r) => [
                'id' => $r->id,
                'receipt_number' => $r->receipt_number,
                'receipt_type' => $r->receipt_type,
                'amount' => $r->amount,
                'user_id' => $r->user_id,
                'status' => $r->status,
                'issued_at' => $r->issued_at,
            ])->values(),
        ]);
    }

    // ==================== 2) ملف العميل 360° ====================

    /** GET /admin/support/customers/{id} */
    public function customer(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $user = User::find($id);
        if (!$user) {
            return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);
        }

        // AMIAL-SUPPORT-PII-001: فتح ملفّ عميل وصولٌ إلى بيانات شخصية —
        // اسمه ورقمه ورصيده وحركاته. والصلاحية تقول «يجوز له»، والسجلّ يقول
        // «فعلها». وبينهما فرقٌ يظهر عند أوّل شكوى تسريب: من فتح ملفّ فلان
        // ومتى وكم مرّة. وبلا سجلّ يبقى السؤال بلا جواب مهما ضُبطت الأدوار.
        app(\App\Services\PiiAccessAuditService::class)->logAccess(
            actorUserId: $request->user()->id,
            subjectType: 'user',
            subjectId: $user->id,
            fieldName: 'support_customer_file',
            accessType: 'view',
            accessReason: (string) $request->query('reason', 'فتح ملفّ العميل من لوحة الدعم'),
        );

        $wallet = EMoney::where('user_id', $user->id)->first();

        $activeSessions = (int) DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->where('revoked', false)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        $lastLogin = DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->value('created_at');

        $recentTx = Transaction::where('user_id', $user->id)
            ->orderByDesc('id')->limit(10)->get()
            ->map(fn($t) => $this->txSummary($t))->values();

        $openTickets = SupportTicket::where('user_id', $user->id)
            ->whereNotIn('status', ['resolved', 'closed'])->count();

        // آخر قرارات التدقيق المتعلقة به (لموظف الدعم رؤية القرارات الأمنية)
        $recentAudit = AuditDecision::where('subject_id', (string) $user->id)
            ->orderByDesc('id')->limit(5)
            ->get(['action', 'decision_code', 'reason', 'severity', 'created_at']);

        // AMIAL-INSIDER-001: فتح ملف 360° = اطّلاع مسجَّل باسم الموظف
        $this->watch->logView($request->user()->id, $user->id, 'profile_360');

        return $this->ok([
            'profile' => $this->userSummary($user) + [
                'email' => $user->email,
                'zone_code' => $user->zone_code,
                'created_at' => $user->created_at,
                'last_active_at' => $user->last_active_at,
            ],
            'wallet' => [
                'current_balance' => $wallet?->current_balance,
                'pending_balance' => $wallet?->pending_balance,
                'held_balance' => $wallet?->held_balance,
                'exists' => (bool) $wallet,
            ],
            'kyc' => [
                'is_verified' => (bool) $user->is_kyc_verified,
                'tier' => $user->kyc_tier,
                'tier_updated_at' => $user->kyc_tier_updated_at,
            ],
            'pin' => [
                'is_set' => !empty($user->transaction_pin),
                'requires_setup' => (bool) ($user->requires_pin_setup ?? false),
                'failed_attempts' => (int) ($user->pin_failed_attempts ?? 0),
                'locked_until' => $user->pin_locked_until,
            ],
            'security' => [
                'is_active' => (bool) $user->is_active,
                'is_temp_blocked' => (bool) ($user->is_temp_blocked ?? false),
                'temp_block_time' => $user->temp_block_time,
                'active_sessions' => $activeSessions,
                'last_token_issued_at' => $lastLogin,
            ],
            'recent_transactions' => $recentTx,
            'open_tickets' => $openTickets,
            'recent_audit' => $recentAudit,
            'documents' => $this->documentsFor($user),
        ]);
    }

    /**
     * AMIAL-SUPPORT-DOCS-001 — «أين إيصالي؟» يُجاب عنه من هنا.
     *
     * كان هذا السؤال بلا جواب في اللوحة: الإيصال يظهر في قائمة العميل بينما
     * ملفّه غير مولَّد، فيفشل تنزيله ولا يعرف الدعم لماذا. والجواب كان في
     * عمود `pdf_storage_path` لا يقرؤه أحد.
     *
     * وإيصالٌ بلا ملفّ ليس عطلاً بذاته — يُولَّد عند أوّل تنزيل. لكنه يصير
     * عطلاً حين يكثر: التوليد داخل الطلب بطيء، فيُقطع الاتصال على شبكة
     * جوّال. فالعدد هنا إشارةٌ إلى صحّة الطابور لا إلى حال إيصال بعينه.
     */
    private function documentsFor(\App\Models\User $user): array
    {
        $receipts = \App\Models\Receipt::where('user_id', $user->id)
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'receipt_number', 'receipt_type', 'amount', 'status',
                   'pdf_storage_path', 'download_count', 'issued_at']);

        $missing = \App\Models\Receipt::where('user_id', $user->id)
            ->whereNull('pdf_storage_path')->count();

        return [
            'receipts_without_file' => $missing,
            'recent' => $receipts->map(fn ($r) => [
                'id' => $r->id,
                'number' => $r->receipt_number,
                'type' => $r->receipt_type,
                'amount' => (string) $r->amount,
                'status' => $r->status,
                // الحقيقة لا الحقل: المسار محفوظ والملفّ قد يكون ذهب مع
                // نشرة جديدة على تخزين مؤقّت. الفرق بينهما هو الفرق بين
                // «سيُفتح فوراً» و«سيُصيَّر الآن وقد يُقطع».
                'file_ready' => $r->pdf_storage_path
                    && \Illuminate\Support\Facades\Storage::disk('local')->exists($r->pdf_storage_path),
                'downloads' => (int) $r->download_count,
                'issued_at' => (string) $r->issued_at,
            ])->all(),
        ];
    }

    /** GET /admin/support/customers/{id}/transactions */
    public function customerTransactions(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        if (!User::where('id', $id)->exists()) {
            return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);
        }

        $q = Transaction::where('user_id', $id)->orderByDesc('id');
        if ($request->filled('type')) {
            $q->where('transaction_type', $request->query('type'));
        }

        $page = $q->paginate(min(50, (int) $request->query('per_page', 20)));

        $this->watch->logView($request->user()->id, $id, 'transactions');

        return $this->ok([
            'transactions' => collect($page->items())->map(fn($t) => $this->txSummary($t))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    // ==================== 3) فحص عملية + الخط الزمني ====================

    /** GET /admin/support/transactions/{ref} */
    public function transaction(Request $request, string $ref): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $tx = Transaction::where('transaction_id', $ref)
            ->orWhere('ref_trans_id', $ref)
            ->first();
        if (!$tx && ctype_digit($ref)) {
            $tx = Transaction::find((int) $ref);
        }
        if (!$tx) {
            return $this->error('TX_NOT_FOUND', 'العملية غير موجودة', 404);
        }

        // الخط الزمني: إنشاء + إيصال + نزاعات + قرارات تدقيق
        $timeline = [];
        $timeline[] = [
            'at' => $tx->created_at,
            'event' => 'transaction_created',
            'detail' => "نوع: {$tx->transaction_type} — مدين: {$tx->debit} / دائن: {$tx->credit}",
        ];

        $receipt = Receipt::where('reference_transaction_id', $tx->transaction_id ?? '')->first();
        if ($receipt) {
            $timeline[] = [
                'at' => $receipt->created_at,
                'event' => 'receipt_issued',
                'detail' => "إيصال {$receipt->receipt_number} — حالة: {$receipt->status}",
            ];
        }

        $disputes = Dispute::where('transaction_id', $tx->id)
            ->orWhere('trx_id', (string) ($tx->transaction_id ?? ''))
            ->get();
        foreach ($disputes as $d) {
            $timeline[] = [
                'at' => $d->created_at,
                'event' => 'dispute_filed',
                'detail' => "نزاع #{$d->id} — حالة: {$d->status}",
            ];
        }

        $audits = AuditDecision::where('transaction_id', (string) ($tx->transaction_id ?? ''))
            ->orderBy('id')->limit(20)
            ->get(['action', 'decision_code', 'reason', 'severity', 'created_at']);
        foreach ($audits as $a) {
            $timeline[] = [
                'at' => $a->created_at,
                'event' => 'audit_decision',
                'detail' => "{$a->action} → {$a->decision_code}" . ($a->reason ? " ({$a->reason})" : ''),
            ];
        }

        $tickets = SupportTicket::where('transaction_ref', (string) ($tx->transaction_id ?? '—'))->get();
        foreach ($tickets as $t) {
            $timeline[] = [
                'at' => $t->created_at,
                'event' => 'ticket_opened',
                'detail' => "تذكرة {$t->ticket_number} — حالة: {$t->status}",
            ];
        }

        usort($timeline, fn($x, $y) => strcmp((string) $x['at'], (string) $y['at']));

        return $this->ok([
            'transaction' => $this->txSummary($tx) + [
                'from_user_id' => $tx->from_user_id,
                'to_user_id' => $tx->to_user_id,
                'charge' => $tx->charge,
                'balance_after' => $tx->balance,
                'zone_code' => $tx->zone_code,
                'decision_code' => $tx->decision_code,
                'decision_reason' => $tx->decision_reason,
                'note' => $tx->note,
                'idempotency_key' => $tx->idempotency_key,
            ],
            'receipt' => $receipt ? [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'status' => $receipt->status,
            ] : null,
            'disputes' => $disputes->map(fn($d) => [
                'id' => $d->id, 'status' => $d->status, 'created_at' => $d->created_at,
            ])->values(),
            'timeline' => $timeline,
        ]);
    }

    // ==================== 4) إجراءات التحقّق (مُدقَّقة) ====================

    /**
     * POST /admin/support/customers/{id}/freeze  {reason, unfreeze?}
     *
     * AMIAL-INSIDER-001 — سياسة أربع عيون:
     *   التجميد (يقيّد) = فوري — إيقاف احتيال لا ينتظر.
     *   فكّ التجميد (يعيد وصولاً) = طلب موافقة يعتمده مشرف مختلف.
     */
    public function freeze(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;
        if ($resp = $this->requireReason($request)) return $resp;

        $user = User::find($id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);

        $unfreeze = (bool) $request->input('unfreeze', false);

        if ($unfreeze) {
            $req = $this->approvals->submit(
                $request->user(), 'unfreeze_wallet', $user->id,
                trim((string) $request->input('reason')),
            );
            return $this->ok([
                'approval_required' => true,
                'request_number' => $req->request_number,
                'request_id' => $req->id,
            ], 'APPROVAL_PENDING', 'فكّ التجميد يتطلب اعتماد مشرف آخر — أُنشئ طلب ' . $req->request_number, 202);
        }

        $user->is_temp_blocked = true;
        $user->temp_block_time = now();
        $user->save();

        $this->auditAction($request, $user, 'SUPPORT_FREEZE_WALLET');

        return $this->ok([
            'user_id' => $user->id,
            'is_temp_blocked' => true,
        ], 'OK', 'تم تجميد الحساب مؤقتاً');
    }

    /**
     * POST /admin/support/customers/{id}/reset-pin  {reason}
     * يعيد وصولاً للحساب → موافقة ثنائية إلزامية (أخطر ناقل استيلاء).
     */
    public function resetPin(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;
        if ($resp = $this->requireReason($request)) return $resp;

        $user = User::find($id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);

        $req = $this->approvals->submit(
            $request->user(), 'reset_pin', $user->id,
            trim((string) $request->input('reason')),
        );

        return $this->ok([
            'approval_required' => true,
            'request_number' => $req->request_number,
            'request_id' => $req->id,
        ], 'APPROVAL_PENDING', 'إعادة تعيين PIN تتطلب اعتماد مشرف آخر — أُنشئ طلب ' . $req->request_number, 202);
    }

    // ==================== الموافقات (Maker-Checker) ====================

    /** GET /admin/support/approvals?status= */
    public function approvalsList(Request $request): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $q = ApprovalRequest::with([
            'subject:id,f_name,l_name,phone',
            'maker:id,f_name,l_name',
            'checker:id,f_name,l_name',
        ])->orderByDesc('id');

        $q->where('status', $request->query('status', 'pending'));

        $page = $q->paginate(min(50, (int) $request->query('per_page', 20)));

        return $this->ok([
            'approvals' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** POST /admin/support/approvals/{id}/approve  {note?} */
    public function approveRequest(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        try {
            $req = $this->approvals->approve($request->user(), $id, $request->input('note'));
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'SELF_APPROVAL_FORBIDDEN' => $this->error('SELF_APPROVAL_FORBIDDEN',
                    'لا يمكنك اعتماد طلبك أنت — يلزم مشرف آخر (قاعدة أربع عيون)', 403),
                'NOT_PENDING' => $this->error('NOT_PENDING', 'الطلب ليس قيد الانتظار', 409),
                'EXPIRED' => $this->error('EXPIRED', 'انتهت صلاحية الطلب (24 ساعة) — أعد تقديمه', 410),
                default => $this->error('APPROVAL_ERROR', $e->getMessage(), 422),
            };
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('REQUEST_NOT_FOUND', 'طلب الموافقة غير موجود', 404);
        }

        return $this->ok(['approval' => $req], 'OK', "اعتُمد الطلب {$req->request_number} ونُفِّذ الإجراء");
    }

    /** POST /admin/support/approvals/{id}/reject  {note} */
    public function rejectRequest(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $data = $request->validate(['note' => 'required|string|min:5|max:500']);

        try {
            $req = $this->approvals->reject($request->user(), $id, $data['note']);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'SELF_APPROVAL_FORBIDDEN' => $this->error('SELF_APPROVAL_FORBIDDEN',
                    'لا يمكنك البتّ في طلبك أنت — يلزم مشرف آخر', 403),
                'NOT_PENDING' => $this->error('NOT_PENDING', 'الطلب ليس قيد الانتظار', 409),
                default => $this->error('APPROVAL_ERROR', $e->getMessage(), 422),
            };
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('REQUEST_NOT_FOUND', 'طلب الموافقة غير موجود', 404);
        }

        return $this->ok(['approval' => $req], 'OK', "رُفض الطلب {$req->request_number}");
    }

    // ==================== المراقبة الداخلية (مسؤول الأمن) ====================

    /** GET /admin/support/insider/overview */
    public function insiderOverview(Request $request): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $data = $this->watch->overview();

        // حالة سلسلة التدقيق (فحص سريع لآخر 200 سجل)
        $chainOk = null;
        try {
            \Illuminate\Support\Facades\Artisan::call('amial:audit-verify', ['--last' => 200]);
            $chainOk = \Illuminate\Support\Facades\Artisan::output();
            $chainOk = str_contains($chainOk, '✓');
        } catch (\Throwable $e) { /* غير حاسم */ }

        return $this->ok([
            'activity_today' => $data['activity_today'],
            'open_alerts' => $data['open_alerts'],
            'audit_chain_ok' => $chainOk,
        ]);
    }

    /** POST /admin/support/insider/alerts/{id}/ack */
    public function acknowledgeAlert(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $alert = SecurityAlert::find($id);
        if (!$alert) return $this->error('ALERT_NOT_FOUND', 'التنبيه غير موجود', 404);

        $alert->update([
            'status' => 'acknowledged',
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
        ]);

        return $this->ok(['alert' => $alert->fresh()], 'OK', 'تمت مراجعة التنبيه');
    }

    /** POST /admin/support/customers/{id}/revoke-sessions  {reason} */
    public function revokeSessions(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;
        if ($resp = $this->requireReason($request)) return $resp;

        $user = User::find($id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);

        $tokenIds = DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)->where('revoked', false)
            ->pluck('id');

        DB::table('oauth_access_tokens')->whereIn('id', $tokenIds)->update(['revoked' => true]);
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);

        $this->auditAction($request, $user, 'SUPPORT_REVOKE_SESSIONS', ['revoked_tokens' => count($tokenIds)]);

        return $this->ok([
            'user_id' => $user->id,
            'revoked_sessions' => count($tokenIds),
        ], 'OK', 'تم إنهاء كل الجلسات');
    }

    /** POST /admin/support/customers/{id}/require-kyc  {reason} */
    public function requireKyc(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;
        if ($resp = $this->requireReason($request)) return $resp;

        $user = User::find($id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);

        $user->is_kyc_verified = false;
        $user->save();

        $this->auditAction($request, $user, 'SUPPORT_REQUIRE_KYC');

        // AMIAL-KYC-DOCS-001: الدائرة صارت مغلقة.
        //
        // كان هذا الزرّ يضع العلامة ثم لا مكان يرفع إليه العميل مستنده —
        // فيطمئنّ الموظّف وينتظر العميل ما لن يأتي. الآن تُعاد حالةُ ما
        // رفعه ليعرف الموظّف ما ينقص قبل أن يغلق المكالمة.
        $kyc = app(\App\Services\KycDocumentService::class);

        return $this->ok([
            'user_id' => $user->id,
            'kyc' => $kyc->completenessFor($user, 2),
            'upload_endpoint' => 'POST /api/v1/amial/me/kyc/documents',
        ], 'OK', 'سيُطلب من العميل رفع وثائق الهوية من التطبيق');
    }

    // ==================== 4.5) أجهزة العميل ====================

    /**
     * GET /admin/support/customers/{id}/devices
     *
     * AMIAL-DEVICE-TRUST-001 — ما يحتاجه الدعم حين يقول العميل «حسابي مسروق».
     *
     * قبلها لم يكن أمام الموظّف إلّا تجميد الحساب كلّه — عقوبةٌ على الضحيّة
     * تمنعه من ماله. والصواب أن يُرى **أيّ جهازٍ** يدخل، فيُحظر هو وحده.
     *
     * ويُرتَّب النشط أوّلاً ثم الأحدث استعمالاً: الموظّف يبحث عمّا يعمل الآن،
     * لا عمّا سُجّل أوّلاً.
     */
    public function devices(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $user = User::find($id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);

        // فتحُ قائمة أجهزة عميل قراءةُ بياناتٍ شخصية — تُسجَّل كغيرها.
        // (بصمة الجهاز وعنوان IP وأوقات الاستعمال تكشف أنماط حياةٍ لا مالاً.)
        app(\App\Services\PiiAccessAuditService::class)->logAccess(
            actorUserId: $request->user()->id,
            subjectType: 'user',
            subjectId: $user->id,
            fieldName: 'support_customer_devices',
            accessType: 'view',
            accessReason: (string) $request->query('reason', 'مراجعة أجهزة العميل'),
        );

        $devices = \App\Models\UserLogHistory::where('user_id', $user->id)
            ->orderByDesc('is_active')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(fn ($d) => [
                'id' => (int) $d->id,
                'device_id' => (string) $d->device_id,
                'device_model' => (string) ($d->device_model ?: '—'),
                'os' => (string) ($d->os ?: '—'),
                'app_version' => $d->app_version,
                'ip_address' => (string) ($d->ip_address ?: '—'),
                'is_active' => (bool) $d->is_active,
                'is_trusted' => (bool) $d->is_trusted,
                'is_blocked' => (bool) $d->is_blocked,
                'block_reason' => $d->block_reason,
                'blocked_at' => $d->blocked_at?->toIso8601String(),
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'first_seen_at' => $d->created_at?->toIso8601String(),
            ])->all();

        return $this->ok([
            'user_id' => $user->id,
            'devices' => $devices,
            'total' => count($devices),
            'blocked' => count(array_filter($devices, fn ($d) => $d['is_blocked'])),
        ]);
    }

    /** POST /admin/support/devices/{deviceRowId}/block  {reason} */
    public function blockDevice(Request $request, int $deviceRowId): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;
        if ($resp = $this->requireReason($request)) return $resp;

        $device = \App\Models\UserLogHistory::find($deviceRowId);
        if (!$device) return $this->error('DEVICE_NOT_FOUND', 'الجهاز غير موجود', 404);

        $user = User::find($device->user_id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'صاحب الجهاز غير موجود', 404);

        $device->is_blocked = true;
        $device->is_trusted = false;   // محظورٌ وموثوق تناقض
        $device->is_active = false;
        $device->blocked_at = now();
        $device->blocked_by_user_id = $request->user()->id;
        $device->block_reason = mb_substr((string) $request->input('reason'), 0, 255);
        $device->save();

        $this->auditAction($request, $user, 'SUPPORT_BLOCK_DEVICE', [
            'device_row_id' => $device->id,
            'device_model' => $device->device_model,
        ]);

        return $this->ok([
            'device_id' => $device->id,
            'user_id' => $user->id,
        ], 'OK', 'حُظر الجهاز — لن يصل الحساب منه ولو سُجّل الدخول');
    }

    /** POST /admin/support/devices/{deviceRowId}/unblock  {reason} */
    public function unblockDevice(Request $request, int $deviceRowId): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;
        if ($resp = $this->requireReason($request)) return $resp;

        $device = \App\Models\UserLogHistory::find($deviceRowId);
        if (!$device) return $this->error('DEVICE_NOT_FOUND', 'الجهاز غير موجود', 404);

        $user = User::find($device->user_id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'صاحب الجهاز غير موجود', 404);

        // AMIAL-FOUR-EYES-003: من حظر لا يرفع الحظر.
        //
        // الحظر قرارُ حمايةٍ يُتّخذ على عجل تحت ضغط مكالمة. ورفعُه على العجل
        // نفسه، بيد صاحب القرار الأوّل، يجعل الضابط كلّه شكلياً — وأخطر
        // صورةٍ لذلك موظّفٌ يحظر جهازاً ثم يرفعه بعد اتّفاقٍ مع من حظره منه.
        if ((int) $device->blocked_by_user_id === (int) $request->user()->id) {
            return $this->error(
                'FOUR_EYES_VIOLATION',
                'من حظر الجهاز لا يرفع الحظر عنه — يراجعه موظّف آخر',
                422,
            );
        }

        $device->is_blocked = false;
        $device->blocked_at = null;
        $device->block_reason = null;
        $device->blocked_by_user_id = null;
        $device->save();

        $this->auditAction($request, $user, 'SUPPORT_UNBLOCK_DEVICE', [
            'device_row_id' => $device->id,
        ]);

        return $this->ok([
            'device_id' => $device->id,
            'user_id' => $user->id,
        ], 'OK', 'رُفع الحظر عن الجهاز');
    }

    // ==================== 5) تذاكر النزاعات ====================

    /** GET /admin/support/tickets?status=&assigned_to=&q= */
    public function tickets(Request $request): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $q = SupportTicket::with('user:id,f_name,l_name,phone')->orderByDesc('id');

        if ($request->filled('status')) $q->where('status', $request->query('status'));
        if ($request->filled('assigned_to')) $q->where('assigned_admin_id', (int) $request->query('assigned_to'));
        if ($request->filled('category')) $q->where('category', $request->query('category'));
        if ($request->filled('q')) {
            $s = $request->query('q');
            $q->where(fn($w) => $w->where('ticket_number', 'like', "%{$s}%")
                ->orWhere('subject', 'like', "%{$s}%")
                ->orWhere('transaction_ref', 'like', "%{$s}%"));
        }

        $page = $q->paginate(min(50, (int) $request->query('per_page', 20)));

        return $this->ok([
            'tickets' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** POST /admin/support/tickets  {user_id, subject, category?, priority?, transaction_ref?, description?} */
    public function createTicket(Request $request): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'subject' => 'required|string|max:200',
            'category' => 'nullable|string|in:' . implode(',', SupportTicket::CATEGORIES),
            'priority' => 'nullable|string|in:' . implode(',', SupportTicket::PRIORITIES),
            'transaction_ref' => 'nullable|string|max:40',
            'description' => 'nullable|string|max:5000',
        ]);

        $admin = $request->user();

        $ticket = DB::transaction(function () use ($data, $admin) {
            $ticket = SupportTicket::create([
                'ticket_number' => SupportTicket::nextTicketNumber(),
                'user_id' => $data['user_id'],
                'opened_by_admin_id' => $admin->id,
                'transaction_ref' => $data['transaction_ref'] ?? null,
                'category' => $data['category'] ?? 'other',
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'open',
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
            ]);

            SupportTicketEvent::create([
                'ticket_id' => $ticket->id,
                'admin_id' => $admin->id,
                'event_type' => 'created',
                'new_value' => 'open',
                'note' => $ticket->subject,
            ]);

            return $ticket;
        });

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'subject_type' => 'support_ticket',
            'subject_id' => (string) $ticket->id,
            'action' => 'SUPPORT_TICKET_CREATED',
            'decision_code' => 'OK',
            'reason' => $ticket->subject,
        ]);

        return $this->ok(['ticket' => $ticket->fresh()], 'OK', 'تم فتح التذكرة', 201);
    }

    /** GET /admin/support/tickets/{id} — مع الخط الزمني الكامل */
    public function showTicket(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $ticket = SupportTicket::with([
            'user:id,f_name,l_name,phone',
            'assignee:id,f_name,l_name',
            'events.admin:id,f_name,l_name',
        ])->find($id);

        if (!$ticket) return $this->error('TICKET_NOT_FOUND', 'التذكرة غير موجودة', 404);

        return $this->ok(['ticket' => $ticket]);
    }

    /** POST /admin/support/tickets/{id}/update  {status?, assigned_admin_id?, priority?, resolution_note?} */
    public function updateTicket(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $ticket = SupportTicket::find($id);
        if (!$ticket) return $this->error('TICKET_NOT_FOUND', 'التذكرة غير موجودة', 404);

        $data = $request->validate([
            'status' => 'nullable|string|in:' . implode(',', SupportTicket::STATUSES),
            'assigned_admin_id' => 'nullable|integer|exists:users,id',
            'priority' => 'nullable|string|in:' . implode(',', SupportTicket::PRIORITIES),
            'resolution_note' => 'nullable|string|max:5000',
        ]);

        $admin = $request->user();

        DB::transaction(function () use ($ticket, $data, $admin) {
            if (isset($data['assigned_admin_id']) && (int) $data['assigned_admin_id'] !== (int) $ticket->assigned_admin_id) {
                SupportTicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'admin_id' => $admin->id,
                    'event_type' => 'assigned',
                    'old_value' => (string) ($ticket->assigned_admin_id ?? ''),
                    'new_value' => (string) $data['assigned_admin_id'],
                ]);
                $ticket->assigned_admin_id = $data['assigned_admin_id'];
            }

            if (isset($data['priority']) && $data['priority'] !== $ticket->priority) {
                SupportTicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'admin_id' => $admin->id,
                    'event_type' => 'priority_changed',
                    'old_value' => $ticket->priority,
                    'new_value' => $data['priority'],
                ]);
                $ticket->priority = $data['priority'];
            }

            if (isset($data['status']) && $data['status'] !== $ticket->status) {
                SupportTicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'admin_id' => $admin->id,
                    'event_type' => 'status_changed',
                    'old_value' => $ticket->status,
                    'new_value' => $data['status'],
                    'note' => $data['resolution_note'] ?? null,
                ]);
                $ticket->status = $data['status'];
                if ($data['status'] === 'resolved') $ticket->resolved_at = now();
                if ($data['status'] === 'closed') $ticket->closed_at = now();
            }

            if (isset($data['resolution_note'])) {
                $ticket->resolution_note = $data['resolution_note'];
            }

            $ticket->save();
        });

        return $this->ok(['ticket' => $ticket->fresh(['events'])], 'OK', 'تم تحديث التذكرة');
    }

    /** POST /admin/support/tickets/{id}/note  {note} */
    public function addTicketNote(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $ticket = SupportTicket::find($id);
        if (!$ticket) return $this->error('TICKET_NOT_FOUND', 'التذكرة غير موجودة', 404);

        $data = $request->validate(['note' => 'required|string|max:5000']);

        $event = SupportTicketEvent::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $request->user()->id,
            'event_type' => 'note',
            'note' => $data['note'],
        ]);

        return $this->ok(['event' => $event], 'OK', 'أُضيفت الملاحظة');
    }

    // ==================== 6) لوحة مراقبة النظام ====================

    /** GET /admin/support/ops-dashboard */
    public function opsDashboard(Request $request): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        $now = now();

        $txToday = Transaction::where('created_at', '>=', $now->copy()->startOfDay())->count();
        $txLastHour = Transaction::where('created_at', '>=', $now->copy()->subHour())->count();
        $txLast5Min = Transaction::where('created_at', '>=', $now->copy()->subMinutes(5))->count();

        $failedToday = Transaction::where('created_at', '>=', $now->copy()->startOfDay())
            ->whereNotNull('decision_code')
            ->where('decision_code', 'NOT LIKE', 'OK%')
            ->where('decision_code', '!=', 'ALLOW')
            ->count();

        $queueDepth = (int) DB::table('jobs')->count();
        $failedJobs = (int) DB::table('failed_jobs')->count();

        $activeUsers15m = User::where('last_active_at', '>=', $now->copy()->subMinutes(15))->count();
        $activePosUsers = (int) DB::table('pos_users')->where('is_active', true)->count();

        $ticketsByStatus = SupportTicket::selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')->pluck('c', 'status');

        $openDisputes = Dispute::whereNotIn('status', ['resolved', 'closed', 'denied'])->count();

        $dbOk = true;
        try { DB::select('SELECT 1'); } catch (\Throwable $e) { $dbOk = false; }

        return $this->ok([
            'transactions' => [
                'today' => $txToday,
                'last_hour' => $txLastHour,
                'last_5_minutes' => $txLast5Min,
                'failed_today' => $failedToday,
            ],
            'queues' => [
                'pending_jobs' => $queueDepth,
                'failed_jobs' => $failedJobs,
            ],
            'activity' => [
                'active_users_15m' => $activeUsers15m,
                'active_pos_terminals' => $activePosUsers,
            ],
            'support' => [
                'tickets_by_status' => $ticketsByStatus,
                'open_disputes' => $openDisputes,
            ],
            'health' => [
                'database' => $dbOk ? 'up' : 'down',
                'time' => $now->toIso8601String(),
            ],
        ]);
    }

    // ==================== Helpers ====================

    private function userSummary(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => trim("{$u->f_name} {$u->l_name}"),
            'phone' => $u->phone,
            'type' => (int) $u->type,
            'type_label' => match ((int) $u->type) {
                0 => 'إداري', 1 => 'وكيل', 2 => 'عميل', 3 => 'تاجر', default => 'غير معروف',
            },
            'is_active' => (bool) $u->is_active,
            'is_temp_blocked' => (bool) ($u->is_temp_blocked ?? false),
            'is_kyc_verified' => (bool) $u->is_kyc_verified,
        ];
    }

    private function txSummary(Transaction $t): array
    {
        return [
            'id' => $t->id,
            'transaction_id' => $t->transaction_id,
            'ref_trans_id' => $t->ref_trans_id,
            'type' => $t->transaction_type,
            'debit' => $t->debit,
            'credit' => $t->credit,
            'amount' => $t->amount,
            'user_id' => $t->user_id,
            'decision_code' => $t->decision_code,
            'created_at' => $t->created_at,
        ];
    }

    private function requireAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user && ((int) ($user->type ?? -1) === 0
            || in_array($user->role ?? null, [A::ROLE_ADMIN, 'super_admin'], true));

        return $isAdmin ? null : $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
    }

    /** كل إجراء أمني يتطلب سبباً موثَّقاً. */
    private function requireReason(Request $request): ?JsonResponse
    {
        $reason = trim((string) $request->input('reason', ''));
        if (mb_strlen($reason) < 5) {
            return $this->error('REASON_REQUIRED', 'يجب توثيق سبب الإجراء (5 أحرف على الأقل)', 422);
        }
        return null;
    }

    private function auditAction(Request $request, User $subject, string $action, array $context = []): void
    {
        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'user',
            'subject_id' => (string) $subject->id,
            'action' => $action,
            'decision_code' => 'OK',
            'reason' => trim((string) $request->input('reason', '')),
            'severity' => 'warning',
            'context' => $context,
            'zone_code' => $subject->zone_code,
        ]);
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
}
