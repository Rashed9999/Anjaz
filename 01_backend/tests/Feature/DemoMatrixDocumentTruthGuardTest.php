<?php

namespace Tests\Feature;

use App\Support\Access\AccessConstants as A;
use Database\Seeders\MerchantDemoMatrixSeeder;
use Tests\TestCase;

/**
 * AMIAL-PLAN-MATRIX-003 — **الورقةُ التي يُدخَل منها، ورقمٌ فيها لا حسابَ له.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * صاحبُ المشروع حسم الباقاتِ ثلاثاً: مجّانيّ · الأعمال · مؤسسة. والشيفرةُ
 * كانت قد وحّدتها فعلاً — `PLAN_STARTER` و`PLAN_MERCHANT_PRO` **مُرادفان**
 * لا باقتان. **وبقي أثرُ الخمسِ في ثلاثة مواضعَ تُقرأ ولا تُنفَّذ:**
 *
 * ① `MerchantDemoMatrixSeeder::PLANS` يُعلن **خمسةَ** صفوف، ومفاتيحُها
 *    مكرَّرةٌ بعد فكِّ المُرادفات (`business` مرّتين و`enterprise` مرّتين).
 *    وPHP **يُبقي الأخيرَ صامتاً**: فالمصفوفةُ ثلاثةٌ فعلاً، ورقمُ باقة
 *    «البداية» (١) يُمحى بـ«الأعمال» (٢). أي **شيفرةٌ تُقرأ خمساً وتعمل
 *    ثلاثاً** — ولا تحذيرَ في أيّ سجلّ.
 *
 * ② سطرا `info()` بعد البذر يقولان «30 accounts» و«777215000 .. 777215004»
 *    — والمبذورُ ثمانيةَ عشرَ، و`…001` و`…003` **لا وجودَ لهما**.
 *
 * ③ و`CODEX_TO_CLAUDE_DEMO_MERCHANTS.md` — **وهي الورقةُ التي يُدخَل
 *    منها** — تنشر جدولاً بثلاثين رقماً، **اثنا عشرَ منها لحساباتٍ لم
 *    تُبذَر قطّ**. فمن كتب `777215001` في شاشة الدخول قرأ «لا حساب»،
 *    وقرأها عطلَ تسجيلٍ لا رقماً ملغى.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا صنفُ العطل المعكوس:** لا «مبنيٌّ ولا يُوصَل إليه» بل **«مُوثَّقٌ
 * ولا وجودَ له»**. وكلاهما يُرسل صاحبَ المشروع خلف عطلٍ ليس عطلاً.
 * (القاعدة السابعة: «لا حساب» ليس «فُحص فلم يوجد».)
 *
 * فالحارسُ يقابل **الورقةَ بالمصدر** في الاتّجاهين: لا رقمَ منشورٌ بلا
 * حساب، ولا حسابَ مبذورٌ بلا سطرٍ في الورقة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وجُرّبت الأربعةُ بالعكس** (القاعدة الثانية):
 *
 *   ① أُضيف `777215003` إلى الوثيقة        → سقط الأوّل
 *   ② مُحي `777215000` منها                → سقط الثاني
 *   ③ أُعيد عمودُ «Starter» إلى الجدول     → سقط الثالث
 *   ④ أُعيد `A::PLAN_STARTER` إلى الباذر   → سقط الرابع
 *
 * **وأوّلُ محاولةٍ في ② مرّت، والحارسُ كان مُحقّاً:** كان الرقمُ قد مُحي
 * من الجدول وحدَه وبقي في قسم «Wholesale focus» — أي أنّه ما زال
 * منشوراً. فأُعيدت التجربةُ بمحوه من الوثيقة كلِّها فسقط.
 */
class DemoMatrixDocumentTruthGuardTest extends TestCase
{
    private const DOC = __DIR__ . '/../../../CODEX_TO_CLAUDE_DEMO_MERCHANTS.md';

    private function doc(): string
    {
        $this->assertFileExists(self::DOC,
            'وثيقةُ حسابات العرض مفقودة — والحارسُ يفحص العدم.');

        return (string) file_get_contents(self::DOC);
    }

    /**
     * أرقامُ الهواتف المحلّيّة كما تنشرها الوثيقة.
     *
     * @return list<string>
     */
    private function publishedPhones(): array
    {
        preg_match_all('/\b(77721\d{4})\b/', $this->doc(), $m);

        $phones = array_values(array_unique($m[1]));

        // **صفرُ مطابقاتٍ ليس نجاحاً.** لو تغيّرت صياغةُ الجدول لخرج
        // الحارسُ أخضرَ على فراغ — وهو الصمتُ بثوب حراسة.
        $this->assertNotEmpty($phones,
            'لم يُقرأ رقمٌ واحدٌ من الوثيقة — تغيّرت صياغتُها والحارسُ '
            . 'يفحص فراغاً. (القاعدة السابعة.)');

        return $phones;
    }

    /** @test */
    public function every_phone_number_the_document_publishes_has_an_account_behind_it(): void
    {
        $seeded = array_column(MerchantDemoMatrixSeeder::accounts(), 'phone_local');

        $ghosts = array_values(array_diff($this->publishedPhones(), $seeded));
        sort($ghosts);

        $this->assertSame([], $ghosts, sprintf(
            "**أرقامٌ منشورةٌ في وثيقة حسابات العرض ولا حسابَ لها:**\n  %s\n\n"
            . 'ومن كتب واحداً منها في شاشة الدخول قرأ «لا حساب» — فيُقرأ '
            . 'عطلَ تسجيلٍ لا رقماً أُلغي مع دمج الباقات.',
            implode('، ', $ghosts)));
    }

