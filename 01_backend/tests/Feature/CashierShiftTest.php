<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CashierService;
use App\Services\CashierShiftService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** AMIAL-SHIFT-CLOSE-001 — ورديات الكاشير ودرج النقد. */
class CashierShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_BUSINESS]);
    }

    /** @test المجّاني ممنوع → 402. */
    public function free_plan_cannot_use_shifts(): void
    {
        $free = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $free->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_FREE]);
        Passport::actingAs($free->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/cashier/shift')->assertStatus(402);
    }

    /** @test الإقفال يحسب النقد المتوقّع والفرق بدقّة. */
    public function close_computes_expected_and_variance(): void
    {
        $svc = app(CashierShiftService::class);
        $cashier = app(CashierService::class);

        // وردية برصيد افتتاحي 5000
        $shift = $svc->open($this->merchant, null, '5000');

        // بيعان نقديان (3000 + 2000) + بيع أميال باي (لا يدخل الدرج)
        $cashier->recordSale(merchant: $this->merchant, total: '3000', paymentMethod: 'cash', items: []);
        $cashier->recordSale(merchant: $this->merchant, total: '2000', paymentMethod: 'cash', items: []);
        $cashier->recordSale(merchant: $this->merchant, total: '1000', paymentMethod: 'amial_pay',
            items: [], paidTransactionId: 'TX');

        // تقرير X: المتوقّع = 5000 + 5000 = 10000
        $x = $svc->snapshot($shift);
        $this->assertSame('10000.0000', $x['expected_cash']);
        $this->assertSame(2, $x['sales_count']);

        // جرد الدرج 9800 → عجز 200
        $closed = $svc->close($shift, '9800', 'عجز بسيط');
        $this->assertSame('10000.00', (string) $closed->expected_cash);
        $this->assertSame('9800.00', (string) $closed->counted_cash);
        $this->assertSame('-200.00', (string) $closed->variance);
        $this->assertSame('closed', $closed->status);
    }

    /** @test لا يمكن فتح وردية ثانية أثناء وجود مفتوحة. */
    public function cannot_open_two_shifts(): void
    {
        $svc = app(CashierShiftService::class);
        $svc->open($this->merchant, null, '1000');
        $this->expectException(\RuntimeException::class);
        $svc->open($this->merchant, null, '2000');
    }
}
