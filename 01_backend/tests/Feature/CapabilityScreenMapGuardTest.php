<?php

namespace Tests\Feature;

use App\Support\Access\CapabilityRegistry;
use Tests\TestCase;

/**
 * AMIAL-CAP-SCREENS-001 — **كتالوجٌ واحدٌ لا اثنان.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس من صور حسابِ تاجرٍ حقيقيّ:**
 *
 *   سجلُّ الخادم       ٤٧ قدرة
 *   كتالوجُ التطبيق    ٢٧ مكتوبةً بيدٍ في ملفّ Dart
 *   والفرقُ            ٢٢ قدرةً لا مدخلَ لها إطلاقاً — منها الكاشيرُ
 *                      والبيعُ السريع والباركود ومحطّةُ الوقود
 *                      والصيدليّةُ والجملةُ كلُّها
 *
 * فكان التاجرُ يقرأ «مفتوح لديك ١٢ من **٢٧** خدمة» — **ومقامٌ لا يُحسب
 * من مصدره** (القاعدة السادسة). وكانت «الكاشير» ظاهرةً في شاشةٍ وغائبةً
 * عن أخرى، وهما شاشتان يمرّ عليهما التاجرُ في دقيقة.
 *
 * **والأسوأ:** التنقّلُ كان **بنصّ المسار** الذي يُعلنه الخادم
 * (`/cashier`, `/products` …)، و`route_helper.dart` لا يسجّل واحداً من
 * الأربعين. **قِيس: ٤٠ من ٤٠ ميّتةٌ عند الضغط.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * فصار المفتاحُ **رمزَ القدرة** — وهو ما يعرفه الطرفان أصلاً. وهذا
 * الحارسُ يمنع عودةَ الافتراق من الجهتين.
 */
class CapabilityScreenMapGuardTest extends TestCase
{
    private function mapFile(): string
    {
        return base_path('../02_flutter_app/lib/features/entitlements/capability_screens.dart');
    }

    /** @return array<string> الأرمزُ المسجَّلةُ في خريطة التطبيق. */
    private function mappedCodes(): array
    {
        $src = file_get_contents($this->mapFile());

        preg_match('~static final Map<String, Widget Function\(\)> _map = \{(.*?)\n  \};~s',
            $src, $m);

        $this->assertNotEmpty($m, 'خريطةُ القدرات لم تعد تُقرأ — تغيّرت بنيتُها');

        preg_match_all("~'([a-z_0-9.]+)':\s*\(\)~", $m[1], $c);

        return $c[1];
    }

    /** @test */
    public function the_map_file_exists(): void
    {
        $this->assertFileExists($this->mapFile(),
            'خريطةُ رمز القدرة ⇒ الشاشة اختفت — يعود التنقّلُ بنصّ المسار');
    }

    /**
     * @test
     *
     * **كلُّ رمزٍ في الخريطة موجودٌ في سجلّ الخادم.**
     *
     * كان في الكتالوج اليدويّ `receipts` و`wallet` — **ولا وجودَ لهما في
     * السجلّ**. فحالتُهما تُحسب على قدرةٍ لا تُوجد: `access.has` تُرجع
     * false أبداً، فتظهر خدمةٌ مقفلةً إلى الأبد بلا خطأ ولا رسالة.
     */
    public function every_mapped_code_exists_in_the_server_registry(): void
    {
        $known = array_map(
            static fn ($cap) => $cap->code,
            CapabilityRegistry::all(),
        );

        $ghosts = array_values(array_diff($this->mappedCodes(), $known));

        $this->assertSame([], $ghosts,
            "أرمزٌ في خريطة التطبيق لا وجودَ لها في سجلّ الخادم: "
            . implode(' · ', $ghosts) . "\n"
            . 'ورمزٌ مخترَعٌ يجعل access.has تُرجع false دائماً — '
            . 'فتظهر خدمةٌ يملكها التاجر مقفلةً إلى الأبد.');
    }

    /**
     * @test
     *
     * **وكلُّ قدرةٍ يُعلن الخادمُ لها شاشةً لها مدخلٌ في الخريطة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا نصفُ الحارس الذي لولاه لعادت الأربعون: قدرةٌ يُعلن الخادمُ
     * `screen` لها وليس لها بانٍ في التطبيق **تُعرَض ومعها سهمُ الدخول،
     * وتُضغط فلا تفتح**.
     *
     * ومن لا شاشةَ له في التطبيق **لا يُعلن الخادمُ له `screen`** — فيُقال
     * غيابُه صراحةً ولا يُوعَد بما لا يُوفى. (القاعدة السابعة.)
     */
    public function every_declared_screen_has_a_builder_in_the_app(): void
    {
        $mapped = $this->mappedCodes();
        $orphans = [];

        foreach (CapabilityRegistry::all() as $cap) {
            $row = $cap->toArray();

            if (empty($row['screen'])) {
                continue;
            }

            if (! in_array($cap->code, $mapped, true)) {
                $orphans[] = "{$cap->code} ({$row['screen']})";
            }
        }

        $this->assertSame([], $orphans,
            "قدراتٌ يُعلن الخادمُ لها شاشةً ولا بانيَ لها في التطبيق:\n  "
            . implode("\n  ", $orphans) . "\n\n"
            . "فتُعرَض ومعها سهمُ الدخول، وتُضغط فلا تفتح.\n"
            . 'الحلّ: أضِف بانياً في capability_screens.dart، '
            . 'أو انزع screen() من السجلّ إن لم تُبنَ الشاشةُ بعد.');
    }
}
