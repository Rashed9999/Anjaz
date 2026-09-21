<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\EstablishesKycEvidence;
use Tests\TestCase;

/**
 * AMIAL-REG-ROLES — التسجيل الذاتي الحقيقي من التطبيق بالأدوار الثلاثة،
 * ووصول الحسابات الجديدة «قيد التحقق» إلى لوحة التحقق لاعتمادها.
 */
class RegistrationRolesTest extends TestCase
{
    use RefreshDatabase;
    use EstablishesKycEvidence;

    private function registerPayload(string $phone, array $extra = []): array
    {
        return array_merge([
            'f_name' => 'مسجّل',
            'father_name' => 'محمد',
            'grandfather_name' => 'علي',
            'family_name' => 'التجريبي',
            'l_name' => 'التجريبي',
            'gender' => 'male',
            'dial_country_code' => '+967',
            'phone' => $phone,
            'email' => 'registration-' . bin2hex(random_bytes(8)) . '@example.test',
            'password' => '1234',
            'identification_number' => '01-01-01-12345',
            'identification_type' => 'nid',
            'address' => 'عدن — المنصورة',
            'declaration_accepted' => '1',
        ], $extra);
    }

    /** @test */
    public function customer_self_registration_creates_active_tier_zero_account(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500001'))
            ->assertOk()
            ->assertJsonPath('verification_status', 'active_unverified')
            ->assertJsonPath('kyc_tier', 0);

        $user = User::where('phone', '967771500001')->first();
        $this->assertNotNull($user);
        $this->assertSame(CUSTOMER_TYPE, (int) $user->type);
        $this->assertSame(0, (int) $user->is_kyc_verified);
        $this->assertTrue(EMoney::where('user_id', $user->id)->exists());
    }

