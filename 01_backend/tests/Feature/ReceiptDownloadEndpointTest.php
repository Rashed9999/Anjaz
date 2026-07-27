<?php

namespace Tests\Feature;

use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AMIAL-PDF-PIPELINE-002 — مسار تنزيل الإيصال كما يستدعيه التطبيق.
 *
 * كان المسار بلا أي اختبار. وكل ما فُحص سابقاً هو التصيير وحده
 * (ThermalReceiptTest، A4InvoiceTest) — أي أن الـ PDF يُبنى صحيحاً في
 * الذاكرة. لكن التطبيق لا يستدعي المُصيِّر، بل ينادي
 * GET /api/v1/amial/receipts/{id}/download ويتوقّع بايتات PDF.
 *
 * وبين الاثنين خطوات لم تُفحص قطّ: العثور على الإيصال، وقراءة الملفّ من
 * القرص أو توليده عند غيابه، وحفظه، وترويسات الاستجابة التي يفحصها
 * التطبيق (Content-Type) قبل أن يقرّر أنجح التنزيل أم فشل.
 */
class ReceiptDownloadEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
    }

    private function receipt(User $u, array $overrides = []): Receipt
    {
        return Receipt::create(array_merge([
            'receipt_number' => '260726123456',
            'verification_code' => '12345678',
            'receipt_type' => 'send_money',
            'user_id' => $u->id,
            'reference_transaction_id' => 'TX-1',
            'reference_type' => 'transaction',
            'reference_id' => 1,
            'amount' => '5000.0000', 'fee' => '50.0000', 'net_amount' => '4950.0000',
            'direction' => 'debit', 'status' => 'pending_pdf',
            'zone_code' => 'SOUTH', 'issued_at' => now(),
        ], $overrides));
    }

    /**
     * الحالة الأولى للمستخدم: إيصال جديد لم يُولَّد ملفّه بعد.
     *
     * هذه هي الحالة الغالبة فعلاً — التطبيق يفتح الإيصال فور العملية.
     */
    public function test_a_fresh_receipt_downloads_as_a_real_pdf(): void
    {
        $user = $this->customer();
        $receipt = $this->receipt($user);

        $response = $this->actingAs($user, 'api')
            ->get("/api/v1/amial/receipts/{$receipt->id}/download");

        $response->assertOk();

        // التطبيق يرفض الاستجابة إن حوى Content-Type كلمة json — فالترويسة
        // ليست تجميلاً بل شرط قبول.
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        $bytes = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    /** التوليد يُحفظ: الطلب الثاني يقرأ ملفّاً ولا يُصيّر من جديد. */
    public function test_the_generated_file_is_stored_for_next_time(): void
    {
        $user = $this->customer();
        $receipt = $this->receipt($user);

        $this->actingAs($user, 'api')
            ->get("/api/v1/amial/receipts/{$receipt->id}/download")->assertOk();

        $fresh = $receipt->fresh();
        $this->assertNotEmpty($fresh->pdf_storage_path,
            'لم يُحفظ المسار — كل تنزيل سيُعيد التصيير كاملاً داخل الطلب');
        $this->assertTrue(Storage::disk('local')->exists($fresh->pdf_storage_path),
            'المسار محفوظ والملفّ غير موجود على القرص الذي يقرأ منه التنزيل');
    }

    /**
     * الملفّ يختفي مع النشرة (تخزين مؤقّت) — يجب أن يُعاد توليده لا أن
     * يُرَدّ خطأ. هذه بالضبط حال الخادم بعد كل نشر.
     */
    public function test_a_missing_file_is_regenerated_not_an_error(): void
    {
        $user = $this->customer();
        $receipt = $this->receipt($user, [
            'status' => 'pdf_generated',
            'pdf_storage_path' => 'receipts/2026/07/ghost.pdf',
        ]);

        $response = $this->actingAs($user, 'api')
            ->get("/api/v1/amial/receipts/{$receipt->id}/download");

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    /** إيصال غيرك لا يُنزَّل — الإيصال يحمل مبلغاً وطرفاً. */
    public function test_another_users_receipt_is_not_downloadable(): void
    {
        $owner = $this->customer();
        $receipt = $this->receipt($owner);

        $this->actingAs($this->customer(), 'api')
            ->getJson("/api/v1/amial/receipts/{$receipt->id}/download")
            ->assertStatus(404);
    }

    /** عدّاد التنزيل يزيد — يُستعمل في كشف السحب المتكرّر المشبوه. */
    public function test_the_download_is_counted(): void
    {
        $user = $this->customer();
        $receipt = $this->receipt($user);

        $this->actingAs($user, 'api')
            ->get("/api/v1/amial/receipts/{$receipt->id}/download")->assertOk();

        $this->assertGreaterThan(0, (int) $receipt->fresh()->download_count);
    }
}
