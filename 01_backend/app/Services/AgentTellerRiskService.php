<?php

namespace App\Services;

use App\Models\Agent\AgentStaff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-TELLER-WS-001 — إشاراتُ الخطر على الشبّاك، **قبل** حركة المال.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **التوقيت هو الميزة كلّها.**
 *
 * كشفُ الاحتيال في هذا المشروع يعمل — لكنّه يعمل **بعد** وقوع العمليّة:
 * تُرصَد وتُصعَّد ويُفتح لها تحقيق. وذلك نافعٌ للمحقّق، عديمُ النفع
 * للصرّاف الذي كان يستطيع أن يسأل سؤالاً واحداً قبل أن يصرف.
 *
 * فهذا الصنف ينقل ما يُعرف أصلاً إلى **لحظة القرار**: قبل الضغط على
 * «صرف»، لا في تقرير الغد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاث قواعد تحكم كلّ إشارةٍ هنا:**
 *
 * ١. **الإشارة ليست حكماً.** أغلبُها تُنبّه ولا تمنع — لأنّ عميلاً أودع
 *    ضعف معتاده قد يكون باع أرضاً. والمنعُ لِما يُمنع صراحةً وحده
 *    (محظور، متوفّى، مجمَّد)، وما عداه يُعرَض ويُقرّر الإنسان.
 *
 * ٢. **ولا إشارةَ بلا سبب مكتوب.** «🔴 عميل مشبوه» بلا تفسيرٍ يجعل
 *    الصرّاف يتجاهلها في الأسبوع الثاني. فكلّ إشارةٍ تحمل رقمها الذي
 *    أنتجها.
 *
 * ٣. **وفشلُ الفحص لا يُقرأ «سليم».** جدولٌ غائبٌ أو استعلامٌ سقط يُعيد
 *    إشارة «تعذّر الفحص» — لا صمتاً يُقرأ براءة. (القاعدة السابعة.)
 */
class AgentTellerRiskService
{
    public const BLOCK = 'block';      // 🔴 يُمنع
    public const WARN = 'warn';        // 🟠 يُنبَّه ويُسأل
    public const NOTE = 'note';        // 🟡 يُعلَم

    /**
     * فحصُ عميلٍ ومبلغٍ قبل التنفيذ.
     *
     * @return array{blocked: bool, flags: array<int, array<string, string>>}
     */
    public function assess(User $customer, string $amount, string $operation, ?AgentStaff $staff = null): array
    {
        $flags = [];

        // ── ١) حالة العميل — النظام الرسميّ للحالات ───────────────────
        $status = app(CustomerStatusResolver::class)->resolve($customer);

        $hardStop = [
            CustomerStatusResolver::BLACKLISTED,
            CustomerStatusResolver::CLOSED,
            CustomerStatusResolver::DECEASED,
            CustomerStatusResolver::FROZEN,
        ];

        if (in_array($status['status'], $hardStop, true)) {
            $flags[] = $this->flag(self::BLOCK, 'customer_status',
                'العميل ' . $status['label'], 'لا تُنفَّذ عمليّة — راجع إدارة أميال');
        } elseif ($status['status'] === CustomerStatusResolver::UNDER_REVIEW) {
            $flags[] = $this->flag(self::WARN, 'customer_status',
                'العميل تحت المراقبة', 'العمليّة مسموحة وتُسجَّل — تحقّق من هويّته');
        } elseif (in_array($status['status'], [
            CustomerStatusResolver::KYC_PENDING, CustomerStatusResolver::KYC_REJECTED,
        ], true)) {
            $flags[] = $this->flag(self::WARN, 'kyc',
                'هويّة العميل ' . $status['label'], 'قد تُرفض العمليّة أو تُحدَّد بسقفٍ منخفض');
        }

        // ── ٢) المبلغ مقابل معتاد العميل ──────────────────────────────
        $unusual = $this->unusualAmount($customer, $amount);

        if ($unusual !== null) {
            $flags[] = $unusual;
        }

        // ── ٣) تكرارٌ سريع — التقسيم للتهرّب من السقف ─────────────────
        $split = $this->rapidRepeat($customer);

        if ($split !== null) {
            $flags[] = $split;
        }

        // ── ٤) بلاغاتٌ سابقة على العميل ───────────────────────────────
        $reports = $this->openReports($customer);

        if ($reports !== null) {
            $flags[] = $reports;
        }

        // ── ٥) حدُّ الصرّاف — يُعرَض إشارةً لا رفضاً مفاجئاً ──────────
        if ($staff) {
            $limit = $this->limitFlag($staff, $amount);

            if ($limit !== null) {
                $flags[] = $limit;
            }
        }

        return [
            // **المنع من الإشارات الحمراء وحدها.** ولو منعت الصفراء لصار
            // كلّ عميلٍ أودع ضعف معتاده ممنوعاً — فيتعطّل الفرع بلا سبب.
            'blocked' => (bool) array_filter($flags, fn ($f) => $f['level'] === self::BLOCK),
            'flags' => $flags,
        ];
    }

