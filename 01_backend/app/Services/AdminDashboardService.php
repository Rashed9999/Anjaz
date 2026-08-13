<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-DASH-TRUTH-001 — أرقام لوحة الإدارة تُحسب مرّةً وصحيحةً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةُ أرقامٍ كانت تُعرض على المدير وهي كاذبة. ولا واحدٌ منها يشتكي.**
 *
 * ── ١) «حجم السنة» كان أعلى مستخدمٍ في كلّ شهر ─────────────────────
 *
 *     ->select([DB::raw("SUM(debit) as total_credit")])
 *     ->orderBy("total_credit", 'desc')
 *     ->groupBy('user_id')      ← يُجمّع لكلّ مستخدمٍ على حدة
 *     ->first()                 ← ثمّ يأخذ أعلاهم وحده
 *
 * والقياس على بياناتٍ مضبوطة (١٠٠+٥٠ لمستخدم، ٣٠ و٢٠ لآخرين):
 *
 *     المجموع الحقيقيّ : 200
 *     وما كان يُعرض   : 150   ← أكبر عميلٍ وحده
 *
 * ── ٢) وحدودُ الشهر كانت خاطئةً في ثمانية أشهر ─────────────────────
 *
 *     $to = date('Y-' . $i . '-30');
 *
 *     فبراير  → 2026-2-30   تاريخٌ لا وجود له
 *     ٧ أشهرٍ من ٣١ يوماً → اليوم ٣١ يسقط صامتاً
 *
 * ── ٣) و«أرباح الرسوم» كانت ربحَ من يفتح اللوحة ────────────────────
 *
 *     $chargeEarned = e_money->where('user_id', Auth::id())->charge_earned
 *
 * و`Auth::id()` هو **الداخل الآن**، بينما بقيّةُ الدالّة تستعمل
 * `Helpers::get_admin_id()` وهو **أوّل** أدمن. فهويّتان في دالّةٍ واحدة،
 * والرقمُ يتغيّر بتغيّر من يفتح الصفحة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقاعدة السادسة: الرقم يُحسب من مصدره لا من عمودٍ مخزَّن.**
 *
 * فالأرباح تُجمع من `transactions.charge` — مصدرِها — لا من
 * `e_money.charge_earned` المخزَّن. وعمودٌ مخزَّنٌ يُثبت أنّه يساوي نفسه،
 * ولا يكشف انحرافاً وقع في الترحيل.
 */
class AdminDashboardService
{
    /**
     * صفّ العملية الاقتصاديّة الأصلي فقط.
     *
     * السجلات التابعة (استلام، رسم، عكس...) تشير إلى الصف الأساسي عبر
     * `ref_trans_id`. الإصدارات القديمة حفظت الأصل بـ 0 والجديدة بـ NULL،
     * ولذلك ينبغي قبول الشكلين، لا اختيار أحدهما وإخفاء الآخر.
     */
    private function primaryTransactions()
    {
        return DB::table('transactions')->where(function ($query) {
            $query->whereNull('ref_trans_id')->orWhere('ref_trans_id', '0');
        });
    }

    /**
     * حجمُ العمليّات لكلّ شهرٍ في سنةٍ — **مجموعُ المنصّة لا أعلى مستخدم**.
     *
     * @return array<int,float> مفتاحُه رقم الشهر ١..١٢
     */
    public function monthlyVolume(?int $year = null): array
    {
        $year = $year ?: (int) now()->year;
        $out  = [];

        for ($m = 1; $m <= 12; $m++) {
            // **حدودُ الشهر تُحسب لا تُكتب.** و`Carbon` يعرف أنّ فبراير
            // ٢٨ أو ٢٩، وأنّ آذار ٣١.
            $from = Carbon::create($year, $m, 1)->startOfMonth();
            $to   = $from->copy()->endOfMonth();

            $out[$m] = (float) $this->primaryTransactions()
                ->whereBetween('created_at', [$from, $to])
                ->sum('debit');   // بلا groupBy — المجموع لا أعلى صفّ
        }

        return $out;
    }

    /**
     * أرباحُ الرسوم — **من مصدرها**.
     *
     * ولا تُقرأ من `e_money.charge_earned`: ذاك عمودٌ مخزَّن، وقراءتُه
     * تُثبت أنّه يساوي نفسه. ولا من حساب الداخل — الرقم واحدٌ للمنصّة.
     */
    /**
     * AMIAL-TRUTH-004 — **المال يُجمَع بدقّةٍ ثابتة لا بفاصلةٍ عائمة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كان الردُّ `(float)`. وكلُّ مسارٍ ماليٍّ آخرَ في هذا المشروع يعمل
     * بـ`bcmath` بأربع منازل — **فرقمُ الإيرادات وحدَه كان يُحسب بقواعدَ
     * أخرى**.
     *
     * و`DB::sum()` على عمود DECIMAL يردّ سلسلةً كاملةَ الدقّة؛ **والقالبُ
     * هو من يفقدها**. فبقاؤها سلسلةً حتّى العرض يحفظها.
     *
     * ولا يظهر الفرقُ في ريالٍ واحد — يظهر بعد مئة ألف عمليّة، حين
     * يُطلَب من الماليّة أن تُطابق فلا تُطابق بقروش. (والقالبُ يعرض
     * `number_format` فلا يتغيّر ما يراه أحد.)
     */
    public function feesEarned(?Carbon $from = null, ?Carbon $to = null): string
    {
        $q = DB::table('transactions')->whereNotNull('charge');

        if ($from) {
            $q->where('created_at', '>=', $from);
        }

        if ($to) {
            $q->where('created_at', '<=', $to);
        }

        return bcadd((string) ($q->sum('charge') ?? '0'), '0', 4);
    }

