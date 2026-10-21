<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Auth\MerchantAccountOpeningController;
use App\Models\Merchant;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use App\Services\Muwathaq\MuwathaqRegistrationContractService;
use App\Services\Otp\EmailOtpService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MerchantWebRegistrationJourneyTest extends TestCase
{
    use RefreshDatabase;

    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'amial_otp.registration_channel' => 'email',
            'amial_otp.password_reset_channel' => 'email',
            'amial_otp.resend.api_key' => 'test-provider-key',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.resend.com/emails' => function ($request) {
            $this->messages[] = $request;
            return Http::response(['id' => 'merchant-mail-' . count($this->messages)], 200);
        }]);

        // لا نختبر اتصال موثّق هنا؛ نختبر أن بوابة التاجر لا تعود إلى
        // معالج KYC القديم وأنها تلتزم بعقد موثّق صحيح.
        app()->instance(MuwathaqRegistrationContractService::class, new class extends MuwathaqRegistrationContractService {
            public function resolve(string $accountType, ?string $gender = null, ?string $businessType = null): array
            {
                return [
                    'policy_version' => 'merchant-v1-test',
                    'subject_kind' => 'merchant',
                    'verification_path' => 'merchant_sector',
                    'registration_fields' => ['business_name', 'business_type', 'declared_owner_name', 'phone', 'verified_email', 'login_password', 'transaction_pin', 'terms_acceptance'],
                    'review_requirements' => [
                        'verified_owner_identity' => 'توثيق هوية مالك المنشأة',
                        'verified_business_requirements' => 'توثيق بيانات المنشأة ونشاطها',
                        'verified_residence' => 'تأكيد موقع النشاط أو العنوان التشغيلي',
                    ],
                    'sector' => ['code' => $businessType, 'purpose' => 'dashboard_and_operational_capabilities'],
                ];
            }
        });
    }

    private function lastCode(): string
    {
        $message = $this->messages[array_key_last($this->messages)];
        $this->assertSame(1, preg_match('/(?<!\d)\d{6}(?!\d)/u', $message['text'], $match));

        return $match[0];
    }

    #[Test]
    public function merchant_portal_uses_muwathaq_contract_and_never_collects_legacy_documents(): void
    {
        $this->get('/merchant/register')->assertOk()
            ->assertSee('إنشاء حساب منشأة')
            ->assertSee('بيع سريع')
            ->assertSee('تجزئة')
            ->assertSee('صيدلية')
            ->assertSee('تجارة جملة')
            ->assertSee('مطعم')
            ->assertSee('محطة وقود')
            ->assertSee('/api/v1/auth/account-opening/contract', false)
            ->assertSee('/api/v1/auth/register/merchant', false)
            ->assertDontSee('وجه الهوية')
            ->assertDontSee('الصورة الشخصية');


        $this->get('/merchant/opening')->assertOk()
            ->assertSee('استكمال توثيق المنشأة')
            ->assertSee('/api/v1/auth/account-opening/', false)
            ->assertSee('owner_identity')
            ->assertSee('business_registration')
            ->assertSee('operating_address');

        $this->get('/merchant/login')->assertOk()->assertSee('استكمال طلب منشأة قائم');
        $this->get('/merchant/recover')->assertOk()
            ->assertSee('/api/v1/auth/password-reset/email', false);
    }

    #[Test]
    public function verified_email_registration_creates_a_pending_merchant_for_every_builtin_sector(): void
    {
        $matrix = [
            A::BIZ_QUICK_SALE => '779610101',
            A::BIZ_RETAIL => '779610102',
            A::BIZ_PHARMACY => '779610103',
            A::BIZ_WHOLESALE => '779610104',
            A::BIZ_RESTAURANT => '779610105',
            A::BIZ_FUEL => '779610106',
        ];

        foreach ($matrix as $sector => $phone) {
            $email = $sector . '-' . $phone . '@example.test';
            $issued = app(EmailOtpService::class)->issue($email, 'registration');
            $token = app(EmailOtpService::class)->verify(
                $issued['challenge_id'], $email, 'registration', $this->lastCode()
            );

            $response = app(MerchantAccountOpeningController::class)->register(
                Request::create('/api/v1/auth/register/merchant', 'POST', [
                    'email' => $email,
                    'email_challenge_id' => $issued['challenge_id'],
                    'email_verification_token' => $token,
                    'dial_country_code' => '+967',
                    'phone' => $phone,
                    'password' => 'MerchantPass9!',
                    'password_confirmation' => 'MerchantPass9!',
                    'transaction_pin' => '5839',
                    'given_name' => 'مالك',
                    'father_name' => 'محمد',
                    'grandfather_name' => 'علي',
                    'family_name' => 'التجريبي',
                    'store_name' => 'منشأة ' . $sector,
                    'business_type' => $sector,
                    'terms_accepted' => true,
                ])
            );

            $this->assertSame(201, $response->getStatusCode(), $response->getContent());
            $user = User::where('email_canonical', $email)->firstOrFail();
            $profile = MerchantProfile::where('user_id', $user->id)->firstOrFail();
            $store = Merchant::where('user_id', $user->id)->firstOrFail();

            $this->assertSame(MERCHANT_TYPE, (int) $user->type);
            $this->assertSame(A::ROLE_MERCHANT, (string) $user->role);
            $this->assertSame(1, (int) $user->is_email_verified);
            $this->assertSame(0, (int) $user->is_active);
            $this->assertSame($sector, (string) $profile->business_type);
            $this->assertSame('unverified', (string) $profile->verification_status);
            $this->assertNotEmpty($store->merchant_number);
            $body = $response->getData(true);
            $this->assertNotEmpty($body['data']['account_opening_access_token'] ?? null);
            $this->assertFalse(EMoney::where('user_id', $user->id)->exists());
            $this->getJson(
                '/api/v1/auth/account-opening/'.$body['data']['muwathaq_application_ulid'].'/status',
                ['Authorization' => 'Bearer '.$body['data']['account_opening_access_token']],
            )->assertOk()->assertJsonPath('data.next_steps.0', 'submit_owner_identity');
            $this->assertDatabaseHas('muwathaq_account_opening_applications', [
                'user_id' => $user->id,
                'subject_kind' => 'merchant',
                'verification_path' => 'merchant_sector',
                'business_type' => $sector,
                'status' => 'submitted',
            ]);
        }
    }

    #[Test]
    public function pending_merchant_cannot_enter_the_operational_portal_until_approval(): void
    {
        $owner = User::factory()->create([
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'is_active' => 1,
            'password' => Hash::make('6392'),
            'email' => 'pending-merchant@example.test',
        ]);
        $store = new Merchant();
        $store->user_id = $owner->id;
        $store->store_name = 'منشأة بانتظار المراجعة';
        $store->merchant_number = '684321';
        $store->address = '—';
        $store->save();
        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => A::BIZ_QUICK_SALE,
            'verification_status' => 'pending_review',
            'subscription_plan' => A::PLAN_FREE,
        ]);

        $this->post('/merchant/login', [
            'identifier' => 'pending-merchant@example.test',
            'password' => '6392',
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest('merchant_web');
    }

    #[Test]
    public function owner_can_resume_existing_pending_application_without_merchant_login_or_wallet(): void
    {
        $email = 'existing-pending-merchant@example.test';
        $owner = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'email' => $email, 'email_canonical' => $email,
            'is_email_verified' => 1, 'is_active' => 0,
            'password' => Hash::make('MerchantSecurePass9!'),
        ]);
        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'unverified',
        ]);
        $opening = app(\App\Services\Muwathaq\MuwathaqAccountOpeningService::class)
            ->submitFor($owner, A::BIZ_RETAIL);

        $url = '/merchant/opening/resume';
        $this->postJson($url, ['email' => $email, 'password' => 'wrong-password'])
            ->assertForbidden()->assertJsonPath('success', false);

        $result = $this->postJson($url, [
            'email' => $email, 'password' => 'MerchantSecurePass9!',
        ])->assertOk()->assertJsonPath('data.application_ulid', $opening->application_ulid)->json();

        $this->assertNotEmpty($result['data']['account_opening_access_token']);
        $this->assertGuest('merchant_web');
        $this->assertFalse(EMoney::where('user_id', $owner->id)->exists());

        $this->getJson(
            '/api/v1/auth/account-opening/'.$opening->application_ulid.'/status',
            ['Authorization' => 'Bearer '.$result['data']['account_opening_access_token']],
        )->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->post('/merchant/login', [
            'identifier' => $email, 'password' => 'MerchantSecurePass9!',
        ])->assertSessionHasErrors('identifier');
    }

    #[Test]
    public function unverified_email_cannot_reissue_an_opening_token(): void
    {
        $email = 'unverified-merchant@example.test';
        $owner = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'email' => $email, 'email_canonical' => $email,
            'is_email_verified' => 0,
            'password' => Hash::make('MerchantSecurePass9!'),
        ]);
        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'unverified',
        ]);
        app(\App\Services\Muwathaq\MuwathaqAccountOpeningService::class)->submitFor($owner, A::BIZ_RETAIL);
        $this->postJson('/merchant/opening/resume', [
            'email' => $email, 'password' => 'MerchantSecurePass9!',
        ])->assertForbidden()->assertJsonPath('success', false);
    }

}
