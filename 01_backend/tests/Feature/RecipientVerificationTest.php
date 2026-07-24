<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RecipientVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-RECIPIENT-VERIFY-001 (v2.6) — اختبارات التحقق من المستلم.
 */
class RecipientVerificationTest extends TestCase
{
    use RefreshDatabase;

    private RecipientVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RecipientVerificationService::class);
    }

    /** @test */
    public function it_verifies_valid_recipient()
    {
        $sender = User::factory()->create(['zone_code' => 'SOUTH']);
        $recipient = User::factory()->create([
            'phone' => '777123456', 'f_name' => 'أحمد', 'l_name' => 'محمد علي',
            'zone_code' => 'SOUTH',
        ]);

        $result = $this->service->verifyRecipient('777123456', $sender->id);

        $this->assertArrayHasKey('verification_token', $result);
        $this->assertEquals($recipient->id, $result['recipient_id']);
        // الاسم مُقنّع: أحمد م** ع**
        $this->assertStringContainsString('أحمد', $result['masked_name']);
        $this->assertStringContainsString('*', $result['masked_name']);
    }

    /** @test AMIAL-ACCOUNT-NUMBER-001: التحقق بالمستلِم عبر رقم الحساب (8 أرقام) */
    public function it_verifies_recipient_by_account_number()
    {
        $sender = User::factory()->create(['zone_code' => 'SOUTH']);
        $accountNumber = app(\App\Services\AccountNumberService::class)->generateUnique();
        $recipient = User::factory()->create([
            'phone' => '777654321', 'f_name' => 'سالم', 'l_name' => 'عبدالله',
            'zone_code' => 'SOUTH', 'account_number' => $accountNumber,
        ]);

        $result = $this->service->verifyRecipient($accountNumber, $sender->id);

        $this->assertArrayHasKey('verification_token', $result);
        $this->assertEquals($recipient->id, $result['recipient_id']);
    }

    /** @test */
    public function it_rejects_unknown_phone()
    {
        $sender = User::factory()->create();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يوجد مستخدم');
        $this->service->verifyRecipient('999999999', $sender->id);
    }

    /** @test */
    public function it_rejects_self_transfer()
    {
        $user = User::factory()->create(['phone' => '777111222', 'zone_code' => 'SOUTH']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لنفسك');
        $this->service->verifyRecipient('777111222', $user->id);
    }

    /** @test */
    public function it_rejects_recipient_outside_south()
    {
        $sender = User::factory()->create(['zone_code' => 'SOUTH']);
        User::factory()->create(['phone' => '777333444', 'zone_code' => 'NORTH']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('غير مؤهل');
        $this->service->verifyRecipient('777333444', $sender->id);
    }

    /** @test */
    public function valid_token_passes_assertion()
    {
        $sender = User::factory()->create(['zone_code' => 'SOUTH']);
        $recipient = User::factory()->create(['phone' => '777555666', 'zone_code' => 'SOUTH']);

        $result = $this->service->verifyRecipient('777555666', $sender->id);

        // التأكيد ينجح بنفس الـ token والمستلم
        $this->service->assertValidToken($sender->id, $result['verification_token'], $recipient->id);
        $this->assertTrue(true);
    }

    /** @test */
    public function token_is_single_use()
    {
        $sender = User::factory()->create(['zone_code' => 'SOUTH']);
        $recipient = User::factory()->create(['phone' => '777777888', 'zone_code' => 'SOUTH']);

        $result = $this->service->verifyRecipient('777777888', $sender->id);
        $token = $result['verification_token'];

        // أول استخدام ينجح
        $this->service->assertValidToken($sender->id, $token, $recipient->id);

        // ثاني استخدام يفشل (single-use)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('انتهت صلاحية');
        $this->service->assertValidToken($sender->id, $token, $recipient->id);
    }

    /** @test */
    public function token_rejects_mismatched_recipient()
    {
        $sender = User::factory()->create(['zone_code' => 'SOUTH']);
        $recipient = User::factory()->create(['phone' => '777999000', 'zone_code' => 'SOUTH']);

        $result = $this->service->verifyRecipient('777999000', $sender->id);

        // محاولة استخدام الـ token لمستلم مختلف (هجوم)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا تطابق');
        $this->service->assertValidToken($sender->id, $result['verification_token'], 99999);
    }

    /** @test */
    public function invalid_token_is_rejected()
    {
        $sender = User::factory()->create();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('انتهت صلاحية');
        $this->service->assertValidToken($sender->id, 'fake-token', 1);
    }

    /** @test */
    public function name_masking_keeps_first_name_visible()
    {
        $user = User::factory()->make(['f_name' => 'محمد', 'l_name' => 'عبدالله الأحمدي']);
        $masked = $this->service->maskName($user);
        // الاسم الأول ظاهر، الباقي مُقنّع
        $this->assertStringStartsWith('محمد', $masked);
        $this->assertStringContainsString('*', $masked);
    }

    /** @test */
    public function phone_masking_hides_middle_digits()
    {
        $masked = $this->service->maskPhone('777123456');
        $this->assertStringStartsWith('777', $masked);
        $this->assertStringEndsWith('56', $masked);
        $this->assertStringContainsString('*', $masked);
    }

    /** @test */
    public function it_normalizes_phone_with_country_code()
    {
        $sender = User::factory()->create(['zone_code' => 'SOUTH']);
        User::factory()->create(['phone' => '777123456', 'zone_code' => 'SOUTH']);

        // برمز الدولة → يجب أن يطابق
        $result = $this->service->verifyRecipient('+967777123456', $sender->id);
        $this->assertArrayHasKey('verification_token', $result);
    }
}
