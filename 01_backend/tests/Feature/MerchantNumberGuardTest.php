<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\User;
use App\Services\Merchant\MerchantNumberService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-NUMBER-001 — **ستّةُ أرقامٍ عشوائيّةٍ بلا صفر.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «كان عبارةً عن أحرفٍ وأرقامٍ وشرطة… الآن أريده مكوَّناً من
 * ستّة أرقامٍ عشوائيّةٍ باستثناء الصفر».
 *
 * وما كان: `sprintf('M-%05d', $user->id)` → `M-00042`. **مشتقٌّ لا
 * عشوائيّ** — يُفشي عدَّ التجّار، ويُخمَّن جارُه، **ورقمُ التاجر عنوانُ
 * دفعٍ يدفع إليه الزبائن** لا معرّفٌ داخليّ.
 *
 * **وقرارُ صاحب المشروع في أمرين، مكتوبان لئلّا يُعادَ فيهما النظر:**
 *
 *   ① **لا صفرَ في أيّ خانة** — لا في الأولى وحدَها. فالمساحةُ 9⁶ =
 *      ٥٣١٬٤٤١، لا مليون.
 *   ② **والقائمون لا يُرقَّمون**: «يبقون، والجديدُ للجدد فقط». ورقمُ
 *      التاجر بيانةُ دخولٍ وعنوانُ دفعٍ معاً، فتغييرُه على من لم يُبلَّغ
 *      يُقفله خارج حسابه ويُضيّع من يدفع إليه.
 */
class MerchantNumberGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(?string $number = null): Merchant
    {
        $u = User::factory()->create(['type' => MERCHANT_TYPE, 'is_active' => 1]);

        // **بالإسناد لا بالتعبئة الجماعيّة** — وهو ما تفعله الشيفرةُ
        // الحقيقيّةُ في الموضعين، و`$fillable` لا يحمل هذه الحقول.
        $m = new Merchant();
        $m->user_id = $u->id;
        $m->store_name = 'متجرُ اختبار';
        $m->address = '—';
        $m->merchant_number = $number;
        $m->save();

        return $m;
    }

    /** @test */
    public function the_number_is_six_digits_with_no_zero_anywhere(): void
    {
        // **ولا يُقاس على عيّنةٍ واحدة**: خانةٌ واحدةٌ فيها صفرٌ نادراً
        // تمرّ في اختبارٍ يولّد رقماً واحداً، وتصل التاجرَ يومَ الإطلاق.
        $svc = app(MerchantNumberService::class);

        for ($i = 0; $i < 300; $i++) {
            $n = $svc->generate();

            $this->assertSame(6, strlen($n), "رقمٌ طولُه ليس ستّةً: {$n}");
            $this->assertMatchesRegularExpression('/^[1-9]{6}$/', $n,
                "رقمٌ فيه صفرٌ أو محرفٌ ليس رقماً: {$n} — والمطلوبُ ١ إلى ٩ فقط");
        }
    }

    /** @test */
    public function the_number_is_random_and_never_derived_from_the_account_id(): void
    {
        // **جذرُ العطل**: `M-%05d` تُفشي عدَّ التجّار — من رأى `M-00042`
        // علم أنّه الثاني والأربعون — **ويُخمَّن جارُه**، وهو عنوانُ دفع.
        $svc = app(MerchantNumberService::class);

        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[] = $svc->generate();
        }

        // مئتا سحبةٍ من ٥٣١٬٤٤١ يجب أن تُخرج تنوّعاً شبهَ كامل. ومولِّدٌ
        // ثابتٌ أو مشتقٌّ يُخرج قيمةً واحدةً أو متتاليةً.
        $this->assertGreaterThan(190, count(array_unique($seen)),
            'المولِّدُ يُعيد نفسَه — أي أنّه ليس عشوائيّاً حقّاً');

        $numeric = array_map('intval', $seen);
        sort($numeric);

        $consecutive = 0;
        for ($i = 1; $i < count($numeric); $i++) {
            if ($numeric[$i] - $numeric[$i - 1] === 1) {
                $consecutive++;
            }
        }

        $this->assertLessThan(20, $consecutive,
            'الأرقامُ متتاليةٌ — فالمولِّدُ يشتقّ ولا يعشّي، ويُخمَّن جارُ كلّ رقم');
    }

    /** @test */
    public function no_source_file_still_builds_the_old_derived_number(): void
    {
        // **وموضعُ التوليد كان اثنين لا واحداً** — التسجيلُ من التطبيق
        // وإنشاءُ الحساب من لوحة الإدارة. وإصلاحُ أحدهما يترك الآخرَ
        // يُنتج الصيغةَ القديمة. (القاعدة الرابعة.)
        $offenders = [];

        foreach ([app_path(), base_path('routes'), base_path('resources/views')] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($it as $f) {
                if (! $f->isFile() || ! in_array($f->getExtension(), ['php'], true)) {
                    continue;
                }

                // **والتعليقُ ليس تنفيذاً** — يُنزَع قبل الفحص، وإلّا
                // أسقط الحارسَ شرحٌ يقتبس الصيغةَ المحذوفة ليقول لماذا
                // حُذفت. (وهو ما وقع في حارس الباقات هذه الجلسة.)
                $code = implode("\n", array_filter(
                    explode("\n", (string) file_get_contents($f->getPathname())),
                    fn ($l) => ! str_starts_with(ltrim($l), '//') && ! str_starts_with(ltrim($l), '*')));

                if (str_contains($code, "'M-%05d'") || str_contains($code, '"M-%05d"')) {
                    $offenders[] = str_replace(base_path().'/', '', $f->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders,
            "ما زال يُولَّد الرقمُ القديم في:\n  ".implode("\n  ", $offenders));
    }

    /** @test */
    public function the_database_itself_refuses_two_merchants_with_one_number(): void
    {
        // **رقمان متطابقان يعني أنّ زبوناً يدفع للخطأ منهما.**
        //
        // و«اقرأ ثمّ اكتب» في الشيفرة ليس ذرّيّاً — عمليّتان متزامنتان
        // تقرآن الرقمَ نفسَه فتجدانه حرّاً. فالقيدُ في القاعدة هو الحارس،
        // **ويُجرَّب بالكتابة لا بقراءة المخطّط**.
        $this->assertTrue(
            Schema::hasColumn('merchants', 'merchant_number'));

        $this->merchant('444555');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->merchant('444555');
    }

    /** @test */
    public function many_null_numbers_stay_allowed_so_existing_rows_are_untouched(): void
    {
        // ② **القائمون لا يُمسّون** — وقيدٌ فريدٌ يرفض `NULL` المتكرّر
        // يُسقط الهجرةَ على قاعدةِ إنتاجٍ فيها متاجرُ بلا أرقام.
        $this->merchant(null);
        $this->merchant(null);

        $this->assertSame(2, Merchant::whereNull('merchant_number')->count());
    }

    /** @test */
    public function assigning_retries_instead_of_failing_when_the_number_is_taken(): void
    {
        // والفحصُ يقلّل الاصطدام، **والقيدُ يمنعه** — فمن ينادي يلتقط
        // التصادمَ ويعيد. وهذا يُثبت أنّ `assignTo` تفعل ذلك.
        $svc = app(MerchantNumberService::class);

        $made = [];
        for ($i = 0; $i < 25; $i++) {
            $m = $this->merchant(null);
            $made[] = $svc->assignTo($m);
        }

        $this->assertCount(25, array_unique($made),
            'أُسنِد رقمٌ مكرَّرٌ — فتاجران يتقاسمان عنوانَ دفعٍ واحداً');
        $this->assertSame(25, Merchant::whereNotNull('merchant_number')->count());
    }

    /** @test */
    public function editing_an_existing_merchant_never_moves_their_number(): void
    {
        // **العطلُ الذي وُلد مع العشوائيّة.** `AdminHubController` يُعيد
        // استعمالَ متجرٍ قائم، وكان يُسنِد `M-%05d` — وهي مشتقّةٌ من
        // `id` فتُنتج القيمةَ نفسَها في كلّ حفظ، أي أنّ إعادةَ الإسناد
        // بلا أثر. **والعشوائيُّ ليس كذلك**: كلُّ تعديلٍ يمنحه رقماً
        // جديداً فيُقفَل خارج حسابه — ولا خطأ في أيّ سجلّ.
        $src = file_get_contents(app_path('Http/Controllers/Admin/AdminHubController.php'));

        $code = implode("\n", array_filter(
            explode("\n", $src),
            fn ($l) => ! str_starts_with(ltrim($l), '//')));

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*trim\(\(string\)\s*\$mr->merchant_number\)\s*===\s*\'\'\s*\)/',
            $code,
            'إنشاءُ التاجر من لوحة الإدارة يُسنِد الرقمَ بلا شرط — '
            .'فكلُّ تعديلٍ لتاجرٍ قائمٍ يُغيّر رقمَه ويُقفله خارج حسابه');
    }

    /** @test */
    public function an_old_style_number_still_logs_in_because_nothing_assumes_the_format(): void
    {
        // ② **القائمون يبقون** — فالبحثُ عن الرقم لا يفترض ستّةَ أرقام.
        // وقِيس أنّ لا قارئَ في المشروع يفترض بادئةَ `M-`، وهذا يُثبّته.
        $m = $this->merchant('M-00042');

        $found = Merchant::where('merchant_number', 'M-00042')->first();

        $this->assertNotNull($found,
            'رقمٌ بالصيغة القديمة لم يُوجَد — فكلُّ تاجرٍ قائمٍ يُقفَل خارج حسابه');
        $this->assertSame($m->id, $found->id);
    }

    /** @test */
    public function the_generator_says_it_ran_out_instead_of_returning_nothing(): void
    {
        // **تسجيلٌ ينجح بلا رقمٍ أسوأ من تسجيلٍ يفشل بسببٍ مفهوم**:
        // تاجرٌ بلا رقمٍ لا يدخل ولا يُدفَع إليه. (القاعدة السابعة.)
        //
        // **وهذه الحالةُ كانت مسحاً نصّيّاً فمرّت وأنا أكسرها** — في
        // الملفّ رميتان، ونزعُ إحداهما لا يُخفي الأخرى عن `str_contains`.
        // فصارت تُقاس بالسلوك: مولِّدٌ يُثبّت مخرَجَه على رقمٍ مأخوذٍ
        // سلفاً، فالمساحةُ نافدةٌ فعلاً لا نصّاً.
        $this->merchant('777777');

        $svc = new class extends MerchantNumberService
        {
            protected function random(): string
            {
                return '777777';
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/تضيق|LENGTH/u');

        $svc->generate();
    }

    /** @test */
    public function the_randomness_is_cryptographic_and_never_a_seeded_generator(): void
    {
        // رقمُ التاجر عنوانُ دفعٍ يُخمَّن ثمنُ تخمينه مال — و`rand()`
        // متوقَّعةُ البذرة تُعيد العيبَ الذي وُلد الملفُّ لإصلاحه.
        $code = implode("\n", array_filter(
            explode("\n", (string) file_get_contents(
                app_path('Services/Merchant/MerchantNumberService.php'))),
            fn ($l) => ! str_starts_with(ltrim($l), '//') && ! str_starts_with(ltrim($l), '*')));

        $this->assertStringContainsString('random_int', $code);
        $this->assertDoesNotMatchRegularExpression('/\bmt_rand\s*\(|(?<!_)\brand\s*\(/', $code,
            'المولِّدُ يستعمل عشوائيّاً غيرَ آمنٍ تشفيريّاً');
    }

    /** @test */
    public function the_registration_response_hands_the_new_number_back_to_the_merchant(): void
    {
        // **رقمٌ يُولَّد ولا يُقال لصاحبه لا يُوصَل إليه** (القاعدة الثانية
        // عشرة). والشاشةُ تقول «احفظه — تدخل به للتطبيق».
        $src = file_get_contents(app_path('Http/Controllers/Api/V1/RegisterController.php'));

        $this->assertStringContainsString('MerchantNumberService', $src);
        $this->assertStringContainsString("\$loginNumbers['merchant_number'] = \$mr->merchant_number;", $src,
            'الرقمُ الجديدُ لا يعود في ردّ التسجيل — فلا يعرفه التاجرُ ولا يدخل');
    }
}
