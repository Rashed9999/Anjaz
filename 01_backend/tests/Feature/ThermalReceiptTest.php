<?php

namespace Tests\Feature;

use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-THERMAL-001 — إيصال حراري 58/80مم لطابعات POS.
 */
class ThermalReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function makeReceipt(User $u): Receipt
    {
        return Receipt::create([
            'receipt_number' => 'RC-TEST-0001',
            'verification_code' => 'ABCD2345EFGH6789',
            'receipt_type' => 'pos_payment',
            'user_id' => $u->id,
            'reference_transaction_id' => 'TX-1',
            'reference_type' => 'transaction',
            'reference_id' => 1,
            'amount' => '5000.0000', 'fee' => '50.0000', 'net_amount' => '4950.0000',
            'direction' => 'debit', 'status' => 'pdf_generated',
            'zone_code' => 'SOUTH', 'issued_at' => now(),
        ]);
    }

    /** رجّع عرض MediaBox (بالنقاط) من محتوى PDF. */
    private function pdfWidth(string $pdf): ?float
    {
        if (preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)/', $pdf, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /** @test */
    public function renders_58mm_thermal_pdf(): void
    {
        $u = User::factory()->create(['type' => 2, 'phone' => '967771999001']);
        $r = $this->makeReceipt($u);
        Passport::actingAs($u, [], 'api');

        $resp = $this->get("/api/v1/amial/receipts/{$r->id}/thermal?size=58");
        $resp->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');

        $pdf = $resp->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        // 58مم ≈ 164pt
        $this->assertEqualsWithDelta(164, $this->pdfWidth($pdf), 2);
    }

    /** @test */
    public function renders_80mm_thermal_pdf(): void
    {
        $u = User::factory()->create(['type' => 2, 'phone' => '967771999002']);
        $r = $this->makeReceipt($u);
        Passport::actingAs($u, [], 'api');

        $resp = $this->get("/api/v1/amial/receipts/{$r->id}/thermal?size=80");
        $resp->assertStatus(200);
        $pdf = $resp->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        // 80مم ≈ 227pt
        $this->assertEqualsWithDelta(227, $this->pdfWidth($pdf), 2);
    }

    /** @test AMIAL-THERMAL: مقاس غير مدعوم يعود لـ58مم افتراضياً */
    public function invalid_size_defaults_to_58mm(): void
    {
        $u = User::factory()->create(['type' => 2, 'phone' => '967771999003']);
        $r = $this->makeReceipt($u);
        Passport::actingAs($u, [], 'api');

        $resp = $this->get("/api/v1/amial/receipts/{$r->id}/thermal?size=999");
        $resp->assertStatus(200);
        $this->assertEqualsWithDelta(164, $this->pdfWidth($resp->getContent()), 2);
    }

    /** @test AMIAL-THERMAL: لا يُطبع إيصال مستخدم آخر (IDOR) */
    public function cannot_print_another_users_receipt(): void
    {
        $owner = User::factory()->create(['type' => 2, 'phone' => '967771999004']);
        $r = $this->makeReceipt($owner);
        $intruder = User::factory()->create(['type' => 2, 'phone' => '967771999005']);
        Passport::actingAs($intruder, [], 'api');

        $this->get("/api/v1/amial/receipts/{$r->id}/thermal?size=58")->assertStatus(404);
    }
}
