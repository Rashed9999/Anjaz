<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CashierService;
use App\Services\CashierShiftService;
use App\Services\MerchantSaleRefundService;
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

    /**
     * @test
     *
     * **AMIAL-SHIFT-GATE-001 — قُلبت هذه الحالةُ عن قصد، ويُقال لماذا.**
     *
     * كانت تشترط ٤٠٢ للمجّانيّ («الورديات في باقة الأعمال فأعلى»)، وكان
     * ذلك صحيحاً يومَ كُتبت. **ثمّ صار البيعُ نفسُه يشترط ورديّةً مفتوحة**
     * (‏`amial.shift` على مسارات القبض) — فبقاءُ القفل يعني أنّ **كلَّ
     * تاجرٍ مجّانيٍّ عاجزٌ عن البيع إطلاقاً**: بيعُ القدرةِ على تشغيل
     * الشبّاك لا بيعُ ميزة.
     *
     * فنزلت القدرةُ إلى الأساس (`core()` و`PLAN_FREE`)، **والعمقُ يبقى
     * مدفوعاً**: تقريرُ ساعات العمل وتاريخُ الورديّات.
     *
     * **ولم تُحذَف الحالةُ بل عُكست** — فحذفُها يترك السؤالَ بلا جواب،
     * ويُعيد أحدُهم القفلَ غداً بحسن نيّة.
     */
    public function the_free_plan_can_use_shifts_because_selling_requires_one(): void
    {
        $free = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $free->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_FREE]);
        Passport::actingAs($free->fresh(), [], 'api');

        $this->getJson('/api/v1/amial/cashier/shift')->assertOk();

        $this->postJson('/api/v1/amial/cashier/shift/open', ['opening_float' => 0])
            ->assertStatus(201);
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
        // مرجعُ الدفع طلبُ QR مدفوعٌ حقيقيّ، لا نصٌّ يدّعي الدفع.
        $this->paidQrRequest($this->merchant, 'TX', '1000');
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

    /** @test المرتجع النقدي يخرج من الدرج ولا يظهر عجزاً وهمياً عند الإغلاق. */
    public function cash_refund_reduces_expected_till_and_closes_balanced(): void
    {
        $shifts = app(CashierShiftService::class);
        $cashier = app(CashierService::class);

        $shift = $shifts->open($this->merchant, null, '1000');

        $sale = $cashier->recordSale(
            merchant: $this->merchant,
            total: '500',
            paymentMethod: 'cash',
            items: [],
        );

        $before = $shifts->snapshot($shift);
        $this->assertSame('1500.0000', $before['expected_cash']);

        app(MerchantSaleRefundService::class)->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '500',
            refundMethod: 'cash',
            reason: 'مرتجع نقدي',
        );

        $after = $shifts->snapshot($shift);
        $this->assertSame('500.0000', $after['cash_movements_out']);
        $this->assertSame('-500.0000', $after['cash_movements_net']);
        $this->assertSame('1000.0000', $after['expected_cash']);

        $closed = $shifts->close($shift, '1000', 'جرد بعد مرتجع');
        $this->assertSame('1000.00', (string) $closed->expected_cash);
        $this->assertSame('0.00', (string) $closed->variance);
    }

    /** @test النقد لا يخرج من درج غير موجود. */
    public function cash_refund_requires_an_open_till(): void
    {
        $cashier = app(CashierService::class);
        $sale = $cashier->recordSale(
            merchant: $this->merchant,
            total: '500',
            paymentMethod: 'cash',
            items: [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('افتح وردية نقطة البيع قبل الاسترداد النقدي');

        app(MerchantSaleRefundService::class)->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '500',
            refundMethod: 'cash',
        );
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
