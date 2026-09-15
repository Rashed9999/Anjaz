<?php

namespace Tests\Feature;

use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use Tests\TestCase;

/**
 * AMIAL-SERVICES-RESTORE-001 — **بوّابةٌ باسمٍ لا يمنحه الخادم = بطاقةٌ
 * لا تظهر أبداً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي قِيس:** سأل صاحبُ المشروع «لماذا اختفت الدفعُ الآمنُ
 * والتبرّعاتُ وصندوقُ العائلة؟». فوُجد التزامٌ حذف بطاقاتِها من شاشة
 * الخدمات (`d8a67a6` — «simplify services»)، **وما وراءها حيٌّ كلُّه**:
 * ٤٧ نقطةَ نهايةٍ و١٢ شاشةً مبنيّة.
 *
 * **وفي أثناء إعادتها كِيد بفخٍّ أخفى من الحذف نفسِه:**
 * `AccessController.has()` في دارت هو `features.contains(code)` —
 * **صارمٌ لا متساهل**. فبوّابةٌ باسمٍ لا يُرسله الخادم تُخفي بطاقتَها
 * **إلى الأبد، بلا خطأ ولا سطرٍ في أيّ سجلّ**. والشيفرةُ تبدو سليمةً
 * تماماً، و`flutter analyze` عنها راضٍ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ الحارسُ هنا لا في دارت:** الجوابُ عند الخادم وحدَه —
 * `resolveFeatures()` هي التي تقرّر ما يصل العميل. فاختبارُ دارت يستطيع
 * قراءةَ اسم البوّابة **ولا يستطيع أن يعرف إن كان يُمنَح**. فيُقرأ
 * الملفُّ من هنا، ويُسأل المصدر.
 *
 * **ولا يُكتب اسمٌ في هذا الملفّ**: البوّاباتُ تُستخرَج من الشاشة نفسِها،
 * فبوّابةٌ تُضاف غداً تدخل الفحصَ من تلقائها.
 */
class CustomerServicesScreenGatesGuardTest extends TestCase
{
    private const SCREEN =
        '02_flutter_app/lib/features/me/screens/my_services_screen.dart';

    /** الشاشاتُ الثلاثُ التي سأل عنها صاحبُ المشروع بالاسم. */
    private const RESTORED = [
        'MySafePaymentsScreen' => 'الدفع الآمن',
        'MyFundsScreen' => 'صندوق العائلة',
        'DonationsHomeScreen' => 'التبرعات',
    ];

    private function screen(): string
    {
        $path = dirname(base_path()).'/'.self::SCREEN;

        $this->assertFileExists($path, 'شاشةُ خدمات العميل اختفت من مكانها.');

        return (string) file_get_contents($path);
    }

    /** ما يناله عميلٌ عاديٌّ فعلاً — من الخدمة لا من قائمةٍ مسطّحة. */
    private function customerFeatures(string $level = 'verified'): array
    {
        return app(FeatureAccessService::class)->resolveFeatures(
            A::ROLE_USER, $level, null, A::PLAN_FREE, []);
    }

    /**
     * **① كلُّ بوّابةٍ في الشاشة اسمٌ يمنحه الخادمُ فعلاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا هو الفخُّ بعينه: `access.has('safe_pay')` صحيحةٌ، و
     * `access.has('donations')` **لا** — ولا فرقَ بينهما في المظهر.
     * الأولى تُظهر البطاقة، والثانيةُ تُخفيها إلى الأبد.
     *
     * **ويُسأل بأدنى مستوى توثيق** — فبوّابةٌ تمرّ لِموثَّقٍ وتسقط لغيره
     * تُخفي البطاقةَ عن كلّ مستخدمٍ جديد، وهو أكثرُهم.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function every_gate_on_the_services_screen_is_a_feature_the_server_grants(): void
    {
        preg_match_all("/access\.has\('([a-z_]+)'\)/", $this->screen(), $m);

        $gates = array_values(array_unique($m[1]));

        $this->assertNotEmpty($gates,
            '**صفرُ بوّاباتٍ في الشاشة** — إمّا نُزعت كلُّها، وإمّا تغيّرت '
            .'صيغةُ الكتابة فصار هذا الحارسُ يفحص العدم. (القاعدة السابعة.)');

        $granted = $this->customerFeatures('unverified');

        $dead = array_values(array_diff($gates, $granted));

        $this->assertSame([], $dead, sprintf(
            "**بوّاباتٌ لا يمنحها الخادمُ لعميلٍ جديد:**\n  %s\n\n"
            ."و`AccessController.has()` هي `features.contains(code)` — "
            ."**صارمة**. فالبطاقةُ خلف كلٍّ منها **لا تظهر أبداً**، ولا "
            ."خطأَ ولا سطرَ في سجلّ.\n\n"
            .'وما يمنحه الخادمُ فعلاً: %s',
            implode('، ', $dead), implode('، ', $granted)));
    }

    /**
     * **② والثلاثُ التي أُعيدت لها بابٌ في الشاشة.**
     *
     * فحارسُ البوّابات وحدَه يمرّ على شاشةٍ حُذفت منها البطاقاتُ كلُّها —
     * صفرُ بوّاباتٍ خاطئة لأنّ صفرَ بوّابات. **وهو الصمتُ بثوب نجاح.**
     */
    /** @test */
    public function the_three_restored_screens_each_have_a_card_that_opens_them(): void
    {
        $src = $this->screen();

        $missing = [];

        foreach (self::RESTORED as $class => $label) {
            if (! str_contains($src, "const {$class}()")) {
                $missing[] = "{$label} ({$class})";
            }
        }

        $this->assertSame([], $missing, sprintf(
            "**شاشاتٌ مبنيّةٌ بلا بابٍ يفتحها من شاشة الخدمات:**\n  %s\n\n"
            .'وهذه الثلاثُ بعينها سأل عنها صاحبُ المشروع بعد أن حُذفت '
            .'بطاقاتُها في التزامٍ اسمُه «simplify». **والتبسيطُ بالحذف '
            .'ينقل العطلَ ولا يرفعه.**',
            implode("\n  ", $missing)));
    }

    /**
     * **③ والبابُ يفتح شاشةً مستوردةً — لا اسماً لا وجودَ له.**
     *
     * (‏`flutter analyze` يمسك هذا، **ولا يُشغَّل على كلّ ملفٍّ في كلّ
     *  التزام**؛ والبوّابةُ تُصرّف التطبيقَ كاملاً في الطبقة السابعة.
     *  فهذا يقولها بالعربيّة وفي موضعها.)
     */
    /** @test */
    public function each_restored_screen_is_actually_imported(): void
    {
        $src = $this->screen();

        $unimported = [];

        foreach (self::RESTORED as $class => $label) {
            if (! preg_match("/^import .*;$/m", $src)
                || ! str_contains($src, strtolower(
                    preg_replace('/(?<!^)[A-Z]/', '_$0', $class)).'.dart')) {
                $unimported[] = "{$label} ({$class})";
            }
        }

        $this->assertSame([], $unimported, sprintf(
            "**شاشاتٌ تُنادى ولا تُستورَد:**\n  %s",
            implode("\n  ", $unimported)));
    }
}
