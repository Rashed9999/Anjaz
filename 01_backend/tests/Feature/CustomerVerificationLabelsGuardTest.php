<?php

namespace Tests\Feature;

use App\Services\KycTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-VERIFICATION-LABELS-001
 *
 * أرقام KYC تبقى مفاتيح سياسة داخلية، لكن لا تعود تسمية واجهة.
 */
class CustomerVerificationLabelsGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function customer_verification_levels_have_the_requested_public_names(): void
    {
        $kyc = app(KycTierService::class);

        $this->assertSame('عميل غير موثق', $kyc->getLimits(0)['name_ar']);
        $this->assertSame('عميل موثق جزئيا', $kyc->getLimits(1)['name_ar']);
        $this->assertSame('عميل موثق بهوية', $kyc->getLimits(2)['name_ar']);
        $this->assertSame('عميل موثق', $kyc->getLimits(3)['name_ar']);
    }

    /** @test */
    public function flutter_uses_one_palette_for_the_four_customer_states(): void
    {
        $path = dirname(base_path())
            .'/02_flutter_app/lib/features/kyc_verification/domain/customer_verification_level.dart';
        $src = (string) file_get_contents($path);

        foreach ([
            'عميل غير موثق' => '0xFF8B5E3C',
            'عميل موثق جزئيا' => '0xFFF28C28',
            'عميل موثق بهوية' => '0xFF16874C',
            'عميل موثق' => '0xFFD4AF37',
        ] as $label => $color) {
            $this->assertStringContainsString($label, $src);
            $this->assertStringContainsString($color, $src);
        }
    }

    /** @test */
    public function customer_surfaces_do_not_render_numeric_tier_names(): void
    {
        $root = dirname(base_path()).'/02_flutter_app/lib/';
        $files = [
            'features/me/screens/customer_services_hub_screen.dart',
            'features/kyc_verification/widgets/customer_verification_panel.dart',
            'features/kyc_verification/screens/complete_my_account_screen.dart',
            'features/kyc_verification/screens/kyc_verify_screen.dart',
            'features/auth/screens/quick_registration_screen.dart',
        ];

        foreach ($files as $relative) {
            $src = (string) file_get_contents($root.$relative);
            // التعليقات الهندسية يجوز أن تذكر المفتاح الداخلي؛ الذي نمنعه
            // هو النص الذي يصل للمستخدم.
            $visible = preg_replace('/^\s*\/\/\/?.*$/m', '', $src) ?? $src;

            $this->assertDoesNotMatchRegularExpression(
                '/\bTier\s*[0-3]\b|المستوى\s*[0-3]|المستوى\s+(الثاني|الثالث)/u',
                $visible,
                "واجهة العميل أعادت عرض رقم KYC في {$relative}",
            );
        }
    }
}
