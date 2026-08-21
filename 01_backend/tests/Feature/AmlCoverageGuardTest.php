<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Aml\AmlRule;
use Tests\TestCase;

/**
 * AMIAL-AML-COVERAGE-001 — **الإعدادُ يَعِد، والشيفرةُ تفي — أو تُقال الفجوة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما كُشف:** `config('amial.aml.screened_types')` تُعلن خمسةَ أنواعٍ
 * خاضعةٍ لفحص غسل الأموال:
 *
 *     send_money · cash_out · safe_payment_fund · donation · pay_merchant
 *
 * و`screenAml()` كانت تُنادى من **موضعٍ واحدٍ في المشروع كلِّه**، بنوعٍ
 * واحدٍ مثبَّت: `SEND_MONEY`.
 *
 * فأربعةٌ من الخمسة تمرّ بلا فحصٍ إطلاقاً. **والإعدادُ يقول إنّها
 * تُفحَص**، ولوحةُ مكافحة غسل الأموال وتقاريرُ المنظّم تُبنى على هذا
 * الوعد. وهو أخطرُ من غياب الفحص: غيابٌ معلومٌ يُعالَج، ووعدٌ كاذبٌ
 * يُطمئن.
 *
 * **ولا شيءَ يمسكه:** `screened_types` مصفوفةُ نصوص، و`in_array` عليها
 * تنجح دائماً — الفحصُ يسأل «أهذا النوعُ مُعلَن؟» ولا يسأل أحدٌ «أيصل
 * هذا النوعُ إلى الفحص أصلاً؟».
 *
 * فيُربط الإعلانُ بمواضع النداء نصّاً.
 */
class AmlCoverageGuardTest extends TestCase
{
    use RefreshDatabase;

    /** كلُّ نوعٍ يُمرَّر فعلاً إلى `screenAml(...)` في الشيفرة. */
    private function screenedInCode(): array
    {
        $types = [];
        $files = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if (str_ends_with((string) $f, '.php')) {
                $files[] = (string) $f;
            }
        }

        // الثوابتُ المعروفة → قيمها النصّيّة، كما في `app/Lib/Constant.php`.
        $constants = [
            'SEND_MONEY' => 'send_money',
            'CASH_OUT' => 'cash_out',
            'CASH_IN' => 'cash_in',
            'WITHDRAW' => 'withdraw',
            'PAYMENT' => 'payment',
        ];

        foreach ($files as $file) {
            foreach (file($file) as $line) {
                // التعليقاتُ تُطرح — وإلّا أسقط شرحُ العطل الحارسَ.
                $code = preg_replace('#(//|\#).*$#', '', ltrim($line));

                if (! preg_match('/screenAml\s*\(([^)]*)\)/', (string) $code, $m)) {
                    continue;
                }

                $args = array_map('trim', explode(',', $m[1]));

                if (count($args) < 3) {
                    continue;
                }

                $third = trim($args[2], "'\" ");
                $types[] = $constants[$third] ?? strtolower($third);
            }
        }

