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

    public function open(User $merchant, ?int $posUserId, string $openingFloat): CashierShift
    {
        if ($this->current($merchant, $posUserId)) {
            throw new RuntimeException('توجد وردية مفتوحة بالفعل — أغلِقها أولاً');
        }
        $openingFloat = MoneyService::normalize($openingFloat);
        return CashierShift::create([
            'merchant_user_id' => $merchant->id,
            'pos_user_id' => $posUserId,
            'opening_float' => $openingFloat,
            'status' => 'open',
            'opened_by' => $posUserId ?? $merchant->id,
            'opened_at' => now(),
            'zone_code' => $merchant->zone_code ?? 'SOUTH',
        ]);
    }

    /** يحسب نقد المبيعات منذ بدء الوردية (نقدي + الجزء النقدي من المختلط). */
    private function computeCash(CashierShift $shift): array
    {
        $q = MerchantSale::where('merchant_user_id', $shift->merchant_user_id)
            ->where('created_at', '>=', $shift->opened_at)
            ->whereIn('status', ['completed', 'credit_paid']);
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
            $q = DB::table('pharmacy_sales')
                ->where('pharmacy_id', $pharmacy)
                ->where('payment_method', 'cash')
                ->where('created_at', '>=', $shift->opened_at)
                ->whereIn('status', ['completed', 'credit_paid']);

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
    public function close(CashierShift $shift, string $countedCash, ?string $notes = null): CashierShift
    {
        return DB::transaction(function () use ($shift, $countedCash, $notes) {
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
            ]);
            return $locked->fresh();
        });
    }
}
