<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Retail\ShiftCashMovement;
use App\Models\User;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * AMIAL-CASH-DROP-001 — حارس فصل عهدة الدرج عن محفظة أميال.
 *
 * تسليم النقد للخزنة ينقص المتوقع المادي في الدرج، لكنه لا "يصفر"
 * محفظة التاجر ولا يحرّك EMoney. تقرير Z بعد التسليم يجب أن يفسر النقد
 * الخارج كحركة عهدة، لا كعجز على موظف نقطة البيع.
 */
class CashierShiftCashDropGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function cash_drop_reduces_till_expectation_without_touching_merchant_wallet(): void
    {
        $merchant = User::factory()->create([
            'type' => 3,
            'role' => 'merchant',
            'zone_code' => 'SOUTH',
        ]);

        $wallet = EMoney::create([
            'user_id' => $merchant->id,
            'current_balance' => '230000.0000',
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);

        $svc = app(CashierShiftService::class);
        $shift = $svc->open($merchant, null, '1000');

        $drop = $svc->cashDrop(
            $shift,
            '400',
            $merchant,
            'توريد نهاية الفترة',
            'SAFE-2026-001',
        );

        $this->assertSame('600.0000', $drop['report']['expected_cash']);
        $this->assertSame('400.0000', $drop['report']['cash_movements_out']);

        $movement = ShiftCashMovement::findOrFail($drop['movement']->id);
        $this->assertSame('cash_drop', $movement->reason);
        $this->assertSame('out', $movement->direction);
        $this->assertSame('400.0000', (string) $movement->amount);
        $this->assertSame('SAFE-2026-001', $movement->reference);
        $this->assertSame($merchant->id, $movement->merchant_user_id);

        $closed = $svc->close($shift, '600', 'مطابقة بعد التسليم', $merchant);
        $this->assertSame('600.0000', (string) $closed->expected_cash);
        $this->assertSame('600.0000', (string) $closed->counted_cash);
        $this->assertSame('0.0000', (string) $closed->variance);

        // الحدّ الفاصل: عهدة الدرج ليست المحفظة الإلكترونية.
        $this->assertSame('230000.0000', (string) $wallet->fresh()->current_balance);
    }

    /** @test */
    public function cash_drop_cannot_exceed_the_cash_expected_in_the_open_till(): void
    {
        $merchant = User::factory()->create([
            'type' => 3,
            'role' => 'merchant',
            'zone_code' => 'SOUTH',
        ]);

        $svc = app(CashierShiftService::class);
        $shift = $svc->open($merchant, null, '500');

        try {
            $svc->cashDrop($shift, '500.0001', $merchant);
            $this->fail('كان يجب رفض تسليم أكبر من المتوقع في الدرج.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('أكبر من النقد المتوقع', $e->getMessage());
        }

        $this->assertSame(0, ShiftCashMovement::where('shift_id', $shift->id)->count());
        $this->assertSame('500.0000', $svc->snapshot($shift)['expected_cash']);
    }
}
