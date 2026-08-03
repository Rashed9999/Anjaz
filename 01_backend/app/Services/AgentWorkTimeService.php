<?php

namespace App\Services;

use App\Models\Agent\AgentStaff;
use App\Models\Agent\AgentWorkEvent;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-WORKTIME-001 — ساعاتُ العمل: يوميّاً وأسبوعيّاً وشهريّاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الرقم لا يُخزَّن — يُروى من أحداثه في كلّ مرّة** (القاعدة السادسة).
 *
 * عمودُ «مجموع الساعات» يُكتب مرّةً ويُقرأ ألفاً، فيصير حقيقةً بديلة عن
 * الواقع: يُعدَّل بيدٍ فلا شيء يناقضه. أمّا الأحداث فتُروى — فتحٌ،
 * واستراحة، وعودة، وإغلاق — والمجموع يُحسب منها فيتغيّر بتغيّرها ولا
 * يتغيّر بغيرها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الدفاعات الثلاث، وكلٌّ ضدّ تهديدٍ آخر:**
 *
 * ١. **ضدّ التلاعب بالإدخال** — لا وقتَ يُقبل من الطلب. كلّ ختمٍ من
 *    `now()` على الخادم، ولا معامل في هذا الصنف يقبل تاريخاً من مستعمل.
 *
 * ٢. **ضدّ التلاعب بالسلوك** — من يفتح ورديّته الفجر ويغلقها منتصف الليل
 *    لم يخالف قاعدة؛ استغلّ غيابها. فما تجاوز `max_shift_hours` لا
 *    يُحتسب عملاً بل يُسجَّل «غير مؤكَّد» ويُعرَض للمدير. **ولا يُبتلع
 *    ولا يُحذف**: يُقال إنّه وقعٌ لم يُتحقَّق منه، ويقرّر إنسان.
 *
 * ٣. **ضدّ التلاعب بالبيانات** — سلسلةُ تجزئة. من يعدّل صفّاً في القاعدة
 *    يكسر بصمةَ كلّ ما بعده، ويُكشف بـ`verifyChain`.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وفترةُ الخمول تُعرَض ولا تُخصَم.**
 *
 * صرّافٌ في فرعٍ هادئ جالسٌ على شبّاكه ثلاث ساعاتٍ بلا عميل **يعمل** —
 * وخصمُها منه ظلمٌ يُقاس بالساعة. فتُحسب وتُعرَض دليلاً بيد المدير، ولا
 * يُنقص منها النظام ريالاً. والقرار لإنسانٍ يعرف الفرع.
 */
class AgentWorkTimeService
{
    /** سقفُ الورديّة الواحدة: ما بعده لا يُحتسب عملاً مؤكَّداً. */
    private function maxShiftMinutes(): int
    {
        return (int) config('amial.worktime.max_shift_hours', 16) * 60;
    }

    /** فترةُ سكونٍ تُعدّ «خمولاً» وتُعرَض للمدير. */
    private function idleGraceMinutes(): int
    {
        return (int) config('amial.worktime.idle_grace_minutes', 90);
    }

    // ══════════════════════════════════════════════════════════════════
    // التسجيل — سلسلةٌ لا تُكسر بلا أثر
    // ══════════════════════════════════════════════════════════════════

    /**
     * تسجيلُ حدثٍ في السلسلة.
     *
     * **ولا معامل هنا لوقتٍ.** ولو وُجد لأصبح أوّل ما يُمرَّر من متحكّمٍ
     * يقرأ `$request->input('at')` — وسقط الدفاع الأوّل كلُّه بسطرٍ واحد.
     */
    public function record(
        AgentStaff $staff,
        string $event,
        ?int $shiftId = null,
        string $source = 'portal',
        ?AgentStaff $actor = null,
        array $meta = [],
    ): AgentWorkEvent {
        if (!in_array($event, AgentWorkEvent::EVENTS, true)) {
            throw new DomainException('حدث دوامٍ غير معروف');
        }

        // القفل على مستوى الموظّف: حدثان متزامنان يقرآن نفس البصمة
        // السابقة فيُنتجان فرعين في سلسلةٍ يجب أن تكون خطّاً واحداً.
        return DB::transaction(function () use ($staff, $event, $shiftId, $source, $actor, $meta) {
            $prev = AgentWorkEvent::where('staff_id', $staff->id)
                ->lockForUpdate()->orderByDesc('id')->first();

            $row = new AgentWorkEvent([
                'agent_user_id' => $staff->agent_user_id,
                'branch_id' => $staff->branch_id,
                'staff_id' => $staff->id,
                'shift_id' => $shiftId,
                'event' => $event,
                'occurred_at' => now(),          // ← ساعةُ الخادم وحدها
                'source' => $source,
                'actor_staff_id' => $actor?->id ?? $staff->id,
                'meta' => $meta ?: null,
            ]);

            $row->prev_hash = $prev?->hash;
            $row->hash = $row->computeHash($prev?->hash);
            $row->save();

            return $row;
        });
    }

