<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-BRAND-WEIGHT-001 — وزنُ صور العلامة: حيث يُعطِّل الحجمُ وحدَه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطلُ الذي وُلد منه هذا الملفّ — وهو من صنعي:**
 *
 * رُفع الشعارُ الجديد فوُضع كما وصل: ١٬٠٢٤٬٣٩٠ بايت مكان ٥٣٬٦٦١ — تسعةَ
 * عشرَ ضعفاً. لا خطأ في الرفع، ولا في القالب، ولا في الشيفرة.
 *
 * **وسقط توليدُ كلّ إيصالات PDF في المنصّة — واحدٌ وعشرون فشلاً.**
 *
 * لأنّ `GeneratePdfReceiptJob::logoDataUri()` يُضمّن الشعارَ في القالب
 * بترميز base64 (وهو ضرورة: mPDF لا يقرأ مساراتٍ نسبيّةً من قالبٍ
 * مُصيَّرٍ خارج سياق HTTP). و base64 يُضخّم الحجمَ الثلثَ، فصار الـHTML
 * فوق `pcre.backtrack_limit` = 1,000,000 — فرمى mPDF:
 *
 *     The HTML code size is larger than pcre.backtrack_limit 1000000
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ حارسٌ وقد أمسكه `AgentCounterReceiptTest` فعلاً:**
 *
 * أمسكه — لكنّه قال «واحدٌ وعشرون فشلاً في الإيصالات»، والسببُ الحقيقيّ
 * كان مدفوناً في سجلٍّ مبتلَع لا يُقرأ إلّا بالحفر. ومن يرفع الشعارَ
 * القادم لن يربط بين «صورةٌ أجمل» و«الإيصالاتُ سقطت».
 *
 * **فالحارسُ هنا لا يمسك ما لم يُمسَك — يمسكه باسمه.** يسقط قبل توليد
 * أيّ PDF، ويقول ما الحدُّ وكم تجاوزَه وما العمل.
 *
 * (القاعدة الثانية عشرة في وجهها الآخر: ليس كلُّ تغييرٍ يظهر في شاشة —
 * وهذا يظهر في **الإيصالات كلّها**، وغيابُه يُظهر لا شيء.)
 */
class BrandAssetWeightGuardTest extends TestCase
{
    /**
     * الهامشُ المتروك لبقيّة الـHTML بعد الشعار.
     *
     * القالبُ نفسُه ٩ كيلوبايت، لكنّ mPDF يقيس ما بعد التصيير لا المصدر،
     * والإيصالُ يحمل بيانات العمليّة ورمزَ التحقّق. فيُترك نصفُ الحدّ
     * تقريباً — لا يُضبط الحارسُ على حافّة ما يمرّ اليوم.
     */
    private const HTML_HEADROOM = 500_000;

