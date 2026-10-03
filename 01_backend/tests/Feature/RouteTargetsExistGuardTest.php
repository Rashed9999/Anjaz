<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-ORPHAN-ROUTE-001 — **كلُّ مسارٍ مسجَّلٍ يجد ما يُنفّذه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * سأل صاحبُ المشروع: «هل ثمّة ملفّاتٌ تحتاجها من 6cash؟» فمُسح المشروعُ
 * بحثاً عن يُتْمٍ — نموذجٍ بلا جدول، أو مسارٍ بلا متحكّم. وخرج واحد:
 *
 *     POST admin/merchant/search  →  MerchantController::search()
 *                                    **والدالّةُ لا وجودَ لها**
 *
 * بقيّةُ بحثِ التجّار في قالب 6cash: حُذفت الدالّةُ مع قوالبها **وبقي
 * تسجيلُ المسار**.
 *
 * ── ولمَ لم يمسكه شيء ────────────────────────────────────────────────
 *
 * **ربطُ المتحكّم بالدالّة نصٌّ يُحلُّ في وقت التشغيل.** فـ`php -l` راضٍ،
 * و`route:list` يعرض السطرَ كسائر السطور، و**`route:cache` ينجح** — لأنّه
 * يخزّن الاسمَ ولا يسأل عن وجود الدالّة. ولا يظهر العطلُ إلّا **٥٠٠ في
 * وجه من يطلب المسار**.
 *
 * وهو أخو العطل الذي كلّف هذا المشروعَ من قبل: `denyUnless` تُستورَد ولا
 * تُستعمَل كتِرَيت، فتنهار «Method does not exist» في مسار المال.
 *
 * **فالحارسُ يمشي المسارات كلَّها** (١١٠٧ مساراً حين كُتب) ويسأل سؤالين
 * لا يسألهما مُصرِّف: أموجودٌ الصنف؟ وأموجودةٌ الدالّة؟
 */
class RouteTargetsExistGuardTest extends TestCase
{
    /**
     * **① لا مسارَ يشير إلى صنفٍ أو دالّةٍ لا وجودَ لها.**
     */
    /** @test */
    public function every_registered_route_resolves_to_a_real_controller_action(): void
    {
        $broken = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('controller');

            // إغلاقةٌ أو وسيطُ عرضٍ — لا متحكّمَ يُفحَص.
            if (!is_string($action) || !str_contains($action, '@')) {
                continue;
            }

            $checked++;
            [$class, $method] = explode('@', $action, 2);

            if (!class_exists($class)) {
                $broken[] = sprintf('%-46s → الصنفُ مفقود: %s',
                    $route->uri(), $class);
                continue;
            }

            // ══════════════════════════════════════════════════════════
            // **وهنا عطلٌ وقع في هذا الحارس نفسِه، فيُكتب لئلّا يُعاد.**
            //
            // كُتب أوّلَ مرّةٍ باستثناء: «إن عرّف الصنفُ `__call` فهو يعالج
            // ما لم يُصرَّح به، فلا يُعدّ مكسوراً». **وهو صحيحٌ نظريّاً
            // وكارثيٌّ هنا**: `Illuminate\Routing\Controller` — أبو كلِّ
            // متحكّمٍ في Laravel — **يعرّف `__call`**، وهي ترمي
            // `BadMethodCallException` لا تعالج شيئاً.
            //
            // فكان `method_exists($class, '__call')` يُرجع `true` **لكلّ
            // متحكّمٍ في المشروع**، فيُخطَّى الفحصُ كلُّه. والحارسُ يخرج
            // أخضرَ على ١١٠٧ مسارٍ وهو لم يفحص واحداً.
            //
            // وقِيس: أُعيد المسارُ الميّتُ عمداً فبقي الحارسُ أخضر — وهو
            // بالضبط «حارسٌ يمرّ والعطل قائم: يُطمئن ولا يحرس».
            //
            // فالفحصُ صار على `is_callable` بعد استبعاد الموروثة من
            // `Controller`، أي: **هل الدالّةُ معرَّفةٌ فعلاً في شجرة
            // المتحكّم؟**
            // ══════════════════════════════════════════════════════════
            if (!method_exists($class, $method)) {
                $broken[] = sprintf('%-46s → %s::%s() مفقودة',
                    $route->uri(), class_basename($class), $method);
                continue;
            }

            // ودالّةٌ موجودةٌ لكنّها غيرُ عموميّة لا يبلغها الموجِّه.
            $ref = new \ReflectionMethod($class, $method);
            if (!$ref->isPublic() || $ref->isStatic()) {
                $broken[] = sprintf('%-46s → %s::%s() ليست عموميّةً قابلةً للنداء',
                    $route->uri(), class_basename($class), $method);
            }
        }

