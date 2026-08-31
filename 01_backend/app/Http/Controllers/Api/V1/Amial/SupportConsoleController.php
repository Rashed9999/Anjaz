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
use App\Services\CustomerActionService;
use App\Services\InsiderWatchService;
use App\Support\Phone;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        private readonly CustomerActionService $customerActions,
    ) {}

    /** لا تعرض اللوحة تبويباً لا يستطيع المشغّل فتح بياناته فعلياً. */
    public function page(Request $request)
    {
        $actor = $request->user();
        $capabilities = [
            'customers' => $actor->hasPlatformPermission('platform.customers.view'),
            'transactions' => $actor->hasPlatformPermission('platform.transactions.view'),
            'tickets' => $actor->hasPlatformPermission('platform.tickets.manage'),
            'approvals' => $actor->hasPlatformPermission('platform.approvals.decide'),
            'insider' => $actor->hasPlatformPermission('platform.audit.view'),
            'ops' => $actor->hasPlatformPermission('platform.ops.view'),
        ];

        abort_unless(in_array(true, $capabilities, true), 403);

        return view('admin-views.support.console', compact('capabilities'));
    }

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
        $canTraceTransactions = $request->user()->hasPlatformPermission('platform.transactions.view');
        $transactions = collect();
        $receipts = collect();

        // — رقم عملية (ULID/ref) أو رقم إيصال أو كود تحقّق
        //
        // AMIAL-RECEIPT-NUMBERS-001: العميل يقرأ الرقم كما هو مطبوع —
        // مجموعاً بمسافات «260726 481037». البحث بالنصّ الخام كان يفشل
        // فيظنّ الموظّف أن العملية غير موجودة. نطبّع قبل المطابقة، ونطابق
        // المخزَّن مطبّعاً أيضاً كي تُوجد الأشكال القديمة والجديدة معاً.
        $normalized = \App\Support\ReadableCode::normalize($q);

        if ($canTraceTransactions && $normalized !== '' && preg_match('/^[A-Z0-9]{6,40}$/', $normalized)) {
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
                // AMIAL-SUPPORT-TRACE-REACH-001 — **مرجعُ العمليّة يُرسَل**،
                // وبه يفتح صفُّ الإيصال التتبّعَ الكامل. وبلا هذا الحقل
                // يبقى الصفُّ سطراً ميّتاً كما كان.
                'reference_transaction_id' => $r->reference_transaction_id,
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
                // AMIAL-KYC-FLAG-001: `=== 1` لا `(bool)`. الافتراضيّ ٣، والكود الذي
                // يمنع التحويل يقرأ `!= 1` — فـ`(bool)` كانت تعرض «موثَّق» لعميلٍ
                // ممنوع، فيبحث الموظّف عن سببٍ آخر لا وجود له.
                'is_verified' => (int) $user->is_kyc_verified === 1,
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

        // فحص العملية يكشف أطرافاً وأرصدةً ومراجع إيصالات؛ يُسجَّل كاطلاع
        // على بيانات شخصية مستقلة، لا كبحث عابر لا يترك أثراً.
        app(\App\Services\PiiAccessAuditService::class)->logAccess(
            actorUserId: $request->user()->id,
            subjectType: 'transaction', subjectId: $tx->id,
            fieldName: 'support_transaction_trace', accessType: 'view',
            accessReason: 'فحص عملية من منصة الدعم: ' . ($tx->transaction_id ?? $tx->id),
        );
        $canRevealPii = $request->user()->hasPlatformPermission('platform.customers.pii.reveal');

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

        // دفتر الأستاذ هو الدليل المالي، لا سجل العملية وحده. نربط العملية
        // بالقيود من source_id كي يرى الدعم طرفي الحركة والقيد العكسي إن وجد.
        $refs = array_values(array_filter([
            (string) $tx->transaction_id,
            (string) $tx->ref_trans_id,
        ]));
        $ledgerEntries = \App\Models\Ledger\LedgerJournalEntry::with('lines.account')
            ->whereIn('source_id', $refs)->orderBy('id')->get();
        foreach ($ledgerEntries as $entry) {
            $timeline[] = [
                'at' => $entry->posted_at ?? $entry->created_at,
                'event' => $entry->is_reversal ? 'ledger_reversal_posted' : 'ledger_entry_posted',
                'detail' => "قيد {$entry->entry_ulid} — {$entry->source_type} — {$entry->total_amount}",
            ];
        }

        usort($timeline, fn($x, $y) => strcmp((string) $x['at'], (string) $y['at']));

        return $this->ok([
            'transaction' => $this->txSummary($tx) + [
                'transaction_no' => $tx->transaction_no,
                'from_user_id' => $tx->from_user_id,
                'to_user_id' => $tx->to_user_id,
                'charge' => $tx->charge,
                'balance_after' => $tx->balance,
                'zone_code' => $tx->zone_code,
                'request_zone' => $tx->request_zone,
                'counterparty_zone' => $tx->counterparty_zone,
                'fee_scheme_id' => $tx->fee_scheme_id,
                'fee_scheme_version' => $tx->fee_scheme_version,
                'pos_user_id' => $tx->pos_user_id,
                'decision_code' => $tx->decision_code,
                'decision_reason' => $tx->decision_reason,
                'note' => $tx->note,
                'updated_at' => $tx->updated_at,
            ],
            'parties' => $this->transactionParties($tx, $canRevealPii),
            'pos_actor' => $this->posActor($tx, $canRevealPii),
            'receipt' => $receipt ? [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'verification_code' => $receipt->verification_code,
                'receipt_type' => $receipt->receipt_type,
                'status' => $receipt->status,
                'op_status' => $receipt->op_status,
                'amount' => $receipt->amount,
                'fee' => $receipt->fee,
                'net_amount' => $receipt->net_amount,
                'direction' => $receipt->direction,
                'reference_type' => $receipt->reference_type,
                'reference_id' => $receipt->reference_id,
                'zone_code' => $receipt->zone_code,
                'issued_at' => $receipt->issued_at,
                'pdf_generated_at' => $receipt->pdf_generated_at,
                'download_count' => $receipt->download_count,
                'last_downloaded_at' => $receipt->last_downloaded_at,
            ] : null,
            'disputes' => $disputes->map(fn($d) => [
                'id' => $d->id, 'status' => $d->status, 'created_at' => $d->created_at,
                'updated_at' => $d->updated_at, 'reason' => $d->reason ?? null,
            ])->values(),
            'tickets' => $tickets->map(fn (SupportTicket $t) => [
                'number' => $t->ticket_number, 'status' => $t->status,
                'category' => $t->category, 'priority' => $t->priority,
                'created_at' => $t->created_at, 'updated_at' => $t->updated_at,
            ])->values(),
            'business_records' => $this->businessRecords($refs, $canRevealPii),
            'ledger_entries' => $ledgerEntries->map(fn($e) => [
                'ulid' => $e->entry_ulid,
                'source_type' => $e->source_type,
                'source_id' => $e->source_id,
                'status' => $e->status,
                'is_reversal' => (bool) $e->is_reversal,
                'reverses_entry_id' => $e->reverses_entry_id,
                'posted_at' => $e->posted_at,
                'lines' => $e->lines->map(fn($l) => [
                    'account' => $l->account?->account_code,
                    'direction' => $l->direction,
                    'amount' => (string) $l->amount,
                    'balance_before' => (string) ($l->balance_before ?? ''),
                    'balance_after' => (string) ($l->balance_after ?? ''),
                    'description' => $l->description_ar,
                ])->values(),
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

        $user = User::where('type', CUSTOMER_TYPE)->find($id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);

        $unfreeze = (bool) $request->input('unfreeze', false);

        return $this->runCustomerAction(
            $request, $user, $unfreeze ? 'unfreeze' : 'freeze',
        );
    }

    /**
     * POST /admin/support/customers/{id}/reset-pin  {reason}
     * يعيد وصولاً للحساب → موافقة ثنائية إلزامية (أخطر ناقل استيلاء).
     */
    public function resetPin(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;
        if ($resp = $this->requireReason($request)) return $resp;

        $user = User::where('type', CUSTOMER_TYPE)->find($id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);

        return $this->runCustomerAction($request, $user, 'reset_pin');
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

        $user = User::where('type', CUSTOMER_TYPE)->find($id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);

        return $this->runCustomerAction($request, $user, 'revoke_sessions');
    }

    /** POST /admin/support/customers/{id}/require-kyc  {reason} */
    public function requireKyc(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;
        if ($resp = $this->requireReason($request)) return $resp;

        $user = User::where('type', CUSTOMER_TYPE)->find($id);
        if (!$user) return $this->error('USER_NOT_FOUND', 'العميل غير موجود', 404);

        return $this->runCustomerAction($request, $user, 'require_kyc');
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

        // **بابٌ واحدٌ لا اثنان** — مركزُ التاجر يفتح التذاكر أيضاً، ونسخُ
        // المنطق هناك يعني رقمَ تذكرةٍ يُولَّد بطريقتين. (AMIAL-MERCHANT-CENTER-002)
        $subject = \App\Models\User::find($data['user_id']);

        try {
            $ticket = app(\App\Services\Support\SupportTicketService::class)
                ->open($request->user(), $subject, $data);
        } catch (\DomainException $e) {
            return $this->error('TICKET_INVALID', $e->getMessage(), 422);
        }

        return $this->ok(['ticket' => $ticket], 'OK', 'تم فتح التذكرة', 201);
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
            'is_kyc_verified' => (int) $u->is_kyc_verified === 1,   // AMIAL-KYC-FLAG-001
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

    /** @return array<int,array<string,mixed>> */
    private function transactionParties(Transaction $tx, bool $canRevealPii): array
    {
        $ids = array_values(array_unique(array_filter([
            $tx->user_id, $tx->from_user_id, $tx->to_user_id,
        ])));
        $users = User::whereIn('id', $ids)->get()->keyBy('id');
        $labels = [
            (int) $tx->user_id => 'صاحب سجل العملية',
            (int) $tx->from_user_id => 'المرسِل / المصدر',
            (int) $tx->to_user_id => 'المستلم / الوجهة',
        ];

        return collect($ids)->map(function (int $id) use ($users, $labels, $canRevealPii) {
            $u = $users->get($id);
            return [
                'role' => $labels[$id] ?? 'طرف العملية',
                'user_id' => $id,
                'type' => $u ? $this->userSummary($u)['type_label'] : 'حساب محذوف/قديم',
                'name' => $u && $canRevealPii ? trim((string) ($u->f_name . ' ' . $u->l_name)) : 'محمي بالصلاحيات',
                'phone' => $u && $canRevealPii ? (string) $u->phone : $this->maskTransactionPhone($u?->phone),
                'zone_code' => $u?->zone_code,
            ];
        })->values()->all();
    }

    private function maskTransactionPhone(?string $phone): string
    {
        $phone = trim((string) $phone);
        return $phone === '' ? '—' : mb_substr($phone, 0, 3) . '••••' . mb_substr($phone, -2);
    }

    /** @return array<string,mixed>|null */
    private function posActor(Transaction $tx, bool $canRevealPii): ?array
    {
        if (!$tx->pos_user_id || !Schema::hasTable('pos_users')) return null;
        $pos = \App\Models\PosUser::with(['user:id,f_name,l_name,phone', 'merchant:id,f_name,l_name,phone'])
            ->find($tx->pos_user_id);
        if (!$pos) return ['id' => (int) $tx->pos_user_id, 'state' => 'السجل غير موجود'];

        return [
            'id' => $pos->id, 'pos_number' => $pos->pos_number, 'display_name' => $pos->display_name,
            'active' => (bool) $pos->is_active,
            'operator' => $canRevealPii ? trim((string) ($pos->user?->f_name . ' ' . $pos->user?->l_name)) : 'محمي بالصلاحيات',
            'merchant_owner_id' => $pos->merchant_user_id,
            'merchant_owner' => $canRevealPii ? trim((string) ($pos->merchant?->f_name . ' ' . $pos->merchant?->l_name)) : 'محمي بالصلاحيات',
        ];
    }

    /** سجلات الأعمال المرتبطة: البيع/الوقود/الصيدلية/الجملة/طلب الدفع/تقسيم الفاتورة. */
    private function businessRecords(array $refs, bool $canRevealPii): array
    {
        $out = [];
        $read = static fn (string $table) => Schema::hasTable($table);

        if ($read('payment_requests')) {
            $out['payment_requests'] = \App\Models\PaymentRequest::whereIn('paid_transaction_id', $refs)->get()
                // short_code رابط قابل للمشاركة وليس معلومة دعم؛ عرضه يحوّل
                // صفحة التتبع إلى قناة استخراج روابط دفع.
                ->map(fn ($r) => ['reference' => $r->request_ulid, 'status' => $r->status, 'amount' => $r->amount, 'requester_user_id' => $r->requester_user_id, 'recipient_user_id' => $r->recipient_user_id, 'paid_at' => $r->paid_at, 'expires_at' => $r->expires_at])->values();
        }
        if ($read('merchant_sales')) {
            $out['merchant_sales'] = \App\Models\MerchantSale::whereIn('paid_transaction_id', $refs)->get()
                ->map(fn ($s) => ['reference' => $s->sale_ulid, 'status' => $s->status, 'payment_method' => $s->payment_method, 'merchant_user_id' => $s->merchant_user_id, 'pos_user_id' => $s->pos_user_id, 'total_amount' => $s->total_amount, 'discount_amount' => $s->discount_amount, 'cash_amount' => $s->cash_amount, 'wallet_amount' => $s->wallet_amount, 'items' => collect($s->items ?? [])->take(50)->values()])->values();
        }
        if ($read('fuel_sales')) {
            $out['fuel_sales'] = \App\Models\FuelSale::whereIn('paid_transaction_id', $refs)->get()
                ->map(fn ($s) => ['reference' => $s->sale_ulid, 'status' => $s->status, 'station_id' => $s->station_id, 'pump_id' => $s->pump_id, 'nozzle_id' => $s->nozzle_id, 'liters' => $s->liters, 'price_per_liter' => $s->price_per_liter, 'total_amount' => $s->total_amount, 'payment_method' => $s->payment_method, 'vehicle_plate' => $canRevealPii ? $s->vehicle_plate : null])->values();
        }
        if ($read('pharmacy_sales')) {
            $out['pharmacy_sales'] = \App\Models\PharmacySale::whereIn('paid_transaction_id', $refs)->get()
                ->map(fn ($s) => ['reference' => $s->sale_ulid, 'status' => $s->status, 'pharmacy_id' => $s->pharmacy_id, 'total_amount' => $s->total_amount, 'discount_amount' => $s->discount_amount, 'payment_method' => $s->payment_method, 'clinical_details_restricted' => true])->values();
        }
        if ($read('wholesale_invoices')) {
            $out['wholesale_invoices'] = \App\Models\WholesaleInvoice::whereIn('paid_transaction_id', $refs)->get()
                ->map(fn ($i) => ['reference' => $i->invoice_number ?: $i->invoice_ulid, 'status' => $i->status, 'business_id' => $i->business_id, 'total_amount' => $i->total_amount, 'paid_amount' => $i->paid_amount, 'balance_due' => $i->balance_due, 'payment_type' => $i->payment_type, 'due_date' => $i->due_date])->values();
        }
        if ($read('wholesale_collections')) {
            $out['wholesale_collections'] = \App\Models\WholesaleCollection::whereIn('paid_transaction_id', $refs)->get()
                ->map(fn ($c) => ['reference' => $c->collection_ulid, 'invoice_id' => $c->invoice_id, 'amount' => $c->amount, 'payment_method' => $c->payment_method, 'collection_date' => $c->collection_date])->values();
        }
        if ($read('split_bill_participants')) {
            $out['split_bill_participants'] = \App\Models\SplitBillParticipant::whereIn('paid_transaction_id', $refs)->get()
                ->map(fn ($p) => ['split_bill_id' => $p->split_bill_id, 'customer_user_id' => $p->customer_user_id, 'share_amount' => $p->share_amount, 'status' => $p->status, 'paid_at' => $p->paid_at])->values();
        }
        return $out;
    }

    private function requireAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user && ((int) ($user->type ?? -1) === 0
            || in_array($user->role ?? null, [A::ROLE_ADMIN, 'super_admin'], true));

        return $isAdmin ? null : $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
    }

    /**
     * لا تنفّذ بوابة الدعم نسخةً ثانية من التجميد وPIN والجلسات. مركز
     * العملاء والدعم يمران من الحارس نفسه: الصلاحية، السبب، منع
     * self-action، نطاق العميل، الموافقة الثنائية، وسجل التدقيق الحرج.
     */
    private function runCustomerAction(Request $request, User $customer, string $action): JsonResponse
    {
        try {
            $out = $this->customerActions->run(
                $customer,
                $request->user(),
                $action,
                trim((string) $request->input('reason', '')),
            );
        } catch (DomainException $e) {
            return $this->error('ACTION_REJECTED', $e->getMessage(), 422);
        }

        $approvalRequired = (bool) ($out['approval_required'] ?? false);

        return $this->ok([
            'user_id' => (int) $customer->id,
            'action' => $action,
            'approval_required' => $approvalRequired,
            'request_number' => $out['approval_request_number'] ?? null,
            // المفتاحُ الذي يُبنى به مسارُ الاعتماد — لا الرقمُ المعروض.
            'request_id' => $out['approval_request_id'] ?? null,
            'operation' => $out['context'] ?? [],
        ], $approvalRequired ? 'APPROVAL_PENDING' : 'OK', (string) $out['message'], $approvalRequired ? 202 : 200);
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
