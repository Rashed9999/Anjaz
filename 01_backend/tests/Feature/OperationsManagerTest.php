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

/**
 * AMIAL-OPS-MANAGER-001 — مدير العمليات (الباقة المؤسسية): يمنح موظفاً
 * صلاحيات إشرافية واسعة، ويُلغى بإرجاعه لصلاحيات أساسية.
 */
class OperationsManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private PosUser $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_FREE,
        ]);
        $staffUser = User::factory()->create(['type' => 4, 'role' => 'pos', 'zone_code' => 'SOUTH']);
        $this->staff = PosUser::create([
            'user_id' => $staffUser->id, 'merchant_user_id' => $this->merchant->id,
            'pos_number' => 'POS-1', 'display_name' => 'سالم', 'is_active' => true, 'permissions' => ['sell'],
        ]);
    }

    private function upgrade(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_ENTERPRISE, $admin);
    }

    /** @test الباقة الأدنى لا تستطيع تعيين مدير عمليات → 402. */
    public function below_enterprise_cannot_set_ops_manager(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->postJson("/api/v1/amial/merchant/staff/{$this->staff->id}/operations-manager", ['enabled' => true])
            ->assertStatus(402);
    }

    /** @test المؤسسية: تعيين ثم إلغاء مدير العمليات. */
    public function enterprise_can_set_and_unset_ops_manager(): void
    {
        $this->upgrade();
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        // تعيين
        $this->postJson("/api/v1/amial/merchant/staff/{$this->staff->id}/operations-manager", ['enabled' => true])
            ->assertOk()->assertJsonPath('meta.is_operations_manager', true);

        $perms = PosUser::find($this->staff->id)->permissions;
        $this->assertContains('operations_manager', $perms);
        $this->assertContains('reports', $perms);
        $this->assertContains('employees', $perms);

        // يظهر في القائمة كمدير عمليات
        $this->getJson('/api/v1/amial/merchant/staff')
            ->assertOk()->assertJsonPath('meta.staff.0.is_operations_manager', true);

        // إلغاء
        $this->postJson("/api/v1/amial/merchant/staff/{$this->staff->id}/operations-manager", ['enabled' => false])
            ->assertOk()->assertJsonPath('meta.is_operations_manager', false);
        $this->assertNotContains('operations_manager', PosUser::find($this->staff->id)->permissions);
    }
}
