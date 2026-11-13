<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Services\CustomerTurnoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-USAGE-LIMITS-BAR-001 — ما يحتاجه شريطُ حدود الاستخدام في «حسابي».
 *
 * التطبيق لا يحسب حدّاً بنفسه: يقرأ `tier.limits` و`tier.usage` من هذه النقطة. فالمحروس هنا أنّ الأرقام الثلاثة
 * التي يعرضها (الحركة اليوم، الحركة هذا الشهر، الرصيد) تصل بقيمها الحقيقية مقابل حدودها — وأنّ الرصيد جزءٌ منها،
 * فلا يعرض الشريطُ «٠» لحسابٍ فيه رصيد.
 */
class VerificationStatusUsageBarTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function the_status_carries_limits_usage_and_balance_for_the_usage_bar(): void
    {
        $u = User::factory()->create(['type' => CUSTOMER_TYPE, 'kyc_tier' => 2, 'is_kyc_verified' => 1]);
        EMoney::updateOrCreate(['user_id' => $u->id], ['current_balance' => '120000']);
        app(CustomerTurnoverService::class)->recordPosted(
            user: $u, amount: '80000', direction: 'in', sourceKey: 'usage-bar-test:1',
            transactionType: 'agent_deposit',
        );

        Passport::actingAs($u->fresh());
        $res = $this->getJson('/api/v1/amial/me/verification-status')->assertOk();

        $res->assertJsonPath('data.tier.current', 2);
        $this->assertSame(0, bccomp((string) $res->json('data.tier.limits.max_daily_total'), '250000', 4));
        $this->assertSame(0, bccomp((string) $res->json('data.tier.limits.max_monthly_total'), '250000', 4));
        $this->assertSame(0, bccomp((string) $res->json('data.tier.limits.max_balance'), '250000', 4));
        $this->assertSame(0, bccomp((string) $res->json('data.tier.usage.today'), '80000', 4), 'الحركة اليوم لا تصل الشريط');
        $this->assertSame(0, bccomp((string) $res->json('data.tier.usage.month'), '80000', 4));
        $this->assertSame(0, bccomp((string) $res->json('data.tier.usage.balance'), '120000', 4), 'الرصيد ناقص من استجابة الشريط');
    }
}
