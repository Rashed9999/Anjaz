<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Services\KycTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-GOLDEN-JOURNEY-001
 *
 * عقدٌ واحد يربط ما كان يُختبر منفصلاً:
 *
 * تسجيل عميل جديد
 *   → طلب submitted لدى موثّق
 *   → لا محفظة ولا صلاحيات مالية قبل قرار الاعتماد
 *   → وجود مدخل واضح لإكمال المتطلبات التي يقررها موثّق.
 *
 * هذا الحارس وُلد بعد أن كان التسجيل والدخول وKYC كلٌّ منها «أخضر»
 * منفرداً بينما رحلة المستخدم الحقيقية كانت تنتهي بشاشة «قيد المراجعة».
 */
class CustomerGoldenJourneyGuardTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $phone): array
    {
        return [
            'f_name' => 'النور',
            'father_name' => 'محمد',
            'grandfather_name' => 'علي',
            'family_name' => 'الساطع',
            'l_name' => 'الساطع',
            'gender' => 'male',
            'dial_country_code' => '+967',
            'phone' => $phone,
            'email' => 'golden-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => '1234',
            'identification_number' => '01-01-01-77889',
            'identification_type' => 'nid',
            'address' => 'عدن — المنصورة',
            'declaration_accepted' => '1',
        ];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_brand_new_customer_must_open_through_muwathaq_only(): void
    {
        $phone = '771599901';

        // This route is retired; reopening it would circumvent the required
        // verified-evidence and reviewer authorization in Muwathaq.
        $this->postJson('/api/v1/customer/auth/register', $this->payload($phone))
            ->assertStatus(410)
            ->assertJsonPath('code', 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ');

        $this->assertFalse(User::where('phone', '967' . $phone)->exists());
        $this->assertSame(0, EMoney::count());
        $this->assertDatabaseCount('muwathaq_account_opening_applications', 0);
    }

        /** @test */
    public function tier_zero_is_a_real_account_but_financially_zero(): void
    {
        // Internal policy contract for a pending customer: the only public
        // account-creation entry point remains Muwathaq.
        $user = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'is_active' => 0,
            'is_kyc_verified' => 0,
            'kyc_tier' => 0,
            'is_phone_verified' => 0,
        ]);
        $this->assertFalse(EMoney::where('user_id', $user->id)->exists());
        $tiers = app(KycTierService::class);

        foreach ([
            'send_money',
            'receive_money',
            'bill_pay',
            'cash_out',
            'merchant_pay',
            'safe_payment',
            'donations',
            'family_fund',
        ] as $feature) {
            try {
                $tiers->assertFeatureAllowed($user, $feature);
                $this->fail("Tier 0 فتح ميزة مالية: {$feature}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    'أكمل إثبات الهاتف واعتماد السكن',
                    $e->getMessage(),
                    "المنع المالي لـ {$feature} خرج بسببٍ غير مفهوم"
                );
            }
        }

        $this->assertSame('0.0000', (string) (
            EMoney::where('user_id', $user->id)->value('current_balance') ?? '0.0000'
        ));
    }

    /** @test */
    public function flutter_routes_customer_to_wallet_and_exposes_upgrade_instead_of_review_prison(): void
    {
        $auth = base_path('../02_flutter_app/lib/features/auth/controllers/unified_auth_controller.dart');
        $services = base_path('../02_flutter_app/lib/features/me/screens/customer_services_hub_screen.dart');
        $complete = base_path('../02_flutter_app/lib/features/kyc_verification/screens/complete_my_account_screen.dart');

        if (!is_file($auth) || !is_file($services) || !is_file($complete)) {
            $this->markTestSkipped('مصادر Flutter غير موجودة في هذه البيئة');
        }

        $authSrc = file_get_contents($auth);
        $servicesSrc = file_get_contents($services);
        $completeSrc = file_get_contents($complete);

        $this->assertStringContainsString('shouldBlockHome', $authSrc);
        $this->assertStringContainsString("role == 'admin' || role == 'customer'", $authSrc);
        $this->assertStringContainsString("role == 'merchant' || role == 'pos'", $authSrc);
        $this->assertStringContainsString('customer-kyc-upgrade-cta', $servicesSrc);
        $this->assertStringContainsString('CompleteMyAccountScreen', $servicesSrc);
        $this->assertStringNotContainsString('profile?.type != 2', $completeSrc);
    }
}
