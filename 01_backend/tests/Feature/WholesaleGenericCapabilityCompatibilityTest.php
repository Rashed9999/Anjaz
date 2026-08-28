<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCapability;
use App\Models\Merchant\MerchantRolePermission;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use App\Support\Merchant\MerchantPermissions as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** AMIAL-WHOLESALE-ACCESS-001 — الجملة لا تُرفض بأنماط retail القديمة. */
class WholesaleGenericCapabilityCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function explicit_wholesale_stock_permission_wins_over_generic_inventory_retail_pattern(): void
    {
        $owner = User::factory()->create([
            'type' => 3,
            'role' => A::ROLE_MERCHANT,
            'verification_level' => A::VERIFICATION_PREMIUM,
            'is_kyc_verified' => 1,
        ]);
        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_BUSINESS,
            'verification_status' => 'verified',
        ]);
        app(VerticalBootstrapService::class)->ensureFor($owner);

        $staff = User::factory()->create(['type' => 3, 'role' => 'pos']);
        PosUser::create([
            'merchant_user_id' => $owner->id,
            'user_id' => $staff->id,
            'pos_number' => 'W-STOCK-' . $staff->id,
            'is_active' => true,
            'permissions' => [],
        ]);

        $role = DB::table('merchant_roles')
            ->where('merchant_user_id', $owner->id)
            ->where('code', 'warehouse_staff')
            ->firstOrFail();

        DB::table('merchant_user_roles')->insert([
            'merchant_user_id' => $owner->id,
            'user_id' => $staff->id,
            'merchant_role_id' => $role->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        MerchantRolePermission::updateOrCreate([
            'merchant_role_id' => $role->id,
            'permission_code' => P::WHOLESALE_STOCK_ADJUST,
        ], [
            'scope_type' => 'merchant',
            'scope_id' => null,
            'max_amount' => null,
            'approval' => 'none',
        ]);

        $request = Request::create(
            '/api/v1/amial/merchant/wholesale/products/12/adjust-stock',
            'POST',
        );
        $request->setUserResolver(fn ($guard = null) => $staff);

        $response = app(EnsureCapability::class)->handle(
            $request,
            fn () => response('OK', 200),
            A::F_INVENTORY,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getContent());
    }
}
