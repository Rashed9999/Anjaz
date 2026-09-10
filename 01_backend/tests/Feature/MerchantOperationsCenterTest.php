<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use App\Support\Merchant\MerchantPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** مركز التشغيل يستعمل عقد HTTP العام، لا حالة شاشة أو خدمة داخلية. */
class MerchantOperationsCenterTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['role' => A::ROLE_MERCHANT, 'type' => 3]);
        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);
        app(VerticalBootstrapService::class)->ensureFor($owner);

        return $owner->refresh();
    }

    /** @test */
    public function owner_can_create_a_role_then_create_an_employee_assigned_to_that_role(): void
    {
        $owner = $this->owner();

        $roleResponse = $this->actingAs($owner, 'api')
            ->postJson('/api/v1/amial/merchant/operations-center/roles', [
                'name_ar' => 'كاشير اختبار',
                'permissions' => [P::STAFF_VIEW, P::SHIFT_OPEN, P::SHIFT_CLOSE],
            ])
            ->assertOk()
            ->assertJsonPath('code', 'ROLE_CREATED');

        $roleId = (int) $roleResponse->json('meta.role.id');

        $this->actingAs($owner, 'api')
            ->postJson('/api/v1/amial/merchant/staff', [
                'display_name' => 'كاشير الاختبار',
                'employee_code' => 'CAS-OPS-01',
                'password' => '1234',
                'merchant_role_id' => $roleId,
            ])
            ->assertCreated()
            ->assertJsonStructure(['meta' => ['id', 'employee_code', 'role_code']]);

        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/amial/merchant/operations-center/roles')
            ->assertOk()
            ->assertJsonFragment(['id' => $roleId, 'assignments_count' => 1]);

        $this->actingAs($owner, 'api')
            ->getJson('/api/v1/amial/merchant/operations-center')
            ->assertOk()
            ->assertJsonPath('meta.counts.active_employees', 1)
            ->assertJsonPath('meta.setup.has_role', true)
            ->assertJsonPath('meta.setup.has_employee', true);
    }

    /** @test */
    public function non_owner_cannot_open_the_operations_center(): void
    {
        $this->owner();
        $customer = User::factory()->create(['role' => A::ROLE_USER]);

        $this->actingAs($customer, 'api')
            ->getJson('/api/v1/amial/merchant/operations-center')
            ->assertForbidden()
            ->assertJsonPath('code', 'OWNER_ONLY');
    }
}
