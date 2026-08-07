<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AMIAL-REG-ROLES — التسجيل الذاتي الحقيقي من التطبيق بالأدوار الثلاثة،
 * ووصول الحسابات الجديدة «قيد التحقق» إلى لوحة التحقق لاعتمادها.
 */
class RegistrationRolesTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(string $phone, array $extra = []): array
    {
        return array_merge([
            'f_name' => 'مسجّل',
            'l_name' => 'ذاتياً من التطبيق',
            'gender' => 'male',
            'dial_country_code' => '+967',
            'phone' => $phone,
            'password' => '1234',
            'identification_number' => '01-01-01-12345',
            'identification_type' => 'nid',
            'address' => 'عدن — المنصورة',
            'declaration_accepted' => '1',
        ], $extra);
    }

    /** @test */
    public function customer_self_registration_creates_pending_account(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500001'))
            ->assertOk()
            ->assertJsonPath('verification_status', 'pending_review');

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
        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'phone' => '967770009100']);

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
        $this->getJson('/api/v1/amial/merchant/cashier/products')->assertOk();
    }

    /** @test AMIAL-VERIFY-GATE — استجابة الدخول تحمل حالة التوثيق لتوجيه التطبيق. */
    public function login_response_exposes_verification_state(): void
    {
        Artisan::call('passport:install', ['--no-interaction' => true]);

        // عميل مسجَّل ذاتياً = قيد المراجعة
        $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500007'))
            ->assertOk();
        $pending = User::where('phone', '967771500007')->first();

        $this->postJson('/api/v1/auth/login', [
            'role' => 'customer', 'phone' => '967771500007', 'password' => '1234',
        ])->assertOk()->assertJsonPath('meta.user.verification_state', 'pending_review')
          ->assertJsonPath('meta.user.is_kyc_verified', 0);

        // بعد اعتماده من الأدمن = موثّق
        $admin = User::factory()->create(['type' => ADMIN_TYPE, 'phone' => '967770009200']);
        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$pending->id}/kyc", ['status' => 1])
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
        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/hub/users/{$user->id}/kyc", ['status' => 1])
            ->assertOk();

        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $user->id,
            'type' => 'kyc_verification',
        ]);
    }

    /** @test */
    public function check_phone_returns_demo_otp_hint_when_not_live(): void
    {
        // في بيئة الاختبار APP_MODE != live → الرمز يُفصح عنه ليُعبّأ تلقائياً
        \Illuminate\Support\Facades\DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'], ['value' => '1', 'created_at' => now(), 'updated_at' => now()]);

        // AMIAL-OTP-SPLIT-001: الإفصاح لأرقام العرض وحدها.
        $resp = $this->postJson('/api/v1/customer/auth/check-phone', ['phone' => '967777100001'])
            ->assertOk()->json();

        $this->assertSame('active', $resp['otp']);
        $this->assertNotEmpty($resp['demo_otp']);

        // **والنفي الحاسم:** رقمٌ حقيقيٌّ لا يُفصح عن رمزه.
        $real = $this->postJson('/api/v1/customer/auth/check-phone', ['phone' => '967771500006'])
            ->assertOk()->json();

        $this->assertNull($real['demo_otp'] ?? null,
            'أُفصح عن رمزِ رقمٍ حقيقيّ — فبطل التحقّق من أصله');
    }
}
