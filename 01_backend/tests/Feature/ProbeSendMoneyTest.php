<?php
namespace Tests\Feature;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProbeSendMoneyTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function probe(): void
    {
        $owner = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
            'last_active_at' => now()->subMinutes(45),
        ]);
        MerchantProfile::create([
            'user_id' => $owner->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => 'retail',
            'subscription_plan' => A::PLAN_BUSINESS,
            'single_receive_limit' => '5000000', 'daily_receive_limit' => '50000000',
        ]);

        // ① التاجرُ يعمل في شاشاته: أيُنعش هذا آخرَ نشاطه؟
        $this->actingAs($owner, 'api')->getJson('/api/v1/amial/merchant/daily-stats');
        fwrite(STDERR, "بعد نداءِ شاشةِ التاجر، آخرُ نشاط: "
            . $owner->fresh()->last_active_at . "  (الآن: " . now() . ")\n");

        // ② ثمّ يضغط «تحويل» فتُنادى نقطةُ العميل
        $r = $this->actingAs($owner, 'api')->getJson('/api/v1/customer/get-customer');
        fwrite(STDERR, "نداءُ «تحويل» → " . $r->status() . " "
            . json_encode($r->json(), JSON_UNESCAPED_UNICODE) . "\n");

        $this->assertTrue(true);
    }
}
