<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-ROLE-SYNC-001 — يثبت أن التاجر الذي أُنشئ بضبط `type` فقط (كما تفعل
 * كلّ نقاط الإنشاء الفعلية) يظهر في /me/access بدور 'merchant' ونشاطه الصحيح،
 * حتى يوجّهه HomeDispatcher للوحة قطاعه (محطة الوقود) بدل شاشة العميل.
 */
class RoleSyncFuelRoutingTest extends TestCase
{
    use RefreshDatabase;

    /** @test إنشاء تاجر بضبط type فقط → role يُملأ 'merchant' تلقائياً. */
    public function creating_a_merchant_with_only_type_auto_fills_role(): void
    {
        $u = new User();
        $u->f_name = 'ماجد';
        $u->phone = '967777200004';
        $u->password = bcrypt('Pass@2026');
        $u->type = MERCHANT_TYPE; // لا نضبط role إطلاقاً — كما في التسجيل/اللوحة/البذور
        $u->save();

        $this->assertSame(A::ROLE_MERCHANT, $u->fresh()->role);
    }

    /** @test /me/access لتاجر محطة وقود يعيد role=merchant و business_type=fuel. */
    public function fuel_merchant_access_exposes_merchant_role_and_fuel_type(): void
    {
        $u = new User();
        $u->f_name = 'ماجد';
        $u->phone = '967777200004';
        $u->password = bcrypt('Pass@2026');
        $u->type = MERCHANT_TYPE;
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'zone_code')) {
            $u->zone_code = 'SOUTH';
        }
        $u->save();

        MerchantProfile::create([
            'user_id' => $u->id,
            'business_type' => A::BIZ_FUEL,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_FREE,
        ]);

        Passport::actingAs($u->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/me/access')
            ->assertOk()
            ->assertJsonPath('meta.access.role', A::ROLE_MERCHANT)
            ->assertJsonPath('meta.access.business_type', A::BIZ_FUEL);
    }

    /** @test الوكيل والأدمن يُملأ دورهما تلقائياً أيضاً. */
    public function agent_and_admin_roles_are_auto_filled(): void
    {
        $agent = new User();
        $agent->f_name = 'وكيل';
        $agent->phone = '967777900009';
        $agent->password = bcrypt('x');
        $agent->type = AGENT_TYPE;
        $agent->save();
        $this->assertSame(A::ROLE_AGENT, $agent->fresh()->role);

        $admin = new User();
        $admin->f_name = 'مدير';
        $admin->phone = '967777000009';
        $admin->password = bcrypt('x');
        $admin->type = ADMIN_TYPE;
        $admin->save();
        $this->assertSame(A::ROLE_ADMIN, $admin->fresh()->role);
    }

    /** @test خطّاف المزامنة لا يدهس دوراً صريحاً (super_admin). */
    public function explicit_role_is_not_overwritten(): void
    {
        $u = new User();
        $u->f_name = 'سوبر';
        $u->phone = '967777000010';
        $u->password = bcrypt('x');
        $u->type = ADMIN_TYPE;
        $u->role = 'super_admin';
        $u->save();

        $this->assertSame('super_admin', $u->fresh()->role);
    }

    /** @test أمر التصحيح يُصلح حساباً قديماً role='user' رغم كونه تاجراً. */
    public function backfill_command_fixes_legacy_user_role(): void
    {
        $u = new User();
        $u->f_name = 'قديم';
        $u->phone = '967777200077';
        $u->password = bcrypt('x');
        $u->type = MERCHANT_TYPE;
        $u->save();

        // محاكاة الحالة القديمة: role='user' في القاعدة مباشرةً (يتجاوز الخطّاف)
        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $u->id)->update(['role' => A::ROLE_USER]);
        $this->assertSame(A::ROLE_USER, $u->fresh()->role);

        $this->artisan('amial:backfill-roles')->assertExitCode(0);

        $this->assertSame(A::ROLE_MERCHANT, $u->fresh()->role);
    }
}
