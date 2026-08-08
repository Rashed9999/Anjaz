<?php

namespace Tests\Feature;

use App\Models\PaymentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-ICON-002 · AMIAL-REQ-QR-001 · AMIAL-REQ-URL-001
 *
 * ثلاثةُ أعطالٍ رآها صاحبُ المشروع على جهازه، وثلاثتُها من صنفٍ واحد:
 * **يُبنى، ويعمل، ولا خطأ في أيّ سجلّ — والنتيجةُ خاطئةٌ في وجه المستعمل.**
 */
class AppIconAndRequestQrGuardTest extends TestCase
{
    use RefreshDatabase;

    private function app_(string $rel): string
    {
        return base_path('../02_flutter_app/' . $rel);
    }

    /**
     * @test
     *
     * **الأيقونتان — المربّعة والمستديرة — تشيران إلى المصدر نفسِه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `ic_launcher.xml` يولّده `flutter_launcher_icons` ويشير إلى
     * `@drawable/ic_launcher_foreground`. و`ic_launcher_round.xml` كان
     * **مكتوباً بيد** ويشير إلى `@mipmap/ic_launcher_foreground`.
     *
     * فلمّا جُدّد الشعار تغيّرت المربّعةُ وحدها. **والمانيفست يعلن
     * `android:roundIcon`** — فمعظمُ مشغّلات أندرويد تعرض المستديرة، أي
     * الشعارَ القديم. علامتان في جهازٍ واحد، والبناءُ ينجح صامتاً.
     */
    public function the_round_and_square_launcher_icons_share_one_source(): void
    {
        $res = $this->app_('android/app/src/main/res');

        $square = @file_get_contents($res . '/mipmap-anydpi-v26/ic_launcher.xml');
        $round = @file_get_contents($res . '/mipmap-anydpi-v26/ic_launcher_round.xml');

        $this->assertNotFalse($square, 'ic_launcher.xml مفقود');
        $this->assertNotFalse($round, 'ic_launcher_round.xml مفقود');

        $fg = static function (string $xml): ?string {
            preg_match('/android:drawable="(@[a-z]+\/ic_launcher_foreground)"/', $xml, $m);

            return $m[1] ?? null;
        };

        $sq = $fg($square);
        $rd = $fg($round);

        $this->assertNotNull($sq, 'ic_launcher.xml بلا مقدّمة');

        $this->assertSame($sq, $rd,
            "الأيقونة المستديرة تشير إلى مصدرٍ غير الذي تشير إليه المربّعة —\n"
            . 'المربّعة: ' . var_export($sq, true) . ' · المستديرة: ' . var_export($rd, true) . "\n"
            . 'فتجديدُ الشعار يغيّر واحدةً ويترك الأخرى، والمانيفست يعلن roundIcon.');
    }

    /**
     * @test
     *
     * **ولا أيقونةٍ نقطيّةٍ أقدمَ من مصدرها.**
     *
     * فأندرويد ما دون 26 يقرأ ملفّات `mipmap-…` النقطيّة مباشرةً، لا
     * الـXML. وملفٌّ نقطيٌّ لم يُعَد توليدُه يحمل الشعارَ القديم على تلك
     * الأجهزة وحدها — **وهي التي لا نملكها للتجربة**.
     */
    public function no_launcher_bitmap_is_older_than_its_source(): void
    {
        $master = $this->app_('assets/branding/icon_square_master.png');

        $this->assertFileExists($master, 'أصلُ الأيقونة مفقود');

        $srcTime = filemtime($master);
        $stale = [];

        foreach (['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'] as $d) {
            foreach (['ic_launcher.png', 'ic_launcher_round.png'] as $f) {
                $p = $this->app_("android/app/src/main/res/mipmap-{$d}/{$f}");

                if (! is_file($p)) {
                    $stale[] = "mipmap-{$d}/{$f} — مفقود";
                    continue;
                }

                if (filemtime($p) < $srcTime) {
                    $stale[] = sprintf('mipmap-%s/%s — %s (والأصل %s)',
                        $d, $f,
                        date('Y-m-d H:i', filemtime($p)),
                        date('Y-m-d H:i', $srcTime));
                }
            }
        }

        $this->assertSame([], $stale,
            "أيقوناتٌ أقدمُ من أصلها — تحمل الشعارَ السابق:\n  "
            . implode("\n  ", $stale) . "\n"
            . 'الحلّ: cd 02_flutter_app && dart run flutter_launcher_icons');
    }

