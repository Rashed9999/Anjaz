<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\RestaurantOrder;
use App\Models\User;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** حساب العامل مستقل عن جهاز POS، وسجل الطلب يحفظ صاحبه. */
class RestaurantRoleStaffAuditTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        $merchant = User::factory()->create(['type' => 3, 'role' => A::ROLE_MERCHANT]);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_RESTAURANT,
            'subscription_plan' => A::PLAN_BUSINESS,
            'verification_status' => 'verified',
        ]);
        app(VerticalBootstrapService::class)->ensureFor($merchant);

        return $merchant->refresh();
    }

    private function roleStaff(User $merchant, string $roleCode): User
    {
        $staff = User::factory()->create(['type' => 3, 'role' => 'merchant_staff']);
        $role = DB::table('merchant_roles')
            ->where('merchant_user_id', $merchant->id)
            ->where('code', $roleCode)
            ->first();
        $this->assertNotNull($role, "دور المطعم {$roleCode} غير موجود");

        DB::table('merchant_user_roles')->insert([
            'merchant_user_id' => $merchant->id,
            'user_id' => $staff->id,
            'merchant_role_id' => $role->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $staff->refresh();
    }

    /** @test */
    public function non_pos_waiter_and_cashier_are_recorded_on_their_restaurant_order(): void
    {
        $merchant = $this->merchant();
        $waiter = $this->roleStaff($merchant, 'waiter');
        $cashier = $this->roleStaff($merchant, 'cashier');

        $opened = $this->actingAs($waiter, 'api')
            ->postJson('/api/v1/amial/merchant/restaurant/orders', [
                'items' => [['name' => 'وجبة اختبار', 'qty' => '1', 'price' => '120']],
            ])
            ->assertCreated()
            ->assertJsonPath('code', 'CREATED');
        $orderId = (int) $opened->json('meta.order.id');

        $order = RestaurantOrder::findOrFail($orderId);
        $this->assertSame($waiter->id, (int) $order->opened_by,
            'سجل فتح الطلب نسبه إلى جهاز POS أو المالك بدلاً من النادل');

        $this->actingAs($cashier, 'api')
            ->postJson("/api/v1/amial/merchant/restaurant/orders/{$orderId}/close", [
                'payment_method' => 'cash',
            ])
            ->assertOk()
            ->assertJsonPath('code', 'CLOSED');

        $order->refresh();
        $this->assertSame($cashier->id, (int) $order->closed_by_user_id,
            'سجل الإغلاق فقد الكاشير المنفذ');
        $this->assertSame($merchant->id, (int) $order->merchant_user_id,
            'طلب الموظف خرج من المنشأة المالكة');
    }
}