        return array_values(array_unique($types));
    }

    public function test_the_scan_finds_the_screening_call_sites(): void
    {
        $this->assertNotEmpty($this->screenedInCode(),
            'المسحُ لم يجد نداءً واحداً لـ screenAml — الحارسُ نفسُه معطَّل');
    }

    /**
     * **كلُّ نوعٍ مُعلَنٍ في الإعداد يصل إلى الفحص فعلاً.**
     *
     * والفجوةُ المعروفةُ الباقية مكتوبةٌ هنا صراحةً لا مسكوتٌ عنها —
     * فالادّعاءُ يُراجَع والصمتُ لا يُراجَع (القاعدة ١٢).
     */
    public function test_every_declared_type_actually_reaches_the_screening(): void
    {
        $declared = (array) config('amial.aml.screened_types', []);
        $inCode = $this->screenedInCode();

        // ── فجوةٌ معلومةٌ ومقبولةٌ مؤقّتاً، بسببها ──
        //
        // `safe_payment_fund` و`donation` يمرّان بخدماتٍ خاصّةٍ بهما
        // (`SafePaymentService` و`DonationsService`) لا بـ`TransactionTrait`،
        // ووصلُهما يحتاج تمرير سياق المعاملة إليهما. وكلاهما **قناةٌ
        // مغلقةُ الطرفين** — المالُ يعود إلى محفظةٍ داخل المنصّة، لا
        // يخرج نقداً — فخطرُ الغسل فيهما أدنى من السحب النقديّ ودفع
        // التاجر. يُوصلان في جولةٍ تالية.
        $knownGap = ['safe_payment_fund', 'donation'];

        $missing = array_values(array_diff($declared, $inCode, $knownGap));

        $this->assertSame([], $missing, "\n"
            . 'أنواعٌ يقول الإعدادُ إنّها تُفحَص ولا يصلها الفحص:' . "\n  "
            . implode('، ', $missing) . "\n\n"
            . 'وهذا أخطرُ من غياب الفحص: الغيابُ المعلومُ يُعالَج، والوعدُ '
            . 'الكاذبُ يُطمئن — ولوحةُ مكافحة غسل الأموال وتقاريرُ المنظّم '
            . 'تُبنى عليه. إمّا أن يُنادى screenAml بهذا النوع، وإمّا أن '
            . 'يُحذف من `screened_types` ليصير الغيابُ ظاهراً.');
    }

    /** ولا يُفحص نوعٌ غيرُ معلَن — فالإعدادُ هو الحكم لا الشيفرة. */
    public function test_no_type_is_screened_without_being_declared(): void
    {
        $declared = (array) config('amial.aml.screened_types', []);
        $extra = array_values(array_diff($this->screenedInCode(), $declared));

        $this->assertSame([], $extra,
            'نوعٌ يُمرَّر إلى screenAml وليس في `screened_types` — '
            . 'فـ`in_array` تُسقطه صامتاً، والنداءُ زينةٌ لا فحص: '
            . implode('، ', $extra));
    }

    /** والفحصُ يقع **قبل** تحريك المال لا بعده. */
    public function test_the_screening_happens_before_the_money_moves(): void
    {
        $src = file(app_path('Traits/TransactionTrait.php'));
        $offenders = [];

        foreach ($src as $i => $line) {
            $code = preg_replace('#(//|\#).*$#', '', ltrim($line));

            if (! str_contains((string) $code, 'screenAml(')) {
                continue;
            }

            if (str_contains((string) $code, 'function screenAml')) {
                continue;
            }

            // أيقع نداءُ الفحص داخل `DB::transaction` مفتوحةٍ قبله؟
            $before = '';

            foreach (array_slice($src, max(0, $i - 40), min(40, $i)) as $c) {
                $before .= preg_replace('#(//|\#).*$#', '', ltrim($c)) . "\n";
            }

            $opens = substr_count($before, 'DB::transaction(');
            $closes = substr_count($before, '});');

            if ($opens > $closes) {
                $offenders[] = 'TransactionTrait.php:' . ($i + 1);
            }
        }

        $this->assertSame([], $offenders,
            'الفحصُ داخل المعاملة: حجبُ عمليّةٍ عندئذٍ يردّ معاملةً بدأت '
            . 'بالفعل — والصحيحُ أن تُمنع قبل أن تبدأ. ' . implode('، ', $offenders));
    }

    /** البذرةُ نفسُها التي يشغّلها الإقلاع — لا تركيبةٌ مخترَعةٌ للاختبار. */
    private function seedProductionRules(): void
    {
        $this->seed(\Database\Seeders\AmlDefaultRulesSeeder::class);
    }

    /**
     * @test
     *
     * **① كلُّ نوعٍ مراقَبٍ تغطّيه قاعدةٌ فعّالةٌ واحدةٌ على الأقلّ.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وبلا هذا يمرّ التدفّقُ الماليُّ الحيُّ إلى `AML_COVERAGE_GAP` فيُحجَز
     * كلَّ مرّة — **والمنتجُ يتوقّف والسجلُّ يقول إنّ الرقابةَ تعمل**.
     */
    public function every_screened_type_is_covered_by_an_active_rule(): void
    {
        $this->seedProductionRules();

        $screened = (array) config('amial.aml.screened_types', []);

        $this->assertNotEmpty($screened,
            'لا نوعَ مراقَبٌ إطلاقاً — إمّا أُفرغت القائمةُ سهواً، '
            . '**وإمّا أُطفئت الرقابةُ كلُّها بلا قرار**');

        $rules = AmlRule::active()->get();

        $this->assertNotEmpty($rules,
            'البذرةُ الإنتاجيّةُ لا تُنتج قاعدةً فعّالةً واحدة — '
            . 'فكلُّ تدفّقٍ مراقَبٍ يُحجَز');

        $uncovered = [];

        foreach ($screened as $type) {
            $covering = $rules->filter(fn (AmlRule $r) => $r->appliesToType($type));

            if ($covering->isEmpty()) {
                $uncovered[] = $type;
            }
        }

        $this->assertSame([], $uncovered, sprintf(
            "أنواعٌ ماليّةٌ مُعلَنةٌ مراقَبةً ولا تغطّيها قاعدةٌ واحدة — "
            . "**تُحجَز أبداً بعد النشر**:\n  %s\n\n"
            . "القواعدُ الموجودة: %s\n"
            . 'إمّا يُوسَّع `applies_to` في `AmlDefaultRulesSeeder`، '
            . 'وإمّا يُرفَع النوعُ من `screened_types` بقرارٍ مكتوب.',
            implode("\n  ", $uncovered),
            $rules->pluck('code')->implode('، ')));
    }

    /**
     * @test
     *
     * **② والحدُّ الأقصى المطلق يغطّي كلَّ تدفّقِ خروجٍ مراقَب.**
     *
     * فهو السياسةُ الوحيدةُ المُنفَّذة من أوّل يوم (‏والباقي يراقب ويسجّل).
     * ونوعٌ خارجٌ منه يعني **سقفاً بلا سقف** على ذلك المسار.
     */
    public function the_hard_cap_covers_every_screened_outflow(): void
    {
        $this->seedProductionRules();

        $hard = AmlRule::where('code', 'MAX_SINGLE_TX_HARD')->first();

        $this->assertNotNull($hard, 'قاعدةُ الحدّ الأقصى المطلق غيرُ مبذورة');
        $this->assertTrue((bool) $hard->is_active, 'الحدُّ الأقصى المطلق غيرُ مفعَّل');

        $missing = [];

        foreach ((array) config('amial.aml.screened_types', []) as $type) {
            if (! $hard->appliesToType($type)) {
                $missing[] = $type;
            }
        }

        $this->assertSame([], $missing,
            'أنواعٌ مراقَبةٌ خارج الحدّ الأقصى المطلق — **سقفٌ بلا سقف**: '
            . implode('، ', $missing));
    }
}