    /**
     * فحصُ سلامة السلسلة لموظّف.
     *
     * **وهذا هو الفحص الذي يجعل التلاعب مكشوفاً لا مستحيلاً.** لا يمنع
     * أحداً من تعديل صفٍّ في القاعدة — يجعل التعديل **يُرى**.
     *
     * @return array{ok: bool, checked: int, broken_at: ?int, reason: ?string}
     */
    public function verifyChain(AgentStaff $staff): array
    {
        $rows = AgentWorkEvent::where('staff_id', $staff->id)->orderBy('id')->get();
        $prevHash = null;
        $n = 0;

        foreach ($rows as $row) {
            $n++;

            if ($row->prev_hash !== $prevHash) {
                return ['ok' => false, 'checked' => $n, 'broken_at' => (int) $row->id,
                        'reason' => 'بصمةُ السابق لا تطابق — حُذف حدثٌ أو أُدرج بينهما'];
            }

            if (!hash_equals((string) $row->hash, $row->computeHash($prevHash))) {
                return ['ok' => false, 'checked' => $n, 'broken_at' => (int) $row->id,
                        'reason' => 'محتوى الحدث تغيّر بعد كتابته'];
            }

            $prevHash = $row->hash;
        }

        return ['ok' => true, 'checked' => $n, 'broken_at' => null, 'reason' => null];
    }

    /**
     * أهو في استراحةٍ الآن؟
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولولا هذا لصارت الاستراحة أداةَ تحايلٍ لا راحة.**
     *
     * يضغط «استراحة» ويبقى يعمل: فالساعات تُخصم من دوامه — وهو ما لا
     * يفعله عاقل. لكنّ العكس هو الخطر: **مديرٌ يطلب من صرّافه أن يعمل
     * «خارج الدوام»** فيبقى الدرج مفتوحاً والعمليّات تجري بلا ساعاتٍ
     * تُنسب إليها. فالفرع يعمل والسجلّ يقول إنّه مغلق.
     *
     * ولذلك تُمنع العمليّات أثناء الاستراحة: إمّا أن تعمل ويُحتسب وقتُك،
     * وإمّا أن تستريح. ولا ثالث.
     */
    public function isOnBreak(AgentStaff $staff, ?int $shiftId = null): bool
    {
        $q = AgentWorkEvent::where('staff_id', $staff->id)
            ->whereIn('event', [AgentWorkEvent::BREAK_START, AgentWorkEvent::BREAK_END]);

        if ($shiftId) {
            $q->where('shift_id', $shiftId);
        }

        return $q->orderByDesc('id')->value('event') === AgentWorkEvent::BREAK_START;
    }

    // ══════════════════════════════════════════════════════════════════
    // الحساب
    // ══════════════════════════════════════════════════════════════════

