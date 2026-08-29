<?php

namespace Tests\Feature;

use App\Support\PlatformAccessTabs;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-OPERATOR-GRAIN-002 — **كلُّ صلاحيّةٍ تحرس عملاً يمكن منحُها،
 * وكلُّ صلاحيّةٍ تُمنَح تحرس عملاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **سؤالُ صاحب المشروع:** «ثمّ كتابةٌ وقراءةٌ — هل تعمل حقّاً؟».
 *
 * وقِيس فكان الجوابُ نصفين: **الآلةُ تعمل والقاموسُ مثقوب.**
 *
 *   · `PlatformPermissionMiddleware` يفحص فعلاً، ويسجّل الرفض، ويعمل
 *     على سطحَي الجلسة والـAPI معاً. فالقراءةُ والكتابةُ تُطبَّقان.
 *   · **وستَّ عشرةَ صلاحيّةً كانت تحرس عملاً ولا تمنحها أيُّ شاشة** —
 *     أي فعلٌ محروسٌ لا سبيلَ لأحدٍ أن يُؤذَن به. وفيها **رادارُ ساهر
 *     كلُّه**: لا يفتحه موظّفٌ مهما أُعطي.
 *   · **وواحدةٌ كانت تُمنَح ولا تُفحَص في أيّ موضع** — مربّعٌ يُؤشَّر
 *     ولا يفتح شيئاً، **ويُعلّم من يمنح أنّ التأشيرَ بلا أثر**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لا يمسكه شيءٌ آخر:** الطرفان صحيحان وحدَهما. المسارُ محروسٌ
 * برمزٍ صحيح، والشاشةُ تمنح رموزاً صحيحة — **والخللُ في أنّهما لا
 * يلتقيان**. ولا اختبارَ وحدةٍ يرى الطرفين معاً.
 *
 * وهو عطلُ «اسمان لا يلتقيان» نفسُه الذي جعل عمليّاتِ الوكيل مجّانيّةً
 * شهوراً (`agent_deposit` مقابل `AGENT_DEPOSIT`)، واقعاً على الصلاحيّات.
 */
class PlatformPermissionCatalogGuardTest extends TestCase
{
    /**
     * كلُّ رمزٍ يُفحَص في المشروع — من الوسيط، ومن أيّ فحصٍ في الشيفرة.
     *
     * **ويُقرأ الفحصُ داخل المتحكّمات لا الوسيط وحدَه.** أوّلُ صياغةٍ
     * قرأت الوسيطَ فقط، فعدّت `platform.customers.security.view` ميّتةً
     * وهي تحرس قسمين في `CustomerCenterController` — **حارسٌ يكذب**،
     * وكان سيُرسل من يصدّقه يحذف صلاحيّةً قائمة.
     *
     * @return array<string,string> الرمز ← أين وُجد
     */
    private function permissionsCheckedInCode(): array
    {
        $used = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (!is_string($middleware) || !str_starts_with($middleware, 'platform:')) {
                    continue;
                }

                foreach (explode(',', substr($middleware, 9)) as $code) {
                    $used[trim($code)] = 'وسيطُ المسار ' . $route->uri();
                }
            }
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            // القاموسُ نفسُه ليس استعمالاً — وإلّا صدّق نفسَه.
            if (str_contains($file->getPathname(), 'PlatformAccessTabs')) {
                continue;
            }

            preg_match_all(
                '~[\'"]((?:platform|saher)\.[a-z0-9_.]+)[\'"]~',
                (string) file_get_contents($file->getPathname()), $m);

