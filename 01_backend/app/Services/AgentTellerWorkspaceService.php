<?php

namespace App\Services;

use App\Models\Agent\AgentAnnouncement;
use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashTill;
use App\Models\Agent\AgentPanicAlert;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\Agent\AgentTellerRequest;
use App\Models\EMoney;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-TELLER-WS-001 — مساحةُ عمل الصرّاف: نقطةُ التقاء الأنظمة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **شاشة الشبّاك ليست شاشةَ إيداعٍ وسحب.**
 *
 * هي المكان الذي يقف فيه موظّفٌ أمام عميلٍ ويقرّر — وكلُّ ما يحتاجه
 * للقرار موجودٌ في أنظمةٍ متفرّقة: حالةُ العميل في نظام العملاء، وحدُّه
 * في نظام الحدود، وصلاحيتُه في نظام الموظّفين، ونقدُه في الخزنة،
 * ورصيدُه في المحفظة، وإشاراتُ الخطر في كشف الاحتيال.
 *
 * ومن غير تجميعها في مكانٍ واحد يقع ما هو أسوأ من العطل: **قرارٌ صحيحُ
 * الشكل خاطئُ الأساس**. صرّافٌ يصرف لعميلٍ تحت المراقبة لأنّه لم يرَ
 * الإشارة، ويقف عند حدٍّ لم يعرف أنّه بلغه، ويحاول ما لا يملك فيبدو
 * النظام معطّلاً وهو يعمل.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وكلّ رقمٍ هنا من مصدره** (القاعدة السادسة): النقد من حركة الدرج،
 * والحدّ المستهلك من العمليّات لا من عمودٍ تجميعيّ، والعمولات من قيود
 * الدفتر. ولا يُقرأ رقمٌ من عمودٍ يُحدَّث بيد.
 *
 * **وما لا يُعرف يُقال «غير معروف»** (القاعدة السابعة): خزنةٌ بلا صفٍّ
 * ليست خزنةً فيها صفر، وحدٌّ غير مضبوطٍ ليس حدّاً قدرُه صفر.
 */
class AgentTellerWorkspaceService
{
    public function __construct(
        private readonly AgentTellerRiskService $risk,
    ) {
    }

