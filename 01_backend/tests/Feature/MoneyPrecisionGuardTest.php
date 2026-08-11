<?php

namespace Tests\Feature;

use App\Services\MoneyService;
use Tests\TestCase;

/**
 * AMIAL-WHOLESALE-FLOAT-001 — **لا يُحسب مالٌ بـ`float`.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `float` في PHP ثنائيٌّ مزدوجُ الدقّة: لا يمثّل الكسورَ العشريّة تمثيلاً
 * تامّاً. فـ`0.1 + 0.2` تساوي `0.30000000000000004`، وضربُ مبلغٍ كبيرٍ في
 * نسبةٍ ينحرف في الخانة الرابعة.
 *
 * **والانحرافُ لا يظهر في فاتورة، ويظهر في المجموع**: ألفُ فاتورةٍ
 * تُراكم فرقاً يقف عنده مدقّقٌ ولا يجد له سبباً — والدفترُ متوازنٌ لأنّ
 * الرقم الخطأ رُحّل مرّتين. وهو أسوأُ صنفٍ من الخلل الماليّ: **متّسقٌ
 * وخاطئ**.
 *
 * وُجد في `WholesaleInvoiceService`: الضريبةُ والعمولةُ تُحسبان
 * `(float)$x * $rate / 100`. و`MoneyService::mul` و`div` — مبنيّتان على
 * `bcmath` — كانتا موجودتين والحسابُ يتجاوزهما.
 */
class MoneyPrecisionGuardTest extends TestCase
{
    /**
     * **أوّلاً: يُثبَت أنّ الفرق حقيقيّ لا نظريّ.**
     *
     * حارسٌ يمنع نمطاً بلا إثبات ضرره يُحذف عند أوّل مراجعة.
     */
    public function test_float_arithmetic_really_does_drift_on_money(): void
    {
        $amount = '1234567.89';
        $rate = '7.5';

        $viaFloat = (string) ((float) $amount * (float) $rate / 100);
        $viaBc = MoneyService::div(MoneyService::mul($amount, $rate), '100');

        // النتيجتان تختلفان في الخانات الدقيقة — وهذا هو الفرق كلُّه.
        $this->assertNotSame(
            number_format((float) $viaFloat, 10, '.', ''),
            number_format((float) $viaBc, 10, '.', ''),
            'إن تطابقتا فقد تغيّرت دقّةُ PHP — راجع هذا الحارس بدل حذفه');
    }

    public function test_the_money_helpers_are_exact(): void
    {
        $this->assertSame('0.3000', MoneyService::add('0.1', '0.2'),
            'جمعُ المال ليس دقيقاً — والباقي مبنيٌّ عليه');

        // ١٬٢٣٤٬٥٦٧٫٨٩ × ٧٫٥٪ = ٩٢٬٥٩٢٫٥٩١٧٥ — و`bcmath` **يبتر** عند
        // أربع خانات لا يقرّب: ٩٢٬٥٩٢٫٥٩١٧.
        //
        // والبترُ هو الاختيار المحافظ في المال: التقريبُ لأعلى يمنح ما لم
        // يُكسَب، والبترُ يترك كسراً في الخزنة. وهو مثبَّتٌ هنا لأنّه سلوكٌ
        // يُعتمد عليه — وتغييرُه يوماً يجب أن يُسقط هذا الحارس لا أن يمرّ.
        $this->assertSame('92592.5917', MoneyService::div(
            MoneyService::mul('1234567.89', '7.5'), '100'));
    }

    /**
     * **ولا موضعَ يحسب نسبةً على مبلغٍ بـ`float`.**
     *
     * والنمطُ المرصود: `(float)$something * $rate / 100` — وهو ما كان في
     * الجملة. ويُبحث عنه في الخدمات وحدها: هناك يُحسب المال، والعرضُ في
     * المتحكّمات يُحوّل للطباعة لا للحساب.
     */
    public function test_no_service_computes_a_percentage_of_money_in_float(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Services'), \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            $file = (string) $f;

            if (! str_ends_with($file, '.php')) {
                continue;
            }

            foreach (file($file) as $i => $line) {
                // التعليقاتُ تُطرح — الدرسُ وقع ثلاثَ مرّاتٍ في هذا المشروع.
                $code = preg_replace('#(//|\#).*$#', '', ltrim($line));
                $code = preg_replace('#^\s*\*.*$#', '', (string) $code);

                // `(float)$x * $rate` أو `* $rate / 100` على متغيّرٍ
                if (! preg_match('#\(float\)\s*\$\w+\s*\*#', (string) $code)
                    && ! preg_match('#\*\s*\$\w*[Rr]ate\s*/\s*100#', (string) $code)) {
                    continue;
                }

                $offenders[] = str_replace(base_path() . '/', '', $file) . ':' . ($i + 1)
                    . '  ' . trim((string) $code);
            }
        }

        $this->assertSame([], $offenders, "\n"
            . 'حسابُ نسبةٍ على مبلغٍ بـfloat:' . "\n  "
            . implode("\n  ", $offenders) . "\n\n"
            . 'استعمل MoneyService::mul و div — فـfloat ينحرف في الخانة '
            . 'الرابعة، والانحرافُ يظهر في المجموع لا في الفاتورة، '
            . 'والدفترُ يبقى متوازناً لأنّ الرقم الخطأ رُحّل مرّتين.');
    }

    public function test_the_scan_actually_reads_the_services(): void
    {
        $n = iterator_count(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Services'), \FilesystemIterator::SKIP_DOTS)));

        $this->assertGreaterThan(40, $n, 'المسحُ لم يجد خدمات — الحارسُ معطَّل');
    }
}
