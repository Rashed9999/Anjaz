<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\KycTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-TEST-FACTORY-CONTRACT-001
 *
 * يمنع أخطر نوع من «الخضرة الكاذبة»: أن يقول تعليق المصنع إن العميل
 * الافتراضي صالح مالياً بينما effectiveTier يراه Tier 0 أو Tier 1.
 *
 * default factory = عميل موثق وجاهز لاختبارات المال.
 * tierZero()       = عميل جديد يدخل التطبيق بصفر مالي.
 */
class CustomerFactoryContractTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function default_customer_factory_is_really_financially_ready(): void
    {
        $user = User::factory()->create();

        $this->assertSame(2, (int) $user->type);
        $this->assertSame(1, (int) $user->is_kyc_verified);
        $this->assertSame(2, (int) $user->kyc_tier);
        $this->assertSame(2, app(KycTierService::class)->effectiveTier($user));

        $limits = app(KycTierService::class)->assertFeatureAllowed($user, 'send_money');
        $this->assertSame(2, (int) $limits['tier']);
    }

    /** @test */
    public function tier_zero_factory_state_models_a_new_customer_not_a_broken_verified_customer(): void
    {
        $user = User::factory()->tierZero()->create();

        $this->assertSame(0, (int) $user->is_kyc_verified);
        $this->assertSame(0, (int) $user->kyc_tier);
        $this->assertSame(0, app(KycTierService::class)->effectiveTier($user));
        $this->assertSame('UNKNOWN', $user->zone_code);
        $this->assertNull($user->verified_residence_governorate);
        $this->assertNull($user->residence_verified_at);
    }
}