    /**
     * كلّ ما تحتاجه الشاشة في نداءٍ واحد.
     *
     * **ونداءٌ واحدٌ لا عشرة عمداً.** الشبّاك يعمل على اتّصالٍ ضعيف في
     * فرعٍ بعيد؛ وعشرةُ نداءاتٍ تعني عشر فرصٍ للفشل الجزئيّ — فتظهر
     * الشاشة نصفَ محمّلةٍ ولا يعرف الصرّاف أيّ نصفٍ يُصدَّق.
     *
     * @return array<string, mixed>
     */
    public function build(AgentStaff $staff): array
    {
        $shift = $staff->openShift();
        $branch = $staff->branch_id ? AgentBranch::find($staff->branch_id) : null;

        return [
            'staff' => [
                'id' => (int) $staff->id,
                'name' => $staff->name,
                'username' => $staff->username,
                'role' => $staff->role,
                'role_label' => $staff->roleLabel(),
                'branch' => $branch?->name,
                'branch_code' => $branch?->code,
            ],
            'kpis' => $this->kpis($staff, $branch, $shift),
            'permissions' => $this->permissions($staff),
            'limits' => $this->limits($staff),
            'systems' => $this->systems(),
            'announcements' => $this->announcements($staff),
            'recent_customers' => $this->recentCustomers($staff),
            'requests' => $this->myRequests($staff),
            'day_log' => $this->dayLog($staff, $shift),
            'panic' => $this->panicState($staff),
            'server_time' => now()->toDateTimeString(),
            'server_ms' => now()->valueOf(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // ١٨) المؤشّرات الرئيسية
    // ══════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function kpis(AgentStaff $staff, ?AgentBranch $branch, ?AgentShift $shift): array
    {
        $today = now()->toDateString();

        // النقد في يد هذا الصرّاف — لا نقدُ الفرع.
        // **والفرق ليس تفصيلاً:** الصرّاف يُسأل عن درجه هو، وعرضُ نقد
        // الفرع في خانة «نقدك» يجعله يظنّ أنّه يستطيع صرف ما ليس بيده.
        $drawer = $shift ? (string) $shift->cash_on_hand : null;

        $emoney = $branch
            ? (string) (EMoney::where('user_id', $branch->branch_user_id)->value('current_balance') ?? '0')
            : null;

        $safe = $branch
            ? AgentCashTill::where('branch_id', $branch->id)->value('cash_on_hand')
            : null;

        $ops = DB::table('agent_cash_movements')
            ->where('staff_id', $staff->id)
            ->where('is_drawer', true)
            ->whereDate('created_at', $today)
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->selectRaw('reason, count(*) n, sum(amount) total, count(distinct customer_user_id) customers')
            ->groupBy('reason')->get()->keyBy('reason');

        $dep = (string) ($ops['customer_deposit']->total ?? '0');
        $wdr = (string) ($ops['customer_withdraw']->total ?? '0');

        $served = (int) DB::table('agent_cash_movements')
            ->where('staff_id', $staff->id)->where('is_drawer', true)
            ->whereDate('created_at', $today)
            ->whereNotNull('customer_user_id')
            ->distinct()->count('customer_user_id');

        return [
            // «لا ورديّة» ليست «درجٌ فيه صفر» — القاعدة السابعة.
            'drawer_cash' => $drawer,
            'drawer_known' => $shift !== null,
            'branch_emoney' => $emoney,
            'branch_safe' => $safe === null ? null : (string) $safe,
            'safe_known' => $safe !== null,
            'customers_served' => $served,
            'deposits_total' => $dep,
            'deposits_count' => (int) ($ops['customer_deposit']->n ?? 0),
            'withdrawals_total' => $wdr,
            'withdrawals_count' => (int) ($ops['customer_withdraw']->n ?? 0),
            'commission_today' => $this->commissionToday($branch, $today),
            'pending_requests' => AgentTellerRequest::where('staff_id', $staff->id)
                ->where('status', AgentTellerRequest::STATUS_PENDING)->count(),
            'risk_alerts' => $this->risk->openAlertCountFor($staff),
        ];
    }

    /**
     * العمولة من قيود الدفتر لا من عمودٍ مخزَّن.
     *
     * وتُنسب إلى **الفرع** لا إلى الصرّاف: العمولة دخلُ الشركة من عمليّاتٍ
     * وقعت في الفرع، ونسبتُها إلى شبّاكٍ بعينه تحتاج قيداً لا يوجد.
     */
    private function commissionToday(?AgentBranch $branch, string $date): ?string
    {
        if (!$branch) {
            return null;
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('ledger_entry_lines')) {
            return null;   // «غير معروف» لا صفر
        }

        // **الأعمدة تُقرأ من المخطّط لا من الذاكرة.** كتبتُ `l.credit`
        // أوّلاً وهو عمودٌ لا وجود له — والجدول فيه `direction` و`amount`.
        // وخطأٌ كهذا لا يُسقط استعلاماً وحده: يُسقط بناء الشاشة كلّها
        // بـ٥٠٠، فيقف الصرّاف أمام صفحةٍ بيضاء لأجل رقم عمولة.
        $v = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->whereDate('j.created_at', $date)
            ->where('j.source_type', 'like', 'agent_%_fee')
            ->where('l.direction', 'credit')
            ->where('l.metadata', 'like', '%"branch_id":' . $branch->id . '%')
            ->sum('l.amount');

        return bcadd((string) ($v ?: '0'), '0', 4);
    }

    // ══════════════════════════════════════════════════════════════════
    // ٦) الصلاحيات
    // ══════════════════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    private function permissions(AgentStaff $staff): array
    {
        $caps = $staff->effectiveCapabilities();
        $out = [];

        foreach (AgentStaff::CAPABILITIES as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'allowed' => (bool) ($caps[$key] ?? false)];
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    // ٣) الحدود
    // ══════════════════════════════════════════════════════════════════

    /**
     * ما استُهلك اليوم وما بقي.
     *
     * **والمستهلَك يُحسب من العمليّات نفسها** لا من عمودٍ تجميعيّ: عمودٌ
     * يُحدَّث بيدٍ ينحرف عن الحقيقة عند أوّل عمليّةٍ فشلت بعد تحديثه،
     * فيُوقف صرّافاً لم يبلغ حدّه أو يمرّر من تجاوزه.
     *
     * @return array<string, mixed>
     */
    public function limits(AgentStaff $staff): array
    {
        $today = now()->toDateString();

        $used = DB::table('agent_cash_movements')
            ->where('staff_id', $staff->id)
            ->where('is_drawer', true)
            ->whereDate('created_at', $today)
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->selectRaw('count(*) n, coalesce(sum(amount), 0) total')
            ->first();

        $usedAmount = bcadd((string) ($used->total ?? '0'), '0', 4);
        $usedCount = (int) ($used->n ?? 0);

        $perOp = bcadd((string) ($staff->max_txn_amount ?? '0'), '0', 4);
        $daily = bcadd((string) ($staff->daily_limit ?? '0'), '0', 4);
        $countLimit = (int) ($staff->daily_count_limit ?? 0);

        // صفرٌ هنا تعني **«بلا حدّ خاصّ»** لا «ممنوع». وهي القراءة التي
        // كانت في `hire()` منذ البداية، وقلبُها يُقفل كلّ شبّاكٍ في النظام.
        $mk = fn (string $limit, string $usedV) => bccomp($limit, '0', 4) <= 0
            ? null
            : bcsub($limit, $usedV, 4);

        return [
            'per_operation' => bccomp($perOp, '0', 4) > 0 ? $perOp : null,
            'daily' => bccomp($daily, '0', 4) > 0 ? $daily : null,
            'daily_used' => $usedAmount,
            'daily_remaining' => $mk($daily, $usedAmount),
            'count_limit' => $countLimit > 0 ? $countLimit : null,
            'count_used' => $usedCount,
            'count_remaining' => $countLimit > 0 ? max(0, $countLimit - $usedCount) : null,
            // ما رُفض اليوم بسبب الحدود — من الطلبات لا من تخمين.
            'rejected_today' => AgentTellerRequest::where('staff_id', $staff->id)
                ->whereDate('created_at', $today)
                ->where('status', AgentTellerRequest::STATUS_REJECTED)->count(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // ٨) مراقبة الاتّصال — **ما يُقاس فعلاً وحده**
    // ══════════════════════════════════════════════════════════════════

    /**
     * حالةُ ما يمكن للخادم أن يفحصه بنفسه.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولا تُذكر هنا طابعةٌ ولا ماسحُ QR ولا بصمةٌ ولا UPS.**
     *
     * لأنّ النظام لا يتّصل بأيٍّ منها: لا سائق، ولا قناة، ولا نداء. ونقطةٌ
     * خضراء أمام «الطابعة» تعني أنّنا نقول للموظّف «طابعتك تعمل» ونحن لم
     * نسألها. وهي كذبةٌ يبني عليها قراراً — يُخبر العميل أنّ الإيصال
     * سيُطبع، ثمّ لا يُطبع.
     *
     * فيُعرَض المقيس، ويُقال عن الباقي «غير مربوط» صراحةً. والقاعدة
     * السابعة: «غير معروف» ليس صفراً، والغياب يُقال مع سببه.
     *
     * @return array<int, array<string, mixed>>
     */
    public function systems(): array
    {
        $out = [];

        // قاعدة البيانات — نداءٌ حقيقيّ لا افتراض.
        $out[] = $this->probe('database', 'قاعدة البيانات', function () {
            DB::select('select 1');
        });

        // الذاكرة المؤقّتة — تُكتب وتُقرأ: كتابةٌ وحدها تنجح على مخزنٍ معطوب.
        $out[] = $this->probe('cache', 'الذاكرة المؤقّتة', function () {
            $k = 'teller_ws_probe';
            Cache::put($k, '1', 10);

            if (Cache::get($k) !== '1') {
                throw new \RuntimeException('كُتبت ولم تُقرأ');
            }
        });

        $out[] = $this->probe('storage', 'مساحة الملفّات', function () {
            if (!is_writable(storage_path('app'))) {
                throw new \RuntimeException('غير قابلة للكتابة');
            }
        });

        // مزوّد واتساب — مضبوطٌ أم لا. وهذه معرفةٌ حقيقيّة: نقرأ إعداده.
        $out[] = [
            'key' => 'whatsapp',
            'label' => 'واتساب (تنبيهات ورموز)',
            'state' => \App\CentralLogics\WhatsappModule::hasEnabledProvider() ? 'ok' : 'off',
            'note' => \App\CentralLogics\WhatsappModule::hasEnabledProvider()
                ? 'مزوّدٌ مفعَّل' : 'لا مزوّد مفعَّل — التنبيهات والرموز لا تصل',
        ];

        // **الأجهزة الطرفية: تُذكر بحالتها الحقيقيّة — غير مربوطة.**
        foreach ([
            'printer' => 'الطابعة',
            'qr_scanner' => 'ماسح QR',
            'fingerprint' => 'قارئ البصمة',
            'ups' => 'مصدر الطاقة الاحتياطيّ',
        ] as $key => $label) {
            $out[] = [
                'key' => $key, 'label' => $label, 'state' => 'not_integrated',
                'note' => 'لا يتّصل به النظام — الطباعة عبر متصفّحك',
            ];
        }

        return $out;
    }

    /** @param callable $fn */
    private function probe(string $key, string $label, callable $fn): array
    {
        $t = microtime(true);

        try {
            $fn();

            return ['key' => $key, 'label' => $label, 'state' => 'ok',
                    'note' => round((microtime(true) - $t) * 1000) . ' مِلّي'];
        } catch (\Throwable $e) {
            return ['key' => $key, 'label' => $label, 'state' => 'down',
                    'note' => mb_substr($e->getMessage(), 0, 120)];
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // ١٢) التعاميم
    // ══════════════════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    private function announcements(AgentStaff $staff): array
    {
        $now = now();

        return AgentAnnouncement::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            // تعميمُ أميال للجميع، أو تعميمُ شركته هو. ولا ثالث.
            ->where(fn ($q) => $q->whereNull('agent_user_id')
                ->orWhere('agent_user_id', $staff->agent_user_id))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $staff->branch_id))
            ->where(fn ($q) => $q->where('audience', 'all')
                ->orWhere('audience', $staff->isTeller() ? 'tellers' : 'managers'))
            ->orderByRaw("field(severity, 'critical', 'warning', 'info')")
            ->orderByDesc('id')->limit(10)->get()
            ->map(fn (AgentAnnouncement $a) => [
                'id' => (int) $a->id,
                'severity' => $a->severity,
                'icon' => AgentAnnouncement::SEVERITY_ICONS[$a->severity] ?? 'ℹ️',
                'title' => $a->title,
                'body' => $a->body,
                'at' => $a->created_at?->toDateTimeString(),
            ])->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // ١٠) العملاء الأخيرون
    // ══════════════════════════════════════════════════════════════════

    /**
     * آخر من خدمهم هذا الصرّاف — اختصاراً لإعادة كتابة الرقم.
     *
     * **ولهذا الصرّاف وحده.** قائمةٌ من عملاء الفرع كلّه تُري صرّافاً من
     * خدمهم زميلُه وبكم — وهي معلومةٌ لا يحتاجها لعمله.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentCustomers(AgentStaff $staff): array
    {
        $rows = DB::table('agent_cash_movements as m')
            ->join('users as u', 'u.id', '=', 'm.customer_user_id')
            ->where('m.staff_id', $staff->id)
            ->where('m.is_drawer', true)
            ->whereNotNull('m.customer_user_id')
            ->where('m.created_at', '>=', now()->subDays(7))
            ->selectRaw('u.id, u.f_name, u.l_name, u.phone, max(m.created_at) last_at,
                         count(*) ops, max(m.amount) biggest')
            ->groupBy('u.id', 'u.f_name', 'u.l_name', 'u.phone')
            ->orderByDesc('last_at')->limit(20)->get();

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'name' => trim(($r->f_name ?? '') . ' ' . ($r->l_name ?? '')) ?: '—',
            'phone' => (string) $r->phone,
            'last_at' => (string) $r->last_at,
            'ops' => (int) $r->ops,
        ])->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // ١٧) طلباتي  ·  ١١) سجلّي اليوميّ  ·  ١٣) الطوارئ
    // ══════════════════════════════════════════════════════════════════

    /** @return array<int, array<string, mixed>> */
    private function myRequests(AgentStaff $staff): array
    {
        return AgentTellerRequest::where('staff_id', $staff->id)
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('id')->limit(10)->get()
            ->map(fn (AgentTellerRequest $r) => [
                'id' => (int) $r->id,
                'number' => $r->request_number,
                'kind' => $r->kind,
                'kind_label' => AgentTellerRequest::KIND_LABELS[$r->kind] ?? $r->kind,
                'operation' => $r->operation,
                'amount' => (string) $r->amount,
                'status' => $r->status,
                'status_label' => AgentTellerRequest::STATUS_LABELS[$r->status] ?? $r->status,
                'usable' => $r->isUsable(),
                'note' => $r->decision_note,
                'at' => $r->created_at?->toDateTimeString(),
            ])->all();
    }

    /** @return array<string, mixed> */
    private function dayLog(AgentStaff $staff, ?AgentShift $shift): array
    {
        $last = DB::table('agent_cash_movements')
            ->where('staff_id', $staff->id)
            ->orderByDesc('id')->value('created_at');

        return [
            'shift_open' => $shift !== null,
            'shift_id' => $shift?->id,
            'opened_at' => $shift?->opened_at?->toDateTimeString(),
            // «كم قضى في النظام» يُحسب من فتح الورديّة — لا من الدخول.
            // فمن يفتح الشاشة ولا يفتح ورديّةً لم يبدأ عملاً.
            'minutes_on_shift' => $shift?->opened_at
                ? (int) $shift->opened_at->diffInMinutes(now()) : null,
            'last_activity_at' => $last ? (string) $last : null,
            'last_login_at' => $staff->last_login_at?->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function panicState(AgentStaff $staff): array
    {
        $open = AgentPanicAlert::where('staff_id', $staff->id)
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('id')->first();

        return [
            'has_open' => $open !== null,
            'number' => $open?->alert_number,
            'status_label' => $open ? (AgentPanicAlert::STATUS_LABELS[$open->status] ?? $open->status) : null,
            // المتصفّح يمنع الموقع على اتّصالٍ غير مشفَّر — يُقال قبل الضغط
            // لا بعده، فلا ينتظر الموظّف مساعدةً موجَّهةً بموقعٍ لم يُرسَل.
            'geo_possible' => request()->isSecure(),
        ];
    }
}
