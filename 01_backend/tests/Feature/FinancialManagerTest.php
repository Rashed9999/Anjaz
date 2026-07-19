<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** AMIAL-FIN-MANAGER-001 — المدير المالي (الباقة المؤسسية). */
class FinancialManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private PosUser $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_FREE]);
        $u = User::factory()->create(['type' => 4, 'role' => 'pos', 'zone_code' => 'SOUTH']);
        $this->staff = PosUser::create(['user_id' => $u->id, 'merchant_user_id' => $this->merchant->id,
            'pos_number' => 'P1', 'display_name' => 'محاسب', 'is_active' => true, 'permissions' => ['sell']]);
    }

    /** @test غير المؤسسي ممنوع → 402. */
    public function below_enterprise_cannot_set_fin_manager(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/merchant/staff/{$this->staff->id}/financial-manager", ['enabled' => true])
            ->assertStatus(402);
    }

    /** @test المؤسسي: تعيين مدير مالي بصلاحيات مالية بحتة. */
    public function enterprise_sets_financial_manager(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_ENTERPRISE, $admin);
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $this->postJson("/api/v1/amial/merchant/staff/{$this->staff->id}/financial-manager", ['enabled' => true])
            ->assertOk()->assertJsonPath('meta.is_financial_manager', true);

        $perms = PosUser::find($this->staff->id)->permissions;
        $this->assertContains('financial_manager', $perms);
        $this->assertContains('profit_reports', $perms);
        $this->assertContains('audit_log', $perms);
        $this->assertNotContains('products', $perms); // مالي فقط — بلا مخزون

        $this->getJson('/api/v1/amial/merchant/staff')
            ->assertOk()->assertJsonPath('meta.staff.0.is_financial_manager', true);
    }
}
