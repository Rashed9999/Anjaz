<?php

namespace Tests\Feature;

use Tests\TestCase;

class AmialOtpEmailTemplateTest extends TestCase
{
    /** @test */
    public function otp_email_uses_official_branding_and_security_copy(): void
    {
        $data = [
            'subject' => 'رمز التحقق لإنشاء حسابك في أميال باي',
            'preheader' => 'أكمل التحقق من بريدك الإلكتروني.',
            'purposeLabel' => 'إنشاء حساب أميال',
            'purposeDescription' => 'استخدم الرمز التالي لإكمال التحقق من بريدك الإلكتروني.',
            'otp' => '482913',
            'minutes' => 5,
            'reference' => 'AM-ABCDEFGH',
            'logoUrl' => 'https://amialpay.com/branding/logo.png',
            'websiteUrl' => 'https://amialpay.com',
        ];

        $html = view('emails.amial-otp', $data)->render();
        $text = view('emails.amial-otp-text', $data)->render();

        $this->assertStringContainsString('https://amialpay.com/branding/logo.png', $html);
        $this->assertStringContainsString('أميال باي · AMIAL PAY', $html);
        $this->assertStringContainsString('482913', $html);
        $this->assertStringContainsString('صالح لمدة', $html);
        $this->assertStringContainsString('لا تشارك هذا الرمز', $html);
        $this->assertStringContainsString('فريق أميال باي لن يطلب منك كشف رمز التحقق', $html);
        $this->assertStringContainsString('AM-ABCDEFGH', $html);

        // The text alternative must preserve the security-critical information
        // for mail clients that block or strip HTML.
        $this->assertStringContainsString('482913', $text);
        $this->assertStringContainsString('5 دقائق', $text);
        $this->assertStringContainsString('لا تشارك هذا الرمز', $text);
        $this->assertStringContainsString('AM-ABCDEFGH', $text);
    }
}