            foreach ($m[1] as $code) {
                $used[$code] ??= basename($file->getPathname());
            }
        }

        return $used;
    }

    /**
     * @test
     *
     * **لا صلاحيّةَ تحرس عملاً ولا سبيلَ إلى منحها.**
     */
    public function every_permission_that_guards_something_can_be_granted(): void
    {
        $checked = $this->permissionsCheckedInCode();
        $grantable = PlatformAccessTabs::allPermissions();

        $this->assertGreaterThan(30, count($checked),
            'لم يُقرأ إلّا نزرٌ يسير من الصلاحيّات — الحارسُ يفحص فراغاً');

        $unreachable = [];

        foreach ($checked as $code => $where) {
            if (!isset($grantable[$code])) {
                $unreachable[] = sprintf('  %-42s (%s)', $code, $where);
            }
        }

        $this->assertSame([], $unreachable,
            "**صلاحيّاتٌ تحرس عملاً ولا تمنحها أيُّ شاشة:**\n"
            . implode("\n", $unreachable) . "\n\n"
            . 'فالفعلُ محروسٌ ولا سبيلَ لأحدٍ أن يُؤذَن به — ولا يفتحه '
            . "إلّا من يتجاوز الفحصَ أصلاً.\n"
            . 'فإمّا تُضاف إلى `PlatformAccessTabs`، وإمّا يُنزَع حارسُها '
            . 'إن كان الفعلُ لا يُفوَّض.');
    }

    /**
     * @test
     *
     * **ولا صلاحيّةَ تُمنَح ولا تحرس شيئاً.**
     *
     * ومربّعٌ يُؤشَّر ولا يفتح شيئاً أسوأ من غيابه: يُعلّم من يمنح أنّ
     * التأشيرَ بلا أثر، فيؤشّر الباقيَ بلا نظر.
     */
    public function every_grantable_permission_actually_guards_something(): void
    {
        $checked = $this->permissionsCheckedInCode();
        $decorative = [];

        foreach (PlatformAccessTabs::allPermissions() as $code => $meta) {
            if (!isset($checked[$code])) {
                $decorative[] = sprintf('  %-42s ← %s / %s',
                    $code, $meta['tab'], $meta['label']);
            }
        }

        $this->assertSame([], $decorative,
            "**صلاحيّاتٌ تُمنَح ولا تحرس شيئاً:**\n"
            . implode("\n", $decorative) . "\n\n"
            . 'فتُؤشَّر ولا يتغيّر شيء. إمّا تُوصَل بما تحرسه، وإمّا تُحذَف.');
    }

    /**
     * @test
     *
     * **والحبّةُ صلاحيّةٌ لا تبويب — وهو ما طُلب بالنصّ.**
     *
     * «قد أحتاج إلى موظّف يرى سجلّ التدقيق فقط من هذه القائمة، بينما في
     * الصلاحيات يمكنك فقط اختيار القائمة كاملة».
     *
     * فيُقاس أنّ تبويباً واحداً على الأقلّ يحمل أكثرَ من صلاحيّةِ قراءة —
     * **وأنّ كلَّ واحدةٍ منها لها اسمٌ عربيٌّ خاصٌّ بها**، وإلّا فالتفصيلُ
     * موجودٌ ولا يُقرأ.
     */
    public function a_single_permission_can_be_granted_without_its_neighbours(): void
    {
        $compliance = PlatformAccessTabs::all()['compliance'] ?? null;

        $this->assertNotNull($compliance, 'تبويبُ الامتثال اختفى');

        $this->assertArrayHasKey('platform.audit.view', $compliance['read'],
            'سجلُّ التدقيق ليس صلاحيّةً مفردةً في الامتثال — وهو ما طُلب بعينه');

        $this->assertGreaterThan(1, count($compliance['read']),
            'تبويبُ الامتثال بصلاحيّةٍ واحدةٍ — الحارسُ يفحص حالةً لا تُظهر الفرق');

        // ولكلٍّ اسمٌ يُقرأ — رمزٌ إنجليزيٌّ في شاشةِ منحٍ يُؤشَّر بلا فهم.
        $unnamed = [];

        foreach (PlatformAccessTabs::allPermissions() as $code => $meta) {
            $label = trim($meta['label']);

            if ($label === '' || $label === $code || !preg_match('~\p{Arabic}~u', $label)) {
                $unnamed[] = $code;
            }
        }

        $this->assertSame([], $unnamed,
            'صلاحيّاتٌ بلا اسمٍ عربيّ: ' . implode('، ', $unnamed)
            . ' — ورمزٌ إنجليزيٌّ في شاشة منحٍ يُؤشَّر بلا فهم.');
    }

    /**
     * @test
     *
     * **وكلُّ رمزٍ في القاموس له صفٌّ في القاعدة.**
     *
     * فرمزٌ يُعرَض ويُختار ثمّ يسقط الحفظُ كلُّه بـ`LogicException` يجعل
     * منحاً سليماً مستحيلاً بسبب سطرٍ واحدٍ ناقص.
     */
    public function every_catalog_permission_exists_in_the_database(): void
    {
        $codes = array_keys(PlatformAccessTabs::allPermissions());

        $present = \Illuminate\Support\Facades\DB::table('permissions')
            ->whereIn('code', $codes)->pluck('code')->all();

        $missing = array_values(array_diff($codes, $present));

        $this->assertSame([], $missing,
            "**رموزٌ في القاموس بلا صفٍّ في جدول الصلاحيات:**\n  "
            . implode("\n  ", $missing) . "\n\n"
            . 'فتُعرَض وتُختار، ثمّ يسقط الحفظُ كلُّه — ومنحٌ سليمٌ يصير '
            . 'مستحيلاً بسبب سطرٍ ناقصٍ في بذرة.');
    }
}