        // **ولا يمرّ الحارسُ على لا شيء.** مرشِّحٌ عمي — أو مساراتٌ لم
        // تُحمَّل في بيئة الاختبار — يُخرجه أخضرَ على صفر، وهو الصمتُ
        // بثوب نجاح. (والعددُ حين كُتب: ١١٠٧ مساراً، منها ما يُفحَص.)
        $this->assertGreaterThan(500, $checked,
            "لم يُفحَص إلّا {$checked} مساراً — المسارات لا تُحمَّل، فالحارسُ "
            .'يمرّ ولا يفحص شيئاً.');

        $this->assertSame([], $broken, sprintf(
            "**مساراتٌ مسجَّلةٌ بلا ما يُنفّذها (%d من %d):**\n  %s\n\n"
            ."ولا يمسكها `php -l` ولا `route:cache` — الربطُ نصٌّ يُحلُّ في\n"
            ."وقت التشغيل. وأثرُها ٥٠٠ في وجه من يطلب المسار.\n\n"
            .'فإمّا تُعاد الدالّةُ، وإمّا يُزال تسجيلُ المسار.',
            count($broken), $checked, implode("\n  ", $broken)));
    }

    /**
     * **② ولا نموذجَ يشير إلى جدولٍ لا وجودَ له.**
     *
     * ══════════════════════════════════════════════════════════════════
     * الشقُّ الثاني من السؤال نفسِه. وتعليقُ `ExecutiveDashboardService`
     * يقول بنصّه إنّ «بعض جداول القاعدة من 6cash قد لا تكون مدموجة بعد»،
     * **وكلُّ مؤشّرٍ فيه ملفوفٌ بـ`try/catch` يُرجع صفراً عند الغياب** —
     * أي أنّ جدولاً مفقوداً يُعرَض على الإدارة **صفراً لا خطأً**.
     *
     * وهذا بعينه ما تمنعه القاعدة السابعة: الصفرُ يُقرأ «فحصنا فلم نجد»،
     * والغيابُ يُقال صراحةً. فيُقاس هنا مرّةً واحدةً بدل أن يُبتلع مؤشّراً
     * مؤشّراً.
     *
     * **وقِيس فلا جدولَ مفقود** — ٢١٣ نموذجاً، كلُّها تجد جدولَها. فالتعليقُ
     * أثرٌ تاريخيٌّ لا نقصٌ قائم.
     */
    /** @test */
    public function every_eloquent_model_has_its_table(): void
    {
        $missing = [];
        $checked = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Models'))
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $rel = str_replace(app_path().'/', '', $file->getPathname());
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $rel);

            if (!class_exists($class)) {
                continue;
            }

            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract()
                || !$ref->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            try {
                $table = $ref->newInstanceWithoutConstructor()->getTable();
            } catch (\Throwable) {
                continue;   // نموذجٌ لا يُنشأ بلا معاملات
            }

            $checked++;
            if (!\Schema::hasTable($table)) {
                $missing[] = sprintf('%-34s → جدول «%s»', class_basename($class), $table);
            }
        }

        $this->assertGreaterThan(100, $checked,
            "لم يُفحَص إلّا {$checked} نموذجاً — المرشِّحُ لا يرى النماذج.");

        $this->assertSame([], $missing, sprintf(
            "**نماذجُ جداولُها مفقودةٌ من القاعدة:**\n  %s\n\n"
            .'وأثرُها ليس خطأً بل **صفراً**: اللوحةُ التنفيذيّة تلفّ كلَّ '
            ."مؤشّرٍ بـ`try/catch`، فجدولٌ مفقودٌ يُعرَض «٠» على الإدارة.\n"
            .'(القاعدة السابعة: «غير معروف» ليس صفراً.)',
            implode("\n  ", $missing)));
    }
}
