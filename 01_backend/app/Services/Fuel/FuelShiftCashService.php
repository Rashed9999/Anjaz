<?php

namespace App\Services\Fuel;

use App\Models\FuelShift;
use App\Models\Retail\ShiftCashMovement;
use App\Models\User;
use App\Services\Retail\MerchantShiftCashService;
use DomainException;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلة ٧ — نقدُ وردية المحطّة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان المتوقَّع يُحسب: `الافتتاح + المبيعات النقديّة`. **فكلُّ ريالٍ يخرج
 * للمصروفات يظهر عجزاً** في وجه الكاشير: اشترى ماءً للمحطّة بألفين
 * فيُطالَب بألفين آخر الوردية.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والمحرّكُ لم يعد هنا** — AMIAL-RETAIL-VERTICAL-001 · المرحلة ٧.
 *
 * فليس في هذا المنطق سطرٌ واحدٌ خاصٌّ بالوقود، والتجزئةُ تحتاجه حرفاً
 * بحرف. فانتقل إلى `MerchantShiftCashService` وصار هذا واجهةً تعرف
 * **ورديّةَ المحطّة** وحدَها: تفحص أنّها مفتوحة، ثمّ تُنادي المحرّك.
 *
 * وبقيت الأصنافُ والتواقيعُ كما كانت — فما ينادي هذه الخدمة لا يتغيّر.
 */
class FuelShiftCashService
{
    public function __construct(private MerchantShiftCashService $engine) {}

    /**
     * حركةُ نقدٍ داخل الوردية.
     *
     * **ولا تُقبل على ورديّةٍ مغلقة:** إضافةُ مصروفٍ بعد الإغلاق تُغيّر
     * فرقاً سبق أن وقّع عليه المشرف.
     */
    public function record(
        FuelShift $shift, User $actor, string $direction, string $reason,
        string $amount, ?string $note = null, ?string $reference = null,
    ): ShiftCashMovement {
        if ($shift->status !== 'open') {
            throw new DomainException(
                'الوردية مغلقة — لا تُضاف حركة نقد بعد الإغلاق. '
                . 'صحّحها بتسويةٍ موثّقة',
            );
        }

        return $this->engine->record(
            shiftType: ShiftCashMovement::FUEL,
            shiftId: $shift->id,
            actor: $actor,
            direction: $direction,
            reason: $reason,
            amount: $amount,
            note: $note,
            reference: $reference,
            merchantUserId: $shift->merchant_user_id ?? null,
        );
    }

    /**
     * صافي الحركة — **موجبٌ يزيد المتوقَّع وسالبٌ ينقصه**.
     *
     * @return array{in:string,out:string,net:string,expenses:string,count:int}
     */
    public function summarise(FuelShift $shift): array
    {
        return $this->engine->summarise(ShiftCashMovement::FUEL, $shift->id);
    }

    /** حركاتُ الوردية للعرض في شاشة الإغلاق. */
    public function movements(FuelShift $shift): array
    {
        return $this->engine->movements(ShiftCashMovement::FUEL, $shift->id);
    }
}
