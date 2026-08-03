<?php

namespace Tests\Feature;

use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-PDF-TRUNCATION-001 — الإيصال يصل كاملاً أو لا يُدّعى أنّه وصل.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل: «ClientException: Connection closed while receiving data».**
 *
 * وصنفُه أخبثُ ما في نقل الملفّات: **الردّ يبدأ صحيحاً ثمّ ينقطع**. فلا
 * حالةَ خطأ تُقرأ، ولا سطرَ في سجلّ التطبيق — لأنّ التطبيق أنهى عمله
 * بنجاح، والقطع بعده.
 *
 * وسببُه دائماً واحدٌ من اثنين:
 *   • طولٌ معلَنٌ يفارق المُرسَل ⇒ العميل ينتظر بايتاتٍ لا تأتي.
 *   • طبقةٌ وسيطة تعجز عن تمرير ما زاد عن مخزنها.
 *
 * والثاني لا يُمسَك من PHP. **والأوّل يُمسَك هنا** — وهو ما يحرسه هذا
 * الملفّ: أن يكون ما يُعلَن هو ما يُرسَل، بايتاً بايتاً.
 */
class ReceiptDownloadIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function makeReceipt(): array
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'راشد', 'l_name' => 'معرابي', 'phone' => '967999000333',
            'type' => CUSTOMER_TYPE, 'password' => bcrypt('x123456'),
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();

        $r = new Receipt();
        $r->forceFill([
            'user_id' => $u->id,
            'receipt_number' => 'AMY-' . date('Ymd') . '-INTEG1',
            'verification_code' => 'K3T2YWA8',
            'receipt_type' => 'send_money',
            'reference_transaction_id' => 'INTEG1',
            'direction' => 'debit',
            'amount' => '3500', 'fee' => '0', 'net_amount' => '3500',
            'status' => 'pending_pdf',
        ])->save();

        return [$u, $r];
    }

    /**
     * @test
     *
     * **ما يُعلَن هو ما يُرسَل.** وأيّ فارقٍ بينهما يُنتج بالضبط
     * «انقطع الاتّصال أثناء استقبال البيانات» عند العميل.
     */
    public function the_declared_length_equals_the_bytes_actually_sent(): void
    {
        [$u, $r] = $this->makeReceipt();

        $res = $this->actingAs($u, 'api')->get("/api/v1/amial/receipts/{$r->id}/download");

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));

        $body = $res->streamedContent();
        $declared = $res->headers->get('Content-Length');

        $this->assertNotNull($declared, 'لا طولَ معلَن — والعميل لا يعرف متى ينتهي');
        $this->assertSame((int) $declared, strlen($body),
            'الطول المعلَن يفارق المُرسَل — وهو بالضبط ما يقطع الاتّصال عند العميل');
    }

    /** @test */
    public function the_body_is_a_real_pdf_not_an_error_page(): void
    {
        [$u, $r] = $this->makeReceipt();

        $body = $this->actingAs($u, 'api')
            ->get("/api/v1/amial/receipts/{$r->id}/download")
            ->assertOk()->streamedContent();

        // **الترويسة تُفحص لا الحالة وحدها.** ردٌّ ٢٠٠ يحمل صفحة خطأٍ
        // بصيغة HTML يبدو نجاحاً في كلّ سجلّ، ويفشل عند فتحه وحده.
        $this->assertSame('%PDF', substr($body, 0, 4),
            'الجسم ليس PDF — ٢٠٠ تحمل شيئاً آخر');
        $this->assertGreaterThan(1000, strlen($body), 'ملفٌّ أصغر من أن يكون إيصالاً');
    }

    /**
     * @test
     *
     * **ولا يُضغط.** فPDF مضغوطٌ أصلاً، وضغطُه في طبقةٍ وسيطة يجعل الطولَ
     * المعلَن يفارق المُرسَل — نفس العطل من بابٍ آخر.
     */
    public function the_pdf_is_never_compressed_in_transit(): void
    {
        [$u, $r] = $this->makeReceipt();

        $res = $this->actingAs($u, 'api')
            ->get("/api/v1/amial/receipts/{$r->id}/download")->assertOk();

        $this->assertSame('identity', $res->headers->get('Content-Encoding'));
    }

    /** @test */
    public function a_receipt_of_another_user_is_not_served(): void
    {
        [$u, $r] = $this->makeReceipt();

        $other = new User();
        $other->forceFill([
            'f_name' => 'آخر', 'l_name' => 'مستخدم', 'phone' => '967999000444',
            'type' => CUSTOMER_TYPE, 'password' => bcrypt('x123456'),
            'is_active' => 1, 'zone_code' => 'SOUTH',
        ])->save();

        $this->actingAs($other, 'api')
            ->get("/api/v1/amial/receipts/{$r->id}/download")
            ->assertStatus(404);
    }

    /**
     * @test
     *
     * **الطبقةُ التي لا يمسكها PHP تُحرَس في ضبط الخادم.**
     *
     * فما زاد عن مخازن FastCGI يُكتب إلى ملفٍّ مؤقّت، ومجلّدُه إن لم يكن
     * مملوكاً لمستخدم nginx تفشل الكتابة **بعد إرسال الترويسات** فتُقطع
     * الاستجابة. ولا يظهر ذلك في أيّ سجلّ تطبيق.
     *
     * ولا يُختبَر ذلك بطلبٍ من PHP — فيُحرَس بقراءة الضبط نفسه.
     */
    public function the_server_config_can_carry_a_receipt_without_touching_disk(): void
    {
        $nginx = file_get_contents(base_path('docker/nginx.conf'));

        $this->assertMatchesRegularExpression('/fastcgi_buffers\s+\d+\s+\d+k/', $nginx,
            'لا مخازن FastCGI مضبوطة — والافتراضيّ ٣٢ كيلوبايت، وإيصالُنا ١٠٢');

        preg_match('/fastcgi_buffers\s+(\d+)\s+(\d+)k/', $nginx, $m);
        $totalKb = (int) $m[1] * (int) $m[2];

        $this->assertGreaterThanOrEqual(512, $totalKb,
            "مجموع المخازن {$totalKb} كيلوبايت — أصغر من أن يحمل إيصالاً بلا لمس القرص");

        // والمجلّد المؤقّت موجودٌ ومملوك — للأحجام التي تتجاوز المخازن.
        $docker = file_get_contents(base_path('Dockerfile'));
        $this->assertStringContainsString('/var/lib/nginx/tmp', $docker,
            'مجلّد nginx المؤقّت لا يُنشأ — فالردّ الكبير يُقطع في منتصفه');
        $this->assertMatchesRegularExpression('/chown -R www-data:www-data \/var\/lib\/nginx/', $docker,
            'المجلّد المؤقّت غير مملوك لمستخدم nginx — فتفشل الكتابة بعد الترويسات');
    }
}
