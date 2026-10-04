<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Auth\EmailRegistrationController;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
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
    }

    private function lastCode(): string
    {
        $message = $this->messages[array_key_last($this->messages)];
        $this->assertSame(1, preg_match('/(?<!\d)\d{6}(?!\d)/u', $message['text'], $match));
        return $match[0];
    }

    #[Test]
    public function merchant_portal_exposes_registration_and_email_recovery_without_login(): void
    {
        $this->get('/merchant/register')->assertOk()
            ->assertSee('إنشاء حساب منشأة')
            ->assertSee('البيع السريع')
            ->assertSee('التجزئة')
            ->assertSee('الصيدلية')
            ->assertSee('تاجر جملة')
            ->assertSee('المطعم')
            ->assertSee('محطة وقود')
            ->assertSee('/api/v1/auth/email-otp/request', false)
            ->assertSee('/api/v1/auth/register/email', false);

        $this->get('/merchant/recover')->assertOk()
            ->assertSee('/api/v1/auth/password-reset/email', false);
    }

    #[Test]
    public function verified_email_registration_creates_a_real_merchant_for_every_builtin_sector(): void
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

            $response = app(EmailRegistrationController::class)->register(
                Request::create('/api/v1/auth/register/email', 'POST', [
                    'email' => $email,
                    'email_challenge_id' => $issued['challenge_id'],
                    'email_verification_token' => $token,
                    'dial_country_code' => '+967',
                    'phone' => $phone,
                    'password' => '6392',
                    'gender' => 'male',
                    'f_name' => 'مالك',
                    'father_name' => 'محمد',
                    'grandfather_name' => 'علي',
                    'family_name' => 'التجريبي',
                    'l_name' => 'التجريبي',
                    'account_type' => 'merchant',
                    'store_name' => 'منشأة ' . $sector,
                    'business_type' => $sector,
                    'origin_governorate' => 'YE-HD',
                    'residence_governorate' => 'YE-HD',
                    'residence_district' => 'المكلا',
                    'residence_area' => 'وسط المدينة',
                    'address' => 'حضرموت — المكلا',
                    'declaration_accepted' => '1',
                ])
            );

            $this->assertSame(200, $response->getStatusCode(), $response->getContent());
            $user = User::where('email_canonical', $email)->firstOrFail();
            $profile = MerchantProfile::where('user_id', $user->id)->firstOrFail();
            $store = Merchant::where('user_id', $user->id)->firstOrFail();

            $this->assertSame(MERCHANT_TYPE, (int) $user->type);
            $this->assertSame(A::ROLE_MERCHANT, (string) $user->role);
            $this->assertSame(1, (int) $user->is_email_verified);
            $this->assertSame($sector, (string) $profile->business_type);
            $this->assertSame('pending_review', (string) $profile->verification_status);
            $this->assertNotEmpty($store->merchant_number);
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
        Merchant::create([
            'user_id' => $owner->id,
            'store_name' => 'منشأة بانتظار المراجعة',
            'merchant_number' => '684321',
            'address' => '—',
        ]);
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
}
