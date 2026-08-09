<?php

namespace App\Services\Fuel;

use App\Models\Fuel\FuelShiftCashMovement;
use App\Models\FuelShift;
use App\Models\User;
use DomainException;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلة ٧ — نقدُ الوردية كاملاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان المتوقَّع يُحسب هكذا:
 *
 *     الافتتاح + المبيعات النقديّة = المتوقَّع
 *
 * **فكلُّ ريالٍ يخرج للمصروفات يظهر عجزاً** في وجه الكاشير: اشترى الكاشيرُ
 * ماءً للمحطّة بألفين، فيُطالَب بألفين آخر الوردية — أو يُسجَّل عليه فرقٌ
 * يُقرأ ريبةً.
 *
 * والصواب:
 *
 *     الافتتاح + النقد المقبوض + الداخل − الخارج − المصروفات − المرتجعات
 *     = المتوقَّع
 */
class FuelShiftCashService
{
    /**
     * حركةُ نقدٍ داخل الوردية.
     *
     * **ولا تُقبل على ورديّةٍ مغلقة:** إضافةُ مصروفٍ بعد الإغلاق تُغيّر
     * فرقاً سبق أن وقّع عليه المشرف.
     */
    public function record(
        FuelShift $shift, User $actor, string $direction, string $reason,
        string $amount, ?string $note = null, ?string $reference = null,
    ): FuelShiftCashMovement {
        if ($shift->status !== 'open') {
            throw new DomainException(
                'الوردية مغلقة — لا تُضاف حركة نقد بعد الإغلاق. '
                . 'صحّحها بتسويةٍ موثّقة',
            );
        }

        if (! in_array($direction, FuelShiftCashMovement::DIRECTIONS, true)) {
            throw new DomainException('اتجاه الحركة غير صحيح');
        }

        if (! in_array($reason, FuelShiftCashMovement::REASONS, true)) {
            throw new DomainException('سبب الحركة غير صحيح');
        }

        if (bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('المبلغ يجب أن يكون موجباً');
        }

        // **المصروفُ خروجٌ دائماً، والإيداعُ دخولٌ دائماً.** وخلطُهما
        // يقلب إشارةَ المعادلة كلَّها، ويُنتج فائضاً حيث العجز.
        $expected = match ($reason) {
            'expense', 'cash_drop', 'refund' => 'out',
            'cash_in', 'change_fund' => 'in',
        };

        if ($direction !== $expected) {
            throw new DomainException(sprintf(
                'سبب «%s» اتجاهه %s لا %s — والخلط يقلب إشارة الفرق',
                $reason, $expected === 'out' ? 'خروج' : 'دخول',
                $direction === 'out' ? 'خروج' : 'دخول',
            ));
        }

        return FuelShiftCashMovement::create([
            'shift_id' => $shift->id,
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
    public function summarise(FuelShift $shift): array
    {
        $rows = FuelShiftCashMovement::where('shift_id', $shift->id)->get();

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
    public function movements(FuelShift $shift): array
    {
        return FuelShiftCashMovement::where('shift_id', $shift->id)
            ->with('actor:id,f_name,l_name')
            ->orderBy('id')->get()
            ->map(fn (FuelShiftCashMovement $m) => [
                'id' => (int) $m->id,
                'direction' => $m->direction,
                'reason' => $m->reason,
                'reason_ar' => match ($m->reason) {
                    'expense' => 'مصروف',
                    'cash_in' => 'إيداع نقد',
                    'cash_drop' => 'تسليم للخزنة',
                    'change_fund' => 'فكّة',
                    'refund' => 'استرجاع',
                },
                'amount' => (string) $m->amount,
                'note' => $m->note,
                'actor' => trim(($m->actor->f_name ?? '') . ' ' . ($m->actor->l_name ?? '')) ?: null,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all();
    }
}
