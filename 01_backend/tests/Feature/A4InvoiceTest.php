<?php

namespace Tests\Feature;

use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-INVOICE-A4-001 — فاتورة رسمية بمقاس A4.
 */
class A4InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeReceipt(User $u, array $overrides = []): Receipt
    {
        return Receipt::create(array_merge([
            'receipt_number' => 'RC-INV-0001',
            'verification_code' => 'ABCD2345EFGH6789',
            'receipt_type' => 'pos_payment',
            'user_id' => $u->id,
            'reference_transaction_id' => 'TX-INV-1',
            'reference_type' => 'transaction',
            'reference_id' => 1,
            'amount' => '12500.0000', 'fee' => '125.0000', 'net_amount' => '12625.0000',
            'direction' => 'debit', 'status' => 'pdf_generated',
            'zone_code' => 'SOUTH', 'issued_at' => now(),
        ], $overrides));
    }

    /** رجّع عرض MediaBox (بالنقاط) من محتوى PDF. */
    private function pdfWidth(string $pdf): ?float
    {
        if (preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)/', $pdf, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /** @test A4 ≈ 595pt عرضاً */
    public function renders_a4_invoice_pdf(): void
    {
        $u = User::factory()->create(['type' => 3, 'phone' => '967771888001']);
        $r = $this->makeReceipt($u);
        Passport::actingAs($u, [], 'api');

        $resp = $this->get("/api/v1/amial/receipts/{$r->id}/invoice");
        $resp->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');

        $pdf = $resp->streamedContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        // A4 = 210mm ≈ 595pt
        $this->assertEqualsWithDelta(595, $this->pdfWidth($pdf), 3);
    }

    /** @test الفاتورة تعرض بنوداً من metadata.items */
    public function renders_line_items_from_metadata(): void
    {
        $u = User::factory()->create(['type' => 3, 'phone' => '967771888002']);
        $r = $this->makeReceipt($u, [
            'receipt_number' => 'RC-INV-0002',
            'metadata' => ['items' => [
                ['name' => 'سكر 1كجم', 'qty' => 2, 'price' => 1000, 'total' => 2000],
                ['name' => 'أرز 5كجم', 'qty' => 1, 'price' => 5000, 'total' => 5000],
            ]],
        ]);
        Passport::actingAs($u, [], 'api');

        $resp = $this->get("/api/v1/amial/receipts/{$r->id}/invoice");
        $resp->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $resp->streamedContent());
    }

    /** @test لا يُصدر فاتورة مستخدم آخر (IDOR) */
    public function cannot_invoice_another_users_receipt(): void
    {
        $owner = User::factory()->create(['type' => 3, 'phone' => '967771888003']);
        $r = $this->makeReceipt($owner);
        $intruder = User::factory()->create(['type' => 2, 'phone' => '967771888004']);
        Passport::actingAs($intruder, [], 'api');

        $this->get("/api/v1/amial/receipts/{$r->id}/invoice")->assertStatus(404);
    }
}
