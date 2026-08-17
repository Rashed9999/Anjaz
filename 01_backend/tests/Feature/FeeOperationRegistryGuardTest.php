<?php

namespace Tests\Feature;

use App\Models\FeeScheme;
use App\Support\Fees\FeeOperation;
use App\Support\Fees\FeeOperationRegistry;
use Tests\TestCase;

/**
 * AMIAL-FEE-TRUTH-009 — **سجلُّ العمليّات يُطابق الشيفرة، لا يصفها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * سجلٌّ يُكتب مرّةً ثمّ تتحرّك الشيفرةُ تحته يصير **أسوأ من غيابه**:
 * يقول للأدمن إنّ العمليّةَ موصولةٌ وهي ليست، أو إنّ حصّةَ الوكيل تنطبق
 * وهي لا تنطبق — **فيملأ حقلاً يُخصَم من ربح المنصّة ويُقيَّد لمن لم يعمل**.
 *
 * فالحارسُ يقيس السجلَّ على الشيفرة في الاتّجاهين:
 *
 *   **configurable → consumed**   كلُّ رمزٍ في السجلّ له مستهلكٌ حقيقيّ
 *                                 أو سببٌ مكتوبٌ لعدم وصله
 *   **consumed → configurable**   كلُّ رمزٍ تطلبه شيفرةٌ حيّةٌ موجودٌ في
 *                                 السجلّ — فيستطيع الأدمنُ تسعيرَه
 */
class FeeOperationRegistryGuardTest extends TestCase
{
    /** الجذورُ التي تُطلب منها الرسوم. */
    private function sourceFiles(): array
    {
        $out = [];

        foreach ([app_path('Services'), app_path('Http/Controllers'),
                  app_path('Traits')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $out[] = $f->getPathname();
                }
            }
        }

