<?php

namespace Tests\Feature;

use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** رابط واتساب العام: يتحقق بلا جلسة ولا يكشف طرفي العملية. */
class PublicReceiptVerificationPageTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(): Receipt
    {
        $user = User::factory()->create(['type' => 2]);

        return Receipt::create([
            'receipt_number' => '260828123456',
            'verification_code' => '1234567890123456',
            'receipt_type' => 'send_money',
            'user_id' => $user->id,
            'reference_transaction_id' => 'VERIFY-WEB-1',
            'amount' => '2000.0000',
            'fee' => '0.0000',
            'net_amount' => '2000.0000',
            'direction' => 'debit',
            'status' => 'pdf_generated',
            'zone_code' => 'SOUTH',
            'issued_at' => now(),
        ]);
    }

    public function test_shared_verification_page_is_public_and_human_readable(): void
    {
        $receipt = $this->receipt();

        $this->get('/v/' . $receipt->verification_code)
            ->assertOk()
            // AMIAL-DOC-VERIFY-001 — النصُّ تغيّر عمداً: الصفحةُ صارت
            // تفرّق بين «لا مستند بهذا الرمز» و«مستند ملغى»، وهو ما لم
            // تكن تقدر عليه. والمحروسُ باقٍ: عامّةٌ · مقروءةٌ · بلا تسريب.
            ->assertSee('مستند أصلي')
            ->assertSee($receipt->receipt_number)
            ->assertDontSee('Unauthorized');
    }

    public function test_unknown_public_verification_code_shows_a_safe_not_found_page(): void
    {
        $this->get('/v/9999999999999999')
            ->assertNotFound()
            ->assertSee('لا مستند بهذا الرمز');
    }
}
