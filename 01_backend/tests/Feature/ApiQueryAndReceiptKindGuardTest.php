<?php

namespace Tests\Feature;

use App\Models\Receipt;
use Tests\TestCase;

/**
 * AMIAL-API-QUERY-001 · AMIAL-RECEIPT-TITLE-001 · AMIAL-PDF-DOWNLOAD-001
 *
 * ثلاثةُ أعطالٍ رآها صاحبُ المشروع، ولا واحدٌ منها يُنتج خطأً في سجلّ.
 */
class ApiQueryAndReceiptKindGuardTest extends TestCase
{
    private function app_(string $rel): string
    {
        return base_path('../02_flutter_app/' . $rel);
    }

    /**
     * @test
     *
     * **معاييرُ الاستعلام تُرسَل، لا تُستقبَل وتُرمى.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **أخطرُ عطلٍ في هذه الجولة، وأصمتُها:**
     *
     * `getData` كانت تستقبل `query` ثمّ تبني `Uri.parse(appBaseUrl + uri)`
     * **بلا أثرٍ له**. فثمانيةَ عشرَ نداءً في تسعة ملفّات تكتب معاييرها
     * وتصل الخادمَ عاريةً:
     *
     *   • مسحُ الباركود  ← الخادمُ بلا `barcode` ⇒ 422 ⇒ «تعذّر البحث»
     *   • كلُّ فلتر       ← يُضغط الزرُّ ولا تتغيّر القائمة
     *   • ترقيمُ الصفحات ← الصفحةُ الأولى دائماً
     *
     * **والخادمُ يردّ ردّاً صحيحاً على سؤالٍ ناقص** — فلا خطأ، ولا تحذير،
     * ولا شيءَ في أيّ سجلّ. أخطرُ من الانهيار: الانهيارُ يُرى.
     *
     * ويُفحص أنّ العنوانَ المبنيّ هو الذي يُطلَب — لا مجرّدَ وجود دالّة.
     */
    public function the_get_client_actually_sends_its_query_parameters(): void
    {
        $src = file_get_contents($this->app_('lib/data/api/api_client.dart'));

        $this->assertStringContainsString('String _withQuery(', $src,
            'لا بانيَ لسلسلة الاستعلام — المعايير تُرمى صامتةً');

        // **والمهمّ أنّ `getData` تستعمله فعلاً.** فدالّةٌ تُبنى ولا تُنادى
        // هي نمطُ العطل الأشهر في هذا المشروع.
        $this->assertMatchesRegularExpression(
            '/final full = _withQuery\(appBaseUrl \+ uri, query\);/', $src,
            'getData لا تنادي بانيَ الاستعلام — المعايير ما زالت تُرمى');

        // **ويُفحص جسدُ كلّ دالّةٍ تقبل `query` وحدَها.**
        //
        // فـ`postData` و`putData` و`deleteData` لا تقبل معايير أصلاً،
        // وبناؤها العنوانَ بلا `_withQuery` صوابٌ لا عطل. وحارسٌ يمسحُ
        // الملفَّ كلَّه يسقط عليها — فيُعطَّل أو يُوسَّع استثناؤه حتّى لا
        // يحرس شيئاً. (حارسٌ يصرخ في غير موضعه يُسكَت.)
        preg_match_all(
            '/Future<Response>\s+(\w+)\s*\([^)]*Map<String,\s*dynamic>\?\s+query[^)]*\)\s*async\s*\{(.*?)\n   \}/s',
            $src,
            $fns,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($fns,
            'لم تُقرأ أيُّ دالّةٍ تقبل query — التوقيعُ تغيّر والحارسُ يمرّ فارغاً');

        foreach ($fns as [, $name, $body]) {
            $this->assertStringNotContainsString('Uri.parse(appBaseUrl+uri)', $body,
                "{$name} تقبل معايير وتبني العنوانَ بدونها — تُرمى صامتةً");

            $this->assertStringContainsString('_withQuery(', $body,
                "{$name} تقبل معايير ولا تُلحقها بالعنوان");
        }
    }

    /**
     * @test
     *
     * **والوثيقةُ تُسمّى بما هي — لا «فاتورة» لكلّ شيء.**
     *
     * ══════════════════════════════════════════════════════════════════
     * خرج تحويلُ مالٍ بين عميلين بعنوان «فاتورة رسمية / TAX / OFFICIAL
     * INVOICE»، وفيه عمودا «الكمّية» و«السعر» و«تحويل أموال» صنفاً
     * بكمّية ١.
     *
     * **والفاتورةُ سندُ بيعٍ يُحتجّ به ضريبيّاً**، والتحويلُ ليس بيعاً.
     * فوثيقةٌ تُسمّي نفسَها غيرَ ما هي تُربك المحاسب، وقد تُسأل عنها
     * المنصّة.
     */
    public function a_transfer_receipt_is_titled_a_voucher_not_a_tax_invoice(): void
    {
        $tpl = file_get_contents(base_path('resources/views/receipts/a4-invoice.blade.php'));

        $this->assertStringNotContainsString(
            '<div class="doc-title">فاتورة رسمية</div>', $tpl,
            'عنوانُ الوثيقة ما زال ثابتاً — كلُّ إيصالٍ «فاتورة رسمية»');

        // **وتُقرأ الخريطةُ وحدَها لا الملفُّ كلُّه.**
        //
        // فـ`send_money` يرد أيضاً في `@case` أسفلَ القالب لتسمية البند.
        // وفحصُ وجوده في الملفّ يمرّ ولو حُذف من خريطة العناوين تماماً —
        // وقد جُرّب ذلك فمرّ. **حارسٌ يفحص وجودَ نصٍّ لا موضعَه لا يحرس.**
        $this->assertMatchesRegularExpression(
            '/\$docTitles = \[(.*?)\];/s', $tpl, 'خريطةُ العناوين مفقودة');

        preg_match('/\$docTitles = \[(.*?)\];/s', $tpl, $m);
        $map = $m[1];

        foreach (['send_money' => 'سند تحويل', 'cash_out' => 'سند صرف نقدي'] as $type => $label) {
            $this->assertMatchesRegularExpression(
                "/'{$type}'\s*=>\s*\['" . preg_quote($label, '/') . "'/u",
                $map,
                "نوعُ الإيصال {$type} بلا عنوانٍ «{$label}» في خريطة العناوين");
        }

        // **والافتراضيُّ يبقى فاتورة** — لا يُغيَّر سلوكُ أنواعٍ لم تُقَس.
        $this->assertStringContainsString("?? ['فاتورة رسمية', 'TAX / OFFICIAL INVOICE']", $tpl,
            'الافتراضيُّ لم يعد فاتورةً — تغيّر سلوكُ المبيعات معه');

        // وأعمدةُ البيع تُحذف من السند.
        $this->assertStringContainsString('@unless($isVoucher)', $tpl,
            'أعمدةُ الكمّية والسعر تظهر في السندات ولا معنى لها فيها');
    }

    /**
     * @test
     *
     * **والنوعُ الذي يُسمَّى سنداً هو نوعٌ يُنشَأ فعلاً.**
     *
     * فخريطةُ عناوينَ لأنواعٍ لا وجودَ لها تُطمئن ولا تعمل: يبقى
     * التحويلُ «فاتورةً» والخريطةُ تبدو مكتملة.
     */
    public function the_voucher_types_are_types_the_system_really_creates(): void
    {
        $used = Receipt::query()->distinct()->pluck('receipt_type')->filter()->all();

        // القاعدةُ قد تكون فارغةً في بيئة الفحص — فيُقرأ المصدرُ حينئذٍ.
        if ($used === []) {
            $svc = shell_exec(
                'grep -rhoE "\'receipt_type\' => \'[a-z_]+\'" '
                . escapeshellarg(base_path('app')) . ' 2>/dev/null');

            preg_match_all("/'receipt_type' => '([a-z_]+)'/", (string) $svc, $m);
            $used = array_unique($m[1] ?? []);
        }

        $this->assertNotEmpty($used, 'لم يُعرَف أيُّ نوعِ إيصالٍ — الفحصُ يمرّ فارغاً');

        $this->assertContains('send_money', $used,
            'نوعُ التحويل غيرُ موجود — راجع الخريطة في a4-invoice');
    }

    /**
     * @test
     *
     * **وزرُّ «تنزيل PDF» يُنزّل — لا ينسخ نصّاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كان ينسخ إلى الحافظة ويقول «افتح المتصفّح». والمنسوخُ **مسارُ API
     * نسبيّ** بلا نطاق، ولو كان كاملاً لما نفع: المسارُ خلف
     * `Authenticate:api` والمتصفّحُ بلا رمز.
     *
     * فالزرُّ يَعِد بتنزيلٍ ولا يفي، ويرسل صاحبَه إلى متصفّحٍ لا يفتح
     * شيئاً. (زرٌّ يعمل ويفعل الشيء الخطأ.)
     */
    public function the_pdf_button_downloads_instead_of_copying_a_link(): void
    {
        $client = file_get_contents($this->app_('lib/data/api/api_client.dart'));

        $this->assertStringContainsString('Future<String?> downloadFile(', $client,
            'لا تنزيلَ محمولاً بترويسة المصادقة');

        $this->assertStringContainsString('headers: _mainHeaders', $client,
            'التنزيلُ بلا ترويسة مصادقة — سيردّ الخادمُ 401');

        $screen = file_get_contents(
            $this->app_('lib/features/fuel_station/screens/fuel_sales_history_screen.dart'));

        $this->assertStringContainsString('downloadFile(', $screen,
            'زرُّ التنزيل لا ينادي التنزيل');

        // **ولا نسخَ رابطٍ تحت اسم «تنزيل».**
        $this->assertStringNotContainsString('افتح المتصفّح لتنزيل الإيصال', $screen,
            'ما زال الزرُّ ينسخ رابطاً ويسمّي ذلك تنزيلاً');

        // ويُفتح الملفُّ بعد حفظه — فملفٌّ يُحفظ ولا يُفتح كأنّه لم يُنزَّل.
        $this->assertStringContainsString('OpenFile.open(', $screen,
            'الملفُّ يُحفظ ولا يُفتح — لا يعرف المستعملُ أين ذهب');
    }
}
