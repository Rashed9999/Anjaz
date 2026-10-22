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
    public function merchant_portal_redirects_new_applicants_to_muwathaq_instead_of_collecting_legacy_documents(): void
    {
        $this->get('/merchant/register')->assertOk()
            ->assertSee('فتح حساب منشأة')
            ->assertSee('موثّق')
            ->assertSee('/open-account?type=merchant', false)
            ->assertDontSee('name="password"', false)
            ->assertDontSee('name="id_card_front"', false);

        $this->get('/merchant/opening')->assertOk()
            ->assertSee('استكمال توثيق المنشأة');

        $this->get('/merchant/login')->assertOk();
    }

    #[Test]
    public function amial_legacy_creation_is_closed_for_every_sector(): void
    {
        // موثّق هو المصدر الوحيد لإنشاء الحساب. إحياء تسجيل أميال القديم
        // يعيد تكرار المنشآت وقرارات KYC والمحافظ قبل مراجعة موثّق.
        foreach ([
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL, A::BIZ_PHARMACY,
            A::BIZ_WHOLESALE, A::BIZ_RESTAURANT, A::BIZ_FUEL,
        ] as $sector) {
            $email = $sector.'@merchant-registration-closed.example.test';
            $response = app(MerchantAccountOpeningController::class)->register(
                Request::create('/api/v1/auth/register/merchant', 'POST', [
                    'email' => $email,
                    'business_type' => $sector,
                    'store_name' => 'منشأة تجريبية',
                ]),
            );

            $this->assertSame(410, $response->getStatusCode());
            $payload = $response->getData(true);
            $this->assertSame('ACCOUNT_CREATION_OWNED_BY_MUWATHAQ', $payload['code']);
            $this->assertSame('/open-account?type=merchant', $payload['muwathaq_url']);
            $this->assertFalse(User::where('email_canonical', $email)->exists());
        }
        $this->assertSame(0, Merchant::count());
        $this->assertSame(0, EMoney::count());
        $this->assertSame(0, MuwathaqAccountOpeningApplication::count());
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