    /**
     * جلساتُ العمل بين تاريخين — كلٌّ بدقائقها المؤكَّدة وغير المؤكَّدة.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessions(AgentStaff $staff, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $events = AgentWorkEvent::where('staff_id', $staff->id)
            ->where('occurred_at', '>=', $start->copy()->subDay())   // ورديّةٌ بدأت أمس
            ->where('occurred_at', '<=', $end)
            ->orderBy('id')->get();

        $out = [];
        $open = null;
        $breakStart = null;
        $breakMinutes = 0;

        foreach ($events as $e) {
            if ($e->event === AgentWorkEvent::SHIFT_OPEN) {
                // فتحٌ بلا إغلاقٍ سابق: الورديّة السابقة لم تُغلق. تُقفل
                // هنا **غير مؤكّدة** بالكامل — لا تُلغى ولا تُحتسب عملاً.
                if ($open) {
                    $out[] = $this->session($open, null, $breakMinutes, true);
                }

                $open = $e;
                $breakStart = null;
                $breakMinutes = 0;
                continue;
            }

            if (!$open) {
                continue;   // إغلاقٌ بلا فتحٍ في المدى — يُتجاهل
            }

            if ($e->event === AgentWorkEvent::BREAK_START) {
                $breakStart = $breakStart ?: $e->occurred_at;
                continue;
            }

            if ($e->event === AgentWorkEvent::BREAK_END && $breakStart) {
                $breakMinutes += (int) $breakStart->diffInMinutes($e->occurred_at);
                $breakStart = null;
                continue;
            }

            if ($e->event === AgentWorkEvent::SHIFT_CLOSE) {
                // استراحةٌ لم تُنهَ قبل الإغلاق: تُحسب حتى لحظة الإغلاق —
                // وإلّا صارت «ابدأ استراحةً ثمّ أغلق» طريقاً لإلغاء خصمها.
                if ($breakStart) {
                    $breakMinutes += (int) $breakStart->diffInMinutes($e->occurred_at);
                    $breakStart = null;
                }

                $out[] = $this->session($open, $e, $breakMinutes, false);
                $open = null;
                $breakMinutes = 0;
            }
        }

        // ورديّةٌ ما زالت مفتوحة الآن: تُعرَض جارية، ولا تُحتسب مغلقة.
        if ($open) {
            $out[] = $this->session($open, null, $breakMinutes, false, running: true);
        }

        // ما وقع خارج المدى المطلوب يُستبعد بعد الحساب لا قبله: ورديّةٌ
        // بدأت أمس وانتهت اليوم تخصّ اليوم الذي أُغلقت فيه.
        return array_values(array_filter($out, function (array $s) use ($start, $end) {
            $ref = Carbon::parse($s['ended_at'] ?? $s['started_at']);

            return $ref->between($start, $end);
        }));
    }

    /** @return array<string, mixed> */
    private function session(
        AgentWorkEvent $open,
        ?AgentWorkEvent $close,
        int $breakMinutes,
        bool $abandoned,
        bool $running = false,
    ): array {
        $endAt = $close?->occurred_at ?? ($running ? now() : null);

        $gross = $endAt ? (int) $open->occurred_at->diffInMinutes($endAt) : 0;
        $net = max(0, $gross - $breakMinutes);

        $cap = $this->maxShiftMinutes();

        // **ما تجاوز السقف لا يُحذف — يُفصَل.** الحذف يُخفي ما وقع،
        // والفصل يجعله سؤالاً على طاولة المدير.
        $unverified = 0;

        if ($abandoned) {
            // ورديّةٌ لم تُغلق أصلاً: لا دقيقةَ مؤكَّدة فيها.
            $unverified = $net;
            $net = 0;
        } elseif ($net > $cap) {
            $unverified = $net - $cap;
            $net = $cap;
        }

        return [
            'shift_id' => $open->shift_id,
            'started_at' => $open->occurred_at->toDateTimeString(),
            'ended_at' => $close?->occurred_at?->toDateTimeString(),
            'running' => $running,
            'abandoned' => $abandoned,
            'closed_by_manager' => $close && (int) $close->actor_staff_id !== (int) $open->staff_id,
            'gross_minutes' => $gross,
            'break_minutes' => $breakMinutes,
            'worked_minutes' => $net,
            'unverified_minutes' => $unverified,
            'idle_minutes' => $this->idleMinutes($open, $endAt),
        ];
    }

    /**
     * دقائقُ بلا نشاطٍ داخل الجلسة — **تُعرَض ولا تُخصَم.**
     *
     * فصرّافٌ في فرعٍ هادئ جالسٌ على شبّاكه بلا عميل يعمل، وخصمُها منه
     * ظلمٌ يُقاس بالساعة. لكنّ إخفاءها يجعل من يفتح ورديّته وينصرف
     * كمن جلس — فتُقال، ويقرّر من يعرف الفرع.
     */
    private function idleMinutes(AgentWorkEvent $open, ?Carbon $endAt): int
    {
        if (!$endAt) {
            return 0;
        }

        $stamps = DB::table('agent_cash_movements')
            ->where('staff_id', $open->staff_id)
            ->whereBetween('created_at', [$open->occurred_at, $endAt])
            ->orderBy('created_at')->pluck('created_at')
            ->map(fn ($t) => Carbon::parse($t))->all();

        $grace = $this->idleGraceMinutes();
        $idle = 0;
        $cursor = $open->occurred_at;

        foreach (array_merge($stamps, [$endAt]) as $t) {
            $gap = (int) $cursor->diffInMinutes($t);

            if ($gap > $grace) {
                $idle += $gap;
            }

            $cursor = $t;
        }

        return $idle;
    }

