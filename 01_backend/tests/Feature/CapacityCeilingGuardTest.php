<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-CAPACITY-002 — **سقفُ التزامن مضبوطٌ في الصورة المنشورة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي وُلد منه هذا الحارس، وقد قِيس لا فُرض:**
 *
 *     Dockerfile.prod   →  pm.max_children = 20   ✓ مضبوط
 *     Dockerfile        →  **غيرُ مضبوطٍ إطلاقاً**  ⇒ الافتراض 5
 *     وCoolify يبني     →  **Dockerfile**
 *
 * فالضبطُ موجودٌ **في الملفّ الذي لا يُنشَر**. والمنصّةُ كلُّها — لوحةُ
 * الإدارة وبوّابةُ الوكيل وواجهةُ التطبيق — كانت تخدم **خمسةَ طلباتٍ
 * متزامنة**، وما زاد ينتظر في طابور nginx حتّى تنتهي مهلتُه.
 *
 * **وهذا نمطُ العطل الأكثرُ تكراراً في هذا المشروع** — مبنيٌّ ولا
 * يُوصَل إليه — واقعاً هذه المرّةَ على **قدرة الاستيعاب** نفسِها. وهو
 * مسجَّلٌ في `CLAUDE.md` بصورةٍ أخرى: «الصورةُ المفحوصةُ ليست المشحونة».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يظهر هذا في أيّ اختبارٍ قائم:**
 *
 *   · مجموعةُ الاختبارات تجري في عمليّةٍ واحدةٍ متتابعة
 *   · واختبارُ الضغط الماليّ يستدعي الخدماتِ **مباشرةً** ولا يمرّ
 *     بـFPM أصلاً — وقد قاس ٣٠٦ عمليّة/ث، فالمحرّكُ ليس العنق
 *   · ومسبارُ الأزرار يفتح صفحةً واحدةً في كلّ مرّة
 *
 * **فلا شيءَ في البوّابة كلِّها يمرّ بسقف FPM.** ولذلك يُقاس من الملفّ.
 */
class CapacityCeilingGuardTest extends TestCase
{
    /** الحدُّ الأدنى المقبول — دونَه لا تُخدَم ذروةُ ألفي مستخدم. */
    private const MIN_CHILDREN = 16;

    private function dockerfile(string $name): string
    {
        $p = base_path($name);

        $this->assertFileExists($p, "ملفُّ الصورة «{$name}» غير موجود");

        return (string) file_get_contents($p);
    }

    private function maxChildrenIn(string $name): ?int
    {
        // **ويُقرأ من سطر الكتابة لا من التعليق.** فالشرحُ أعلاه يذكر
        // الرقمَ نفسَه، ومطابقةٌ عمياءُ تقرأ التعليقَ ضبطاً — وهو العطلُ
        // المسجَّل في `CLAUDE.md`: «التعليقُ الذي يصف العطلَ كان يُخفيه».
        $src = preg_replace('/^\s*#.*$/m', '', $this->dockerfile($name)) ?? '';

        return preg_match('/pm\.max_children\s*=\s*(\d+)/', $src, $m)
            ? (int) $m[1]
            : null;
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_image_coolify_actually_builds_has_a_tuned_pool(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذا هو الحارسُ الذي يهمّ.** `Dockerfile.prod` مضبوطٌ منذ
        // البداية ولا يُبنى؛ والمنشورُ هو هذا.
        // ══════════════════════════════════════════════════════════════
        $n = $this->maxChildrenIn('Dockerfile');

        $this->assertNotNull($n,
            'الصورةُ المنشورةُ لا تضبط `pm.max_children` — '
            . 'فالافتراضُ خمسةٌ، وخمسةُ طلباتٍ متزامنةٍ لا تخدم ألفي مستخدم');

        $this->assertGreaterThanOrEqual(self::MIN_CHILDREN, $n,
            "سقفُ التزامن {$n} — ودونَ " . self::MIN_CHILDREN
            . ' تنتظر الطلباتُ في طابور nginx حتّى تنتهي مهلتُها');
    }

    /** @test */
    public function the_two_images_do_not_drift_on_capacity(): void
    {
        // **وصورتان تفترقان في سقفٍ تشغيليٍّ تُنتجان قياساً كاذباً**:
        // تُفحص إحداهما وتُشحن الأخرى، فتُقاس قدرةٌ غيرُ التي تعمل.
        $deployed = $this->maxChildrenIn('Dockerfile');
        $audited = $this->maxChildrenIn('Dockerfile.prod');

        $this->assertNotNull($audited, '`Dockerfile.prod` فقد ضبطَه');

        $this->assertLessThanOrEqual(4, abs((int) $deployed - (int) $audited),
            "الصورتان تفترقان في سقف التزامن ({$deployed} مقابل {$audited}) — "
            . 'فما يُفحَص ليس ما يُشحَن');
    }

    /** @test */
    public function a_leaking_worker_is_recycled(): void
    {
        // **وعاملٌ يعيش إلى الأبد يجمع تسرّبَ الذاكرة** حتّى يُقتَل
        // بـOOM في منتصف طلبٍ ماليّ. و`max_requests` تُدوّره قبل ذلك.
        $src = preg_replace('/^\s*#.*$/m', '', $this->dockerfile('Dockerfile')) ?? '';

        $this->assertMatchesRegularExpression('/pm\.max_requests\s*=\s*[1-9]/', $src,
            'العمّالُ لا يُدوَّرون — فتسرّبُ ذاكرةٍ صغيرٌ يُسقط عاملاً بعد ساعات');
    }

    /** @test */
    public function the_pool_fits_under_the_database_connection_limit(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وسقفٌ أعلى من طاقة القاعدة يستبدل عطلاً بأسوأ منه:** بدل
        // انتظارٍ في الطابور، يخرج `Too many connections` — وهو خطأٌ
        // يصل المستعملَ ويُسقط معاملةً ماليّةً في منتصفها.
        //
        // والحسابُ: عمّالُ FPM + عاملا الطابور + المجدوِل + هامشُ الإدارة.
        // ══════════════════════════════════════════════════════════════
        $children = (int) $this->maxChildrenIn('Dockerfile');

        $rows = \Illuminate\Support\Facades\DB::select("SHOW VARIABLES LIKE 'max_connections'");
        $limit = (int) ($rows[0]->Value ?? 0);

        if ($limit === 0) {
            $this->markTestSkipped('تعذّرت قراءةُ حدّ الاتّصالات من القاعدة');
        }

        // عاملان للطابور + مجدوِل + هامشٌ لأدوات الإدارة والنسخ.
        $needed = $children + 2 + 1 + 10;

        $this->assertLessThan($limit, $needed,
            "سقفُ العمّال {$children} يطلب ~{$needed} اتّصالاً وحدُّ القاعدة "
            . "{$limit} — فيخرج «Too many connections» في وجه معاملةٍ ماليّة");
    }
}