    /**
     * مبلغٌ أعلى من معتاد هذا العميل بكثير.
     *
     * **ويُقاس بمعتاده هو لا بسقفٍ ثابت.** سقفٌ واحدٌ لكلّ الناس يُغرق
     * الشبّاك بإنذاراتٍ على تاجرٍ يودع يوميّاً، ويصمت عن موظّفٍ راتبُه
     * خمسون ألفاً أودع مليونين.
     */
    private function unusualAmount(User $customer, string $amount): ?array
    {
        $stats = DB::table('agent_cash_movements')
            ->where('customer_user_id', $customer->id)
            ->where('created_at', '>=', now()->subDays(90))
            ->selectRaw('count(*) n, avg(amount) avg_amt, max(amount) max_amt')
            ->first();

        $n = (int) ($stats->n ?? 0);

        // **تاريخٌ قصيرٌ ليس «معتاداً منخفضاً».** عميلٌ بعمليّتين لا يُبنى
        // على متوسّطهما حكم — وإنذارٌ من عيّنةٍ صغيرة إنذارٌ كاذب.
        if ($n < 5) {
            return null;
        }

        $avg = bcadd((string) ($stats->avg_amt ?: '0'), '0', 4);
        $max = bcadd((string) ($stats->max_amt ?: '0'), '0', 4);

        if (bccomp($avg, '0', 4) <= 0) {
            return null;
        }

        // خمسةُ أضعاف المتوسّط **و** أعلى من أكبر عمليّةٍ سابقة معاً.
        // وشرطٌ واحدٌ منهما وحده يُنتج ضجيجاً: من يودع ألفاً ثمّ عشرة
        // آلاف مرّةً في الشهر ليس حالةً تستحقّ إيقاف الشبّاك.
        if (bccomp($amount, bcmul($avg, '5', 4), 4) > 0
            && bccomp($amount, $max, 4) > 0) {
            return $this->flag(self::WARN, 'unusual_amount',
                'المبلغ أعلى من معتاد هذا العميل',
                'متوسّطه ' . $this->m($avg) . ' وأكبر عمليّةٍ له ' . $this->m($max)
                . ' — تحقّق من مصدر المال');
        }

        return null;
    }

