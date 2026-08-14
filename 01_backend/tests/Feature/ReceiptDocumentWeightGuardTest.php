<?php

namespace Tests\Feature;

use App\Models\Receipt;
use App\Models\User;
use App\Services\ReceiptDocumentService;
use App\Support\ArabicPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * AMIAL-DOCUMENTS-002 — **صورةٌ غيرُ مصغَّرة أوقفت كلّ إيصال.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:** `public/branding/logo-full.png` يزن ٩٢٣ كيلوبايت
 * (١٢٥٤×١٢٥٤ بكسل)، وكان يُضمَّن base64 في HTML كلِّ إيصال — فيبلغ
 * المستندُ ١٫٢٤ ميغابايت. **والقالبُ يعرضه ١١٦×٦٦ بكسل.**
 *
 * وmPDF يرفض ما تجاوز `pcre.backtrack_limit`:
 *
 *     MpdfException: The HTML code size is larger than
 *     pcre.backtrack_limit 1000000
 *
 * **وإصدارُ الإيصال يستدعي توليدَ الـPDF**، فسقط معه ٧٦ اختباراً في
 * التحويل والشبّاك ودفعِ الطلبات — ولا واحدٌ منها يذكر شعاراً ولا صورة.
 *
 * **ولمَ حارسٌ لا إصلاحٌ وحده:** الشعارُ ملفٌّ يُستبدل من خارج الشيفرة.
 * فمن يرفع شعاراً أوضح غداً يُعيد العطلَ كاملاً، ولا يعلم — الرسالةُ
 * تتحدّث عن حدٍّ في PCRE.
 */
class ReceiptDocumentWeightGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **السقفُ نصفُ حدّ mPDF** — لا يساويه.
     *
     * فحدٌّ يساوي الحدَّ يمرّ اليوم ويسقط غداً مع أوّل سطرِ عنوانٍ أطول.
     */
    private const MAX_HTML_BYTES = 500_000;

    private function receipt(string $type): Receipt
    {
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE]);
        $merchant = User::factory()->create(['type' => MERCHANT_TYPE]);

        return Receipt::create([
            'receipt_number' => (string) random_int(100000000000, 999999999999),
            'verification_code' => (string) random_int(100000, 999999),
            'receipt_type' => $type,
            'user_id' => $customer->id,
            'counterparty_user_id' => $merchant->id,
            'reference_transaction_id' => 'TX-' . uniqid(),
            'amount' => '5000.0000',
            'fee' => '0.0000',
            'net_amount' => '5000.0000',
            'direction' => 'debit',
            'status' => 'pending_pdf',
            'metadata' => [],
            'zone_code' => 'SOUTH',
            'issued_at' => now(),
        ]);
    }

    private function html(Receipt $receipt): string
    {
        $documents = app(ReceiptDocumentService::class);
        $document = $documents->build($receipt);

        return View::make($documents->a4View($document), [
            'document' => $document,
            'qrDataUri' => null,
        ])->render();
    }

    /** **سندُ المحفظة وفاتورةُ التاجر — كلاهما يحمل الشعار.** */
    public function test_no_document_html_is_heavy_enough_to_break_mpdf(): void
    {
        foreach (['send_money' => 'سند محفظة',
                  'pay_merchant' => 'فاتورة تاجر'] as $type => $label) {
            $bytes = strlen($this->html($this->receipt($type)));

            $this->assertLessThan(self::MAX_HTML_BYTES, $bytes,
                "«{$label}» يزن " . number_format($bytes) . ' بايت — و mPDF '
                . 'يرفض ما تجاوز pcre.backtrack_limit فيسقط كلُّ إيصال. '
                . 'غالباً شعارٌ رُفع بحجمه الكامل ويُعرض مصغَّراً.');
        }
    }

    /** **ولا يُكتفى بالقياس** — يُولَّد PDF فعلاً. */
    public function test_a_wallet_voucher_actually_renders_to_pdf(): void
    {
        $bytes = ArabicPdf::render($this->html($this->receipt('send_money')),
            ['format' => 'A4', 'margin' => 12]);

        $this->assertStringStartsWith('%PDF', $bytes,
            'المخرَجُ ليس PDF — وصفحةُ خطأٍ تُحفَظ باسم إيصال');

        $this->assertGreaterThan(1000, strlen($bytes));
    }

    /**
     * **الشعارُ يُصغَّر مهما كان أصلُه** — وهذا ما يمنع عودةَ العطل.
     */
    public function test_the_platform_logo_is_downscaled_before_embedding(): void
    {
        $document = app(ReceiptDocumentService::class)->build($this->receipt('send_money'));

        $uri = (string) ($document['platform_logo_data'] ?? '');

        $this->assertNotSame('', $uri, 'الإيصالُ بلا شعار');

        $raw = base64_decode((string) preg_replace('#^data:[^,]+,#', '', $uri), true);

        $this->assertNotFalse($raw);

        $size = @getimagesizefromstring($raw);

        $this->assertIsArray($size, 'بيانات الشعار ليست صورةً صالحة');

        $this->assertLessThanOrEqual(240, $size[0],
            "الشعارُ مضمَّنٌ بعرض {$size[0]} بكسل والقالبُ يعرضه ١١٦ — "
            . 'وهو ما فجّر حجم المستند أوّل مرّة');

        // وملفُّ المصدر يبقى بحجمه الكامل — التصغيرُ للطباعة لا للأصل.
        $this->assertGreaterThan(240,
            (int) (@getimagesize(public_path('branding/logo-full.png'))[0] ?? 0),
            'أصلُ الشعار نفسُه صُغِّر — والتصغيرُ كان يجب أن يقع عند التضمين');
    }
}
