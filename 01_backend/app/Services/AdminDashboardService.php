<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

            $out[$m] = (float) DB::table('transactions')
                ->where('ref_trans_id', 0)
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
    public function feesEarned(?Carbon $from = null, ?Carbon $to = null): float
    {
        $q = DB::table('transactions')->whereNotNull('charge');

        if ($from) {
            $q->where('created_at', '>=', $from);
        }

        if ($to) {
            $q->where('created_at', '<=', $to);
        }

        return (float) $q->sum('charge');
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

        $circulating = (float) DB::table('e_money')
            ->where('user_id', '!=', $platformId)->sum('current_balance');

        $pending = (float) DB::table('e_money')->sum('pending_balance');

        $treasury = (float) DB::table('e_money')
            ->where('user_id', $platformId)->sum('current_balance');

        $toppedUp = (float) DB::table('transactions')
            ->where('user_id', $platformId)
            ->where('transaction_type', CASH_IN)
            ->sum('credit');

        return [
            'total_balance'  => $toppedUp,
            'used_balance'   => $circulating + $pending,
            'unused_balance' => $treasury,
            'total_earned'   => $this->feesEarned(),
        ];
    }

    /** عدّاداتُ اليوم — تُحسب من الحركة لا من عدّادٍ مخزَّن. */
    public function today(): array
    {
        return [
            'tx_count'  => (int) DB::table('transactions')
                ->whereDate('created_at', today())->count(),
            'tx_volume' => (float) DB::table('transactions')
                ->whereDate('created_at', today())->sum('debit'),
        ];
    }
}
