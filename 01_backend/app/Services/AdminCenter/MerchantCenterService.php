<?php

namespace App\Services\AdminCenter;

use App\Models\AdminCenter\MerchantDataAccessGrant;
use App\Models\Branch;
use App\Models\EMoney;
use App\Models\KycDocument;
use App\Models\RegistrationDossier;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\PosUser;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLogHistory;
use App\Services\Support\SupportTicketService;
use App\Support\Access\AccessConstants as A;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-MERCHANT-CENTER-001 — **مركزُ التاجر: ما تملكه أميال، لا ما يملكه هو**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **حدُّ المسؤوليّة صريح:**
 *
 * | يملكها التاجر ويديرها بنفسه | تملكها أميال وتراقبها |
 * |---|---|
 * | المنتجات · المخزون · الموردون · أسعار الشراء · التشغيل اليوميّ | الحساب · الأموال · العمليّات · التسويات · العمولات · المخاطر · الامتثال · الاشتراك · الأجهزة |
 *
 * **ولا اسمَ صنفٍ واحدٍ في هذا الملفّ.** فأميال تعرف أنّ التاجر نفّذ
 * ١٢٤٠ عمليّةَ دفعٍ بقيمة كذا، **ولا تحتاج أن تعرف أنّ إحداها كانت
 * «بيبسي»**. ومعرفةُ ذلك بلا سببٍ تسرُّب، وبسببٍ مكتوبٍ وأجلٍ رقابة —
 * و`MerchantDataAccessGrant` هي الفرقُ بينهما.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وكلُّ رقمٍ هنا يُحسب من مصدره** (القاعدة ٦): العمليّاتُ من
 * `transactions`، والرصيدُ من `e_money`، والمبيعاتُ عدداً ومبلغاً من
 * `merchant_sales` — **بلا أسطرها**.
 */
class MerchantCenterService
{
    public function __construct(private readonly MerchantAdminActionService $actions) {}

    /**
     * أقسامُ المركز — **يُبنى منها التبويبُ ولا يُكتب مرّتين**.
     *
     * وصلاحيّةُ كلّ قسمٍ هنا هي **نفسُها** التي يطلبها مسارُه في
     * `routes/admin/amial.php`. ولو اختلفتا لظهر التبويبُ ولم يُفتح —
     * أو انفتح ولم يظهر. يمنعهما `MerchantCenterGuardTest`.
     *
     * AMIAL-OPERATOR-RBAC-003: كان القسمُ محميّاً بصلاحيّتين لا غير
     * (`customers.view` لكلّ قراءة و`audit.view` لكلّ ما يمسّ المال)، فمن
     * يفتح ملفّ تاجرٍ ليردّ على تذكرةٍ كان يقرأ تسوياته وعمولاته.
     */
    public const SECTIONS = [
        'profile' => ['name' => 'الملف الأساسي', 'icon' => 'tio-user', 'permission' => 'platform.customers.view'],
        'money' => ['name' => 'المركز المالي', 'icon' => 'tio-money', 'permission' => 'platform.merchants.money'],
        'settlements' => ['name' => 'التسويات', 'icon' => 'tio-refresh', 'permission' => 'platform.merchants.money'],
        'operations' => ['name' => 'العمليات', 'icon' => 'tio-chart-bar-4', 'permission' => 'platform.customers.view'],
        'risk' => ['name' => 'المخاطر', 'icon' => 'tio-warning', 'permission' => 'platform.merchants.risk'],
        'staff' => ['name' => 'الموظفون (عرض)', 'icon' => 'tio-group-equal', 'permission' => 'platform.customers.view'],
        'devices' => ['name' => 'الأجهزة والجلسات', 'icon' => 'tio-devices', 'permission' => 'platform.merchants.risk'],
        'subscription' => ['name' => 'الاشتراك', 'icon' => 'tio-receipt', 'permission' => 'platform.customers.view'],
        'compliance' => ['name' => 'الامتثال والتوثيق', 'icon' => 'tio-shield-check', 'permission' => 'platform.merchants.compliance'],
        'support' => ['name' => 'الدعم', 'icon' => 'tio-support', 'permission' => 'platform.customers.view'],
        'audit' => ['name' => 'سجل التدقيق', 'icon' => 'tio-history', 'permission' => 'platform.audit.view'],
    ];