    /**
     * أرصدةُ المنصّة.
     *
     * **وهويّةُ المنصّة واحدة** — `Helpers::get_admin_id()` — في كلّ سطر.
     * وخلطُها بـ`Auth::id()` يجعل الرقم يتغيّر بتغيّر من يفتح الصفحة.
     */
    public function balances(): array
    {
        $platformId = Helpers::get_admin_id();

        // **وكلُّ جمعٍ بـbcmath** — لا `(float)` ولا `+` على مال.
        // فالجمعُ العائم يُنتج فرقاً لا يُفسَّر بعد مئة ألف عمليّة،
        // ويُطلَب من الماليّة أن تُطابقه فلا تستطيع. (AMIAL-TRUTH-004)
        $sum = static fn ($q): string => bcadd((string) ($q ?? '0'), '0', 4);

        $circulating = $sum(DB::table('e_money')
            ->where('user_id', '!=', $platformId)->sum('current_balance'));

        $pending = $sum(DB::table('e_money')->sum('pending_balance'));

        $treasury = $sum(DB::table('e_money')
            ->where('user_id', $platformId)->sum('current_balance'));

        $toppedUp = $sum(DB::table('transactions')
            ->where('user_id', $platformId)
            ->where('transaction_type', CASH_IN)
            ->sum('credit'));

        return [
            'total_balance'  => $toppedUp,
            'used_balance'   => bcadd($circulating, $pending, 4),
            'unused_balance' => $treasury,
            'total_earned'   => $this->feesEarned(),
        ];
    }

    /**
     * القوائم والترتيبات من العملية الأصلية، لا من كل قيودها المحاسبية.
     *
     * @return array{agents:\Illuminate\Support\Collection,customers:\Illuminate\Support\Collection}
     */
    public function leaderboards(): array
    {
        $primary = static fn () => Transaction::query()
            ->with('user')
            ->where(function ($query) {
                $query->whereNull('ref_trans_id')->orWhere('ref_trans_id', '0');
            });

        $rank = static fn ($query, int $take) => $query
            ->select(['user_id', DB::raw('SUM(debit) as total_transaction')])
            ->orderByDesc('total_transaction')
            ->groupBy('user_id')
            ->take($take)
            ->get();

        return [
            'agents' => $rank($primary()->agent(), 6),
            'customers' => $rank($primary()->customer(), 4),
        ];
    }

    /** @return array{customers:int,agents:int,merchants:int} */
    public function populationCounts(): array
    {
        return [
            'customers' => User::where('type', CUSTOMER_TYPE)->count(),
            'agents' => User::where('type', AGENT_TYPE)->count(),
            'merchants' => User::where('type', MERCHANT_TYPE)->count(),
        ];
    }

    /**
     * طابورُ العمل الذي يحتاج تدخلاً من المشغّل، لا أرقاماً للزينة.
     *
     * كل عنصر يقرأ من جدول نطاقه التشغيلي القائم، ولا يُستعلم عنه أصلاً
     * إن لم يملك المشغّل الصلاحية المقابلة. وحين لا تكون الهجرة مطبقة،
     * تكون الحالة «غير متاح» لا صفراً مضللاً.
     *
     * @param array<string,bool> $permissions
     * @return array<string,array{state:string,count?:int,status?:string,ran_at?:string|null}>
     */
    public function attentionQueue(array $permissions): array
    {
        $counter = static function (bool $allowed, string $table, callable $query): array {
            if (!$allowed) {
                return ['state' => 'hidden'];
            }

            if (!Schema::hasTable($table)) {
                return ['state' => 'unavailable'];
            }

            return ['state' => 'ready', 'count' => (int) $query()];
        };

        $queue = [
            'kyc' => $counter((bool) ($permissions['kyc'] ?? false), 'kyc_documents',
                fn () => DB::table('kyc_documents')->where('status', 'pending')->count()),
            'tickets' => $counter((bool) ($permissions['tickets'] ?? false), 'support_tickets',
                fn () => DB::table('support_tickets')
                    ->whereIn('status', ['open', 'investigating'])
                    ->whereIn('priority', ['high', 'urgent'])->count()),
            'approvals' => $counter((bool) ($permissions['approvals'] ?? false), 'approval_requests',
                fn () => DB::table('approval_requests')->where('status', 'pending')->count()),
            'security' => $counter((bool) ($permissions['audit'] ?? false), 'security_alerts',
                fn () => DB::table('security_alerts')->where('status', 'new')->count()),
        ];

        if (!($permissions['audit'] ?? false)) {
            $queue['reconciliation'] = ['state' => 'hidden'];
        } elseif (!Schema::hasTable('reconciliation_runs')) {
            $queue['reconciliation'] = ['state' => 'unavailable'];
        } else {
            $last = DB::table('reconciliation_runs')->orderByDesc('ran_at')->first();
            $queue['reconciliation'] = $last
                ? [
                    'state' => 'ready',
                    'status' => (string) $last->status,
                    'ran_at' => $last->ran_at,
                    'count' => (int) $last->wallets_diverged
                        + (int) $last->unbalanced_entries
                        + (int) $last->tills_diverged,
                ]
                : ['state' => 'unknown'];
        }

        return $queue;
    }

    /** عدّاداتُ اليوم — تُحسب من الحركة لا من عدّادٍ مخزَّن. */
    public function today(): array
    {
        return [
            'tx_count'  => (int) $this->primaryTransactions()
                ->whereDate('created_at', today())->count(),
            'tx_volume' => (float) $this->primaryTransactions()
                ->whereDate('created_at', today())->sum('debit'),
        ];
    }
}