        return $out;
    }

    /**
     * الشيفرةُ بلا تعليقات — **فذكرُ رمزٍ في شرحٍ ليس استعمالاً له**.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولا يُمسح المشروعُ كلُّه: تُمسح ملفّاتُ مستهلكي المحرّك وحدَها.**
     *
     * فالمسحُ الشاملُ يقرأ الرمزَ حيث لا يُحسَب به مال. وقد وقع فعلاً:
     * `FeeProfitReportService` يحمل جدولَ ترجمةٍ من نوع الحركة إلى رمز
     * الرسم — `'cash_in' => 'CASH_IN'` — **وهو ترجمةٌ للعرض لا استهلاك**.
     * فقرأه الحارسُ استعمالاً وأسقط استثناءَ `CASH_IN` الصحيح.
     *
     * **ومستهلكُ رسمٍ تعريفُه واحد: ملفٌّ ينادي المحرّك.** وما لا ينادي
     * المحرّكَ لا يحسب رسماً مهما ذكر الرمز.
     */
    private function codeBlob(): string
    {
        $blob = '';

        foreach ($this->sourceFiles() as $f) {
            $src = (string) preg_replace(
                ['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents($f));

            if (! preg_match('~->(?:calculate|quote)\s*\(~', $src)) {
                continue;
            }

            $blob .= $src;
        }

        return $blob;
    }

    /**
     * @test
     *
     * **① السجلُّ و`FeeScheme::codes()` قائمةٌ واحدةٌ لا قائمتان.**
     */
    public function the_model_reads_its_codes_from_the_registry(): void
    {
        $this->assertSame(FeeOperationRegistry::codes(), FeeScheme::codes(),
            '`FeeScheme::codes()` لا تقرأ من السجلّ — فقائمتان لعمليّةٍ واحدة، '
            . 'وتفترقان في اليوم الذي تُضاف فيه عمليّةٌ جديدة');

        $this->assertNotEmpty(FeeOperationRegistry::codes(),
            'السجلُّ فارغٌ — والحارسُ كلُّه يمرّ على لا شيء');
    }

    /**
     * @test
     *
     * **② `orphan fee codes = 0` — كلُّ رمزٍ مستهلَكٌ أو مصرَّحٌ بسببه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فرمزٌ في اللوحة بلا مستهلك: يضبطه الأدمنُ ويحفظه ويراه فعّالاً
     * **ولا يُخصم منه ريال**. زرٌّ يعمل ولا يفعل شيئاً.
     */
    public function no_registry_code_is_an_orphan(): void
    {
        $blob = $this->codeBlob();
        $orphans = [];

        foreach (FeeOperationRegistry::all() as $code => $op) {
            if (str_contains($blob, "'{$code}'")) {
                continue;
            }

            if (! $op->isLive()) {
                continue;   // مصرَّحٌ به وبسببه
            }

            $orphans[] = $code;
        }

        $this->assertSame([], $orphans, sprintf(
            "رموزٌ يعلن السجلُّ أنّها حيّةٌ ولا يستهلكها أحد:\n  %s\n"
            . 'إمّا تُوصَل، وإمّا يُكتب لها `notWiredReason`.',
            implode("\n  ", $orphans)));
    }

    /**
     * @test
     *
     * **③ `unconfigurable live fee consumers = 0`.**
     *
     * فمسارٌ ماليٌّ يطلب رمزاً ليس في السجلّ لا يجد نسخةً أبداً، **فيردّ
     * صفراً صامتاً** — وهو العطلُ الذي جعل عمليّاتِ الوكيل مجّانيّةً شهوراً.
     */
    public function every_live_consumer_can_be_configured(): void
    {
        $unknown = [];

        foreach ($this->sourceFiles() as $file) {
            $src = (string) preg_replace(
                ['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents($file));

            preg_match_all("/->(?:calculate|quote)\(\s*'([A-Za-z_]+)'/", $src, $m);

            foreach ($m[1] ?? [] as $code) {
                if (FeeOperationRegistry::find($code) === null) {
                    $unknown[] = $code.'  ← '.str_replace(base_path().'/', '', $file);
                }
            }
        }

        $this->assertSame([], $unknown, sprintf(
            "مستهلكُ رسمٍ حيٌّ يطلب رمزاً ليس في السجلّ — فيردّ صفراً أبداً:\n  %s",
            implode("\n  ", $unknown)));
    }

    /**
     * @test
     *
     * **④ والمستهلكُ المُعلَن ملفٌّ موجودٌ يذكر الرمز.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فسجلٌّ يقول «تُحسب في `X.php`» وقد نُقلت أو حُذفت **يُرسل من يقرؤه
     * خلف ملفٍّ لا وجودَ له**، وهو أسوأُ من ألّا يقول شيئاً.
     */
    public function every_declared_consumer_file_exists_and_mentions_the_code(): void
    {
        $bad = [];

        foreach (FeeOperationRegistry::all() as $code => $op) {
            foreach ($op->consumers as $rel) {
                $path = base_path($rel);

                if (! is_file($path)) {
                    $bad[] = "{$code}: ملفٌّ لا وجودَ له — {$rel}";

                    continue;
                }

                $src = (string) preg_replace(
                    ['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents($path));

                if (! str_contains($src, "'{$code}'")) {
                    $bad[] = "{$code}: مُعلَنٌ مستهلكاً في {$rel} ولا يذكره";
                }
            }
        }

        $this->assertSame([], $bad, sprintf(
            "مستهلكونَ مُعلَنون لا يطابقون الشيفرة:\n  %s",
            implode("\n  ", $bad)));
    }

    /**
     * @test
     *
     * **⑤ وما ليس له مستهلكٌ لا يُعلَن حيّاً.**
     *
     * (الاتّجاهُ المعاكس للرابع: استثناءٌ قديمٌ لرمزٍ صار مستعمَلاً يُطمئن
     * على غير موضعه.)
     */
    public function no_stale_not_wired_reason_survives(): void
    {
        $blob = $this->codeBlob();
        $stale = [];

        foreach (FeeOperationRegistry::notWired() as $code => $reason) {
            if (str_contains($blob, "'{$code}'")) {
                $stale[] = $code;
            }

            $this->assertNotSame('', trim($reason),
                "الرمزُ {$code} مُعلَنٌ غيرَ موصولٍ **بلا سبب** — والصمتُ لا يُراجَع");
        }

        $this->assertSame([], $stale,
            'رموزٌ مكتوبٌ أنّها غيرُ موصولةٍ وهي مستعمَلة: '.implode('، ', $stale));
    }

    /**
     * @test
     *
     * **⑥ وكلُّ جهةٍ ومتحمّلٍ في السجلّ قيمةٌ يقبلها المحرّك.**
     *
     * فقيمةٌ في السجلّ لا يعرفها `FeeScheme` تُعرَض في القائمة المنسدلة
     * ثمّ **يرفضها التحقّقُ عند الحفظ** — خيارٌ معروضٌ لا يُقبَل.
     */
    public function registry_actors_and_bearers_are_values_the_engine_accepts(): void
    {
        $bad = [];

        foreach (FeeOperationRegistry::all() as $code => $op) {
            $this->assertNotEmpty($op->actors, "{$code}: بلا جهةِ تطبيقٍ واحدة");
            $this->assertNotEmpty($op->bearers, "{$code}: بلا متحمّلٍ واحد");

            foreach ($op->actors as $a) {
                if (! in_array($a, FeeScheme::APPLIES_TO, true)) {
                    $bad[] = "{$code}: applies_to «{$a}» لا يقبله المحرّك";
                }
            }

            foreach ($op->bearers as $b) {
                if (! in_array($b, FeeScheme::BEARERS, true)) {
                    $bad[] = "{$code}: bearer «{$b}» لا يقبله المحرّك";
                }
            }

            if (! array_key_exists($op->category, FeeOperationRegistry::CATEGORIES)) {
                $bad[] = "{$code}: تصنيفٌ «{$op->category}» بلا اسمٍ عربيّ";
            }

            if (trim($op->labelAr) === '' || $op->labelAr === $code) {
                $bad[] = "{$code}: بلا اسمٍ عربيّ — فالأدمنُ يسعّر ما لا يعرف اسمَه";
            }
        }

        $this->assertSame([], $bad, implode("\n  ", $bad));
    }

    /**
     * @test
     *
     * **⑦ وحصّةُ الوكيل معلَنةٌ حيث يوجد وكيلٌ فقط.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا أخطرُ حقلٍ في الشاشة كلِّها.** في التحويل بين المحفظتين لا
     * يدَ بشريّةً أصلاً، فحصّةُ وكيلٍ فيه **تُقتطع من ربح المنصّة وتُقيَّد
     * لمن لم يعمل** — والدفترُ يبقى متوازناً فلا يُنبّه أحد.
     *
     * فالسجلُّ يقول أين تنطبق، والشاشةُ تُخفي الحقلَ حيث لا تنطبق.
     */
    public function agent_commission_is_declared_only_where_an_agent_exists(): void
    {
        $withAgent = [];

        foreach (FeeOperationRegistry::all() as $code => $op) {
            if ($op->agentCommission) {
                $withAgent[] = $code;
            }
        }

        sort($withAgent);

        // **العمليّاتُ التي يقف فيها إنسانٌ خلف شبّاكٍ ويسلّم نقداً** — ولا غيرُها.
        $this->assertSame(['AGENT_DEPOSIT', 'AGENT_WITHDRAW', 'CASH_IN', 'CASH_OUT'],
            $withAgent,
            'حصّةُ الوكيل معلَنةٌ في عمليّةٍ لا وكيلَ فيها (أو غائبةٌ عمّا فيه وكيل) — '
            . 'والأولى تُقيّد حصّةً لمن لم يعمل، والثانيةُ تجعل الوكيلَ يعمل مجّاناً');

        // والتحويلُ بالاسم: هو الأكثرُ استعمالاً وأخطرُ ما يقع فيه الخطأ.
        $this->assertFalse(FeeOperationRegistry::find('SEND_MONEY')->agentCommission,
            'التحويلُ بين المحافظ لا وكيلَ فيه');
    }

    /**
     * @test
     *
     * **⑧ والسجلُّ لا يُطمئن فارغاً — أُثبت بالعكس.**
     *
     * (القاعدة الثانية: حارسٌ لم يسقط مرّةً ليس حارساً.)
     */
    public function the_guard_actually_catches_an_orphan(): void
    {
        $fake = new FeeOperation(
            code: 'AMIAL_NOT_A_REAL_CODE',
            labelAr: 'رمزٌ مخترَع',
            labelEn: 'Fake',
            category: 'other',
            actors: ['customer'],
            bearers: ['sender'],
            agentCommission: false,
            zoneScoped: true,
            consumers: ['app/Services/FeeService.php'],
            owner: '—',
        );

        // ① يُعلَن حيّاً ولا يذكره أيُّ ملفّ ⇒ يتيم.
        $this->assertFalse(str_contains($this->codeBlob(), "'{$fake->code}'"),
            'الرمزُ المخترَعُ موجودٌ فعلاً في الشيفرة — يُبدَّل باسمٍ آخر');

        $this->assertTrue($fake->isLive());

        // ② ومستهلكُه المُعلَن ملفٌّ موجودٌ **لا يذكره** ⇒ يسقط الفحصُ الرابع.
        $src = (string) file_get_contents(base_path($fake->consumers[0]));
        $this->assertFalse(str_contains($src, "'{$fake->code}'"),
            'الفحصُ الرابع لا يميّز مستهلكاً مُعلَناً لا يذكر الرمز');
    }
}
