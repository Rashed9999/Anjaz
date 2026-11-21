<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Support\Access\AccessConstants as A;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * اختبارات ربط دخول التاجر ببوابة «أميال أعمال» الجديدة:
 *  1) PortalHost::merchantLoginUrl() يحيل إلى بوابة أميال أعمال الجديدة (/login) فور تفعيلها.
 *  2) GET /merchant/login يحوّل إلى البوابة الجديدة (مع إبقاء ?legacy=1 عند الحاجة).
 *  3) POST /merchant/login يصدر تذكرة SSO موقّعة لمرة واحدة إلى /auth/amial-pay-sso.
 *  4) POST /api/v1/business/auth/verify-owner يتحقق بتوقيع HMAC ويعيد هوية المالك المعتمد ويربطه تلقائياً.
 */
class BusinessPortalLoginHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedMerchant(string $phone = '+967771234567', string $email = 'owner@merchant.test'): User
    {
        $user = new User();
        $user->forceFill([
            'f_name' => 'أحمد',
            'l_name' => 'التاجر',
            'phone' => $phone,
            'email' => $email,
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'password' => Hash::make('MerchantPass123'),
            'is_active' => 1,
            'is_kyc_verified' => 1,
        ])->save();

        $merchant = new Merchant();
        $merchant->forceFill([
            'user_id' => $user->id,
            'merchant_number' => 'M-90001',
            'store_name' => 'متجر الاختبار الحديث',
        ])->save();

        $profile = new MerchantProfile();
        $profile->forceFill([
            'user_id' => $user->id,
            'business_type' => 'retail',
            'verification_status' => 'verified',
        ])->save();

        return $user->fresh();
    }

    public function test_merchant_login_url_and_legacy_route_redirect_to_amial_business_portal(): void
    {
        config([
            'amial_business.portal_url' => 'https://merchant.amialpay.com',
            'amial_business.redirect_merchant_login' => true,
        ]);

        $this->assertSame('https://merchant.amialpay.com/login', PortalHost::merchantLoginUrl());

        $this->get('/merchant/login')->assertRedirect('https://merchant.amialpay.com/login');
        $this->get('/merchant/login?legacy=1')->assertRedirect(PortalHost::merchantLoginUrl());

        // الاشتقاق التلقائي من webhook_url + inbound_secret عند عدم ضبط portal_url صراحةً.
        config([
            'amial_business.portal_url' => '',
            'amial_business.webhook_url' => 'https://merchant.amialpay.com/api/amial-pay/webhooks',
            'amial_business.inbound_secret' => 'test-inbound-secret-32chars-0000',
        ]);
        $this->assertSame('https://merchant.amialpay.com/login', PortalHost::merchantLoginUrl());
    }

    public function test_unified_merchant_submit_issues_signed_sso_handoff_to_amial_business(): void
    {
        $this->createVerifiedMerchant();
        $secret = 'test-webhook-secret-32chars-0000';
        config([
            'amial_business.portal_url' => 'https://merchant.amialpay.com',
            'amial_business.webhook_secret' => $secret,
            'amial_business.redirect_merchant_login' => true,
        ]);

        $res = $this->post('/login', [
            'username' => '771234567',
            'password' => 'MerchantPass123',
        ]);

        $res->assertStatus(302);
        $location = (string) $res->headers->get('Location');
        $this->assertStringStartsWith('https://merchant.amialpay.com/auth/amial-pay-sso?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $this->assertNotEmpty($q['payload'] ?? '');
        $this->assertNotEmpty($q['sig'] ?? '');

        $padded = strtr($q['payload'], '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $json = base64_decode($padded, true);
        $this->assertIsString($json);
        $data = json_decode($json, true);

        $this->assertSame('M-90001', $data['merchant_number']);
        $this->assertSame('retail', $data['business_type']);
        $this->assertSame('owner@merchant.test', $data['owner']['email']);
        $expectedSig = BusinessGatewaySignature::outbound((string) $data['ts'], $json, $secret);
        $this->assertTrue(hash_equals($expectedSig, $q['sig']));
    }

    public function test_signed_verify_owner_endpoint_authenticates_and_links_verified_merchant(): void
    {
        $this->createVerifiedMerchant();
        $secret = 'test-inbound-secret-32chars-0000';
        config(['amial_business.inbound_secret' => $secret]);

        $path = '/api/v1/business/auth/verify-owner';
        $payload = json_encode(['identifier' => '771234567', 'password' => 'MerchantPass123']);
        $ts = (string) time();
        $nonce = Str::random(32);
        $sig = BusinessGatewaySignature::sign(
            BusinessGatewaySignature::inboundCanonical($ts, $nonce, 'POST', $path, '', (string) $payload),
            $secret,
        );

        // بلا توقيع -> 401
        $this->postJson($path, ['identifier' => '771234567', 'password' => 'MerchantPass123'])
            ->assertStatus(401);

        // بتوقيع سليم -> 200 مع link_id وهوية المالك
        $res = $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AMIAL_BUSINESS_TIMESTAMP' => $ts,
            'HTTP_X_AMIAL_BUSINESS_NONCE' => $nonce,
            'HTTP_X_AMIAL_BUSINESS_SIGNATURE' => $sig,
        ], (string) $payload);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.merchant_number', 'M-90001')
            ->assertJsonPath('meta.business_type', 'retail')
            ->assertJsonPath('meta.owner.email', 'owner@merchant.test');
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $res->json('meta.link_id'));

        // إعادة نفس الـ nonce -> 409 REPLAYED
        $replay = $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AMIAL_BUSINESS_TIMESTAMP' => $ts,
            'HTTP_X_AMIAL_BUSINESS_NONCE' => $nonce,
            'HTTP_X_AMIAL_BUSINESS_SIGNATURE' => $sig,
        ], (string) $payload);
        $replay->assertStatus(409);
    }
}
