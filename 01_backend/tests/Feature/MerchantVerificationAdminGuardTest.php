<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MerchantVerificationRequest;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The customer phase preserves merchant records while closing reviewer access. */
class MerchantVerificationAdminGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $permissions): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'is_active' => 1,
        ]);

        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');

        if ($roleId) {
            DB::table('admin_user_roles')->insert([
                'user_id' => $u->id, 'role_id' => $roleId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $u;
    }

    /** موظّفٌ بلا صلاحيّاتِ المنصّة إطلاقاً. */
    private function stranger(): User
    {
        return User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'staff', 'is_active' => 1,
        ]);
    }

    private function merchant(): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 0,
            'zone_code' => 'SOUTH', 'residence_governorate' => 'عدن',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'tier' => 'small',
            'verification_status' => 'pending_review', 'business_type' => A::BIZ_RETAIL,
            'daily_receive_limit' => '5000000', 'single_receive_limit' => '1000000',
        ]);

        $m = new Merchant();
        $m->user_id = $u->id;
        $m->store_name = 'متجر الاختبار';
        $m->merchant_number = 'AM-'.Str::upper(Str::random(6));
        $m->save();

        return $u;
    }

    private function request(User $merchant): MerchantVerificationRequest
    {
        return MerchantVerificationRequest::create([
            'request_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'business_name' => 'متجر النور للتجزئة',
            'commercial_register_number' => 'CR-4455',
            'city' => 'عدن',
            'contact_phone' => '967771234567',
            'id_card_front_path' => 'merchant_verifications/test/id-front.jpg',
            'id_card_back_path' => 'merchant_verifications/test/id-back.jpg',
            'commercial_register_path' => 'merchant_verifications/test/commercial-register.jpg',
            'store_photo_path' => 'merchant_verifications/test/store.jpg',
            'status' => 'pending_review',
            'zone_code' => 'SOUTH',
        ]);
    }

    /** @test */
    public function the_three_admin_actions_finally_have_a_door(): void
    {
        // ① قبلَ هذا التغيير: `route()` ترمي لأنّ المسارَ لا وجودَ له.
        foreach ([
            'admin.amial.merchants.verification.page',
            'admin.amial.merchants.verification.list',
            'admin.amial.merchants.verification.approve',
            'admin.amial.merchants.verification.reject',
            'admin.amial.merchants.verification.resubmit',
            'admin.amial.merchants.verification.document',
        ] as $name) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($name),
                "المسار «{$name}» غير مسجَّل — والفعلُ مبنيٌّ في المتحكّم "
                .'بلا باب، فيبقى التاجرُ عالقاً بلا نهاية');
        }
    }

    /** @test */
    public function frozen_merchant_files_are_hidden_even_from_platform_reviewers(): void
    {
        $merchant = $this->merchant();
        $request = $this->request($merchant);

        $admin = $this->admin([]);
        $this->actingAs($admin, 'user')
            ->getJson(route('admin.amial.kyc.center.account', $merchant->id))->assertNotFound();

        foreach (['hub.merchants', 'hub.staff', 'hub.subscriptions', 'entitlements.page', 'verticals.page',
                  'retail.page', 'fuel.page', 'catalog.page', 'invoices.page'] as $name) {
            $this->get(route('admin.amial.'.$name))->assertNotFound();
        }
        $this->postJson(route('admin.amial.merchant-center.freeze', $merchant->id), [
            'reason' => 'اختبار تجميد واجهات التجار',
        ])->assertNotFound();
        $this->assertSame((int) $merchant->is_active, (int) $merchant->fresh()->is_active);

        foreach ([$admin, $this->stranger()] as $actor) {
            $this->actingAs($actor, 'user');
            $this->getJson(route('admin.amial.merchants.verification.list'))->assertNotFound();
            $this->get(route('admin.amial.merchants.verification.document', [
                'id' => $request->id, 'type' => 'commercial_register',
            ]))->assertNotFound();
            foreach (['approve', 'reject', 'resubmit'] as $action) {
                $this->postJson(route('admin.amial.merchants.verification.'.$action, $request->id), [
                    'reason' => 'طلب مراجعة سجل تجاري',
                ])->assertNotFound();
            }
        }

        $this->assertSame('pending_review', $request->fresh()->status);
        $this->assertSame('merchant_verifications/test/commercial-register.jpg', $request->fresh()->commercial_register_path);
        $this->assertSame(0, (int) $merchant->fresh()->is_kyc_verified);
    }
}
