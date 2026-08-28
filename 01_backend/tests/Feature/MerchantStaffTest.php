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
 * AMIAL-MERCHANT-STAFF-001 — إدارة موظفي نقاط البيع من تطبيق التاجر،
 * محميّة بميزة «الموظفين» (باقة الأعمال فأعلى).
 */
class MerchantStaffTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create([
            'type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_FREE,
        ]);
    }

    /** @test المجاني لا يملك ميزة الموظفين → 402. */
    public function free_plan_cannot_manage_staff(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->postJson('/api/v1/amial/merchant/staff', [
            'pos_number' => '1', 'display_name' => 'موظّف', 'password' => '1234',
        ])->assertStatus(402);
    }

    /** @test بعد الترقية للأعمال: إنشاء موظف + ظهوره في القائمة + تعطيله. */
    public function business_plan_can_create_list_and_toggle_staff(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);

        Passport::actingAs($this->merchant->fresh(), [], 'api');

        // إنشاء
        $create = $this->postJson('/api/v1/amial/merchant/staff', [
            'pos_number' => 'POS-01', 'display_name' => 'أحمد', 'password' => 'secret1',
            'permissions' => ['sell', 'refund'],
        ])->assertStatus(201);
        $staffId = $create->json('meta.id');

        $this->assertDatabaseHas('pos_users', [
            'merchant_user_id' => $this->merchant->id,
            'pos_number' => 'POS-01', 'display_name' => 'أحمد', 'is_active' => true,
        ]);

        // ══════════════════════════════════════════════════════════════
        // **والعقدُ الجديد اسمُه `employee_code`.**
        //
        // فُصل حسابُ الموظّف عن جهاز نقطة البيع، فصار الردُّ يقول
        // `employee_code` — والعمودُ في القاعدة `pos_number` كما هو لئلّا
        // تنكسر المبيعاتُ القديمة. وكان هذا الفحصُ يقرأ الاسمَ القديم من
        // الردّ فيجد `null`.
        //
        // **والمدخلُ يُجرَّب من بابيه**: الإنشاءُ أعلاه أُرسل بـ`pos_number`
        // ونجح، وهو عهدُ التوافق الخلفيّ. (القاعدة الرابعة.)
        // ══════════════════════════════════════════════════════════════
        $this->getJson('/api/v1/amial/merchant/staff')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.staff.0.employee_code', 'POS-01');

        // تعطيل
        $this->postJson("/api/v1/amial/merchant/staff/{$staffId}/toggle")
            ->assertOk()
            ->assertJsonPath('meta.is_active', false);

        $this->assertFalse((bool) PosUser::find($staffId)->is_active);
    }

    /** @test رقم نقطة بيع مكرّر يُرفض. */
    public function duplicate_pos_number_is_rejected(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $body = ['pos_number' => 'X1', 'display_name' => 'أ', 'password' => '1234'];
        $this->postJson('/api/v1/amial/merchant/staff', $body)->assertStatus(201);
        $this->postJson('/api/v1/amial/merchant/staff', $body)->assertStatus(422);
    }
}
