<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\MerchantRiskProfile;
use App\Models\User;
use App\Services\MerchantRiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-RISK-001 (v2.9) — اختبارات تصنيف ومراقبة التجار.
 */
class MerchantRiskServiceTest extends TestCase
{
    use RefreshDatabase;

    private MerchantRiskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MerchantRiskService::class);
    }

    private function makeMerchant(string $tier = 'small', array $overrides = []): User
    {
        $m = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        $limits = MerchantProfile::defaultLimitsForTier($tier);
        MerchantProfile::create(array_merge([
            'user_id' => $m->id,
            'tier' => $tier,
            'risk_category' => 'standard',
            'verification_status' => 'verified',
        ], $limits, $overrides));
        return $m;
    }

    // ===== التصنيف والحدود =====

    /** @test */
    public function tiers_have_different_limits()
    {
        $micro = MerchantProfile::defaultLimitsForTier('micro');
        $large = MerchantProfile::defaultLimitsForTier('large');

        $this->assertTrue(
            bccomp($large['daily_receive_limit'], $micro['daily_receive_limit'], 4) > 0,
            'حد الكبير يجب أن يتجاوز الصغير'
        );
    }

    /** @test */
    public function micro_merchant_blocked_above_single_limit()
    {
        $m = $this->makeMerchant('micro'); // single limit = 50000
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('حد الاستلام للعملية');
        $this->service->assertReceiveAllowed($m->id, '60000');
    }

    /** @test */
    public function large_merchant_allows_big_amount()
    {
        $m = $this->makeMerchant('large'); // single limit = 5,000,000
        $this->service->assertReceiveAllowed($m->id, '1000000');
        $this->assertTrue(true);
    }

    /** @test */
    public function merchant_without_profile_treated_as_micro()
    {
        $m = User::factory()->create(['type' => 3]);
        // بلا profile → micro limits → 50000 single
        $this->expectException(\RuntimeException::class);
        $this->service->assertReceiveAllowed($m->id, '100000');
    }

    /** @test */
    public function suspended_merchant_cannot_receive()
    {
        $m = $this->makeMerchant('small', ['verification_status' => 'verification_suspended']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('موقوف');
        $this->service->assertReceiveAllowed($m->id, '1000');
    }

    /** @test */
    public function set_tier_updates_limits()
    {
        $m = $this->makeMerchant('micro');
        $admin = User::factory()->create(['type' => 0]);

        $profile = $this->service->setTier($m, 'large', $admin);

        $this->assertEquals('large', $profile->tier);
        // الحدود تحدّثت
        $this->assertEquals('20000000.0000', (string)$profile->daily_receive_limit);
    }

    // ===== المراقبة (3 أنماط غسيل) =====

    /** @test */
    public function pass_through_pattern_raises_risk()
    {
        $m = $this->makeMerchant('medium');
        // أنشئ risk profile باستلام عالٍ
        $risk = MerchantRiskProfile::create([
            'merchant_user_id' => $m->id,
            'total_received_lifetime' => '1000000',
            'total_transferred_out' => '900000', // 90% pass-through!
        ]);

        $this->service->analyzeReceived($m->id, '10000', 999);

        $risk->refresh();
        // النمط 3 (pass-through) يجب أن يرفع المخاطر
        $this->assertGreaterThan(0, (float)$risk->current_risk_score);
        $this->assertDatabaseHas('merchant_risk_events', [
            'merchant_user_id' => $m->id,
            'event_type' => 'pass_through',
        ]);
    }

    /** @test */
    public function volume_spike_is_detected()
    {
        $m = $this->makeMerchant('medium');
        // متوسط يومي منخفض
        MerchantRiskProfile::create([
            'merchant_user_id' => $m->id,
            'avg_daily_volume' => '10000',
        ]);

        // اليوم: استلام ضخم (300000 = 30x المتوسط)
        for ($i = 0; $i < 3; $i++) {
            DB::table('transactions')->insert([
                'transaction_id' => "BIG{$i}",
                'user_id' => $m->id, 'from_user_id' => 100 + $i, 'to_user_id' => $m->id,
                'transaction_type' => 1, 'amount' => 100000,
                'debit' => 0, 'credit' => 100000, 'balance' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->service->analyzeReceived($m->id, '100000', 102);

        $this->assertDatabaseHas('merchant_risk_events', [
            'merchant_user_id' => $m->id,
            'event_type' => 'volume_spike',
        ]);
    }

    /** @test */
    public function record_transfer_out_accumulates()
    {
        $m = $this->makeMerchant();
        $this->service->recordTransferOut($m->id, '5000');
        $this->service->recordTransferOut($m->id, '3000');

        $risk = MerchantRiskProfile::where('merchant_user_id', $m->id)->first();
        $this->assertEquals('8000.0000', (string)$risk->total_transferred_out);
    }

    /** @test */
    public function risk_score_maps_to_correct_level()
    {
        $m = $this->makeMerchant('medium');
        $risk = MerchantRiskProfile::create([
            'merchant_user_id' => $m->id,
            'total_received_lifetime' => '1000000',
            'total_transferred_out' => '900000', // pass-through 90% = +35
        ]);

        $this->service->analyzeReceived($m->id, '1000', 999);
        $risk->refresh();
        // 35 نقطة → medium (20-39)
        $this->assertContains($risk->risk_level, ['medium', 'high', 'critical']);
    }

    /** @test */
    public function risk_events_are_immutable()
    {
        $m = $this->makeMerchant();
        MerchantRiskProfile::create([
            'merchant_user_id' => $m->id,
            'total_received_lifetime' => '1000000',
            'total_transferred_out' => '900000',
        ]);
        $this->service->analyzeReceived($m->id, '1000', 999);

        $event = \App\Models\MerchantRiskEvent::where('merchant_user_id', $m->id)->first();
        $this->expectException(\RuntimeException::class);
        $event->update(['risk_contribution' => 0]);
    }

    /** @test */
    public function dashboard_returns_risk_summary()
    {
        $m = $this->makeMerchant('large');
        MerchantRiskProfile::create([
            'merchant_user_id' => $m->id,
            'current_risk_score' => '45',
            'risk_level' => 'high',
            'total_received_lifetime' => '1000000',
            'total_transferred_out' => '500000',
        ]);

        $dashboard = $this->service->getRiskDashboard($m->id);
        $this->assertEquals('high', $dashboard['risk_level']);
        $this->assertEquals('large', $dashboard['tier']);
        $this->assertEquals(50.0, $dashboard['pass_through_ratio']);
    }

    /** @test */
    public function set_tier_to_micro_lowers_limits()
    {
        $m = $this->makeMerchant('large');
        $admin = User::factory()->create(['type' => 0]);
        $profile = $this->service->setTier($m, 'micro', $admin);
        $this->assertEquals('200000.0000', (string)$profile->daily_receive_limit);
    }

    /** @test */
    public function daily_limit_accumulates_across_payments()
    {
        $m = $this->makeMerchant('micro'); // daily 200000
        // سجّل استلام 180000 اليوم
        DB::table('transactions')->insert([
            'transaction_id' => 'ACC1',
            'user_id' => $m->id, 'from_user_id' => 50, 'to_user_id' => $m->id,
            'transaction_type' => 1, 'amount' => 180000,
            'debit' => 0, 'credit' => 180000, 'balance' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // محاولة 30000 إضافية (≤ حد العملية) → المجموع 210000 > 200000
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('حد الاستلام اليومي');
        $this->service->assertReceiveAllowed($m->id, '30000');
    }
}
