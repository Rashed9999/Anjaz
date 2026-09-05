<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\MerchantSale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AMIAL-SHIFT-CLOSE-001 — ورديات الكاشير ودرج النقد.
 *
 *   open()     — بدء وردية برصيد افتتاحي (واحدة مفتوحة لكل كاشير).
 *   snapshot() — تقرير X (لحظي، بلا إقفال): المتوقّع في الدرج الآن.
 *   close()    — تقرير Z (إقفال): يجرد النقد ويحسب الفرق.
 */
class CashierShiftService
{
    public function current(User $merchant, ?int $posUserId): ?CashierShift
    {
        return CashierShift::where('merchant_user_id', $merchant->id)
            ->where('pos_user_id', $posUserId)
            ->where('status', 'open')
            ->latest('id')->first();
    }

    public function open(
        User $merchant,
        ?int $posUserId,
        string $openingFloat,
        ?int $posDeviceId = null,
    ): CashierShift {
        if ($this->current($merchant, $posUserId)) {
            throw new RuntimeException('توجد وردية مفتوحة بالفعل — أغلِقها أولاً');
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-SHIFT-DEVICE-001 — **وصندوقٌ واحدٌ لا يحمل ورديّتين.**
        //
        // درجُ النقد واحدٌ ماديّاً. فورديّتان مفتوحتان عليه تعنيان أنّ
        // الجردَ لا يُنسَب لأحد: كلٌّ يقول «العجزُ من الآخر»، **ولا
        // يُحسم**. والفحصُ هنا رسالةٌ تُقرأ، والقيدُ الفريدُ في القاعدة
        // هو الحارس — فموظّفان يضغطان «افتح» في اللحظة نفسِها يقرآن
        // كلاهما «لا ورديّةَ مفتوحة».
        // ══════════════════════════════════════════════════════════════
        if ($posDeviceId !== null) {
            $busy = CashierShift::where('open_device_lock', $posDeviceId)->first();

            if ($busy !== null) {
                throw new RuntimeException(
                    'على هذا الصندوق ورديّةٌ مفتوحةٌ باسم «'
                    .($busy->opened_by_name ?: 'غير معروف')
                    .'» — تُقفَل قبل فتح غيرها. ودرجُ النقد واحد.');
            }
        }
        $openingFloat = MoneyService::normalize($openingFloat);

        // AMIAL-SHIFT-GATE-001 — **الاسمُ يُلتقَط الآن ولا يُقرأ لاحقاً.**
        //
        // الموظّفُ يُعاد تسميتُه أو يُحذَف أو يُنقَل، وقراءةُ الاسم يومَ
        // العرض **تُعيد كتابةَ فواتيرِ الشهر الماضي**: ورقةٌ في يد الزبون
        // تقول «أحمد» والشاشةُ تقول «محمد». (وهي قاعدةُ `unit_cost` نفسُها
        // في حركة المخزون: يُلتقَط لحظةَ الحدث.)
        [$name, $role] = $this->openerIdentity($merchant, $posUserId);

        return CashierShift::create([
            'merchant_user_id' => $merchant->id,
            'pos_user_id' => $posUserId,
            'pos_device_id' => $posDeviceId,

            // **يحمل الجهازَ ما دامت مفتوحة، ويُفرَّغ عند الإغلاق** —
            // فالقيدُ الفريدُ يمنع الثانيةَ ويسمح بألفِ ورديّةٍ مغلقة.
            'open_device_lock' => $posDeviceId,

            'opening_float' => $openingFloat,
            'status' => 'open',
            'opened_by' => $posUserId ?? $merchant->id,
            'opened_by_name' => $name,
            'opened_by_role' => $role,
            'opened_at' => now(),
            'zone_code' => $merchant->zone_code ?? 'SOUTH',
        ]);
    }

    /**
     * اسمُ من يفتح الورديّة ودورُه.
     *
     * **ولا يُترَك فارغاً إن جُهل** (القاعدة السابعة): «غير معروف» تُكتب
     * صراحةً، لأنّ فراغاً في ذيل الفاتورة يُقرأ «لا أحد» — والورديّةُ لا
     * تُفتح بلا أحد.
     *
     * @return array{0:string,1:string}
     */
    private function openerIdentity(User $merchant, ?int $posUserId): array
    {
        if ($posUserId === null) {
            $name = trim(($merchant->f_name ?? '') . ' ' . ($merchant->l_name ?? ''));

            return [$name !== '' ? $name : 'صاحب المتجر', 'owner'];
        }

        $pos = \App\Models\PosUser::find($posUserId);
        $user = $pos?->user_id ? User::find($pos->user_id) : null;

        $name = trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? ''));
        if ($name === '') {
            $name = trim((string) ($pos->display_name ?? '')) ?: 'موظّف غير معروف';
        }

