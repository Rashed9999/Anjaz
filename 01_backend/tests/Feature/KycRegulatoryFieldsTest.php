<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\KycDocumentService;
use App\Support\Kyc\KycProfileFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-KYC-INTL-003 — **حقولُ «اعرف عميلك» — ومَن يطالب بها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «المفترض يتم تطبيق ذلك عندنا» — ومعه نموذجُ فتح حسابٍ من
 * بنك عدن فيه أكثرُ من ثلاثين حقلاً.
 *
 * وقِيست الفجوةُ حقلاً حقلاً قبل هجرةٍ واحدة، **فأُخذ ما يخدم إلزاماً
 * رقابيّاً أو قرارَ مخاطر، ورُدّ ما عداه صراحةً**: هاتفُ المنزل،
 * والمؤهّلُ العلميّ، ونوعُ الحساب المصرفيّ، وبريدُ جهة العمل. **وكلُّ
 * حقلٍ زائدٍ يُطيل التسجيلَ ويرفع الانسحاب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ عقودٍ تُحرَس ها هنا، ولكلٍّ ثمنٌ لو انكسر:**
 *
 * ① **حقلٌ يُطلَب ولا يُحفَظ أسوأ من غيابه** — يُوهم بأنّ البيانَ عندنا
 *    فلا يُطلَب ثانية. (وقع في هذا المشروع: `metadata` مقابل `context`
 *    في سبعةَ عشرَ موضعاً، فسقط `staff_id` و`branch_id` بصمت.)
 *
 * ② **و«لم يُسأل» ليس «لا»** — والفرقُ في ملفّ امتثالٍ هو الفرقُ بين
 *    ثغرةٍ في الإجراء وإجابةٍ تُراجَع.
 *
 * ③ **والإلزامُ موضعُه بوّابةُ الاعتماد لا بوّابةُ التسجيل** — فإلزامُه
 *    في التسجيل يُقفل البابَ على مئات الحسابات القائمة.
 */
class KycRegulatoryFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return array_merge([
            'f_name' => 'راشد', 'l_name' => 'محمد عوض معرابي',
            'gender' => 'male', 'dial_country_code' => '+967',
            'phone' => '783545525', 'password' => '4321',
        ], $extra);
    }

    private function register(array $extra = []): User
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => 0, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->postJson('/api/v1/customer/auth/register', $this->payload($extra))
            ->assertSuccessful();

        return User::where('phone', 'like', '%783545525')->firstOrFail();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① ما يُرسَل يُحفَظ — كلُّه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_field_the_form_asks_for_is_actually_stored(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وحقلٌ يُطلَب في النموذج ولا يُحفَظ أسوأ من غيابه.**
        //
        // يُوهم بأنّ البيانَ عندنا فلا يُطلَب ثانية، ثمّ يُكتشَف فراغُه
        // يومَ يُسأل عنه — وهو يومُ التدقيق لا يومُ التسجيل.
        //
        // **ويُقاس بالجرد لا بقائمةٍ مكتوبةٍ هنا**: قائمتان تفترقان
        // بحقلٍ تُنتجان حقلاً يُرسَل ولا يصل، بلا خطأٍ في أيّ سجلّ.
        // ══════════════════════════════════════════════════════════════
        $sent = [
            'name_en' => 'RASHED MOHAMMED AWADH MARABE',
            'father_name' => 'محمد',
            'grandfather_name' => 'عوض',
            'country_of_birth' => 'اليمن',
            'dual_nationality' => 'لا',
            'id_place_of_issue' => 'المهرة',
            'marital_status' => 'single',
            'residence_district' => 'سيحوت',
            'residence_area' => '14 أكتوبر',
            'residence_landmark' => 'قرب المسجد',
            'housing_type' => 'owned',
            'employer_name' => 'صياد',
            'job_title' => 'عامل حرّ',
            'work_address' => 'سيحوت',
            'income_source' => 'business',
            'account_purpose' => 'savings',
            'kin2_name' => 'يوسف محمد عوض معرابي',
            'kin2_phone' => '777777777',
            'kin2_relation' => 'أخي',
        ];

        $user = $this->register($sent);

        foreach ($sent as $field => $value) {
            $this->assertSame($value, (string) $user->{$field},
                "الحقل «{$field}» طُلب في النموذج ولم يصل إلى القاعدة — "
                . 'وحقلٌ يُجمَع ولا يُحفَظ يُوهم بأنّ البيان عندنا');
        }

        // **ولا يُترك حقلٌ من الجرد بلا اختبار.** فمُختبِرٌ يغطّي بعضَ
        // الحقول يُقرأ تغطيةً كاملة، وهو الصمتُ بثوب نجاح.
        $this->assertEqualsCanonicalizing(
            KycProfileFields::TEXT_FIELDS, array_keys($sent),
            'الجردُ والاختبارُ افترقا — فحقلٌ أُضيف ولا حارسَ له');
    }

    /** @test */
    public function income_is_stored_as_a_number_with_its_currency_said(): void
    {
        // **و«١٠٠٠٠٠» بلا عملةٍ رقمٌ صحيحٌ بمعنىً مجهول:** بالريال اليمنيّ
        // دخلٌ متواضع وبالدولار ثروة. والتحويلُ الصامتُ يستبدل كذبةً
        // بأطولَ منها عمراً — الدرسُ نفسُه من عملة الباقات.
        $user = $this->register([
            'monthly_income' => '100000', 'monthly_income_currency' => 'YER',
        ]);

        $this->assertSame(100000.0, (float) $user->monthly_income);
        $this->assertSame('YER', $user->monthly_income_currency,
            'دخلٌ محفوظٌ بلا عملة — رقمٌ صحيحٌ بمعنىً مجهول');
    }

    /** @test */
    public function a_field_not_sent_never_overwrites_one_already_stored(): void
    {
        // **وكتابةُ فراغٍ فوق قيمةٍ قائمةٍ تمحو بياناً بلا أن يطلب أحدٌ
        // محوَه** — وهي أخطرُ من ألّا يُحفَظ الجديد.
        $u = User::factory()->create(['type' => 2]);
        $u->forceFill(['name_en' => 'OLD NAME', 'residence_district' => 'سيحوت'])->save();

        $req = \Illuminate\Http\Request::create('/', 'POST', ['job_title' => 'مهندس']);

        KycProfileFields::fill($u, $req);
        $u->save();

        $this->assertSame('OLD NAME', $u->refresh()->name_en,
            'مُحي بيانٌ قائمٌ لأنّ الطلبَ لم يحمله');
        $this->assertSame('مهندس', $u->job_title);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② و«لم يُسأل» ليس «لا»
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function pep_is_null_when_never_asked_not_false(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **أخطرُ حقلٍ في النموذج كلِّه.**
        //
        // «هل تشغل أنت أو أحد أقاربك منصباً سياسيّاً؟» هو الحقلُ الذي
        // تقوم عليه العنايةُ الواجبةُ المشدّدة في كلّ نظامٍ لمكافحة غسل
        // الأموال. **وغيابُه يعني أنّ المنصّةَ لا تستطيع أن تقول إنّها
        // فحصت** — لا أنّها فحصت فلم تجد. (القاعدة السابعة.)
        //
        // **وكتابةُ `false` لغياب الحقل تُحوّل ثغرةً في الإجراء إلى
        // إجابةٍ مطمئنّة**، وهي أسوأُ ما يقع في ملفّ امتثال.
        // ══════════════════════════════════════════════════════════════
        $user = $this->register();

        $this->assertNull($user->is_pep,
            '«لم يُسأل» كُتب «لا» — فثغرةُ الإجراء صارت إجابةً مطمئنّة');
    }

    /** @test */
    public function pep_denied_and_pep_declared_are_told_apart(): void
    {
        $denied = $this->register(['is_pep' => '0']);

        $this->assertFalse((bool) $denied->is_pep);
        $this->assertNotNull($denied->is_pep, '«أنكر» خُلط بـ«لم يُسأل»');

        // **ولا يُحذف حسابٌ ليُعاد التسجيل** — الحذفُ ثمّ الإنشاءُ يقيس
        // مسارَ الحذف لا مسارَ الإقرار. فيُسجَّل حسابٌ ثانٍ برقمٍ آخر.
        $this->postJson('/api/v1/customer/auth/register', $this->payload([
            'phone' => '783545526', 'is_pep' => '1', 'pep_position' => 'وكيل وزارة',
        ]))->assertSuccessful();

        $declared = User::where('phone', 'like', '%783545526')->firstOrFail();

        $this->assertTrue((bool) $declared->is_pep);
        $this->assertSame('وكيل وزارة', $declared->pep_position);
    }

    /** @test */
    public function a_declared_pep_without_a_position_is_flagged_incomplete(): void
    {
        // **و«نعم» وحدَها لا تُحقَّق** — إقرارٌ بلا منصبٍ لا يُبنى عليه
        // فحصٌ ولا قرار.
        $u = User::factory()->create(['type' => 2]);
        $u->forceFill([
            'is_pep' => true, 'pep_position' => null,
            'name_en' => 'X', 'father_name' => 'أ', 'grandfather_name' => 'ب',
            'residence_district' => 'ج', 'income_source' => 'salary',
            'account_purpose' => 'savings',
        ])->save();

        $this->assertContains('المنصب السياسيّ المُقرّ به',
            KycProfileFields::missingFor($u->refresh()));
    }

    /** @test */
    public function never_being_asked_about_pep_is_itself_reported_as_missing(): void
    {
        $u = User::factory()->create(['type' => 2]);

        $this->assertContains('الإفصاح عن المنصب السياسيّ (لم يُسأل بعد)',
            KycProfileFields::missingFor($u),
            'غيابُ السؤال مرّ صامتاً — والمنصّةُ لا تستطيع أن تقول إنّها فحصت');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ والإلزامُ في بوّابة الاعتماد لا في التسجيل
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function registration_still_succeeds_with_none_of_the_new_fields(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرة.** إلزامُ الحقول في
        // التسجيل يُقفل البابَ على من لا يملكها بعد.
        $this->assertNotNull($this->register()->id);
    }

    /** @test */
    public function approval_is_refused_while_regulatory_fields_are_missing(): void
    {
        // **وحقلٌ يُجمَع ولا يُطالَب به عند القرار زينةٌ لا امتثال.**
        // فبوّابةُ الاعتماد هي التي تطالب — وهي المرحلةُ التي يُبنى
        // عليها سقفُ مال.
        $completeness = app(KycDocumentService::class)
            ->completenessFor(User::factory()->create(['type' => 2]), 2);

        $this->assertNotEmpty($completeness['missing_fields'],
            'اعتمادُ الفئة الثانية لا ينظر إلى الحقول الرقابيّة إطلاقاً');

        $this->assertFalse($completeness['complete']);
    }

    /** @test */
    public function the_first_tier_is_not_burdened_with_them(): void
    {
        // **وتشديدٌ على من لا يحتاجه يُطفَأ عند أوّل شكوى.** الفئةُ
        // الأولى محفظةٌ بحدٍّ أدنى، والنموذجُ المصرفيُّ لا يحكمها.
        $completeness = app(KycDocumentService::class)
            ->completenessFor(User::factory()->create(['type' => 2]), 3);

        $this->assertArrayHasKey('missing_fields', $completeness);

        $reflect = new \ReflectionMethod(KycDocumentService::class, 'completenessFor');
        $this->assertTrue($reflect->isPublic());
    }

    /** @test */
    public function the_refusal_names_the_missing_field_not_just_the_file(): void
    {
        // **ورسالةٌ لا تدلّ على سببها تُنتج تذكرةَ دعمٍ لا إجراء.**
        $src = file_get_contents(app_path('Services/KycDocumentService.php'));

        // ══════════════════════════════════════════════════════════════
        // **ويُقاس العقدُ لا مسافةُ البايتات.**
        //
        // كان الشرطُ `~KYC_PROFILE_INCOMPLETE.{0,120}missing_fields~s` —
        // **نافذةٌ من مئةٍ وعشرين بايتاً**. وبينهما اليومَ سطرُ العنوان
        // العربيّ («لا تُرفَع الفئةُ الثالثةُ قبل استكمال هذه الحقول»)،
        // والحرفُ العربيُّ بايتان، فتجاوزت النافذةَ **والشيفرةُ سليمة**.
        //
        // فحارسٌ يقيس التجاورَ يسقط على تعليقٍ يُضاف أو عنوانٍ يُترجَم،
        // **ويُطمئن لو نُزع `missing_fields` وبقيا متجاورين**. والمفحوصُ
        // أنّ الرفضَ الحاملَ لهذا الرمز يحمل الحقولَ الناقصةَ معه.
        // ══════════════════════════════════════════════════════════════
        $at = strpos($src, "'KYC_PROFILE_INCOMPLETE'");

        $this->assertNotFalse($at,
            '**رمزُ رفضِ الفئة الثالثة اختفى** — فلا يُعرف صنفُ النقص أصلاً.');

        // جسمُ النداء الذي يحمل الرمز، بأقواسه المتوازنة.
        $open = strrpos(substr($src, 0, $at), '(');
        $depth = 0;
        $end = $open;

        for ($i = $open; $i < strlen($src); $i++) {
            if ($src[$i] === '(') { $depth++; }
            if ($src[$i] === ')') { $depth--; if ($depth === 0) { $end = $i; break; } }
        }

        $call = substr($src, $open, $end - $open + 1);

        $this->assertStringContainsString('missing_fields', $call,
            '**رفضُ الفئة الثالثة لا يسمّي الحقلَ الناقص** — فيقرأ '
            . 'المراجعُ «الملفُّ ناقص» ويبحث، وتُنتج الرسالةُ تذكرةَ '
            . 'دعمٍ لا إجراءً.');

        // **ورمزان لا رمزٌ واحد**: «الوثائقُ ناقصة» غيرُ «الملفُّ ناقص»،
        // والخلطُ بينهما يُرسل المراجعَ يرفع صورةً لحقلٍ نصّيّ.
        $this->assertStringContainsString('KYC_DOCUMENTS_INCOMPLETE', $src);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ ولا يُخترَع جوابٌ لِما لا يُعرف
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_unknown_income_source_is_refused_not_stored_raw(): void
    {
        // **وقيمةٌ خارجَ القائمة تُفسد كلَّ تجميعٍ يُبنى عليها.**
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => 0, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->postJson('/api/v1/customer/auth/register',
            $this->payload(['income_source' => 'من عند الله']))
            ->assertStatus(403);
    }

    /** @test */
    public function a_latin_name_field_refuses_arabic(): void
    {
        // **والحقلُ الذي يقبل كلَّ شيءٍ لا يضمن شيئاً**: `name_en` بالعربيّة
        // يُبطل الغرضَ منه — مطابقةُ قوائم العقوبات اللاتينيّة.
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => 0, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->postJson('/api/v1/customer/auth/register',
            $this->payload(['name_en' => 'راشد معرابي']))
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ والتطبيق يُدخلها فعلاً — فحقلٌ يُرسَل ولا يُدخَل ليس مبنيّاً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_app_has_an_input_for_every_field_it_sends(): void
    {
        // **ونصفُ ميزةٍ في الخادم بلا نصفِها في التطبيق ليس ميزة.**
        $wizard = base_path(
            '../02_flutter_app/lib/features/auth/screens/amial_registration_wizard_screen.dart');

        if (! is_file($wizard)) {
            $this->markTestSkipped('معالجُ التسجيل غيرُ موجودٍ في هذه البيئة');
        }

        $src = file_get_contents($wizard);

        foreach ([
            '_nameEn', '_countryOfBirth', '_employerName', '_jobTitle',
            '_workAddress', '_monthlyIncome', '_pepPosition',
            '_kin2Name', '_kin2Phone', '_kin2Relation',
        ] as $field) {
            $this->assertMatchesRegularExpression(
                '~_field\(' . preg_quote($field, '~') . '~', $src,
                "«{$field}» يُرسَل ولا حقلَ إدخالٍ له في الشاشة");
        }

        // **والمنصبُ السياسيُّ يُختار ولا يُترك بقيمةٍ افتراضيّة.**
        $this->assertStringContainsString('emptySelectionAllowed: true', $src,
            'سؤالُ المنصب السياسيّ يبدأ بجوابٍ منتقىً — فيُقرأ صمتُ المستعمل جواباً');
    }

    /** @test */
    public function the_app_sends_the_address_structured_not_only_joined(): void
    {
        // **والبنيةُ تُجمَع ثمّ تُتلَف**: أربعةُ حقولٍ تُدمج في نصٍّ واحدٍ
        // بفواصل، فلا تُقارَن ولا تُفهرَس ولا يُبنى عليها تحقّقُ سكن.
        $wizard = base_path(
            '../02_flutter_app/lib/features/auth/screens/amial_registration_wizard_screen.dart');

        if (! is_file($wizard)) {
            $this->markTestSkipped('معالجُ التسجيل غيرُ موجودٍ في هذه البيئة');
        }

        $src = file_get_contents($wizard);

        foreach (['residence_district', 'residence_area', 'residence_landmark',
            'father_name', 'grandfather_name'] as $key) {
            $this->assertStringContainsString("'{$key}':", $src,
                "«{$key}» يُدمج في نصٍّ حرٍّ ولا يُرسَل مُهيكلاً");
        }
    }
}
