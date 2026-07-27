<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-PDF-SURFACE-001 — كل نقطة تُخرج PDF تمرّ على الذاكرة المخبّأة.
 *
 * **لماذا وُجد هذا الملفّ:**
 * فشل تنزيل الإيصال أُصلح، ثم سُئل سؤال بسيط: «وفواتير المشتريات؟». فتبيّن
 * أن المُصلَح مسارٌ واحد من عدّة، وأن البقيّة تُصيّر في كل طلب بلا تخزين —
 * أي أنها تحمل العطل نفسه وتنتظر أن يصطدم بها أحد.
 *
 * والخطر باقٍ ما دام الإصلاح يدوياً: كل مستند جديد — ملفّ بيانات عميل،
 * استمارة تسجيل، شهادة، عقد — سيُكتب على النمط القديم لأنه النمط الظاهر في
 * الملفّات المجاورة، ولن يشتكي شيء.
 *
 * فهذا الفحص يجعل النسيان مستحيلاً: من يضيف نقطة PDF بلا تخزين يسقط هنا،
 * ويقرأ في رسالة السقوط سببَ الاشتراط.
 *
 * وهو فحصٌ بنيويّ لا سلوكيّ — يتأكّد أن الطبقة مُستدعاة، لا أنها عملت.
 * سلوكها مفحوص في PdfCacheServiceTest. والاثنان معاً يغطّيان: هل تعمل
 * الطبقة، وهل تُستعمل حيث يجب.
 */
class PdfSurfaceGuardTest extends TestCase
{
    /**
     * نقاط مستثناة، ولكل واحدة سبب مكتوب.
     *
     * الاستثناء بسبب مكتوب أفضل من قائمة صامتة: من يقرأها يعرف لماذا، ومن
     * يشكّ يراجع. وقائمة فارغة الأسباب تصير مكاناً يُخبَّأ فيه ما لا يُراد فحصه.
     */
    private const EXEMPT = [
        // تصدير لوحة الإدارة من متصفّح على شبكة ثابتة، ومخرجاته تتبدّل بكل
        // مرشّح يختاره المسؤول — فمفتاح التخزين يصير أطول من فائدته.
        'Admin/TransactionController.php' => 'تصدير إداري متغيّر المرشّحات، من متصفّح لا من جوّال',
    ];

    public function test_every_pdf_endpoint_uses_the_cache_layer(): void
    {
        $offenders = [];

        foreach ($this->controllerFiles() as $file) {
            $code = file_get_contents($file);

            // هل يُخرج هذا الملفّ PDF أصلاً؟
            $producesPdf = str_contains($code, "'application/pdf'")
                || str_contains($code, 'Mpdf')
                || preg_match('/->download\([\'"][^\'"]*\.pdf/', $code);

            if (!$producesPdf) {
                continue;
            }

            $short = $this->shortName($file);
            if (isset(self::EXEMPT[$short])) {
                continue;
            }

            // الشرط الحقيقي ليس «استعمل هذه الخدمة» بل «لا تُصيّر في كل طلب».
            //
            // فالإيصال يحفظ مساره في صفّه (`pdf_storage_path`) ويُخدَم منه —
            // وهي آلية أقوى لا أضعف: المسار مرتبط بسجلّ لا بملفّ عابر. فقبولها
            // صحيح، ورفضُها يدفع إلى تكرار عمل قائم لأجل شكل الفحص.
            $isCached = str_contains($code, 'PdfCacheService')
                || str_contains($code, 'pdf_storage_path');

            if (!$isCached) {
                $offenders[] = $short;
            }
        }

        $this->assertSame([], $offenders,
            "نقاط تُخرج PDF بلا تخزين: " . implode('، ', $offenders) . "\n"
            . 'كل تصيير داخل الطلب يعرّض الاتصال للقطع على شبكة جوّال '
            . '(Connection closed while receiving data) — وهو عطلٌ وقع فعلاً. '
            . 'مرّرها على PdfCacheService::remember، أو أضفها إلى EXEMPT بسبب مكتوب.');
    }

    /** والذي يُخرج PDF يجب أن يُعلن طوله — وإلّا لم يعرف العميل أنه بُتر. */
    public function test_every_pdf_response_declares_its_length(): void
    {
        $offenders = [];

        foreach ($this->controllerFiles() as $file) {
            $code = file_get_contents($file);
            if (!str_contains($code, "'application/pdf'")) {
                continue;
            }

            $short = $this->shortName($file);
            if (isset(self::EXEMPT[$short])) {
                continue;
            }

            // نعدّ ترويسات النوع والطول: لكل استجابة PDF طولٌ يقابلها.
            $types = substr_count($code, "'Content-Type' => 'application/pdf'");
            $lengths = substr_count($code, "'Content-Length'");

            if ($lengths < $types) {
                $offenders[] = "$short ($types استجابة، $lengths طولاً)";
            }
        }

        $this->assertSame([], $offenders,
            "استجابات PDF بلا Content-Length: " . implode('، ', $offenders) . "\n"
            . 'بلا الطول لا يميّز العميل ملفّاً اكتمل من ملفّ انقطع في منتصفه، '
            . 'فيحفظ نصف إيصال ويفتحه فيراه تالفاً بلا سبب ظاهر.');
    }

    /** @return string[] */
    private function controllerFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers')));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    private function shortName(string $path): string
    {
        $parts = explode('Controllers/', $path);
        return end($parts);
    }
}
