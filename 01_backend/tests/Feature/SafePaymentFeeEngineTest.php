<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\SafePayment;
use App\Models\User;
use App\Services\SafePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-FEE-ENGINE-001 — ربط رسم الدفع الآمن بالمحرّك المركزي.
 */
class SafePaymentFeeEngineTest extends TestCase
{
    use RefreshDatabase;

    private SafePaymentService $service;
    private User $buyer;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->service = app(SafePaymentService::class);

        $this->buyer = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700005001']);
        EMoney::create(['user_id' => $this->buyer->id, 'current_balance' => '2000.0000']);

        $this->seller = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700005002']);
        EMoney::create(['user_id' => $this->seller->id, 'current_balance' => '0.0000']);
    }

    private function runToRelease(string $amount): SafePayment
    {
        $p = $this->service->createAndFund($this->buyer, $this->seller, 'X', 'Y', $amount);
        $p = $this->service->sellerAccept($p, $this->seller);
        $p = $this->service->sellerMarkInDelivery($p, $this->seller);
        $p = $this->service->sellerMarkDelivered($p, $this->seller);
        return $this->service->buyerConfirm($p, $this->buyer);
    }

    /** @test */
    public function release_uses_fee_engine_when_scheme_active()
    {
        FeeScheme::create([
            'code' => 'SAFE_PAYMENT', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '2.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $p = $this->runToRelease('1000.0000')->fresh();

        // 2% من 1000 = 20 → البائع يأخذ 980
        $this->assertSame('20.0000', (string)$p->platform_fee);
        $this->assertSame('980.0000', (string)$p->released_to_seller_amount);
        $this->assertSame('980.0000', (string)EMoney::where('user_id', $this->seller->id)->first()->current_balance);
        // snapshot مخزّن
        $this->assertNotNull($p->fee_scheme_id);
        $this->assertSame(1, (int)$p->fee_scheme_version);
    }

    /** @test */
    public function release_falls_back_to_config_when_no_scheme()
    {
        // لا توجد نسخة SAFE_PAYMENT → fallback لـ config (افتراضي 1%)
        config(['amial.safe_payment.fee_percent' => '1']);

        $p = $this->runToRelease('1000.0000')->fresh();

        $this->assertSame('10.0000', (string)$p->platform_fee); // 1%
        $this->assertSame('990.0000', (string)$p->released_to_seller_amount);
        $this->assertNull($p->fee_scheme_id); // لا snapshot في وضع fallback
    }
}