    // ══════════════════════════════════════════════════════════════════
    // التلخيص: يوميّ وأسبوعيّ وشهريّ
    // ══════════════════════════════════════════════════════════════════

    /**
     * ملخّصُ مدّة — بالساعات والدقائق، مقسوماً على الأيّام والأسابيع.
     *
     * @return array<string, mixed>
     */
    public function summary(AgentStaff $staff, string $from, string $to): array
    {
        $sessions = $this->sessions($staff, $from, $to);

        $days = [];
        $weeks = [];
        $months = [];

        foreach ($sessions as $s) {
            // اليومُ يُنسب إلى **الإغلاق** لا الفتح: ورديّةٌ بدأت ١١ ليلاً
            // وانتهت ٧ صباحاً عملٌ ليومِ انتهائها في عُرف الجداول.
            $ref = Carbon::parse($s['ended_at'] ?? $s['started_at']);
            $d = $ref->toDateString();
            $w = $ref->copy()->startOfWeek()->toDateString();
            $mo = $ref->format('Y-m');

            foreach ([[&$days, $d], [&$weeks, $w], [&$months, $mo]] as [&$bucket, $key]) {
                $bucket[$key] ??= ['worked' => 0, 'break' => 0, 'unverified' => 0,
                                   'idle' => 0, 'sessions' => 0];
                $bucket[$key]['worked'] += $s['worked_minutes'];
                $bucket[$key]['break'] += $s['break_minutes'];
                $bucket[$key]['unverified'] += $s['unverified_minutes'];
                $bucket[$key]['idle'] += $s['idle_minutes'];
                $bucket[$key]['sessions']++;
            }
        }

        ksort($days);
        ksort($weeks);
        ksort($months);

        $expectedDaily = (float) ($staff->daily_hours_expected ?? 0) * 60;

        $dayRows = [];

        foreach ($days as $date => $v) {
            $over = $expectedDaily > 0 ? max(0, $v['worked'] - (int) $expectedDaily) : 0;

            $dayRows[] = [
                'date' => $date,
                'worked' => $this->hm($v['worked']),
                'worked_minutes' => $v['worked'],
                'break' => $this->hm($v['break']),
                'unverified' => $this->hm($v['unverified']),
                'unverified_minutes' => $v['unverified'],
                'idle' => $this->hm($v['idle']),
                'sessions' => $v['sessions'],
                // **«غير مضبوط» ليس صفراً** (القاعدة السابعة): من لم
                // يُحدَّد دوامُه لا يُقال إنّ إضافيّه صفر — يُقال إنّه
                // لا يُحتسب لغياب المرجع.
                'expected' => $expectedDaily > 0 ? $this->hm((int) $expectedDaily) : null,
                'overtime' => $expectedDaily > 0 ? $this->hm($over) : null,
                'overtime_minutes' => $over,
                'short_minutes' => $expectedDaily > 0 ? max(0, (int) $expectedDaily - $v['worked']) : 0,
            ];
        }

        $mk = fn (array $b) => array_map(fn ($k, $v) => [
            'key' => $k,
            'worked' => $this->hm($v['worked']),
            'worked_minutes' => $v['worked'],
            'unverified' => $this->hm($v['unverified']),
            'idle' => $this->hm($v['idle']),
            'sessions' => $v['sessions'],
        ], array_keys($b), $b);

        $totalWorked = array_sum(array_column($days, 'worked'));
        $totalUnverified = array_sum(array_column($days, 'unverified'));
        $totalOvertime = array_sum(array_column($dayRows, 'overtime_minutes'));

        return [
            'period' => ['from' => $from, 'to' => $to],
            'staff' => [
                'id' => (int) $staff->id, 'name' => $staff->name, 'username' => $staff->username,
                'daily_hours_expected' => $expectedDaily > 0 ? (float) $staff->daily_hours_expected : null,
                'overtime_policy' => $staff->overtime_policy,
            ],
            'totals' => [
                'worked' => $this->hm($totalWorked),
                'worked_minutes' => $totalWorked,
                'break' => $this->hm(array_sum(array_column($days, 'break'))),
                'unverified' => $this->hm($totalUnverified),
                'unverified_minutes' => $totalUnverified,
                'idle' => $this->hm(array_sum(array_column($days, 'idle'))),
                'overtime' => $expectedDaily > 0 ? $this->hm($totalOvertime) : null,
                'overtime_minutes' => $totalOvertime,
                'sessions' => count($sessions),
                'days_worked' => count($days),
            ],
            'overtime' => $this->overtimeBreakdown($staff, $dayRows),
            'daily' => $dayRows,
            'weekly' => $mk($weeks),
            'monthly' => $mk($months),
            'sessions_list' => $sessions,
            // سلامةُ السلسلة تُعرَض مع الأرقام لا في شاشةٍ منفصلة: رقمٌ
            // من سلسلةٍ مكسورة لا يُقرأ قبل أن يُعرف أنّها مكسورة.
            'integrity' => $this->verifyChain($staff),
        ];
    }