    /** عمليّاتٌ متلاحقة: قد تكون تقسيماً للتهرّب من سقفٍ يوميّ. */
    private function rapidRepeat(User $customer): ?array
    {
        $n = DB::table('agent_cash_movements')
            ->where('customer_user_id', $customer->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($n >= 3) {
            return $this->flag(self::WARN, 'rapid_repeat',
                "{$n} عمليّات لهذا العميل خلال ساعة",
                'التقسيم إلى مبالغ صغيرة نمطُ تهرّبٍ معروف — تحقّق من السبب');
        }

        return null;
    }

    /** بلاغاتٌ مفتوحة على العميل — إن كان الجدول موجوداً. */
    private function openReports(User $customer): ?array
    {
        if (!Schema::hasTable('aml_alerts')) {
            return null;
        }

        try {
            $n = DB::table('aml_alerts')
                ->where('subject_type', 'user')
                ->where('subject_id', (string) $customer->id)
                ->whereIn('status', ['open', 'pending', 'investigating'])
                ->count();
        } catch (\Throwable $e) {
            // **فشلُ الفحص ليس براءة.** يُقال إنّه تعذّر.
            return $this->flag(self::NOTE, 'screening_failed',
                'تعذّر فحص بلاغات العميل', 'نفِّذ بحذر وأبلغ الإدارة إن تكرّر');
        }

        if ($n > 0) {
            return $this->flag(self::WARN, 'open_reports',
                "على العميل {$n} بلاغاً مفتوحاً",
                'العمليّة مسموحة وتُسجَّل باسمك — تحقّق من هويّته');
        }

        return null;
    }

    /** الحدّ يُعرَض قبل الضغط لا بعده. */
    private function limitFlag(AgentStaff $staff, string $amount): ?array
    {
        $perOp = bcadd((string) ($staff->max_txn_amount ?? '0'), '0', 4);

        if (bccomp($perOp, '0', 4) > 0 && bccomp($amount, $perOp, 4) > 0) {
            return $this->flag(self::WARN, 'over_limit',
                'المبلغ فوق حدّ عمليّتك (' . $this->m($perOp) . ')',
                'اطلب موافقة مديرك من الزرّ أدناه — لا تقسّم العمليّة');
        }

        $daily = bcadd((string) ($staff->daily_limit ?? '0'), '0', 4);

        if (bccomp($daily, '0', 4) > 0) {
            $used = bcadd((string) (DB::table('agent_cash_movements')
                ->where('staff_id', $staff->id)->where('is_drawer', true)
                ->whereDate('created_at', now()->toDateString())
                ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
                ->sum('amount') ?: '0'), '0', 4);

            if (bccomp(bcadd($used, $amount, 4), $daily, 4) > 0) {
                return $this->flag(self::WARN, 'over_daily',
                    'العمليّة تتجاوز حدّك اليوميّ',
                    'استهلكتَ ' . $this->m($used) . ' من ' . $this->m($daily)
                    . ' — اطلب موافقة مديرك');
            }
        }

        return null;
    }

    /**
     * عدد الإشارات المفتوحة المنسوبة إلى هذا الصرّاف — لبطاقة المؤشّرات.
     *
     * وتُحسب من الورديّات ذات الفروق التي لم تُراجَع: هي الإشارة الوحيدة
     * المنسوبة إلى صرّافٍ بعينه في هذا النظام. وغيرُها منسوبٌ إلى عملاء.
     */
    public function openAlertCountFor(AgentStaff $staff): int
    {
        return (int) \App\Models\Agent\AgentShift::where('staff_id', $staff->id)
            ->whereIn('review_status', [
                \App\Models\Agent\AgentShift::REVIEW_PENDING,
                \App\Models\Agent\AgentShift::REVIEW_INVESTIGATING,
            ])->count();
    }

    /** @return array<string, string> */
    private function flag(string $level, string $key, string $title, string $advice): array
    {
        return [
            'level' => $level,
            'key' => $key,
            'icon' => match ($level) {
                self::BLOCK => '🔴',
                self::WARN => '🟠',
                default => '🟡',
            },
            'title' => $title,
            // **النصيحة إلزاميّة.** إشارةٌ لا تقول ماذا يُفعل بها تُتجاهل.
            'advice' => $advice,
        ];
    }

    private function m(string $n): string
    {
        return number_format((float) $n, 0);
    }
}