    /**
     * @test
     *
     * **ورمزُ QR يظهر في شاشة الطلب — بالحمولة التي يفهمها الماسح.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كانت الشاشةُ تعرض الرمزَ القصير والرابطَ نصّاً ولا رمزَ QR إطلاقاً.
     * فيقول الطالبُ للدافع «امسح» ولا شيءَ يُمسح.
     *
     * **وشاشةُ محطّة الوقود كانت تعرضه منذ جولات** بالحمولة نفسِها — فأُصلح
     * مدخلٌ وتُرك الآخر. (القاعدة الرابعة.)
     *
     * ويُفحص **شكلُ الحمولة** لا وجودُ الودجت: رمزٌ بصيغةٍ أخرى يُطبع
     * ويُعلَّق على الصندوق ويُمسح — **ولا يقع شيء**.
     */
    public function the_request_screen_shows_a_scannable_qr(): void
    {
        $src = file_get_contents(
            $this->app_('lib/features/requested_money/screens/payment_request_show_screen.dart'));

        $this->assertStringContainsString('QrDisplayWidget', $src,
            'شاشةُ الطلب بلا رمز QR — يقول الطالبُ «امسح» ولا شيءَ يُمسح');

        $this->assertMatchesRegularExpression(
            "/'t'\s*:\s*'amial_pr'/", $src,
            'الحمولةُ ليست {"t":"amial_pr",…} — يُمسح الرمزُ ولا يقع شيء');

        $this->assertMatchesRegularExpression(
            "/'code'\s*:\s*shortCode/", $src,
            'الحمولةُ بلا الرمز القصير — الماسحُ يقرأ ولا يجد الطلب');
    }

    /**
     * @test
     *
     * **ولا أيقونةٍ تتظاهر برمز QR.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كان في شاشة الطلب `Icon(Icons.qr_code_2)` بحجم ١٤٠ داخل إطارٍ
     * أبيض، وتحته «سيُولَّد رمز QR كامل قريباً».
     *
     * **وهي أسوأُ من غياب الرمز**: يعرضها التاجرُ على العميل، فيصوّب
     * العميلُ ماسحَه فلا يُقرأ شيء — فيظنّ هاتفَه معطوباً أو التطبيقَ
     * لا يعمل. ولا خطأ في أيّ سجلّ.
     *
     * ويُمسح كلُّ ملفٍّ لا هذا وحده: النمطُ يتكرّر حيث يُؤجَّل بناءُ رمز.
     */
    public function no_icon_pretends_to_be_a_qr_code(): void
    {
        $lib = base_path('../02_flutter_app/lib');
        $fake = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib));

        foreach ($it as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'dart') {
                continue;
            }

            // **وتُجرَّد التعليقات قبل المسح.**
            //
            // فالتعليقُ الذي يشرح حذفَ العطل يذكر نصَّه، فيُمسكه الحارسُ
            // ويسقط على ما أُصلح. وهو المكتوبُ في القاعدة الثانية معكوساً:
            // هناك مرَّ حارسٌ لأنّ الكلمة وردت في تعليقٍ يشرح العطل، وهنا
            // يسقط لأنّ التعليق يشرح إصلاحَه. **والشيفرةُ تُقاس لا وصفُها.**
            $src = preg_replace(
                ['#/\*.*?\*/#s', '#^\s*//.*$#m'],
                '',
                file_get_contents($f->getPathname()),
            );

            // أيقونةُ رمزٍ بحجمٍ كبير = مربّعٌ يتظاهر برمزٍ يُمسح.
            // والأيقونةُ الصغيرة في زرٍّ أو عنوانٍ مقصودةٌ ولا تُمنع.
            if (preg_match('/Icons\.qr_code[_0-9a-z]*\s*,\s*size:\s*(\d+)/', $src, $m)
                && (int) $m[1] >= 100) {
                $fake[] = str_replace($lib . '/', '', $f->getPathname())
                    . " — أيقونة بحجم {$m[1]}";
            }

            if (str_contains($src, 'سيُولَّد رمز QR')) {
                $fake[] = str_replace($lib . '/', '', $f->getPathname()) . ' — وعدٌ بلا رمز';
            }
        }

        $this->assertSame([], $fake,
            "أيقونةٌ تتظاهر برمز QR:\n  " . implode("\n  ", $fake) . "\n"
            . 'يصوّب العميلُ ماسحَه فلا يُقرأ شيء، ويظنّ هاتفَه معطوباً.');
    }

    /**
     * @test
     *
     * **ورابطُ المشاركة على نطاقٍ نملكه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كان الافتراضيّ `https://amial.pay`، و`app.public_url` **غير معرَّفٍ
     * في `config/app.php` إطلاقاً** — فالافتراضيُّ هو ما يُستعمل دائماً.
     * والنطاقُ المملوك `amialpay.com`.
     *
     * فكلُّ من شارك طلبَ دفعٍ أرسل رابطاً لا يفتح شيئاً، **وقد يسجّله
     * غيرُنا غداً فيستقبل عملاءنا**. والرابطُ يُنسخ ويبدو سليماً.
     */
    public function the_shared_request_link_points_at_a_domain_we_own(): void
    {
        // بلا مصنع ولا قاعدة: `publicUrl()` تقرأ الإعدادات و`short_code`
        // وحدهما، ونموذجٌ غيرُ محفوظ يكفي — والفحصُ عن الرابط لا عن الحفظ.
        $req = new PaymentRequest(['short_code' => 'ABC123']);
        $req->short_code = 'ABC123';

        $url = $req->publicUrl();

        $host = parse_url($url, PHP_URL_HOST);

        $this->assertNotNull($host, "رابطُ المشاركة بلا مضيف: {$url}");

        $this->assertNotSame('amial.pay', $host,
            "رابطُ المشاركة على «amial.pay» — نطاقٌ لا نملكه ولا يفتح شيئاً.\n"
            . 'والمملوكُ amialpay.com.');

        $this->assertStringEndsWith('/req/ABC123', $url,
            "شكلُ الرابط تغيّر: {$url}");
    }
}