    /**
     * أقسامُ هذا المشغّل وحده — **لا يُعرض تبويبٌ لا يُفتح**.
     *
     * والقاعدةُ التاسعة تقول: زرٌّ لم يُضغط ليس مبنيّاً. وأختُها هنا:
     * **تبويبٌ يُعرض ثمّ يردّ ٤٠٣ أسوأ من غيابه** — يُخبر الموظّف أنّ
     * هناك ما يُخفى عنه، ويُرسله يسأل عن عطلٍ لا وجود له.
     *
     * @return array<string, array{name:string, icon:string, permission:string}>
     */
    public function sectionsFor(User $admin): array
    {
        return array_filter(
            self::SECTIONS,
            fn (array $s) => $admin->hasPlatformPermission($s['permission']),
        );
    }

    /** هل يملك هذا المشغّل قسماً بعينه؟ يُقرأ قبل بناء حمولة النظرة العامّة. */
    public function maySee(User $admin, string $section): bool
    {
        $perm = self::SECTIONS[$section]['permission'] ?? null;

        return $perm !== null && $admin->hasPlatformPermission($perm);
    }

    public function merchant(int $id): User
    {
        $u = User::find($id);

        if (! $u) {
            throw new DomainException('التاجر غير موجود');
        }

        return $u;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ١) الملف الأساسي
    // ══════════════════════════════════════════════════════════════════