    /**
     * @test
     *
     * **الشعارُ المُضمَّن في الإيصال يبقى تحت حدّ mPDF بعد base64.**
     *
     * ══════════════════════════════════════════════════════════════════
     * ويُقاس **الملفُّ الذي يختاره الكود فعلاً** لا ملفٌّ يُفترض: الدالّة
     * تجرّب ثلاثة مساراتٍ بالترتيب وتأخذ أوّلَ موجود. فلو حَرَس الاختبارُ
     * `branding/logo.png` وحدَه، ثمّ وُضع `logo.png` في الجذر لسبقه في
     * الترتيب — **ويحرس الحارسُ ملفّاً لا يُقرأ.**
     */
    public function the_receipt_logo_stays_under_the_pdf_engine_limit(): void
    {
        // نفسُ الترتيب في GeneratePdfReceiptJob::logoDataUri().
        $chosen = null;

        foreach (['branding/logo.png', 'logo.png', 'images/logo.png'] as $rel) {
            $abs = public_path($rel);

            if (is_file($abs) && is_readable($abs)) {
                $chosen = $abs;
                break;
            }
        }

        // غيابُ الشعار كلِّه ليس عطلاً هنا: الدالّة تُرجع سلسلةً فارغة
        // والإيصالُ يخرج بلا شعار. («غير معروف» ليس صفراً — يُقال صراحةً.)
        if ($chosen === null) {
            $this->markTestSkipped('لا شعارَ في أيٍّ من المسارات الثلاثة — الإيصالُ يخرج بلا شعار.');
        }

        $bytes = filesize($chosen);

        // base64 يُخرج أربعةَ محارفَ لكلّ ثلاثة بايتات.
        $encoded = (int) ceil($bytes / 3) * 4;

        $limit = (int) ini_get('pcre.backtrack_limit') ?: 1_000_000;
        $ceiling = $limit - self::HTML_HEADROOM;

        $this->assertLessThan(
            $ceiling,
            $encoded,
            sprintf(
                "شعارُ الإيصال %s حجمُه %s بايت، وبعد base64 %s — والسقفُ %s "
                . "(حدّ mPDF %s ناقصَ هامشِ بقيّة الـHTML).\n"
                . "وتجاوزُه يُسقط توليدَ كلّ إيصالات PDF في المنصّة برسالة "
                . "«The HTML code size is larger than pcre.backtrack_limit».\n"
                . "الحلّ: صغِّر الصورة (٥١٢ بكسل تكفي — تُعرَض بعرض ١٢٨) "
                . "واحفظ الأصلَ في public/branding/logo-full.png.",
                basename($chosen),
                number_format($bytes),
                number_format($encoded),
                number_format($ceiling),
                number_format($limit),
            ),
        );
    }

    /**
     * @test
     *
     * **ولا صورةَ في حزمة التطبيق لا يرسمها سطرُ شيفرةٍ واحد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا عطلٌ آخرُ من صنعي، أُمسك قبل أن يُدفَع:**
     *
     * سُجّل `assets/brand/` كاملاً في `pubspec.yaml`، ووُضعت فيه ورقةُ
     * متغيّرات العلامة (٢٫٣ ميغا) ومواصفةُ السبلاش (١٫١٥ ميغا) — صورتان
     * **مرجعيّتان للتصميم لا يرسمهما أيُّ ودجت**.
     *
     * فكانتا تُشحنان في كلّ نسخةٍ من التطبيق: **٣٫٤ ميغابايت في جيب كلّ
     * مستعمل، على شبكةٍ يمنيّة، بلا فائدةٍ واحدة**. ولا خطأ في أيّ بناء،
     * ولا تحذير في أيّ سجلّ — الحزمةُ تكبر صامتة.
     *
     * وتسجيلُ مجلّدٍ كاملاً في Flutter يعني **كلَّ ما يقع فيه لاحقاً**،
     * فالعطلُ يعود مع أوّل صورةٍ مرجعيّةٍ تُحفظ هناك.
     */
    public function no_bundled_brand_asset_goes_undrawn(): void
    {
        $dir = base_path('../02_flutter_app/assets/brand');

        $this->assertDirectoryExists($dir, 'مجلّدُ طبقات الشعار مفقود');

        $lib = base_path('../02_flutter_app/lib');
        $sources = '';

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib));

        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'dart') {
                $sources .= file_get_contents($f->getPathname());
            }
        }

        foreach (glob($dir . '/*') as $asset) {
            $name = basename($asset);

            $this->assertStringContainsString(
                "assets/brand/{$name}",
                $sources,
                "الصورةُ {$name} (" . number_format(filesize($asset)) . " بايت) "
                . "تُشحن في حزمة التطبيق ولا يرسمها أيُّ سطرِ Dart.\n"
                . "والمجلّدُ مسجَّلٌ كاملاً في pubspec — فكلُّ ما يقع فيه يُشحن.\n"
                . "إن كانت مرجعاً للتصميم فمكانُها docs/العلامة/ لا حزمةُ التطبيق.",
            );
        }
    }
}