    /** @test */
    public function every_seeded_account_appears_in_the_document(): void
    {
        $published = $this->publishedPhones();

        $missing = [];
        foreach (MerchantDemoMatrixSeeder::accounts() as $row) {
            if (! in_array($row['phone_local'], $published, true)) {
                $missing[] = sprintf('%s (%s × %s)',
                    $row['phone_local'], $row['business_type'], $row['plan']);
            }
        }

        $this->assertSame([], $missing, sprintf(
            "**حساباتٌ تُبذَر ولا تُذكر في الوثيقة:**\n  %s\n\n"
            . 'وحسابُ عرضٍ لا يعرف أحدٌ رقمَه ليس مبذوراً عملاً — '
            . 'هو صفٌّ في قاعدةٍ لا يُدخَل منه. (القاعدة الثانية عشرة.)',
            implode("\n  ", $missing)));
    }

    /**
     * **والوثيقةُ لا تسمّي باقةً لا تُباع.**
     *
     * جدولُها كان بأعمدةٍ خمسة — «Starter» و«Merchant Pro» عمودان لباقتين
     * ألغاهما صاحبُ المشروع. وعمودٌ باسمِ باقةٍ ملغاةٍ يَعِد بمستوى
     * تسعيرٍ لا يُشترى.
     */
    /** @test */
    public function the_document_names_only_plans_that_are_actually_sold(): void
    {
        $doc = $this->doc();

        // ══════════════════════════════════════════════════════════════
        // **وسطورُ الاقتباس (`>`) مستثناةٌ عمداً.** فيها شرحُ الدمج نفسِه،
        // ولا بدّ فيه من ذكر الاسمين الملغيين — **ومن قرأ رقماً محفوظاً
        // ولم يجد له عموداً يحتاج أن يُقال له أين ذهب**، وإلّا قرأ
        // الوثيقةَ ناقصةً وأعاد الأعمدةَ ظنّاً أنّها سقطت سهواً.
        // والمنعُ يبقى قائماً على ما يُقرأ فهرساً حيّاً: الجدولِ
        // والأسطرِ والقوائم. (القاعدة الثانية: يُجرَّب بالعكس — أُعيد
        // «| Starter |» إلى الجدول فسقط هذا الفحص.)
        // ══════════════════════════════════════════════════════════════
        $live = implode("\n", array_filter(
            explode("\n", $doc),
            fn (string $line): bool => ! str_starts_with(ltrim($line), '>')));

        foreach (['Starter', 'Merchant Pro', 'البداية', 'تاجر محترف'] as $retired) {
            $this->assertStringNotContainsString($retired, $live,
                "**«{$retired}» باقةٌ ملغاةٌ ما زالت تُعرَض حيّةً في الوثيقة.** "
                . 'والفهرسُ المُباع ثلاثٌ: ' . implode(' · ', A::ALL_PLANS)
                . ' — وذكرُها التاريخيُّ موضعُه سطرُ اقتباسٍ يبدأ بـ«>».');
        }

        // والعددُ يُشتقّ ولا يُكتب — رقمٌ مكتوبٌ يشيخ مع أوّل قرارِ تسعير.
        $expected = count(A::ALL_BUSINESS_TYPES) * count(A::ALL_PLANS);

        $this->assertStringContainsString("{$expected} merchant accounts", $doc,
            "**عددُ الحسابات في الوثيقة لا يطابق المشتقّ ({$expected}).** "
            . 'وكان «30» مكتوباً بعد أن صارت ثمانيةَ عشر.');
    }

    /**
     * **والمصدرُ نفسُه يُقرأ كما يعمل.**
     *
     * `PLANS` كان خمسةَ صفوفٍ ينهار إلى ثلاثة بتكرار المفاتيح. والقارئُ
     * يعدّ خمساً، والمُنفِّذُ يبذر ثلاثاً — **ولا خطأ**. فيُقاس المُعلَنُ
     * في النصّ بالمُنفَّذ في المصفوفة.
     */
    /** @test */
    public function the_seeder_declares_exactly_as_many_plans_as_it_seeds(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../database/seeders/MerchantDemoMatrixSeeder.php');

        $start = strpos($src, 'private const PLANS = [');
        $this->assertNotFalse($start, 'اختفى ثابتُ الباقات من الباذر');

        $end = strpos($src, '];', $start);
        $block = substr($src, $start, $end - $start);

        $declared = preg_match_all("/=> \['digit'/", $block);

        $seededPlans = array_unique(
            array_column(MerchantDemoMatrixSeeder::accounts(), 'plan'));

        $this->assertSame(count($seededPlans), $declared, sprintf(
            '**البذرةُ تُعلن %d باقةً وتبذر %d.** ومفتاحان متساويان بعد '
            . 'فكِّ المُرادفات (`PLAN_STARTER` = `PLAN_BUSINESS`) يبتلع '
            . "أحدُهما الآخر في PHP **بلا تحذير**:\n  المبذور: %s",
            $declared, count($seededPlans), implode(' · ', $seededPlans)));
    }
}
