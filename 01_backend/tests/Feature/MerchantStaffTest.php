<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MerchantProfile;
use App\Models\Merchant\MerchantRole;
use App\Models\Merchant\MerchantUserRole;
use App\Models\Merchant\PosDeviceSession;
use App\Models\PosUser;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\Merchant\PosDeviceRegistrar;
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

        // **موظفٌ بلا دورٍ حديث يرى شاشةً قد تسمح له ثمّ يردُّه الخادم.**
        // حقلُ `permissions` القديم يبقى توافقاً مؤقتاً، أمّا إنفاذ
        // الصلاحيات القطاعية فيقرأ `merchant_user_roles`. لذلك لا يكفي
        // إنشاء صفّ POS: يجب أن يولد معه إسناد «كاشير» حقيقي للمنشأة.
        $pos = PosUser::findOrFail($staffId);
        $cashier = MerchantRole::where('merchant_user_id', $this->merchant->id)
            ->where('code', 'cashier')->firstOrFail();
        $this->assertTrue(MerchantUserRole::where([
            'merchant_user_id' => $this->merchant->id,
            'user_id' => $pos->user_id,
            'merchant_role_id' => $cashier->id,
            'is_active' => true,
        ])->exists(), 'أُنشئ الموظف بلا إسناد دور حديث؛ ستختلف الواجهة عن حارس الخادم');

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

    /** @test الجملة تستخدم دور POS مستقل افتراضياً بدل خلطه بمندوب المبيعات. */
    public function wholesale_can_create_pos_employee_without_explicit_role(): void
    {
        MerchantProfile::where('user_id', $this->merchant->id)
            ->update(['business_type' => A::BIZ_WHOLESALE]);

        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $response = $this->postJson('/api/v1/amial/merchant/staff', [
            'employee_code' => 'WH-POS-01',
            'display_name' => 'مندوب نقطة البيع',
            'password' => 'TempPass2026',
        ])->assertCreated()
            ->assertJsonPath('code', 'STAFF_CREATED')
            ->assertJsonPath('meta.role_code', 'pos_cashier');

        $pos = PosUser::findOrFail((int) $response->json('meta.id'));
        $role = MerchantRole::where('merchant_user_id', $this->merchant->id)
            ->where('code', 'pos_cashier')->firstOrFail();

        $this->assertTrue(MerchantUserRole::where([
            'merchant_user_id' => $this->merchant->id,
            'user_id' => $pos->user_id,
            'merchant_role_id' => $role->id,
            'is_active' => true,
        ])->exists());

        $staff = User::findOrFail($pos->user_id);
        $perm = app(\App\Services\Merchant\MerchantPermissionService::class);
        $this->assertTrue($perm->can($staff,
            \App\Support\Merchant\MerchantPermissions::WHOLESALE_INVOICE_CREATE));
        $this->assertTrue($perm->can($staff,
            \App\Support\Merchant\MerchantPermissions::WHOLESALE_COLLECTION_RECORD));
        $this->assertTrue($perm->can($staff,
            \App\Support\Merchant\MerchantPermissions::SHIFT_OPEN));
        $this->assertTrue($perm->can($staff,
            \App\Support\Merchant\MerchantPermissions::SHIFT_CLOSE));
        $this->assertFalse($perm->can($staff,
            \App\Support\Merchant\MerchantPermissions::WHOLESALE_INVOICE_VOID));
        $this->assertFalse($perm->can($staff,
            \App\Support\Merchant\MerchantPermissions::WHOLESALE_PRICE_SET));
    }

    public static function cashierSectors(): array
    {
        return [
            'بيع سريع' => [A::BIZ_QUICK_SALE],
            'تجزئة' => [A::BIZ_RETAIL],
            'وقود' => [A::BIZ_FUEL],
            'صيدلية' => [A::BIZ_PHARMACY],
            'مطعم' => [A::BIZ_RESTAURANT],
        ];
    }

    /**
     * كل قطاع غير الجملة يملك دور cashier حقيقياً ويُسند تلقائياً.
     *
     * @dataProvider cashierSectors
     */
    public function default_pos_role_exists_for_every_cashier_sector(string $businessType): void
    {
        MerchantProfile::where('user_id', $this->merchant->id)
            ->update(['business_type' => $businessType]);

        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $response = $this->postJson('/api/v1/amial/merchant/staff', [
            'employee_code' => 'POS-' . strtoupper(substr(md5($businessType), 0, 6)),
            'display_name' => 'كاشير القطاع',
            'password' => 'TempPass2026',
        ])->assertCreated()
            ->assertJsonPath('meta.role_code', 'cashier');

        $pos = PosUser::findOrFail((int) $response->json('meta.id'));
        $cashier = MerchantRole::where('merchant_user_id', $this->merchant->id)
            ->where('code', 'cashier')
            ->where('is_active', true)
            ->firstOrFail();

        $this->assertTrue(MerchantUserRole::where([
            'merchant_user_id' => $this->merchant->id,
            'user_id' => $pos->user_id,
            'merchant_role_id' => $cashier->id,
            'is_active' => true,
        ])->exists());
    }

    /** @test كاشير الوقود يبيع، لكنه لا يفتح أو يغلق وردية المحطة التي يديرها المشرف. */
    public function fuel_cashier_api_cannot_promote_itself_to_shift_supervisor(): void
    {
        MerchantProfile::where('user_id', $this->merchant->id)
            ->update(['business_type' => A::BIZ_FUEL]);

        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $response = $this->postJson('/api/v1/amial/merchant/staff', [
            'employee_code' => 'FUEL-POS-01',
            'display_name' => 'كاشير المضخة',
            'password' => 'TempPass2026',
        ])->assertCreated();

        $pos = PosUser::findOrFail((int) $response->json('meta.id'));
        $staff = User::findOrFail($pos->user_id);

        // هذا الاختبار يقيس RBAC نفسه؛ ربط الجهاز له حرّاس مستقلة.
        config(['amial.pos_devices.enforce_session_binding' => false]);
        Passport::actingAs($staff, [], 'api');

        $this->postJson('/api/v1/amial/merchant/fuel/shifts/open', [
            'opening_cash' => 0,
        ])->assertForbidden()
          ->assertJsonPath('code', 'FORBIDDEN');

        // البيع نفسه من صلاحيات الكاشير. الطلب الناقص يصل إلى
        // validation (422) ولا يتوقف عند حارس الصلاحية (403).
        $this->postJson('/api/v1/amial/merchant/fuel/sales', [])
            ->assertStatus(422);

        // وردية الوقود محطة-كاملة. إن فتحها المالك/المشرف فلا تكفي منحة
        // SHIFT_CLOSE القديمة ذات scope=own كي يغلقها كاشير آخر.
        $station = app(\App\Services\FuelStationService::class)
            ->getOrCreateStation($this->merchant->fresh());
        $shift = app(\App\Services\FuelShiftService::class)
            ->openShift($station, $this->merchant->fresh(), '0');

        Passport::actingAs($staff, [], 'api');
        $this->postJson("/api/v1/amial/merchant/fuel/shifts/{$shift->id}/close", [
            'actual_cash' => 0,
        ])->assertForbidden()
          ->assertJsonPath('code', 'FORBIDDEN');
    }

    /** @test اختيار المنشأة الرئيسية صراحةً لا يتحول خفيةً إلى الفرع الافتراضي. */
    public function explicit_main_establishment_keeps_new_pos_staff_unassigned_to_a_branch(): void
    {
        $default = Branch::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'الفرع الافتراضي',
            'code' => 'DEFAULT',
            'is_active' => true,
            'is_default' => true,
        ]);

        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $explicitMain = $this->postJson('/api/v1/amial/merchant/staff', [
            'employee_code' => 'MAIN-POS',
            'display_name' => 'كاشير المنشأة',
            'password' => 'TempPass2026',
            'branch_id' => null,
        ])->assertCreated();

        $this->assertNull(
            PosUser::findOrFail((int) $explicitMain->json('meta.id'))->branch_id,
            'اختيار المنشأة الرئيسية تحوّل إلى الفرع الافتراضي خفيةً',
        );

        $legacyOmitted = $this->postJson('/api/v1/amial/merchant/staff', [
            'employee_code' => 'AUTO-BRANCH',
            'display_name' => 'كاشير تلقائي',
            'password' => 'TempPass2026',
        ])->assertCreated();

        $this->assertSame(
            $default->id,
            (int) PosUser::findOrFail((int) $legacyOmitted->json('meta.id'))->branch_id,
            'حذف branch_id كسر سلوك التوافق الذي يختار الفرع الافتراضي',
        );
    }

    /** @test تغيير فرع الموظف عبر الـAPI يقطع جلسة الجهاز القديمة. */
    public function staff_branch_assignment_route_updates_scope_and_ends_live_device_session(): void
    {
        $from = Branch::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'فرع أ',
            'code' => 'BR-A',
            'is_active' => true,
            'is_default' => true,
        ]);
        $to = Branch::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'فرع ب',
            'code' => 'BR-B',
            'is_active' => true,
            'is_default' => false,
        ]);

        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $created = $this->postJson('/api/v1/amial/merchant/staff', [
            'employee_code' => 'MOVE-POS',
            'display_name' => 'موظف متنقل',
            'password' => 'TempPass2026',
            'branch_id' => $from->id,
        ])->assertCreated();

        $pos = PosUser::findOrFail((int) $created->json('meta.id'));
        $device = app(PosDeviceRegistrar::class)
            ->register($this->merchant, 'move-device', ['branch_id' => $from->id])['device'];

        PosDeviceSession::create([
            'access_token_id' => 'move-session-token',
            'pos_device_id' => $device->id,
            'merchant_user_id' => $this->merchant->id,
            'actor_user_id' => $pos->user_id,
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->putJson('/api/v1/amial/merchant/staff/'.$pos->id.'/branch', [
            'branch_id' => $to->id,
        ])->assertOk()
          ->assertJsonPath('meta.branch_id', $to->id)
          ->assertJsonPath('meta.ended_device_sessions', 1);

        $this->assertSame($to->id, (int) $pos->fresh()->branch_id);
        $this->assertNotNull(
            PosDeviceSession::where('access_token_id', 'move-session-token')->value('ended_at'),
            'نُقل الموظف إلى فرع جديد وبقيت جلسة صندوق الفرع القديم حيّة',
        );
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

    /** @test تعطيل الموظف يزيل عضويته الحديثة وحده ويحفظ عضوية زميله. */
    public function disabling_staff_deactivates_only_its_modern_role_assignments(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $firstId = $this->postJson('/api/v1/amial/merchant/staff', [
            'employee_code' => 'EMP-01', 'display_name' => 'الأول', 'password' => 'secret1',
        ])->assertCreated()->json('meta.id');
        $secondId = $this->postJson('/api/v1/amial/merchant/staff', [
            'employee_code' => 'EMP-02', 'display_name' => 'الثاني', 'password' => 'secret2',
        ])->assertCreated()->json('meta.id');

        $first = PosUser::findOrFail($firstId);
        $second = PosUser::findOrFail($secondId);
        $inactiveRole = MerchantRole::create([
            'merchant_user_id' => $this->merchant->id,
            'code' => 'explicitly-inactive', 'name_ar' => 'دور غير مسند حالياً',
            'is_active' => true,
        ]);
        $inactiveAssignment = MerchantUserRole::create([
            'merchant_user_id' => $this->merchant->id, 'user_id' => $first->user_id,
            'merchant_role_id' => $inactiveRole->id, 'is_active' => false,
        ]);

        $this->postJson("/api/v1/amial/merchant/staff/{$firstId}/toggle")
            ->assertOk()->assertJsonPath('meta.is_active', false);

        $this->assertFalse(MerchantUserRole::where('merchant_user_id', $this->merchant->id)
            ->where('user_id', $first->user_id)->where('is_active', true)->exists(),
            'الموظف المعطّل بقيت له عضوية حديثة نافذة');
        $this->assertTrue(MerchantUserRole::where('merchant_user_id', $this->merchant->id)
            ->where('user_id', $second->user_id)->where('is_active', true)->exists(),
            'تعطيل موظف واحد عطّل عضوية زميله');

        $this->postJson("/api/v1/amial/merchant/staff/{$firstId}/toggle")
            ->assertOk()->assertJsonPath('meta.is_active', true);
        $this->assertTrue(MerchantUserRole::where('merchant_user_id', $this->merchant->id)
            ->where('user_id', $first->user_id)->where('is_active', true)->exists());
        $this->assertFalse((bool) $inactiveAssignment->fresh()->is_active,
            'إعادة تفعيل الموظف أعادت إسناد دور كان معطلاً قبل إيقافه');
        $this->assertSame(0, MerchantUserRole::where('merchant_user_id', $this->merchant->id)
            ->where('user_id', $first->user_id)->where('suspended_by_staff_toggle', true)->count());
    }
}
