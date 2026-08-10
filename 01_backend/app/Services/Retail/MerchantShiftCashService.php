<?php

namespace App\Services\Retail;

use App\Models\Retail\ShiftCashMovement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٧ — **نقدُ الوردية، عامّاً**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **تعميمٌ لِما بُني في الوقود، لا نسخٌ له.**
 *
 * وكان `FuelShiftCashService` يفعل هذا للمحطّة وحدَها، **وليس فيه سطرٌ
 * واحدٌ خاصٌّ بالوقود**: مصروفٌ يخرج من الدرج، وفكّةٌ تدخله، ومعادلةٌ
 * تُصلح ما كان يُقرأ عجزاً في وجه الكاشير:
 *
 * ```
 * الافتتاح + النقد المقبوض + الداخل − الخارج = المتوقَّع
 * ```
 *
 * فصار الجدولُ عامّاً (`merchant_shift_cash_movements`) بنوعِ ورديّةٍ
 * ومعرّفها، **وخدمةُ الوقود واجهةٌ رفيعةٌ فوق هذه**. ولولا التعميم لصار
 * إصلاحُ عطلٍ في أحدهما إبقاءً له في الآخر.
 */
class MerchantShiftCashService
{
    public const DIRECTIONS = ['in', 'out'];

    /**
     * الأسبابُ واتجاهُها **المفروض**.
     *
     * والخلطُ يقلب إشارةَ المعادلة كلَّها فيُنتج فائضاً حيث العجز — ولذلك
     * لا يُقبل الاتجاهُ من الطلب بل يُقارَن بالمفروض.
     */
    public const REASON_DIRECTION = [
        'expense' => 'out',           // مصروف
        'cash_drop' => 'out',         // تسليم للخزنة
        'refund' => 'out',            // استرجاع للعميل
        'supplier_payment' => 'out',  // دفع لمورّد من الدرج
        'cash_in' => 'in',            // إيداع نقد
        'change_fund' => 'in',        // فكّة
        'owner_injection' => 'in',    // ضخّ من المالك
    ];

    public const REASON_AR = [
        'expense' => 'مصروف',
        'cash_drop' => 'تسليم للخزنة',
        'refund' => 'استرجاع',
        'supplier_payment' => 'دفع لمورّد',
        'cash_in' => 'إيداع نقد',
        'change_fund' => 'فكّة',
        'owner_injection' => 'ضخّ من المالك',
    ];

    /**
     * تسجيلُ حركة.
     *
     * **ولا تُقبل على ورديّةٍ مغلقة** — والفحصُ عند المنادي لأنّه يعرف
     * جدولَ ورديّته. وإضافةُ مصروفٍ بعد الإغلاق تغيّر فرقاً وقّع عليه
     * المشرفُ فعلاً.
     */
    public function record(
        string $shiftType, int $shiftId, User $actor, string $direction,
        string $reason, string $amount, ?string $note = null,
        ?string $reference = null, ?int $merchantUserId = null,
    ): ShiftCashMovement {
        if (! in_array($shiftType, [ShiftCashMovement::FUEL, ShiftCashMovement::CASHIER], true)) {
            throw new DomainException('نوع وردية غير معروف');
        }

        if (! in_array($direction, self::DIRECTIONS, true)) {
            throw new DomainException('اتجاه الحركة غير صحيح');
        }

        if (! array_key_exists($reason, self::REASON_DIRECTION)) {
            throw new DomainException('سبب الحركة غير صحيح');
        }

        if (! is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('المبلغ يجب أن يكون موجباً');
        }

        $expected = self::REASON_DIRECTION[$reason];
        if ($direction !== $expected) {
            throw new DomainException(sprintf(
                'سبب «%s» اتجاهه %s لا %s — والخلط يقلب إشارة الفرق',
                self::REASON_AR[$reason],
                $expected === 'out' ? 'خروج' : 'دخول',
                $direction === 'out' ? 'خروج' : 'دخول',
            ));
        }

        return ShiftCashMovement::create([
            'uuid' => (string) Str::uuid(),
            'merchant_user_id' => $merchantUserId,
            'shift_type' => $shiftType,
            'shift_id' => $shiftId,
            'direction' => $direction,
            'reason' => $reason,
            'amount' => $amount,
            'reference' => $reference,
            'note' => $note,
            'actor_user_id' => $actor->id,
        ]);
    }

    /**
     * صافي الحركة — **موجبٌ يزيد المتوقَّع وسالبٌ ينقصه**.
     *
     * @return array{in:string,out:string,net:string,expenses:string,count:int}
     */
    public function summarise(string $shiftType, int $shiftId): array
    {
        $rows = ShiftCashMovement::where('shift_type', $shiftType)
            ->where('shift_id', $shiftId)->get();

        $in = $out = $expenses = '0';

        foreach ($rows as $m) {
            $amount = (string) $m->amount;

            if ($m->direction === 'in') {
                $in = bcadd($in, $amount, 4);
            } else {
                $out = bcadd($out, $amount, 4);
            }

            if ($m->reason === 'expense') {
                $expenses = bcadd($expenses, $amount, 4);
            }
        }

        return [
            'in' => $in,
            'out' => $out,
            'net' => bcsub($in, $out, 4),
            'expenses' => $expenses,
            'count' => $rows->count(),
        ];
    }

    /** حركاتُ الوردية للعرض في شاشة الإغلاق. */
    public function movements(string $shiftType, int $shiftId): array
    {
        return ShiftCashMovement::where('shift_type', $shiftType)
            ->where('shift_id', $shiftId)
            ->with('actor:id,f_name,l_name')
            ->orderBy('id')->get()
            ->map(fn (ShiftCashMovement $m) => [
                'id' => (int) $m->id,
                'direction' => $m->direction,
                'reason' => $m->reason,
                'reason_ar' => self::REASON_AR[$m->reason] ?? $m->reason,
                'amount' => (string) $m->amount,
                'note' => $m->note,
                'actor' => trim(($m->actor->f_name ?? '') . ' ' . ($m->actor->l_name ?? '')) ?: null,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all();
    }

    /**
     * المتوقَّعُ في الدرج — **محسوباً من مصدره** (القاعدة ٦).
     *
     * ولا يُقرأ من عمودٍ مخزَّن: جردٌ يقارن المعدود بعمودٍ حُسب منه يُخرج
     * الفرقَ صفراً دائماً.
     */
    public function expectedCash(
        string $shiftType, int $shiftId, string $openingFloat, string $cashSales,
    ): string {
        $net = $this->summarise($shiftType, $shiftId)['net'];

        return bcadd(bcadd($openingFloat, $cashSales, 4), $net, 4);
    }
}