    public function profile(User $m): array
    {
        $p = MerchantProfile::where('user_id', $m->id)->first();
        $store = \App\Models\Merchant::where('user_id', $m->id)->first();

        $lastTx = Transaction::where('user_id', $m->id)->latest('id')->first();
        $lastLogin = UserLogHistory::where('user_id', $m->id)
            ->orderByDesc('last_seen_at')->first();

        return [
            'id' => $m->id,
            'merchant_number' => $store->merchant_number ?? null,
            'name' => trim(($m->f_name ?? '') . ' ' . ($m->l_name ?? '')) ?: '—',
            'store_name' => $store->store_name ?? null,
            'phone' => $m->phone,
            'email' => $m->email,
            // AMIAL-MERCHANT-PROFILE-NULL-001 — **حسابُ تاجرٍ بلا ملفٍّ
            // يُسقط الصفحةَ كلَّها.** `$p` قد تكون `null`: مستخدمٌ نوعُه ٣
            // ولا صفَّ له في `merchant_profiles` (يقع عند إنشاءٍ يدويٍّ من
            // اللوحة أو ترقيةِ حسابٍ لم تكتمل). وقراءةُ خاصّيّةٍ على `null`
            // ترفع `ErrorException` فيردّ المركزُ **٥٠٠**.
            //
            // وكُشف بالمسبار حين كانت في القاعدة بياناتُ عرضٍ حقيقيّة —
            // فمسحٌ على قاعدةٍ فارغةٍ كان يمرّ سنةً كاملةً وهي مكسورة.
            //
            // **والغيابُ يُقال ولا يُملأ صفراً** (القاعدة السابعة).
            'business_type' => $p?->business_type,
            'business_type_label' => $p === null
                ? 'لا ملفَّ تاجرٍ لهذا الحساب'
                : (A::BUSINESS_TYPE_LABELS[$p->business_type ?? ''] ?? '—'),
            'has_profile' => $p !== null,
            'status' => (int) ($m->is_active ?? 0) === 1 ? 'active' : 'frozen',
            'status_ar' => (int) ($m->is_active ?? 0) === 1 ? 'نشط' : 'مجمَّد',
            'created_at' => $m->created_at?->format('Y-m-d'),
            // **«لم يدخل قطّ» ليست تاريخاً فارغاً** (القاعدة ٧).
            'last_login' => $lastLogin?->last_seen_at?->format('Y-m-d H:i') ?? 'لم يُسجَّل دخول',
            'last_transaction' => $lastTx?->created_at?->format('Y-m-d H:i') ?? 'لا عمليات',
            'kyc_status' => $p?->verification_status ?? 'pending',
            'verification_level' => $m->verification_level ?? 'basic',
            'zone_code' => $m->zone_code,
            'plan' => A::canonicalPlan($p?->subscription_plan),
            'plan_name' => A::PLAN_LABELS[A::canonicalPlan($p?->subscription_plan)] ?? '—',
            'plan_expires' => $p?->subscription_expires_at?->format('Y-m-d'),
            // بياناتٌ تشغيليّةٌ **عدداً لا تفصيلاً**
            'branches_count' => Branch::where('merchant_user_id', $m->id)->count(),
            'pos_count' => PosUser::where('merchant_user_id', $m->id)->count(),
            'branches' => Branch::where('merchant_user_id', $m->id)
                ->get(['name', 'code', 'city', 'is_active'])
                ->map(fn ($b) => [
                    'name' => $b->name, 'code' => $b->code,
                    'city' => $b->city, 'active' => (bool) $b->is_active,
                ])->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ٢) المركز المالي — **وكلُّ رقمٍ يُنقر فيُفضي إلى قيوده**
    // ══════════════════════════════════════════════════════════════════

    public function money(User $m): array
    {
        $w = EMoney::where('user_id', $m->id)->first();

        $agg = fn (array $types) => (string) Transaction::where('user_id', $m->id)
            ->whereIn('transaction_type', $types)->sum('amount');

        $count = fn (array $types) => Transaction::where('user_id', $m->id)
            ->whereIn('transaction_type', $types)->count();

        // **الرسومُ تُجمع من عمود `charge` لا تُقدَّر بنسبة.**
        $fees = (string) Transaction::where('user_id', $m->id)->sum('charge');

        return [
            'wallet' => [
                // **الفراغُ يُقال «لا محفظة» لا صفراً** (القاعدة ٧).
                'exists' => $w !== null,
                'current' => $w ? (string) $w->current_balance : null,
                'held' => $w ? (string) $w->held_balance : null,
                'pending' => $w ? (string) $w->pending_balance : null,
            ],
            'totals' => [
                'received' => ['amount' => $agg(['merchant_payment', 'receive_money', 'add_money']),
                    'count' => $count(['merchant_payment', 'receive_money', 'add_money'])],
                'paid' => ['amount' => $agg(['send_money', 'merchant_payout']),
                    'count' => $count(['send_money', 'merchant_payout'])],
                'cash_in' => ['amount' => $agg(['cash_in', 'agent_deposit']),
                    'count' => $count(['cash_in', 'agent_deposit'])],
                'cash_out' => ['amount' => $agg(['cash_out', 'agent_withdraw']),
                    'count' => $count(['cash_out', 'agent_withdraw'])],
                'refunds' => ['amount' => $agg(['refund', 'merchant_refund']),
                    'count' => $count(['refund', 'merchant_refund'])],
            ],
            'platform_fees_paid' => $fees,
            // مبيعاتُه **عدداً ومبلغاً فقط** — ولا سطرَ ولا صنف.
            'sales_summary' => [
                'count' => MerchantSale::where('merchant_user_id', $m->id)->count(),
                'amount' => (string) MerchantSale::where('merchant_user_id', $m->id)
                    ->sum('total_amount'),
                'note' => 'إجمالي مبيعاته من نقطة بيعه — أميال لا تحفظ أصنافه',
            ],
        ];
    }

    /**
     * كشفُ الحساب بين **أميال والتاجر** — لا محاسبةُ منتجاته.
     */
    public function statement(User $m, ?string $from = null, ?string $to = null, int $limit = 100): array
    {
        $q = Transaction::where('user_id', $m->id);

        if ($from) $q->whereDate('created_at', '>=', $from);
        if ($to) $q->whereDate('created_at', '<=', $to);

        $rows = $q->orderByDesc('id')->limit($limit)->get();

        return [
            'from' => $from, 'to' => $to,
            'rows' => $rows->map(fn (Transaction $t) => [
                'id' => $t->id,
                'transaction_id' => $t->transaction_id,
                'type' => $t->transaction_type,
                'debit' => (string) $t->debit,
                'credit' => (string) $t->credit,
                'charge' => (string) $t->charge,
                'balance_after' => (string) $t->balance,
                'pos_user_id' => $t->pos_user_id,
                'note' => $t->note,
                'at' => $t->created_at?->format('Y-m-d H:i'),
            ])->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ٣) التسويات
    // ══════════════════════════════════════════════════════════════════

    public function settlements(User $m, int $limit = 30): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('settlements')) {
            return ['available' => false, 'reason' => 'محرّك التسويات غير مثبَّت', 'rows' => []];
        }

        $rows = DB::table('settlements')
            ->where('created_by', $m->id)
            ->orWhere('destination_account_detail', 'like', '%"user_id":' . $m->id . '%')
            ->orderByDesc('id')->limit($limit)->get();

        return [
            'available' => true,
            'rows' => $rows->map(fn ($s) => [
                'reference' => $s->reference_number,
                'ulid' => $s->ulid,
                'amount' => (string) $s->amount,
                'currency' => $s->currency,
                'status' => $s->status,
                // **من أنشأ ومن اعتمد ومتى** — والموافقةُ المزدوجة تُقرأ هنا.
                'created_by' => $s->created_by,
                'approved_by' => $s->approved_by,
                'second_approved_by' => $s->second_approved_by,
                'approved_at' => $s->approved_at,
                'completed_at' => $s->completed_at,
                'external_ref' => $s->external_bank_ref ?? $s->external_reference,
                'balance_before' => $s->balance_before !== null ? (string) $s->balance_before : null,
                'balance_after' => $s->balance_after !== null ? (string) $s->balance_after : null,
            ])->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ٤) العمليات — **بالنوع، ويُنقر النوعُ فتُفتح عمليّاته**
    // ══════════════════════════════════════════════════════════════════

    public function operations(User $m, int $days = 30): array
    {
        $from = now()->subDays(max(1, min(365, $days)))->startOfDay();

        $rows = Transaction::where('user_id', $m->id)
            ->where('created_at', '>=', $from)
            ->select('transaction_type',
                DB::raw('COUNT(*) as n'),
                DB::raw('SUM(amount) as total'),
                DB::raw('SUM(charge) as fees'))
            ->groupBy('transaction_type')
            ->orderByDesc(DB::raw('SUM(amount)'))->get();

        return [
            'days' => $days,
            'by_type' => $rows->map(fn ($r) => [
                'type' => $r->transaction_type,
                'count' => (int) $r->n,
                'amount' => (string) ($r->total ?? '0'),
                'fees' => (string) ($r->fees ?? '0'),
            ])->all(),
            'totals' => [
                'count' => (int) $rows->sum('n'),
                'amount' => (string) $rows->sum('total'),
                'fees' => (string) $rows->sum('fees'),
            ],
        ];
    }

    /** عمليّاتُ نوعٍ واحد — **الدرجةُ التالية في التعمّق**. */
    public function operationsOfType(User $m, string $type, int $limit = 100): array
    {
        return Transaction::where('user_id', $m->id)
            ->where('transaction_type', $type)
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (Transaction $t) => [
                'transaction_id' => $t->transaction_id,
                'amount' => (string) $t->amount,
                'charge' => (string) $t->charge,
                'from_user_id' => $t->from_user_id,
                'to_user_id' => $t->to_user_id,
                // **أيُّ موظّفٍ نفّذها داخل التاجر** — وهذا ما تحتاجه أميال،
                // لا اسمُ الصنف المباع.
                'pos_user_id' => $t->pos_user_id,
                'decision' => $t->decision_code,
                'zone' => $t->zone_code,
                'at' => $t->created_at?->format('Y-m-d H:i:s'),
            ])->all();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ٥) المخاطر
    // ══════════════════════════════════════════════════════════════════