    /** @test */
    public function merchant_self_registration_creates_store_and_pending_profile(): void
    {
        $resp = $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500002', [
            'account_type' => 'merchant',
            'store_name' => 'متجر التسجيل الذاتي',
            'business_type' => 'pharmacy',
        ]))->assertOk()->json();

        $this->assertNotEmpty($resp['merchant_number']);

        $user = User::where('phone', '967771500002')->first();
        $this->assertSame(MERCHANT_TYPE, (int) $user->type);

        $store = Merchant::where('user_id', $user->id)->first();
        $this->assertSame('متجر التسجيل الذاتي', $store->store_name);
        $this->assertSame($resp['merchant_number'], $store->merchant_number);

        $profile = MerchantProfile::where('user_id', $user->id)->first();
        $this->assertSame('pending_review', $profile->verification_status);
        $this->assertSame('pharmacy', $profile->business_type);
    }

    /** @test */
    public function merchant_registration_requires_store_name(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500003', [
            'account_type' => 'merchant',
        ]))->assertStatus(403);
    }

    /** @test */
    public function agent_self_registration_gets_agent_number(): void
    {
        $resp = $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500004', [
            'account_type' => 'agent',
        ]))->assertOk()->json();

        $this->assertNotEmpty($resp['agent_number']);
        $user = User::where('phone', '967771500004')->first();
        $this->assertSame(AGENT_TYPE, (int) $user->type);
        $this->assertSame($resp['agent_number'], $user->agent_number);
    }

    /** @test */
    public function verification_panel_lists_pending_and_approval_makes_account_fully_real(): void
    {
        // تسجيل تاجر ذاتياً
        $resp = $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500005', [
            'account_type' => 'merchant',
            'store_name' => 'صيدلية النور',
            'business_type' => 'pharmacy',
        ]))->assertOk()->json();
        $merchant = User::where('phone', '967771500005')->first();

        // أدمن
        // AMIAL-ADMIN-DOORS-001 — لوحةُ التحقّق صارت خلف `approvals.decide`:
        // اعتمادُ حسابٍ جديدٍ قرارٌ لا قراءة.
        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'phone' => '967770009100']);
        app(\App\Services\PlatformRoleService::class)
            ->assign($admin, \App\Services\PlatformRoleService::ADMIN);
        $admin->refresh();

        // يظهر في لوحة التحقق
        $this->actingAs($admin, 'user')->get('/admin/amial/hub/verification')->assertOk();
        $list = $this->actingAs($admin, 'user')
            ->getJson('/admin/amial/hub/verification/list.json?filter=pending')
            ->assertOk()->json();
        $row = collect($list['data'])->firstWhere('id', $merchant->id);
        $this->assertNotNull($row, 'الحساب المسجَّل ذاتياً يظهر في لوحة التحقق');
        $this->assertSame('تاجر', $row['role']);
        $this->assertSame('صيدلية النور', $row['store_name']);

        // الاعتماد يوثّق الحساب وملف التاجر معاً
        // اعتمادٌ بلا وثيقة مرفوض بحقّ — يُبنى الدليلُ أوّلاً.
        $this->establishKycEvidence($merchant);
        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$merchant->id}/kyc", ['status' => 1])
            ->assertOk();
        $this->assertSame(1, (int) $merchant->fresh()->is_kyc_verified);
        $this->assertSame('verified',
            MerchantProfile::where('user_id', $merchant->id)->value('verification_status'));

        // وبعدها يدخل من التطبيق برقم تاجره ويستخدم الكاشير فعلاً
        Artisan::call('passport:install', ['--no-interaction' => true]);
        $this->postJson('/api/v1/auth/login', [
            'role' => 'merchant',
            'merchant_number' => $resp['merchant_number'],
            'phone' => '967771500005',
            'password' => '1234',
        ])->assertOk();

        \Laravel\Passport\Passport::actingAs($merchant->fresh(), [], 'api');

        // ══════════════════════════════════════════════════════════════
        // **والتاجرُ الموثَّقُ يفتح بابَ قطاعه — لا بابَ غيره.**
        //
        // كان هذا السطرُ يشترط أن يفتح **تاجرُ صيدليّةٍ كاشيرَ البقالة**
        // (`/merchant/cashier/products`)، وهو ما يمنعه عزلُ القطاعات
        // عمداً بـ`PHARMACY_CASHIER_ONLY`: «استخدم كاشير الصيدلية لتبقى
        // الوصفات والتشغيلات والصلاحية في الفاتورة».
        //
        // **والمقصودُ من الفحص باقٍ**: أنّ الحسابَ بعد الاعتماد يعمل
        // فعلاً. فيُقاس على بابه هو — **ويُشترط معه أنّ البابَ الآخرَ
        // مغلق**، فيصير السطرُ الذي كان يناقض العزلَ حارساً له.
        // ══════════════════════════════════════════════════════════════
        $this->getJson('/api/v1/amial/merchant/pharmacy')->assertOk();

        $this->getJson('/api/v1/amial/merchant/cashier/products')
            ->assertForbidden()
            ->assertJsonPath('code', 'PHARMACY_CASHIER_ONLY');
    }

    /** @test AMIAL-VERIFY-GATE — استجابة الدخول تحمل حالة التوثيق لتوجيه التطبيق. */
    public function login_response_exposes_verification_state(): void
    {
        Artisan::call('passport:install', ['--no-interaction' => true]);

        // عميل مسجَّل ذاتياً = نشط Tier 0 وليس حساباً محبوساً في المراجعة
        $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500007'))
            ->assertOk();
        $pending = User::where('phone', '967771500007')->first();

        $this->postJson('/api/v1/auth/login', [
            'role' => 'customer', 'phone' => '967771500007', 'password' => '1234',
        ])->assertOk()->assertJsonPath('meta.user.verification_state', 'active_unverified')
          ->assertJsonPath('meta.user.is_kyc_verified', 0)
          ->assertJsonPath('meta.user.kyc_tier', 0);

        // بعد اعتماده من الأدمن = موثّق
        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'phone' => '967770009200']);
        app(\App\Services\PlatformRoleService::class)
            ->assign($admin, \App\Services\PlatformRoleService::ADMIN);
        $admin->refresh();
        // العميل لا يقفز 0 → 2. نبني Tier 1 الحقيقي أولاً:
        // هاتف مثبت + إقامة معتمدة، ثم هوية Tier 2.
        $pending = $this->establishTierOnePrerequisite($pending);
        $this->establishKycEvidence($pending, 2, $admin);
        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$pending->id}/kyc", [
                'status' => 1,
                'target_tier' => 2,
            ])
            ->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'role' => 'customer', 'phone' => '967771500007', 'password' => '1234',
        ])->assertOk()->assertJsonPath('meta.user.verification_state', 'verified');
    }

    /** @test AMIAL-VERIFY-GATE — اعتماد الحساب يُنشئ إشعاراً داخل التطبيق. */
    public function approval_creates_in_app_notification(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500008'))
            ->assertOk();
        $user = User::where('phone', '967771500008')->first();

        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'phone' => '967770009201']);
        app(\App\Services\PlatformRoleService::class)
            ->assign($admin, \App\Services\PlatformRoleService::ADMIN);
        $admin->refresh();
        // نفس المسار المتسلسل: Tier 0 → Tier 1 → Tier 2.
        $user = $this->establishTierOnePrerequisite($user);
        $this->establishKycEvidence($user, 2, $admin);
        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$user->id}/kyc", [
                'status' => 1,
                'target_tier' => 2,
            ])
            ->assertOk();

        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $user->id,
            'type' => 'kyc_verification',
        ]);
    }

    /** @test */
    public function check_phone_obeys_the_explicit_pilot_otp_policy(): void
    {
        \Illuminate\Support\Facades\DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()],
        );

        // AMIAL-PILOT-PHONE-OTP-001 — في الإنتاج التجريبي الحالي اتُخذ قرار
        // صريح: كل هاتف عميل يستخدم 123456 مؤقتاً حتى ربط المزود الحقيقي.
        config([
            'amial.otp.pilot_customer_phone_enabled' => true,
            'amial.otp.pilot_customer_phone_code' => '123456',
        ]);

        $pilot = $this->postJson('/api/v1/customer/auth/check-phone', [
            'phone' => '967771500006',
        ])->assertOk()->json();

        $this->assertSame('active', $pilot['otp']);
        $this->assertTrue((bool) ($pilot['pilot_mode'] ?? false));
        $this->assertSame('123456', $pilot['demo_otp']);

        // وعند إطفاء السياسة المرحلية يعود العقد الأمني الحقيقي:
        // رقم غير Demo بلا قناة إيصال لا يُقال له كذباً «أرسلنا».
        config(['amial.otp.pilot_customer_phone_enabled' => false]);
        \App\Services\Otp\OtpPolicy::forget();
        \Illuminate\Support\Facades\DB::table('phone_verifications')
            ->where('phone', '967771500007')->delete();

        $real = $this->postJson('/api/v1/customer/auth/check-phone', [
            'phone' => '967771500007',
        ])->assertStatus(503)->json();

        $this->assertNull($real['demo_otp'] ?? null);
        $this->assertStringContainsString(
            'غير مهيّأة',
            (string) ($real['message'] ?? ''),
        );
        $this->assertDatabaseMissing('phone_verifications', [
            'phone' => '967771500007',
        ]);
    }
}
