<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MuwathaqAccountOpeningApplication as Opening;
use App\Models\User;
use App\Services\Merchant\MerchantNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Support\ProvisionsAccountsFromMuwathaq;

class MuwathaqMerchantActivationTest extends TestCase
{
    use RefreshDatabase;
    use ProvisionsAccountsFromMuwathaq;

    private array $emailMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial_otp.resend.api_key' => 'fake-provider',
            'amial_otp.resend.api_url' => 'https://api.resend.com/emails',
            'amial_otp.resend_seconds' => 60]);
        Http::preventStrayRequests();
        Http::fake(['api.resend.com/emails' => function ($request) {
            $this->emailMessages[] = $request;
            return Http::response(['id' => 'activation-'.count($this->emailMessages)], 200);
        }]);
    }

    private function merchant(bool $approved = true, string $email = 'approved-merchant@example.test'): User
    {
        $user = User::factory()->create([
            'type' => MERCHANT_TYPE, 'email' => $email,
            'email_canonical' => $email,
            'is_active' => $approved ? 1 : 0,
            'is_email_verified' => 0, 'is_kyc_verified' => $approved ? 1 : 0,
            'password' => Hash::make(Str::random(64)),
        ]);
        $merchant = new Merchant();
        $merchant->user_id = $user->id;
        $merchant->store_name = 'متجر التجربة';
        $merchant->address = 'تعز';
        $merchant->save();
        app(MerchantNumberService::class)->assignTo($merchant);
        MerchantProfile::create([
            'user_id' => $user->id,
            'business_type' => 'retail',
            'verification_status' => $approved ? 'verified' : 'unverified',
        ]);
        Opening::create([
            'application_ulid' => (string) Str::ulid(),
            'user_id' => $user->id,
            'origin' => 'muwathaq',
            'subject_kind' => 'merchant',
            'verification_path' => 'merchant_sector',
            'business_type' => 'retail',
            'status' => $approved ? 'approved' : 'submitted',
            'submitted_at' => now(),
        ]);
        return $user;
    }

    private function otp(): string
    {
        $message = $this->emailMessages[array_key_last($this->emailMessages)];
        $this->assertMatchesRegularExpression('/(?<!\d)\d{6}(?!\d)/', $message['text']);
        preg_match('/(?<!\d)\d{6}(?!\d)/', $message['text'], $matches);
        return $matches[0];
    }

    #[Test]
    public function signed_muwathaq_approval_provisions_merchant_number_before_email_claim(): void
    {
        $user = $this->provisionMerchantFromMuwathaq('retail', '967777186451', [
            'email' => 'real-provisioning-merchant@example.test',
            'store_name' => 'نشاط للتأكد',
            'governorate' => 'YE-TA',
        ]);
        $merchant = Merchant::where('user_id', $user->id)->firstOrFail();
        $this->assertMatchesRegularExpression('/^[1-9]{6}$/', (string) $merchant->merchant_number);
        $this->assertSame(1, (int) $user->is_active);
        $this->assertSame(0, (int) $user->is_email_verified);
        $this->assertSame('verified',
            MerchantProfile::where('user_id', $user->id)->value('verification_status'));
        $this->assertSame('muwathaq',
            Opening::where('user_id', $user->id)->value('origin'));
        $this->assertSame(1, Merchant::where('user_id', $user->id)->count());
    }

    #[Test]
    public function approved_merchant_claims_original_account_with_email_otp_password_pin_and_number(): void
    {
        $user = $this->merchant();
        $merchant = Merchant::where('user_id', $user->id)->firstOrFail();
        $number = $merchant->merchant_number;
        $oldPassword = $user->password;
        $this->get('/merchant/activate')->assertOk()->assertSee('تفعيل حساب التاجر');
        $this->postJson('/merchant/activate/request', ['email' => $user->email])
            ->assertOk()->assertJsonPath('success', true);
        $code = $this->otp();
        $this->assertNotEmpty($code);
        $this->assertStringNotContainsString($code, json_encode([
            'number' => $number, 'email' => $user->email,
        ]));

        $this->postJson('/merchant/activate/complete', [
            'email' => $user->email, 'otp' => $code,
            'password' => 'MerchantPass2026!', 'password_confirmation' => 'MerchantPass2026!',
            'pin' => '5938', 'pin_confirmation' => '5938',
        ])->assertOk()->assertJsonPath('data.merchant_number', $number);

        $user->refresh();
        $this->assertSame(1, (int) $user->is_email_verified);
        $this->assertTrue(Hash::check('MerchantPass2026!', $user->password));
        $this->assertNotSame($oldPassword, $user->password);
        $this->assertTrue(Hash::check('5938', $user->transaction_pin));
        $this->assertFalse((bool) $user->requires_pin_setup);
        $this->assertSame(1, Merchant::where('user_id', $user->id)->count());
        $this->assertDatabaseCount('muwathaq_account_opening_applications', 1);
        $this->postJson('/merchant/activate/complete', [
            'email' => $user->email, 'otp' => $code,
            'password' => 'DifferentMerchantPass!', 'password_confirmation' => 'DifferentMerchantPass!',
            'pin' => '5938', 'pin_confirmation' => '5938',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('MerchantPass2026!', $user->fresh()->password));
        $this->post('/merchant/login', [
            'identifier' => $number, 'password' => 'MerchantPass2026!',
        ])->assertRedirect(route('merchant.web.dashboard'));
    }

    #[Test]
    public function pending_merchant_gets_no_activation_code_or_credentials(): void
    {
        $user = $this->merchant(false);
        $this->postJson('/merchant/activate/request', ['email' => $user->email])
            ->assertOk();
        $this->assertCount(0, $this->emailMessages);
        $this->postJson('/merchant/activate/complete', [
            'email' => $user->email,'otp' => '123456',
            'password' => 'MerchantPass2026!', 'password_confirmation' => 'MerchantPass2026!',
            'pin' => '5938', 'pin_confirmation' => '5938',
        ])->assertStatus(422);
        $this->assertSame(0, (int) $user->fresh()->is_email_verified);
    }

    #[Test]
    public function wrong_otp_or_weak_pin_cannot_claim_merchant_account(): void
    {
        $user = $this->merchant();
        $this->postJson('/merchant/activate/request', ['email' => $user->email])->assertOk();
        $this->postJson('/merchant/activate/complete', [
            'email' => $user->email, 'otp' => '000000',
            'password' => 'MerchantPass2026!', 'password_confirmation' => 'MerchantPass2026!',
            'pin' => '5938', 'pin_confirmation' => '5938',
        ])->assertStatus(422);
        $this->postJson('/merchant/activate/complete', [
            'email' => $user->email, 'otp' => $this->otp(),
            'password' => 'MerchantPass2026!', 'password_confirmation' => 'MerchantPass2026!',
            'pin' => '1234', 'pin_confirmation' => '1234',
        ])->assertStatus(422);
        $this->assertSame(0, (int) $user->fresh()->is_email_verified);
    }

    #[Test]
    public function missing_email_provider_yields_explicit_error_instead_of_fake_sent_message(): void
    {
        $user = $this->merchant();
        config(['amial_otp.resend.api_key' => '']);
        $this->postJson('/merchant/activate/request', ['email' => $user->email])
            ->assertStatus(503);
        $this->assertCount(0, $this->emailMessages);
    }
}