    public function risk(User $m): array
    {
        $p = DB::table('merchant_risk_profiles')->where('merchant_user_id', $m->id)->first();
        $events = DB::table('merchant_risk_events')->where('merchant_user_id', $m->id)
            ->orderByDesc('id')->limit(30)->get();

        return [
            // **«لم يُقيَّم» ليست «منخفض»** — والصفرُ هنا يُقرأ أماناً كاذباً.
            'assessed' => $p !== null,
            'score' => $p?->current_risk_score,
            'level' => $p?->risk_level,
            'level_ar' => match ($p?->risk_level) {
                'high', 'critical' => 'مرتفع',
                'medium' => 'متوسط',
                'low' => 'منخفض',
                default => 'لم يُقيَّم بعد',
            },
            'avg_daily_volume' => $p?->avg_daily_volume,
            'peak_daily_volume' => $p?->peak_daily_volume,
            'aml_flags' => (int) ($p?->aml_flags_count ?? 0),
            'last_flagged_at' => $p?->last_flagged_at,
            'last_reviewed_at' => $p?->last_reviewed_at,
            'events' => $events->map(fn ($e) => [
                'type' => $e->event_type,
                'contribution' => (string) $e->risk_contribution,
                'description' => $e->description,
                'transaction_ulid' => $e->transaction_ulid,
                'at' => $e->created_at,
            ])->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ٦) الموظفون — **عرضٌ ومراقبةٌ لا إدارة**
    // ══════════════════════════════════════════════════════════════════

    public function staff(User $m): array
    {
        $rows = PosUser::where('merchant_user_id', $m->id)
            ->with('branch:id,name')->get();

        return [
            'total' => $rows->count(),
            'active' => $rows->where('is_active', true)->count(),
            'disabled' => $rows->where('is_active', false)->count(),
            // **ولا زرَّ «اجعله كاشيراً»** — التاجرُ يبني أدوارَه.
            // والمتاح إداريّاً فعلٌ أمنيٌّ واحد: التعطيل، وله سجلّ.
            'note' => 'أميال ترى الموظفين ولا تديرهم — التاجر ينشئ الأدوار ويمنحها',
            'rows' => $rows->map(function (PosUser $p) {
                $txCount = Transaction::where('pos_user_id', $p->id)->count();
                $lastTx = Transaction::where('pos_user_id', $p->id)->latest('id')->first();
                $lastLogin = $p->user_id
                    ? UserLogHistory::where('user_id', $p->user_id)
                        ->orderByDesc('last_seen_at')->value('last_seen_at')
                    : null;

                return [
                    'id' => $p->id,
                    'name' => $p->display_name,
                    'pos_number' => $p->pos_number,
                    'branch' => $p->branch->name ?? null,
                    'active' => (bool) $p->is_active,
                    'transactions' => $txCount,
                    'last_transaction' => $lastTx?->created_at?->format('Y-m-d H:i') ?? 'لا عمليات',
                    'last_login' => $lastLogin ? \Carbon\Carbon::parse($lastLogin)->format('Y-m-d H:i')
                        : 'لم يُسجَّل دخول',
                ];
            })->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ٧) الأجهزة والجلسات
    // ══════════════════════════════════════════════════════════════════

    public function devices(User $m): array
    {
        $ids = array_merge(
            [$m->id],
            PosUser::where('merchant_user_id', $m->id)->whereNotNull('user_id')
                ->pluck('user_id')->all(),
        );

        $rows = UserLogHistory::whereIn('user_id', $ids)
            ->orderByDesc('last_seen_at')->limit(50)->get();

        return [
            'total' => $rows->count(),
            'active' => $rows->where('is_active', true)->count(),
            'blocked' => $rows->where('is_blocked', true)->count(),
            'rows' => $rows->map(fn (UserLogHistory $d) => [
                'id' => $d->id,
                'user_id' => $d->user_id,
                'device_id' => $d->device_id,
                'model' => $d->device_model,
                'os' => $d->os,
                'browser' => $d->browser,
                'app_version' => $d->app_version,
                'ip' => $d->ip_address,
                'last_seen' => $d->last_seen_at?->format('Y-m-d H:i') ?? 'غير معروف',
                'active' => (bool) $d->is_active,
                'trusted' => (bool) $d->is_trusted,
                'blocked' => (bool) $d->is_blocked,
                'block_reason' => $d->block_reason,
            ])->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ٨) الامتثال والتوثيق
    // ══════════════════════════════════════════════════════════════════

    public function compliance(User $m): array
    {
        $p = MerchantProfile::where('user_id', $m->id)->first();

        $docs = KycDocument::where('user_id', $m->id)
            ->orderByDesc('id')->limit(20)->get();

        return [
            'kyc_status' => $p?->verification_status ?? 'pending',
            'verification_level' => $m->verification_level ?? 'basic',
            'verified_at' => $p?->verified_at?->format('Y-m-d'),
            // AMIAL-MERCHANT-PROFILE-NULL-001 — الغيابُ يُقال لا يُملأ.
            'has_profile' => $p !== null,
            'documents_count' => $docs->count(),
            'documents' => $docs->map(fn (KycDocument $d) => [
                'id' => $d->id,
                // **أسماءُ الأعمدة مقيسةٌ لا مفترَضة**: `doc_type` و
                // `document_expires_at` — والافتراضُ يُخرج «—» صامتاً.
                'type' => $d->doc_type ?? '—',
                'status' => $d->status ?? '—',
                'reviewed_at' => optional($d->reviewed_at)?->format('Y-m-d'),
                'rejection_reason' => $d->rejection_reason,
                'uploaded_at' => $d->created_at?->format('Y-m-d'),
                'expires_at' => optional($d->document_expires_at)?->format('Y-m-d')
                    ?? 'بلا تاريخ انتهاء',
            ])->all(),
            'registration_dossiers' => RegistrationDossier::query()
                ->where('subject_user_id', $m->id)->latest()->limit(10)->get()
                ->map(fn (RegistrationDossier $d) => [
                    'reference' => $d->reference, 'source' => $d->source, 'state' => $d->state,
                    'has_paper_form' => (bool) $d->paper_form_encrypted_path,
                    'created_at' => $d->created_at?->format('Y-m-d H:i'),
                ])->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ٩) الدعم
    // ══════════════════════════════════════════════════════════════════

    public function support(User $m): array
    {
        $rows = SupportTicket::where('user_id', $m->id)
            ->orderByDesc('id')->limit(30)->get();

        // `in_progress` لم تكن من `SupportTicket::STATUSES` قطّ — فتذكرةٌ
        // «قيد التحقيق» أو «بانتظار العميل» كانت تُحسب مغلقة، والرقمُ
        // المعروض أقلَّ من الحقيقة بلا أن يُخطئ شيء.
        return [
            'open' => $rows->whereIn('status', SupportTicketService::OPEN_STATUSES)->count(),
            'total' => $rows->count(),
            'categories' => SupportTicket::CATEGORIES,
            'priorities' => SupportTicket::PRIORITIES,
            'rows' => $rows->map(fn (SupportTicket $t) => [
                'number' => $t->ticket_number,
                'subject' => $t->subject,
                'category' => $t->category,
                'priority' => $t->priority,
                'status' => $t->status,
                'assigned' => $t->assigned_admin_id,
                'at' => $t->created_at?->format('Y-m-d H:i'),
            ])->all(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ١٠) الإحصائيات — نبضُ التاجر لا إدارةُ متجره
    // ══════════════════════════════════════════════════════════════════

    public function pulse(User $m): array
    {
        $window = function (int $days) use ($m) {
            $from = now()->subDays($days)->startOfDay();

            $row = Transaction::where('user_id', $m->id)
                ->where('created_at', '>=', $from)
                ->selectRaw('COUNT(*) n, SUM(amount) total, SUM(charge) fees')
                ->first();

            $n = (int) ($row->n ?? 0);

            return [
                'days' => $days,
                'count' => $n,
                'amount' => (string) ($row->total ?? '0'),
                'fees' => (string) ($row->fees ?? '0'),
                // **«لا عمليّات» ليست «متوسّط صفر»** — القسمةُ على صفرٍ تُقال.
                'average' => $n > 0
                    ? bcdiv((string) ($row->total ?? '0'), (string) $n, 2)
                    : null,
            ];
        };

        return ['today' => $window(1), 'week' => $window(7),
            'month' => $window(30), 'quarter' => $window(90)];
    }

    // ══════════════════════════════════════════════════════════════════
    //  ١١) البوّابةُ التشغيليّة — **ما لا يُرى بلا إذن**
    // ══════════════════════════════════════════════════════════════════

    /**
     * تفصيلٌ تشغيليٌّ (أصنافٌ ومخزون) — **يُرفض بلا إذنٍ سارٍ**.
     *
     * وهذا هو الاستثناءُ الذي يجعل القاعدةَ قابلةً للتطبيق: لا يُقال
     * «ممنوعٌ أبداً» فيلتفّ عليه من احتاجه، بل «يُفتح بسببٍ ويُسجَّل».
     */
    public function operationalDetail(User $actor, User $m): array
    {
        if (! $this->actions->hasAccess($actor, $m->id, MerchantDataAccessGrant::SCOPE_OPERATIONAL)) {
            throw new DomainException(
                'التفصيل التشغيلي (الأصناف والمخزون) يخصّ التاجر — '
                . 'افتح إذن اطّلاع بسبب مكتوب لعرضه');
        }

        $productCount = \App\Models\MerchantProduct::where('merchant_user_id', $m->id)->count();
        $locations = \App\Models\Retail\MerchantLocation::where('merchant_user_id', $m->id)->count();

        $negative = \App\Models\Retail\ProductStock::whereHas('product',
            fn ($q) => $q->where('merchant_user_id', $m->id))
            ->where('on_hand', '<', 0)->count();

        return [
            'granted' => true,
            'products' => $productCount,
            'locations' => $locations,
            'negative_stock_rows' => $negative,
            'note' => 'اطّلاع مؤقّت بإذن — مسجَّل في سجل التدقيق',
        ];
    }
}
