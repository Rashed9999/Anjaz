<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MerchantProfile;
use App\Models\Permission;
use App\Models\PosUser;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-RBAC tests — للنظام الجديد (App\Models\Role, Permission).
 * مستقلّ تماماً عن RbacTest القديم (App\Models\Rbac\*).
 */
class PosRbacTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private PosUser $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->merchant = User::factory()->create(['type' => 3]);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_MERCHANT_PRO,
            'subscription_expires_at' => now()->addDays(30),
        ]);

        $cashierUser = User::factory()->create(['type' => 4]);
        $this->cashier = PosUser::create([
            'user_id' => $cashierUser->id,
            'merchant_user_id' => $this->merchant->id,
            'pos_number' => '1', 'display_name' => 'موظّف 1', 'is_active' => true,
        ]);
    }

    /** @test */
    public function seeder_creates_six_system_roles(): void
    {
        // AMIAL-OPERATOR-RBAC-001: صار جدول roles يحمل عائلتين:
        // أدوار موظّفي التاجر (هذه)، وأدوار فريق المنصّة (platform_*).
        // وكلتاهما is_system، فالعدّ المطلق صار يخلط بينهما ويسقط كلّما
        // أُضيف دورٌ إلى العائلة الأخرى — وهو سقوطٌ بلا معنى.
        //
        // فيُعدّ المقصود بالاسم: أدوار التاجر الستّة كما يعرّفها النموذج.
        $this->assertEqualsCanonicalizing(
            Role::ALL_SYSTEM_ROLES,
            Role::where('is_system', true)
                ->whereIn('code', Role::ALL_SYSTEM_ROLES)
                ->pluck('code')->all(),
        );

        $this->assertCount(6, Role::ALL_SYSTEM_ROLES);
    }

    /** @test */
    public function cashier_role_has_sales_but_not_delete(): void
    {
        $role = Role::where('code', Role::CASHIER)->first();
        $codes = $role->permissions()->pluck('code')->toArray();
        $this->assertContains('sales.create', $codes);
        $this->assertNotContains('products.delete', $codes);
    }

    /** @test */
    public function pos_user_can_be_assigned_role(): void
    {
        $cashierRole = Role::where('code', Role::CASHIER)->first();
        $this->cashier->roles()->attach($cashierRole->id);

        $this->assertTrue($this->cashier->hasPermission('sales.create'));
        $this->assertFalse($this->cashier->hasPermission('products.delete'));
    }

    /** @test */
    public function role_with_branch_scope_restricts_to_branch(): void
    {
        $b1 = Branch::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'فرع 1', 'is_active' => true, 'is_default' => true,
        ]);
        $b2 = Branch::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'فرع 2', 'is_active' => true,
        ]);

        $mgr = Role::where('code', Role::BRANCH_MANAGER)->first();
        $this->cashier->roles()->attach($mgr->id, ['branch_scope_id' => $b1->id]);

        $this->assertTrue($this->cashier->hasPermission('products.edit', $b1->id));
        $this->assertFalse($this->cashier->hasPermission('products.edit', $b2->id));
    }

    /** @test */
    public function unscoped_role_applies_everywhere(): void
    {
        $admin = Role::where('code', Role::SUPER_ADMIN)->first();
        $this->cashier->roles()->attach($admin->id, ['branch_scope_id' => null]);

        $this->assertTrue($this->cashier->hasPermission('sales.create', 1));
        $this->assertTrue($this->cashier->hasPermission('sales.create', null));
        $this->assertTrue($this->cashier->hasPermission('settings.business', 999));
    }

    /** @test */
    public function no_role_means_no_permissions(): void
    {
        $this->assertFalse($this->cashier->hasPermission('sales.create'));
        $this->assertFalse($this->cashier->hasPermission('anything'));
    }
}
