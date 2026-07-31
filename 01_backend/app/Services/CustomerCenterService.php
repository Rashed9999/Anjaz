<?php

namespace App\Services;

use App\Models\Aml\AmlFlaggedTransaction;
use App\Models\Aml\AmlInvestigation;
use App\Models\Aml\AmlUserRiskProfile;
use App\Models\AmialNotification;
use App\Models\EMoney;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CUSTOMER-CENTER-001 — مركز العملاء (الفصل ٠٢).
 *
 * **ما تشترطه الوثيقة حرفياً:** «يجب أن يستطيع موظف خدمة العملاء إدارة
 * العميل **بالكامل من شاشة واحدة** دون الحاجة للانتقال بين أكثر من لوحة».
 *
 * وكان العميل موزَّعاً على ثلاث شاشات: «مركز العملاء» (قائمة وإنشاء)،
 * و«مركز الدعم» (ملفّ ٣٦٠)، و«كشف المعاملات». فموظّفٌ على الهاتف مع عميل
 * يقفز بينها ويفقد سياقه في كلّ قفزة.
 *
 * ══════════════════════════════════════════════════════════════
 * **ولماذا كلّ تبويبٍ نقطةُ نهايةٍ مستقلّة:**
 *
 * الوثيقة تشترط فتح الملفّ في **≤ ثانية واحدة**. وتجميعُ التبويبات العشرة
 * في ردٍّ واحد يجعل زمن الفتح مجموعَ أبطئها — وأبطؤها «العمليات» و«التدقيق»
 * وهما ما يُفتح أقلّ.
 *
 * فتُحمَّل «النظرة العامة» وحدها عند الفتح، ويُحمَّل كلّ تبويبٍ عند الضغط
 * عليه. والنتيجة أنّ الشرط يتحقّق لا بتحسينِ استعلامٍ بل بعدم تنفيذه أصلاً
 * حتى يُطلب.
 * ══════════════════════════════════════════════════════════════
 */
class CustomerCenterService
{
    public function __construct(
        private readonly CustomerStatusResolver $status,
        private readonly PiiAccessAuditService $pii,
    ) {
    }

    /**
     * كلّ فتحٍ لتبويبٍ يُسجَّل — والوثيقة تشترط ذلك صراحةً:
     * «يسجل النظام تلقائياً: جميع عمليات البحث، فتح صفحة العميل، تنفيذ أي
     * إجراء».
     *
     * ويُسجَّل **لكلّ تبويب** لا لفتح الصفحة وحده: من يفتح «المخاطر» أو
     * «الهوية» يطّلع على بياناتٍ أشدّ حساسيةً ممّن يقرأ الرصيد، وتسجيلٌ
     * واحد عند الدخول يُسوّي بينهما.
     */
    private function logAccess(int $actorId, int $customerId, string $tab, ?string $reason = null): void
    {
        $this->pii->logAccess(
            actorUserId: $actorId,
            subjectType: 'user',
            subjectId: $customerId,
            fieldName: 'customer_center:' . $tab,
            accessType: 'view',
            accessReason: $reason ?: 'فتح تبويب ' . $tab . ' في مركز العملاء',
        );
    }

    // ── التبويب ١: نظرة عامة ────────────────────────────────────────────

    public function overview(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'overview');

        $wallet = EMoney::where('user_id', $customer->id)->first();
        $status = $this->status->resolve($customer);
        $risk = AmlUserRiskProfile::find($customer->id);