        return [$name, 'pos'];
    }

    /** يحسب نقد المبيعات منذ بدء الوردية (نقدي + الجزء النقدي من المختلط). */
    private function computeCash(CashierShift $shift): array
    {
        // ══════════════════════════════════════════════════════════════
        // AMIAL-SHIFT-GATE-001 — **الرابطُ الصريحُ أوّلاً، والزمنُ بديلٌ
        // للقديم وحدَه.**
        //
        // كان الفرزُ بالزمن فقط: `created_at >= opened_at`. **وقِيس أنّه
        // ينكسر**: أُقفلت ورديّةٌ وفُتحت أخرى، فحُسبت بيعةُ الأولى في
        // الثانية — والفحصُ خرج «عجزاً» حيث الصواب «فائض».
        //
        //     أُقفلت الأولى · فُتحت ثانيةٌ بعهدة ١٠٠٠ · عُدَّ ١٠٧٥
        //     المنتظَر: فائضُ ٧٥
        //     والمقيس : عجزُ ٢٢٥  ← لأنّ بيعةَ ٣٠٠ من الأولى دخلت الثانية
        //
        // وفي الواقع يقع أوضحَ من هذا: **ورديّةٌ يُنسى إقفالُها**، أو
        // ساعةُ الجهاز تُعدَّل. فالبيعةُ صارت تحمل `shift_id`، ويُفرَز به.
        //
        // **وما لا `shift_id` له يُفرَز بالزمن كما كان** — بيعاتٌ سُجّلت
        // قبل هذا الحارس، أو تاجرٌ أطفأ الإلزام من لوحته. وإسقاطُها
        // يُنقص «المتوقَّع» فيظهر فائضٌ وهميٌّ في وجه الكاشير.
        // ══════════════════════════════════════════════════════════════
        $q = MerchantSale::where('merchant_user_id', $shift->merchant_user_id)
            ->whereIn('status', ['completed', 'credit_paid'])
            ->where(function ($w) use ($shift) {
                $w->where('shift_id', $shift->id)
                    ->orWhere(fn ($u) => $u->whereNull('shift_id')
                        ->where('created_at', '>=', $shift->opened_at)
                        ->when($shift->closed_at,
                            fn ($c) => $c->where('created_at', '<=', $shift->closed_at)));
            });

        if ($shift->pos_user_id) {
            $q->where('pos_user_id', $shift->pos_user_id);
        }
        $sales = $q->get(['payment_method', 'total_amount', 'cash_amount']);

        $cash = '0';
        $count = 0;
        foreach ($sales as $s) {
            if ($s->payment_method === 'cash') {
                $cash = MoneyService::add($cash, (string) $s->total_amount);
                $count++;
            } elseif ($s->payment_method === 'mixed' && $s->cash_amount !== null) {
                $cash = MoneyService::add($cash, (string) $s->cash_amount);
                $count++;
            }
        }
        // ══════════════════════════════════════════════════════════════
        // AMIAL-SHIFT-VERTICALS-001 — **ودرجُ الصيدليّة والجملة يُعدّان.**
        //
        // **الثمنُ المقيس:** كانت هذه الدالّةُ تقرأ `merchant_sales`
        // وحدَه — جدولَ الكاشير العامّ. فصيدليّةٌ تفتح ورديّةً وتبيع
        // نقداً طولَ اليوم تُقفلها والمتوقَّعُ **عهدتُها وحدَها**:
        //
        //     عهدة ١٠٠٠ · مبيعاتُ نقدٍ ٣٠٠ · معدود ١٣٠٠
        //     المتوقَّع كان يخرج ١٠٠٠  ⇒  «فائضٌ ٣٠٠»
        //
        // **فكلُّ ريالٍ باعته يُقرأ فائضاً في وجه الكاشير** — وهو أسوأُ
        // من العجز: العجزُ يُحقَّق فيه، والفائضُ يُقرأ «بيعٌ لم يُسجَّل»
        // أو «مالٌ من غير مصدره».
        //
        // **ووصفُ القدرة نفسِه كان يَعِد بغير ذلك**: «الورديات وإغلاق
        // الصندوق — **والمتوقَّع يُحسب من الحركة** فلا يظهر مصروف
        // الكاشير عجزاً في وجهه». (القاعدة السادسة: الرقمُ يُحسب من
        // مصدره لا من عمودٍ مخزَّن.)
        //
        // **والوقودُ مستثنىً عمداً** — له `FuelShiftService` وجدولُ
        // `fuel_shifts` الخاصّ به، فضمُّه هنا يعدّ بيعَه مرّتين.
        // ══════════════════════════════════════════════════════════════
        $vertical = $this->verticalCash($shift);

        return [
            'cash_sales' => MoneyService::normalize(
                MoneyService::add($cash, $vertical['cash'])),
            'sales_count' => $count + $vertical['count'],
        ];
    }

    /** نقدُ القطاعات التي لا ورديّةَ خاصّةً لها — الصيدليّةُ والجملة. */
    private function verticalCash(CashierShift $shift): array
    {
        $cash = '0';
        $count = 0;

        // ① الصيدليّة — تُربط بالتاجر عبر `pharmacies`.
        $pharmacy = DB::table('pharmacies')
            ->where('merchant_user_id', $shift->merchant_user_id)
            ->value('id');

        if ($pharmacy !== null) {
            // **وبالرابط الصريح أوّلاً هنا أيضاً** — انظر أعلاه.
            $q = DB::table('pharmacy_sales')
                ->where('pharmacy_id', $pharmacy)
                ->where('payment_method', 'cash')
                ->whereIn('status', ['completed', 'credit_paid'])
                ->where(function ($w) use ($shift) {
                    $w->where('shift_id', $shift->id)
                        ->orWhere(fn ($u) => $u->whereNull('shift_id')
                            ->where('created_at', '>=', $shift->opened_at)
                            ->when($shift->closed_at,
                                fn ($c) => $c->where('created_at', '<=', $shift->closed_at)));
                });

            // **ونطاقُ الجهاز يُحترَم** — ورديّةُ كاشيرٍ بعينه لا تحمل
            // بيعَ زميله، وإلّا حُوسب على ما لم يقبضه.
            if ($shift->pos_user_id) {
                $q->where('pos_user_id', $shift->pos_user_id);
            }

            foreach ($q->get(['total_amount']) as $r) {
                $cash = MoneyService::add($cash, (string) $r->total_amount);
                $count++;
            }
        }

        // ② الجملة — تحصيلاتُها النقديّة، وتُربط عبر `wholesale_businesses`.
        $business = DB::table('wholesale_businesses')
            ->where('merchant_user_id', $shift->merchant_user_id)
            ->value('id');

        if ($business !== null) {
            foreach (DB::table('wholesale_collections')
                ->where('business_id', $business)
                ->where('payment_method', 'cash')
                ->where('created_at', '>=', $shift->opened_at)
                ->get(['amount']) as $r) {
                $cash = MoneyService::add($cash, (string) $r->amount);
                $count++;
            }
        }

        return ['cash' => $cash, 'count' => $count];
    }

    // ══════════════════════════════════════════════════════════════════
    //  AMIAL-SHIFT-GATE-001 — وقتُ العمل: اليوم والشهر
    // ══════════════════════════════════════════════════════════════════

    /**
     * **ساعاتُ عملِ كلِّ من وقف على الشبّاك — اليومَ وهذا الشهر.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **بنصّ الطلب:** «ورديّة تحمل اسمَه ووقتَ عمله اليوميّ والشهريّ».
     *
     * **ولا عمودَ ساعاتٍ يُخزَّن** (القاعدة السادسة): الرقمُ يُحسب من
     * `opened_at`…`closed_at` في مصدره. وعمودٌ مخزَّنٌ ثالثٌ يمكن أن
     * يناقض الورديّاتِ نفسَها — ويومَ يختلفان لا يُعرف أيُّهما الأجر.
     *
     * **والورديّةُ المفتوحةُ تُحسب حتّى اللحظة وتُوسَم `is_running`**
     * (القاعدة السابعة): ورديّةٌ لم تُقفَل ليست «صفرَ ساعات» — هي جارية.
     * وصفرٌ هناك يقتطع من أجرِ من هو واقفٌ الآن.
     *
     * **والتجميعُ بالاسم الملتقَط لا بالمعرّف وحدَه** — فموظّفٌ حُذف
     * حسابُه تبقى ساعاتُه منسوبةً إليه، ولا تصير «غير معروف» بأثرٍ رجعيّ.
     *
     * @return array<int,array<string,mixed>>
     */
    public function workTime(User $merchant, ?string $month = null): array
    {
        $now = now();
        $monthStart = $month
            ? \Carbon\Carbon::parse($month . '-01')->startOfMonth()
            : $now->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $dayStart = $now->copy()->startOfDay();

        $shifts = CashierShift::where('merchant_user_id', $merchant->id)
            ->where('opened_at', '>=', $monthStart)
            ->where('opened_at', '<=', $monthEnd)
            ->orderBy('opened_at')
            ->get();

        $people = [];

        foreach ($shifts as $shift) {
            $key = ($shift->pos_user_id ?? 0) . '|' . ($shift->opened_by_name ?? '');

            $people[$key] ??= [
                'pos_user_id' => $shift->pos_user_id,
                'name' => $shift->opened_by_name ?: 'غير معروف',
                'role' => $shift->opened_by_role ?: ($shift->pos_user_id ? 'pos' : 'owner'),
                'minutes_today' => 0,
                'minutes_month' => 0,
                'shifts_today' => 0,
                'shifts_month' => 0,
                'is_running' => false,
                'cash_sales_month' => '0',
                'variance_month' => '0',
            ];

            $from = $shift->opened_at;
            $to = $shift->closed_at ?? $now;
            $minutes = max(0, $from->diffInMinutes($to));

            $people[$key]['minutes_month'] += $minutes;
            $people[$key]['shifts_month']++;

            if ($shift->status === 'open') {
                $people[$key]['is_running'] = true;
            }

            // **ويومُ اليوم يُقصّ عند منتصف الليل** — ورديّةٌ فُتحت أمسِ
            // ولم تُقفَل لا تُحسب كلُّها في اليوم.
            if ($to->greaterThan($dayStart)) {
                $todayFrom = $from->greaterThan($dayStart) ? $from : $dayStart;
                $people[$key]['minutes_today'] += max(0, $todayFrom->diffInMinutes($to));
                $people[$key]['shifts_today']++;
            }

            $people[$key]['cash_sales_month'] = MoneyService::add(
                $people[$key]['cash_sales_month'], (string) ($shift->cash_sales ?? '0'));

            if ($shift->variance !== null) {
                $people[$key]['variance_month'] = MoneyService::add(
                    $people[$key]['variance_month'], (string) $shift->variance);
            }
        }

        return array_values(array_map(function (array $p) {
            $p['hours_today'] = round($p['minutes_today'] / 60, 2);
            $p['hours_month'] = round($p['minutes_month'] / 60, 2);
            $p['cash_sales_month'] = MoneyService::normalize($p['cash_sales_month']);
            $p['variance_month'] = MoneyService::normalize($p['variance_month']);

            return $p;
        }, $people));
    }

    /** تقرير X — لقطة لحظية بلا إقفال. */
    public function snapshot(CashierShift $shift): array
    {
        $c = $this->computeCash($shift);
        $expected = MoneyService::add((string) $shift->opening_float, $c['cash_sales']);
        return [
            'opening_float' => (string) $shift->opening_float,
            'cash_sales' => $c['cash_sales'],
            'sales_count' => $c['sales_count'],
            'expected_cash' => MoneyService::normalize($expected),
            'opened_at' => $shift->opened_at?->toIso8601String(),
        ];
    }

    /** تقرير Z — إقفال الوردية وجرد الدرج. */
    public function close(
        CashierShift $shift,
        string $countedCash,
        ?string $notes = null,
        ?User $closedBy = null,
    ): CashierShift {
        return DB::transaction(function () use ($shift, $countedCash, $notes, $closedBy) {
            $locked = CashierShift::where('id', $shift->id)->lockForUpdate()->first();
            if ($locked->status === 'closed') {
                throw new RuntimeException('الوردية مُقفلة مسبقاً');
            }
            $c = $this->computeCash($locked);
            $expected = MoneyService::normalize(MoneyService::add((string) $locked->opening_float, $c['cash_sales']));
            $counted = MoneyService::normalize($countedCash);
            $variance = MoneyService::normalize(MoneyService::sub($counted, $expected));

            $locked->update([
                'cash_sales' => $c['cash_sales'],
                'sales_count' => $c['sales_count'],
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'variance' => $variance,
                'status' => 'closed',
                'notes' => $notes,
                'closed_at' => now(),

                // AMIAL-SHIFT-DEVICE-001 — **ويُحرَّر الصندوقُ للتالي.**
                // و`pos_device_id` يبقى — هو التاريخُ الذي يقول على أيّ
                // صندوقٍ جرت. المُفرَّغُ هو القفلُ وحدَه.
                'open_device_lock' => null,
                // AMIAL-SHIFT-GATE-001 — **ومن أقفلَ يُسمّى أيضاً.**
                // فقد يُقفلها المالكُ نيابةً عن كاشيرٍ انصرف، والفرقُ
                // يُنسَب حينها إلى من عدَّ الدرجَ لا إلى من فتحه.
                'closed_by' => $closedBy?->id,
                'closed_by_name' => $closedBy
                    ? (trim(($closedBy->f_name ?? '') . ' ' . ($closedBy->l_name ?? ''))
                        ?: 'غير معروف')
                    : null,
            ]);
            return $locked->fresh();
        });
    }
}