    /**
     * الوقتُ الإضافيّ: ما وقع، وما يُحتسب منه.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ووقوعُه ليس استحقاقَه.**
     *
     * موظّفٌ يبقى ساعتين بعد دوامه بلا طلبٍ من أحد لا يُنشئ التزاماً على
     * شركته بمجرّد بقائه — وإلّا صار الجدولُ اختياراً بيد من يُجدوَل له.
     * ولا يُمحى بقاؤه أيضاً: يُسجَّل «واقعٌ بلا موافقة»، ويقرّر المدير.
     *
     * @param array<int, array<string, mixed>> $dayRows
     * @return array<string, mixed>
     */
    private function overtimeBreakdown(AgentStaff $staff, array $dayRows): array
    {
        $policy = (string) ($staff->overtime_policy ?: 'approved');
        $minutes = array_sum(array_column($dayRows, 'overtime_minutes'));

        if ($minutes <= 0) {
            return ['policy' => $policy, 'occurred' => $this->hm(0),
                    'payable' => $this->hm(0), 'pending' => $this->hm(0), 'rows' => []];
        }

        if ($policy === 'no') {
            return ['policy' => 'no', 'occurred' => $this->hm($minutes),
                    'payable' => $this->hm(0), 'pending' => $this->hm(0),
                    'note' => 'سياسةُ هذا الحساب لا تُقرّ وقتاً إضافيّاً — البقاء لا يُنشئ استحقاقاً',
                    'rows' => []];
        }

        if ($policy === 'auto') {
            return ['policy' => 'auto', 'occurred' => $this->hm($minutes),
                    'payable' => $this->hm($minutes), 'pending' => $this->hm(0), 'rows' => []];
        }

        // `approved`: يُطابَق كلُّ يومٍ بموافقةٍ مقرّة لتاريخه.
        $approvedDates = \App\Models\Agent\AgentTellerRequest::where('staff_id', $staff->id)
            ->where('kind', 'overtime')
            ->where('status', \App\Models\Agent\AgentTellerRequest::STATUS_APPROVED)
            ->get()->map(fn ($r) => (string) ($r->limit_snapshot['date'] ?? ''))
            ->filter()->unique()->all();

        $payable = 0;
        $pending = 0;
        $rows = [];

        foreach ($dayRows as $d) {
            if ($d['overtime_minutes'] <= 0) {
                continue;
            }

            $ok = in_array($d['date'], $approvedDates, true);
            $ok ? $payable += $d['overtime_minutes'] : $pending += $d['overtime_minutes'];

            $rows[] = ['date' => $d['date'], 'overtime' => $d['overtime'],
                       'approved' => $ok,
                       'label' => $ok ? '✅ مُقَرّ' : '⏳ واقعٌ بلا موافقة'];
        }

        return [
            'policy' => 'approved',
            'occurred' => $this->hm($minutes),
            'payable' => $this->hm($payable),
            'pending' => $this->hm($pending),
            'note' => 'الإضافيّ لا يُقَرّ إلّا بموافقة المدير على يومه',
            'rows' => $rows,
        ];
    }

    /** «٧س ٤٥د» — الدقائق تُقرأ صعبةً والساعات العشريّة تُقرأ خطأً. */
    public function hm(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        if ($h === 0) {
            return $m . 'د';
        }

        return $m === 0 ? $h . 'س' : "{$h}س {$m}د";
    }
}