        return [
            'profile' => [
                'id' => (int) $customer->id,
                'name' => trim((string) ($customer->f_name . ' ' . $customer->l_name)) ?: '—',
                'phone' => (string) $customer->phone,
                'email' => (string) ($customer->email ?? '—'),
                'type' => $this->typeLabel((int) $customer->type),
                'zone_code' => (string) ($customer->zone_code ?? '—'),
                'registered_at' => $customer->created_at?->toIso8601String(),
                'last_active_at' => $customer->last_active_at?->toIso8601String()
                    ?? $customer->updated_at?->toIso8601String(),
            ],
            'status' => $status,
            'wallet' => [
                'current_balance' => (string) ($wallet->current_balance ?? '0'),
                'held_balance' => (string) ($wallet->held_balance ?? '0'),
                'pending_balance' => (string) ($wallet->pending_balance ?? '0'),
                // المتاح = الحاليّ ناقص المحجوز. ويُحسب هنا لا يُترك للموظّف:
                // من يقرأ «الرصيد ١٠٠٠» ولا يرى «محجوز ٩٠٠» يعِد العميل بما
                // لا يستطيع.
                'available_balance' => bcsub(
                    (string) ($wallet->current_balance ?? '0'),
                    (string) ($wallet->held_balance ?? '0'),
                    4,
                ),
            ],
            'kyc' => [
                'is_verified' => (bool) ($customer->is_kyc_verified ?? false),
                'tier' => (int) ($customer->kyc_tier ?? 0),
                'sanction_status' => (string) ($customer->sanction_status ?? 'clear'),
            ],
            'risk' => [
                'score' => (string) ($risk->current_risk_score ?? '0'),
                'level' => (string) ($risk->risk_level ?? 'unknown'),
                'override' => (string) ($risk->manual_override ?? 'none'),
            ],
            'limits' => $this->limits($customer),
            'notes' => $this->notes($customer),
            'counters' => [
                'open_tickets' => Schema::hasTable('support_tickets')
                    ? DB::table('support_tickets')->where('user_id', $customer->id)
                        ->whereIn('status', ['open', 'investigating', 'waiting_customer'])->count()
                    : 0,
                'open_investigations' => Schema::hasTable('aml_investigations')
                    ? AmlInvestigation::where('subject_user_id', $customer->id)->open()->count()
                    : 0,
                'pending_kyc_docs' => KycDocument::where('user_id', $customer->id)
                    ->where('status', KycDocument::STATUS_PENDING)->count(),
                'blocked_devices' => DB::table('user_log_histories')
                    ->where('user_id', $customer->id)->where('is_blocked', 1)->count(),
            ],
        ];
    }

    private function typeLabel(int $type): string
    {
        return match ($type) {
            ADMIN_TYPE => 'موظّف منصّة',
            AGENT_TYPE => 'وكيل',
            MERCHANT_TYPE => 'تاجر',
            default => 'عميل',
        };
    }

    /** الحدّ النافذ: استثناء العميل إن وُجد، وإلّا حدّ فئته. */
    private function limits(User $customer): array
    {
        $override = is_array($customer->limit_override)
            ? $customer->limit_override
            : (json_decode((string) $customer->limit_override, true) ?: []);

        $tier = Schema::hasTable('kyc_tier_limits')
            ? DB::table('kyc_tier_limits')->where('tier', (int) ($customer->kyc_tier ?? 0))->first()
            : null;

        $pick = fn (string $key, $default) => $override[$key] ?? ($tier->{$key} ?? $default);

        return [
            'source' => $override !== [] ? 'استثناء خاصّ بالعميل' : 'حدّ الفئة',
            'max_balance' => (string) $pick('max_balance', '0'),
            'max_single_transaction' => (string) $pick('max_single_transaction', '0'),
            'max_daily_total' => (string) $pick('max_daily_total', '0'),
            'max_monthly_total' => (string) $pick('max_monthly_total', '0'),
            'has_override' => $override !== [],
        ];
    }

    public function notes(User $customer): array
    {
        if (!Schema::hasTable('customer_notes')) {
            return [];
        }

        return DB::table('customer_notes as n')
            ->leftJoin('users as u', 'u.id', '=', 'n.author_id')
            ->where('n.user_id', $customer->id)
            // المثبّتة أوّلاً: «اتّصل ثلاث مرّات بخصوص المشكلة نفسها» يجب أن
            // تُرى قبل أن يبدأ الموظّف من الصفر.
            ->orderByDesc('n.is_pinned')->orderByDesc('n.created_at')
            ->limit(50)
            ->selectRaw('n.id, n.body, n.is_pinned, n.created_at, u.f_name, u.l_name')
            ->get()
            ->map(fn ($n) => [
                'id' => (int) $n->id,
                'body' => (string) $n->body,
                'is_pinned' => (bool) $n->is_pinned,
                'author' => trim((string) ($n->f_name . ' ' . $n->l_name)) ?: '—',
                'created_at' => (string) $n->created_at,
            ])->all();
    }

    // ── التبويب ٢: المحافظ ──────────────────────────────────────────────

    public function wallets(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'wallets');

        $main = EMoney::where('user_id', $customer->id)->first();

        $rows = [[
            'name' => 'المحفظة الرئيسية',
            'balance' => (string) ($main->current_balance ?? '0'),
            'reserved' => (string) ($main->held_balance ?? '0'),
            'pending' => (string) ($main->pending_balance ?? '0'),
            'currency' => 'YER',
            'status' => (int) ($customer->is_temp_blocked ?? 0) === 1 ? 'مجمَّدة' : 'نشطة',
        ]];

        // صناديق العائلة: محافظ فعلية للعميل وإن كانت جماعية. وإخفاؤها يجعل
        // مجموع ما يملكه العميل مجهولاً للموظّف.
        if (Schema::hasTable('family_funds')) {
            DB::table('family_funds as f')
                ->join('family_fund_members as m', 'm.fund_id', '=', 'f.id')
                ->where('m.user_id', $customer->id)
                ->select('f.name', 'f.balance', 'f.id')
                ->get()
                ->each(function ($f) use (&$rows) {
                    $rows[] = [
                        'name' => 'صندوق عائلة: ' . ($f->name ?? '#' . $f->id),
                        'balance' => (string) ($f->balance ?? '0'),
                        'reserved' => '0', 'pending' => '0',
                        'currency' => 'YER', 'status' => 'مشترك',
                    ];
                });
        }

        return ['wallets' => $rows];
    }

    // ── التبويب ٣: العمليات ─────────────────────────────────────────────

    public function transactions(User $customer, int $actorId, array $filters = []): array
    {
        $this->logAccess($actorId, $customer->id, 'transactions');

        if (!Schema::hasTable('transactions')) {
            return ['items' => []];
        }

        // الطرفان معاً: `from_user_id` و`to_user_id` إلى جانب `user_id`.
        // وقصرُها على `user_id` يُخفي عن الموظّف العملياتِ التي كان العميل
        // **مستقبِلها** — وهي نصف قصّته.
        $q = DB::table('transactions')
            ->where(fn ($w) => $w->where('user_id', $customer->id)
                ->orWhere('from_user_id', $customer->id)
                ->orWhere('to_user_id', $customer->id));

        if (!empty($filters['type'])) {
            $q->where('transaction_type', $filters['type']);
        }
        if (!empty($filters['from'])) {
            $q->where('created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->where('created_at', '<=', $filters['to'] . ' 23:59:59');
        }

        $items = $q->orderByDesc('id')->limit(300)->get()
            ->map(fn ($t) => [
                'transaction_id' => (string) ($t->transaction_id ?? $t->id),
                'type' => (string) ($t->transaction_type ?? '—'),
                'debit' => (string) ($t->debit ?? '0'),
                'credit' => (string) ($t->credit ?? '0'),
                'balance' => (string) ($t->balance ?? '0'),
                'created_at' => (string) $t->created_at,
            ])->all();

        return [
            'items' => $items,
            'types' => DB::table('transactions')->distinct()
                ->orderBy('transaction_type')->pluck('transaction_type')->filter()->values()->all(),
        ];
    }

    // ── التبويب ٤: الأجهزة ──────────────────────────────────────────────

    public function devices(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'devices');

        return [
            'devices' => DB::table('user_log_histories')
                ->where('user_id', $customer->id)
                ->orderByDesc('is_active')->orderByDesc('updated_at')
                ->limit(50)->get()
                ->map(fn ($d) => [
                    'id' => (int) $d->id,
                    'device_id' => (string) $d->device_id,
                    'device_model' => (string) ($d->device_model ?: '—'),
                    'os' => (string) ($d->os ?: '—'),
                    'app_version' => $d->app_version ?? null,
                    'ip_address' => (string) ($d->ip_address ?: '—'),
                    'is_active' => (bool) $d->is_active,
                    'is_trusted' => (bool) ($d->is_trusted ?? false),
                    'is_blocked' => (bool) ($d->is_blocked ?? false),
                    'block_reason' => $d->block_reason ?? null,
                    'last_seen_at' => $d->last_seen_at ?? $d->updated_at,
                ])->all(),
        ];
    }

    // ── التبويب ٥: المصادقة ─────────────────────────────────────────────

    public function authentication(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'authentication');

        $events = Schema::hasTable('account_security_events')
            ? DB::table('account_security_events')
                ->where('user_id', $customer->id)
                ->orderByDesc('id')->limit(100)->get()
            : collect();

        return [
            'pin' => [
                'is_set' => !empty($customer->pin_code ?? $customer->pin ?? null),
                'failed_attempts' => (int) ($customer->pin_failed_attempts ?? 0),
                'locked_until' => $customer->pin_locked_until ?? null,
            ],
            // يُفرَّق بين أنواع الأحداث لا تُعرض كتلةً واحدة: تغييرُ الهاتف
            // أخطر ما يقع على حساب — وهو مدفون بين مئة محاولة دخول لو خُلطا.
            'phone_changes' => $events->filter(fn ($e) => str_contains((string) $e->event_type, 'PHONE'))
                ->map(fn ($e) => $this->securityEvent($e))->values()->all(),
            'pin_changes' => $events->filter(fn ($e) => str_contains((string) $e->event_type, 'PIN')
                && !str_contains((string) $e->event_type, 'FAILED'))
                ->map(fn ($e) => $this->securityEvent($e))->values()->all(),
            'failed_attempts' => $events->filter(fn ($e) => str_contains((string) $e->event_type, 'FAILED'))
                ->map(fn ($e) => $this->securityEvent($e))->values()->all(),
            'other' => $events->filter(fn ($e) => !str_contains((string) $e->event_type, 'PHONE')
                && !str_contains((string) $e->event_type, 'PIN'))
                ->map(fn ($e) => $this->securityEvent($e))->take(30)->values()->all(),
        ];
    }

    private function securityEvent($e): array
    {
        return [
            'type' => (string) $e->event_type,
            'severity' => (string) ($e->severity ?? 'info'),
            'ip' => (string) ($e->ip_address ?? '—'),
            'note' => (string) ($e->note ?? ''),
            'at' => (string) $e->created_at,
        ];
    }

    // ── التبويب ٦: الهوية ───────────────────────────────────────────────

    public function kyc(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'kyc');

        return [
            'is_verified' => (bool) ($customer->is_kyc_verified ?? false),
            'tier' => (int) ($customer->kyc_tier ?? 0),
            'documents' => KycDocument::with('reviewer:id,f_name,l_name')
                ->where('user_id', $customer->id)
                ->orderByDesc('created_at')->get()
                ->map(fn (KycDocument $d) => [
                    'id' => (int) $d->id,
                    'doc_type' => $d->doc_type,
                    'doc_label' => KycDocument::TYPE_LABELS[$d->doc_type] ?? $d->doc_type,
                    'status' => $d->status,
                    'rejection_reason' => $d->rejection_reason,
                    'ocr_status' => $d->ocr_status ?? 'not_run',
                    'expires_at' => $d->document_expires_at?->format('Y-m-d'),
                    'reviewer' => $d->reviewer
                        ? trim((string) ($d->reviewer->f_name . ' ' . $d->reviewer->l_name)) : null,
                    'reviewed_at' => $d->reviewed_at?->toIso8601String(),
                    'uploaded_at' => $d->created_at?->toIso8601String(),
                ])->all(),
            'completeness' => app(KycDocumentService::class)->completenessFor($customer, 2),
        ];
    }

    // ── التبويب ٧: المخاطر ──────────────────────────────────────────────

    public function risk(User $customer, int $actorId): array
    {
        // فتحُ ملفّ المخاطر اطّلاعٌ أشدّ حساسيةً — يُسجَّل بسببٍ مميَّز.
        $this->logAccess($actorId, $customer->id, 'risk', 'مراجعة ملفّ مخاطر العميل');

        $profile = AmlUserRiskProfile::find($customer->id);

        return [
            'profile' => [
                'score' => (string) ($profile->current_risk_score ?? '0'),
                'level' => (string) ($profile->risk_level ?? 'unknown'),
                'override' => (string) ($profile->manual_override ?? 'none'),
                'override_reason' => $profile->override_reason ?? null,
            ],
            'sanction_status' => (string) ($customer->sanction_status ?? 'clear'),
            'flagged' => Schema::hasTable('aml_flagged_transactions')
                ? AmlFlaggedTransaction::where('actor_user_id', $customer->id)
                    ->orderByDesc('id')->limit(30)->get()
                    ->map(fn ($f) => [
                        'flag_ulid' => $f->flag_ulid,
                        'amount' => (string) $f->amount,
                        'risk_score' => (string) $f->total_risk_score,
                        'status' => $f->current_status,
                        'at' => $f->created_at?->toIso8601String(),
                    ])->all()
                : [],
            'investigations' => Schema::hasTable('aml_investigations')
                ? AmlInvestigation::where('subject_user_id', $customer->id)
                    ->orderByDesc('id')->limit(20)->get()
                    ->map(fn (AmlInvestigation $i) => [
                        'id' => (int) $i->id,
                        'case_number' => $i->case_number,
                        'status' => $i->status,
                        'priority' => $i->priority,
                        'decision' => $i->decision,
                        'age_hours' => $i->ageHours(),
                    ])->all()
                : [],
            'evaluations' => Schema::hasTable('aml_rule_evaluations')
                ? DB::table('aml_rule_evaluations')
                    ->where('actor_user_id', $customer->id)->where('matched', true)
                    ->orderByDesc('id')->limit(30)
                    ->get(['rule_code', 'amount', 'contributed_risk_score', 'created_at'])
                    ->map(fn ($e) => (array) $e)->all()
                : [],
        ];
    }

    // ── التبويب ٨: الدعم ────────────────────────────────────────────────

    public function support(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'support');

        if (!Schema::hasTable('support_tickets')) {
            return ['tickets' => []];
        }

        return [
            'tickets' => DB::table('support_tickets')
                ->where('user_id', $customer->id)
                ->orderByDesc('id')->limit(50)->get()
                ->map(fn ($t) => [
                    'ticket_number' => (string) ($t->ticket_number ?? $t->id),
                    'subject' => (string) ($t->subject ?? '—'),
                    'status' => (string) ($t->status ?? '—'),
                    'priority' => (string) ($t->priority ?? '—'),
                    'created_at' => (string) $t->created_at,
                    'updated_at' => (string) $t->updated_at,
                ])->all(),
        ];
    }

    // ── التبويب ٩: الإشعارات ────────────────────────────────────────────

    public function notifications(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'notifications');

        return [
            'items' => AmialNotification::where('user_id', $customer->id)
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn ($n) => [
                    'type' => (string) $n->type,
                    'title' => (string) $n->title,
                    'body' => (string) $n->body,
                    // «أُرسل» و«قُرئ» ليسا واحداً: شكوى «لم يصلني إشعار» تُحسم
                    // بالفرق بينهما — أُرسل ولم يُقرأ مشكلةُ العميل، ولم يُرسل
                    // مشكلتنا.
                    'sent_at' => $n->created_at?->toIso8601String(),
                    'read_at' => $n->read_at?->toIso8601String(),
                    'is_read' => $n->read_at !== null,
                ])->all(),
        ];
    }

    // ── التبويب ١٠: التدقيق ─────────────────────────────────────────────

    public function audit(User $customer, int $actorId): array
    {
        $this->logAccess($actorId, $customer->id, 'audit');

        $rows = [];

        // قرارات الموظّفين على هذا العميل.
        if (Schema::hasTable('audit_decisions')) {
            DB::table('audit_decisions as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
                ->where(function ($q) use ($customer) {
                    $q->where('a.subject_id', (string) $customer->id)
                      ->orWhere('a.actor_user_id', $customer->id);
                })
                ->orderByDesc('a.id')->limit(100)
                ->selectRaw('a.action, a.decision_code, a.severity, a.reason, a.created_at,
                    a.actor_type, u.f_name, u.l_name')
                ->get()->each(function ($a) use (&$rows) {
                    $rows[] = [
                        'source' => 'قرار',
                        'action' => (string) $a->action,
                        'code' => (string) ($a->decision_code ?? ''),
                        'severity' => (string) ($a->severity ?? 'info'),
                        'note' => (string) ($a->reason ?? ''),
                        'actor' => trim((string) ($a->f_name . ' ' . $a->l_name)) ?: (string) $a->actor_type,
                        'at' => (string) $a->created_at,
                    ];
                });
        }

        // أحداث أمان الحساب.
        if (Schema::hasTable('account_security_events')) {
            DB::table('account_security_events')
                ->where('user_id', $customer->id)
                ->orderByDesc('id')->limit(100)->get()
                ->each(function ($e) use (&$rows) {
                    $rows[] = [
                        'source' => 'أمان',
                        'action' => (string) $e->event_type,
                        'code' => '',
                        'severity' => (string) ($e->severity ?? 'info'),
                        'note' => (string) ($e->note ?? ''),
                        'actor' => '—',
                        'at' => (string) $e->created_at,
                    ];
                });
        }

        // من اطّلع على بيانات هذا العميل — والوثيقة تشترط تسجيله.
        // ويُعرَض للمراجع نفسه: «من فتح ملفّي؟» سؤالٌ رقابيّ لا فضول.
        if (Schema::hasTable('pii_access_logs')) {
            DB::table('pii_access_logs as p')
                ->leftJoin('users as u', 'u.id', '=', 'p.actor_user_id')
                ->where('p.subject_id', $customer->id)
                ->orderByDesc('p.id')->limit(50)
                ->selectRaw('p.field_name, p.access_reason, p.created_at, u.f_name, u.l_name')
                ->get()->each(function ($p) use (&$rows) {
                    $rows[] = [
                        'source' => 'اطّلاع',
                        'action' => (string) $p->field_name,
                        'code' => '',
                        'severity' => 'info',
                        'note' => (string) ($p->access_reason ?? ''),
                        'actor' => trim((string) ($p->f_name . ' ' . $p->l_name)) ?: '—',
                        'at' => (string) $p->created_at,
                    ];
                });
        }

        // مصادر ثلاثة في خطٍّ زمنيٍّ واحد: القصّة لا تُقرأ إن وُزّعت على
        // ثلاثة جداول يقلّبها الموظّف بيده.
        usort($rows, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return ['items' => array_slice($rows, 0, 150)];
    }
}
