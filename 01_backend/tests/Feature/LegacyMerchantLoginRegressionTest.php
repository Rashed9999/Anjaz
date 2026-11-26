<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * اختبارات منع تكرار توقف تسجيل الدخول لحسابات التجار القدامى والجدد:
 *  - حساب تاجر قديم معتمد بدور تاريخي (role='0' أو 'user' أو null من ترحيل 6cash).
 *  - مطابقة المعرّف بلا حساسية لحالة الأحرف (البريد / رقم التاجر) وبكل صيغ الهاتف وphone_canonical.
 *  - الحفاظ على ?legacy=1 عند إرسال نموذج /merchant/login حتى لا يُخفي تحويل SSO الدخول القديم.
 *  - عدم تجاوز التوثيق (KYC) أو السماح لموظفي POS أو العملاء أو الحسابات الموقوفة.
 */
class LegacyMerchantLoginRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function createLegacyVerifiedMerchant(array $overrides = []): User
    {
        $user = new User();
        $user->forceFill([
            'f_name' => 'صالح',
            'l_name' => 'القديم',
            'phone' => $overrides['phone'] ?? '967777200001',
            'email' => $overrides['email'] ?? 'Legacy.Owner@Amial.Test',
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'password' => Hash::make($overrides['password'] ?? 'Pass@2026'),
            'is_active' => $overrides['is_active'] ?? 1,
            'is_temp_blocked' => $overrides['is_temp_blocked'] ?? 0,
            'is_kyc_verified' => 1,
        ])->save();

        // محاكاة سجل قديم من 6cash لم يُحدّث عمود role فيه عند ترحيل 2026_06_02 (مثل '0' أو null أو 'user')
        if (array_key_exists('raw_role', $overrides)) {
            DB::table('users')->where('id', $user->id)->update(['role' => $overrides['raw_role']]);
        }

        // محاكاة هاتف مخزّن بفراغات مع وجود phone_canonical
        if (array_key_exists('raw_phone', $overrides)) {
            DB::table('users')->where('id', $user->id)->update(['phone' => $overrides['raw_phone']]);
        }

        $merchant = new Merchant();
        $merchant->forceFill([
            'user_id' => $user->id,
            'merchant_number' => $overrides['merchant_number'] ?? 'AM-GROC-001',
            'store_name' => $overrides['store_name'] ?? 'بقالة النور القديمة',
        ])->save();

        $profile = new MerchantProfile();
        $profile->forceFill([
            'user_id' => $user->id,
            'business_type' => array_key_exists('business_type', $overrides) ? $overrides['business_type'] : null,
            'verification_status' => $overrides['verification_status'] ?? 'verified',
        ])->save();

        return $user->fresh();
    }

    private function signedVerifyOwner(string $identifier, string $password, string $secret = 'test-inbound-secret-32chars-0000')
    {
        config(['amial_business.inbound_secret' => $secret]);

        $path = '/api/v1/business/auth/verify-owner';
        $payload = json_encode(['identifier' => $identifier, 'password' => $password]);
        $ts = (string) time();
        $nonce = Str::random(32);
        $sig = BusinessGatewaySignature::sign(
            BusinessGatewaySignature::inboundCanonical($ts, $nonce, 'POST', $path, '', (string) $payload),
            $secret,
        );

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AMIAL_BUSINESS_TIMESTAMP' => $ts,
            'HTTP_X_AMIAL_BUSINESS_NONCE' => $nonce,
            'HTTP_X_AMIAL_BUSINESS_SIGNATURE' => $sig,
        ], (string) $payload);
    }

    public function test_legacy_verified_merchant_with_6cash_zero_role_can_login_and_verify_owner(): void
    {
        config(['amial_business.redirect_merchant_login' => false]);
        $this->createLegacyVerifiedMerchant(['raw_role' => '0']);
        $this->signedVerifyOwner('am-groc-001', 'Pass@2026')->assertOk();

        $res = $this->signedVerifyOwner('legacy.owner@amial.test', 'Pass@2026');
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.merchant_number', 'AM-GROC-001')
            ->assertJsonPath('meta.business_type', null);
    }

    public function test_legacy_verified_merchant_with_formatted_phone_matches_via_phone_canonical_and_variants(): void
    {
        config(['amial_business.redirect_merchant_login' => false]);
        $this->createLegacyVerifiedMerchant([
            'phone' => '967777200001',
            'raw_phone' => '+967 777 200 001',
            'raw_role' => 'user',
        ]);

        $res = $this->signedVerifyOwner('777200001', 'Pass@2026');
        $res->assertOk()->assertJsonPath('meta.merchant_number', 'AM-GROC-001');
    }

    public function test_unified_web_login_accepts_legacy_merchant_phone_variants_and_hands_off_cleanly(): void
    {
        $this->createLegacyVerifiedMerchant([
            'phone' => '967777200001',
            'raw_role' => '0',
            'business_type' => 'retail',
        ]);
        config([
            'amial_business.portal_url' => 'https://merchant.amialpay.com',
            'amial_business.webhook_secret' => 'test-webhook-secret-32chars-0000',
            'amial_business.redirect_merchant_login' => true,
        ]);

        // الدخول بـ 777200001 (بدون 967) من صفحة /login الموحّدة
        $res = $this->post('/login', [
            'username' => '777200001',
            'password' => 'Pass@2026',
        ]);

        $res->assertStatus(302);
        $this->assertStringStartsWith(
            'https://merchant.amialpay.com/auth/amial-pay-sso?',
            (string) $res->headers->get('Location')
        );
    }

    public function test_old_dashboard_and_saved_forms_redirect_without_forwarding_credentials(): void
    {
        config(['amial_business.redirect_merchant_login' => false]);
        $target = 'https://merchant.amialpay.com/login';

        foreach (['/merchant', '/merchant/login', '/merchant/login?legacy=1'] as $path) {
            $this->get($path)->assertStatus(303)->assertRedirect($target);
        }

        $this->post('/merchant/login?legacy=1', [
            'identifier' => 'old-owner',
            'password' => 'test-password',
        ])->assertStatus(303)->assertRedirect($target);
        $this->assertGuest('merchant_web');
    }

    public function test_unverified_and_non_merchant_accounts_remain_strictly_blocked(): void
    {
        // تاجر غير موثّق -> 403 KYC_REQUIRED (لا تجاوز للتوثيق أبداً)
        $this->createLegacyVerifiedMerchant([
            'phone' => '967777200099',
            'email' => 'unverified@amial.test',
            'merchant_number' => 'AM-UNV-099',
            'verification_status' => 'pending_review',
        ]);
        $this->signedVerifyOwner('AM-UNV-099', 'Pass@2026')
            ->assertStatus(403)
            ->assertJsonPath('code', 'KYC_REQUIRED');

        // موظف POS أو عميل لا يمرّ كمالك منشأة
        $pos = new User();
        $pos->forceFill([
            'f_name' => 'موظف',
            'l_name' => 'كاشير',
            'phone' => '967777200088',
            'email' => 'pos@amial.test',
            'type' => MERCHANT_TYPE,
            'role' => 'pos',
            'password' => Hash::make('Pass@2026'),
            'is_active' => 1,
        ])->save();
        MerchantProfile::query()->create([
            'user_id' => $pos->id,
            'business_type' => 'retail',
            'verification_status' => 'verified',
        ]);

        $this->signedVerifyOwner('pos@amial.test', 'Pass@2026')
            ->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }
}
