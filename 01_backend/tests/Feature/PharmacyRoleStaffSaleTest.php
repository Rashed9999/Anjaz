<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PharmacySale;
use App\Models\User;
use App\Services\PharmacyService;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** الموظف ذو الدور ليس جهاز POS، ويظل أثره التدقيقي محفوظاً. */
class PharmacyRoleStaffSaleTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        $merchant = User::factory()->create(['type' => 3, 'role' => A::ROLE_MERCHANT]);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_PHARMACY,
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
        $this->assertNotNull($role, "دور الصيدلية {$roleCode} غير موجود");

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
    public function a_role_staff_member_can_sell_without_a_pos_account_and_is_audited(): void
    {
        $merchant = $this->merchant();
        $staff = $this->roleStaff($merchant, 'pharmacy_technician');
        $service = app(PharmacyService::class);
        $pharmacy = $service->getOrCreatePharmacy($merchant);
        $product = $service->addProduct($pharmacy, [
            'trade_name' => 'دواء اختبار الموظف', 'sale_price' => '250',
        ]);
        $service->addBatch($product, [
            'batch_number' => 'ROLE-STAFF-001',
            'expiry_date' => now()->addMonth()->toDateString(),
            'quantity_received' => '5',
        ]);

        $this->actingAs($staff, 'api')
            ->postJson('/api/v1/amial/merchant/pharmacy/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('code', 'SALE_RECORDED');

        $sale = PharmacySale::query()->sole();
        $this->assertNull($sale->pos_user_id, 'الموظف المستقل لا يجب أن يتحول إلى جهاز POS');
        $this->assertSame($staff->id, (int) $sale->created_by_user_id,
            'فُقد منفذ البيع من أثر التدقيق');
        $this->assertSame($merchant->id, (int) $sale->merchant_user_id,
            'بيع الموظف خرج من محفظة المنشأة');
    }
}
