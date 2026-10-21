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
    public function customer_self_registration_creates_submitted_tier_zero_application_without_wallet(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500001'))
            ->assertOk()
            ->assertJsonPath('verification_status', 'submitted');

        $user = User::where('phone', '967771500001')->first();
        $this->assertNotNull($user);
        $this->assertSame(CUSTOMER_TYPE, (int) $user->type);
        $this->assertSame(0, (int) $user->is_kyc_verified);
        $this->assertFalse(EMoney::where('user_id', $user->id)->exists(), 'لا تُنشأ المحفظة قبل قرار موثّق ثم اعتماد الإدارة.');
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
        $this->assertSame('unverified', $profile->verification_status);
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
    public function merchant_registration_requires_an_explicit_business_type(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500013', [
            'account_type' => 'merchant',
            'store_name' => 'بسطة بلا قطاع',
        ]))->assertStatus(403);

        $this->assertDatabaseMissing('users', ['phone' => '967771500013']);
        $this->assertSame(0, MerchantProfile::count(),
            'تسجيل تاجر بلا قطاع أنشأ ملفاً ثم خمّنه كتجزئة.');
    }

    /** @test */
    public function merchant_registration_preserves_the_selected_vertical_for_all_six_built_in_sectors(): void
    {
        $matrix = [
            \App\Support\Access\AccessConstants::BIZ_QUICK_SALE => '771501101',
            \App\Support\Access\AccessConstants::BIZ_RETAIL => '771501102',
            \App\Support\Access\AccessConstants::BIZ_PHARMACY => '771501103',
            \App\Support\Access\AccessConstants::BIZ_WHOLESALE => '771501104',
            \App\Support\Access\AccessConstants::BIZ_RESTAURANT => '771501105',
            \App\Support\Access\AccessConstants::BIZ_FUEL => '771501106',
        ];

        foreach ($matrix as $sector => $phone) {
            $response = $this->postJson(
                '/api/v1/customer/auth/register',
                $this->registerPayload($phone, [
                    'account_type' => 'merchant',
                    'store_name' => 'منشأة ' . $sector,
                    'business_type' => $sector,
                ])
            )->assertOk()->json();

            $user = User::where('phone', '967' . $phone)->firstOrFail();
            $profile = MerchantProfile::where('user_id', $user->id)->firstOrFail();

            $this->assertSame($sector, $profile->business_type,
                "تغيّر القطاع المختار أثناء التسجيل: {$sector}");
            $this->assertSame('unverified', $profile->verification_status);
            $this->assertNotEmpty($response['merchant_number'] ?? null);

            $access = app(\App\Services\FeatureAccessService::class)->accessFor($user->fresh());
            $this->assertSame($sector, $access['business_type'] ?? null,
                "محرّك الاستحقاقات لم يقرأ قطاع {$sector} من ملف التاجر");

            if ($sector === \App\Support\Access\AccessConstants::BIZ_FUEL) {
                $this->assertDatabaseHas('fuel_stations', ['merchant_user_id' => $user->id]);
            }
            if ($sector === \App\Support\Access\AccessConstants::BIZ_PHARMACY) {
                $this->assertDatabaseHas('pharmacies', ['merchant_user_id' => $user->id]);
            }
            if ($sector === \App\Support\Access\AccessConstants::BIZ_WHOLESALE) {
                $this->assertDatabaseHas('wholesale_businesses', ['merchant_user_id' => $user->id]);
            }
        }
    }

    /** @test */
    public function agent_self_registration_is_closed_and_creates_no_account(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500004', [
            'account_type' => 'agent',
        ]))->assertForbidden()
            ->assertJsonPath('errors.0.code', 'AGENT_SELF_REGISTRATION_CLOSED');

        $this->assertDatabaseMissing('users', ['phone' => '967771500004']);
    }

    /** @test */
    public function merchant_registration_remains_unavailable_until_muwathaq_and_admin_final_approval(): void
    {
        $resp = $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500005', [
            'account_type' => 'merchant',
            'store_name' => 'صيدلية النور',
            'business_type' => 'pharmacy',
        ]))->assertOk()
            ->assertJsonPath('verification_status', 'submitted')
            ->json();

        $merchant = User::where('phone', '967771500005')->firstOrFail();
        $this->assertSame('unverified', MerchantProfile::where('user_id', $merchant->id)->value('verification_status'));

        Artisan::call('passport:install', ['--no-interaction' => true]);
        $this->postJson('/api/v1/auth/login', [
            'role' => 'merchant',
            'merchant_number' => $resp['merchant_number'],
            'phone' => '967771500005',
            'password' => '1234',
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'AUTH_FAILED')
            ->assertJsonPath('message', 'طلب فتح الحساب ما زال لدى موثّق للمراجعة.');
    }

    /** @test AMIAL-VERIFY-GATE — الطلب لا يصبح جلسة قبل قرار موثّق واعتماد الإدارة. */
    public function login_is_explicitly_blocked_while_muwathaq_review_is_pending(): void
    {
        Artisan::call('passport:install', ['--no-interaction' => true]);

        $this->postJson('/api/v1/customer/auth/register', $this->registerPayload('771500007'))
            ->assertOk()
            ->assertJsonPath('verification_status', 'submitted');

        $this->postJson('/api/v1/auth/login', [
            'role' => 'customer', 'phone' => '967771500007', 'password' => '1234',
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'AUTH_FAILED')
            ->assertJsonPath('message', 'طلب فتح الحساب ما زال لدى موثّق للمراجعة.');
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
