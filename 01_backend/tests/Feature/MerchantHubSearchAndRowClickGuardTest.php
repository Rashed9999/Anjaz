<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-HUB-SEARCH-001 · AMIAL-HUB-ROW-SWALLOW-001 · AMIAL-HUB-EDIT-SILENT-001
 *
 * **ثلاثةُ أعطالٍ وصلت في رسالةٍ واحدة، وكلُّها في مركز التجّار:**
 *
 *     «تعديل لم يفتح · ثم ضغطت على فتح المركز تظهر قائمة و تختفي لا أعلم
 *      ما هي · و أيضاً حاولت إنشاء حساب جديد أخبرني تم الحفظ، بحثت عن
 *      الحساب فلم أجده»
 *
 * ══════════════════════════════════════════════════════════════════════
 * **① «بحثت فلم أجده» — والحسابُ موجود.** قِيس على حسابٍ أُنشئ للتوّ
 * (‏٢٠١) باسمِ منشأةٍ «محطة الشرق» ومالكٍ «أحمد علي»:
 *
 *     «محطة الشرق»    → صفر     ← ما يكتبه المستعمل
 *     «الشرق»         → صفر
 *     «أحمد»          → واحد
 *     «712345678»     → واحد
 *     «+967712345678» → صفر     ← الهاتفُ كما يُنسَخ
 *     «0712345678»    → صفر     ← الهاتفُ كما يُملى
 *
 * فاسمُ المنشأة لم يكن يُبحَث فيه إطلاقاً — **والتاجرُ يُعرَف بمحطّته لا
 * باسم مالكه**. والهاتفُ يُخزَّن معياريّاً `967…`، فمن نسخه بصيغة `+967…`
 * أو أملاه `07…` لا يطابق `LIKE` حرفاً بحرف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **② «تظهر قائمة و تختفي» — قِيس في متصفّحٍ حقيقيّ.** الضغطُ على سهم
 * «فتح المركز» كان **ينتقل إلى `/hub/account/7`** بدل أن يفتحها: تفتحها
 * Bootstrap جزءاً من ثانيةٍ ثمّ تذهب الصفحةُ كلُّها.
 *
 * وسببُه أنّ زرَّ السهم بلا `data-act`، و`closest` تصعد إلى الأجداد لا
 * إلى الإخوة — فيصير `btn = null` ويلتقط الصفُّ الضغطة.
 *
 * **وهي المرّةُ الثالثة لهذا النمط في هذا الملفّ**، وعولجت مرّتين بإضافة
 * اسمٍ إلى قائمةٍ مكتوبة فعادت مع أوّل عنصرٍ جديد. فصار الحدُّ على الصنف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **③ «تعديل لم يفتح» — وهي تُفتح.** قِيس فإذا النافذةُ تظهر، لكنّها
 * تبقى على «جارٍ قراءة حالة الحساب…» إن سقط أيُّ سطرٍ في التعبئة:
 * `loadEdit` غيرُ متزامنةٍ تُنادى بلا `catch`، **والاستثناءُ يضيع في وعدٍ
 * لا يمسكه أحد**. فلا رسالةَ ولا أثر، والمستعمل يرى مستطيلاً رمادياً.
 */
class MerchantHubSearchAndRowClickGuardTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'views/admin-views/amial/hub/users.blade.php';

    private function hubView(): string
    {
        return (string) file_get_contents(resource_path(self::VIEW));
    }

    private function admin(): User
    {
        $a = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770008888',
        ]);

        $rid = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');

        DB::table('admin_user_roles')->updateOrInsert(
            ['user_id' => $a->id, 'role_id' => $rid],
            ['created_at' => now(), 'updated_at' => now()]);

        return $a;
    }

    /** تاجرٌ اسمُ مالكه غيرُ اسم منشأته — **وهو الحالُ الغالب**. */
    private function station(): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'zone_code' => 'SOUTH',
            'f_name' => 'أحمد', 'l_name' => 'علي',
            'phone' => '967712345678',
        ]);

        // **كتابةٌ مباشرةٌ لا `create`** — `Merchant` يحرس الإسنادَ
        // الجَماعيَّ عمداً (مفاتيحُ الربط فيه)، والتجهيزُ لا يخترق حرساً.
        DB::table('merchants')->insert([
            'user_id' => $u->id, 'store_name' => 'محطة الشرق',
            'merchant_number' => 'M-'.$u->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    private function search(User $admin, string $term): int
    {
        $r = $this->actingAs($admin, 'user')
            ->get('/admin/amial/hub/merchants/users.json?search='.urlencode($term));

        $r->assertOk();

        return count($r->json('data') ?? []);
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① يُوجَد باسم منشأته — وهو ما يكتبه المستعمل.**
     */
    /** @test */
    public function a_station_is_found_by_its_business_name(): void
    {
        $admin = $this->admin();
        $this->station();

        $this->assertSame(1, $this->search($admin, 'محطة الشرق'),
            "**أُنشئ الحسابُ ولا يُوجَد باسم منشأته.**\n"
            .'والتاجرُ يُعرَف بمحطّته لا باسم مالكه — فمن أنشأ «محطة الشرق» '
            .'يكتبها في البحث، والمطابقةُ كانت على الاسم الشخصيّ وحدَه. '
            .'**فيُقرَأ «تم الحفظ» ثمّ لا يُوجَد الحساب.**');

        $this->assertSame(1, $this->search($admin, 'الشرق'),
            'المطابقةُ الجزئيّةُ على اسم المنشأة لا تعمل.');
    }

    /**
     * **② ويُوجَد بهاتفه أيّاً كانت صيغتُه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فالرقمُ يُخزَّن معياريّاً `967…`، ويُكتب في الحياة بأربع صيغ. ومن
     * نسخ الرقمَ من رسالةٍ أو ملفٍّ يلصقه بـ`+`، ومن أملاه محلّيّاً يبدأ
     * بصفر. **والبحثُ الذي لا يجد الرقمَ بصيغته المخزَّنة نفسِها عطلٌ
     * صريح** — و`+967712345678` كان يُخرج صفراً.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_station_is_found_by_its_phone_in_any_shape(): void
    {
        $admin = $this->admin();
        $this->station();

        $misses = [];

        foreach (['712345678', '967712345678', '+967712345678',
            '0712345678', '00967712345678', '+967 712 345 678'] as $shape) {
            if ($this->search($admin, $shape) !== 1) {
                $misses[] = $shape;
            }
        }

        $this->assertSame([], $misses, sprintf(
            "**صيغُ هاتفٍ لا يجدها البحث:**\n  %s\n\n"
            .'والرقمُ واحدٌ والحسابُ واحد — فمن لصق الرقمَ كما نسخه قرأ '
            .'«لا نتائج» على حسابٍ أمامه.',
            implode('  ·  ', $misses)));
    }

    /** **③ ولا يُكسَر ما كان يعمل: الاسمُ الشخصيُّ ورقمُ الحساب.** */
    /** @test */
    public function the_old_ways_of_finding_an_account_still_work(): void
    {
        $admin = $this->admin();
        $u = $this->station();

        $this->assertSame(1, $this->search($admin, 'أحمد'), 'البحثُ بالاسم الأوّل انكسر.');
        $this->assertSame(1, $this->search($admin, 'علي'), 'البحثُ باسم العائلة انكسر.');
        $this->assertSame(1, $this->search($admin, (string) $u->id), 'البحثُ برقم الحساب انكسر.');
    }

    /** **④ ولا يُوسَّع البحثُ فيجلب من لا علاقةَ له.** */
    /** @test */
    public function the_search_does_not_start_matching_everyone(): void
    {
        $admin = $this->admin();
        $this->station();

        $this->assertSame(0, $this->search($admin, 'محطة الغرب'),
            '**البحثُ صار يجلب ما لا يطابق** — وقائمةٌ تجلب الجميع '
            .'كقائمةٍ لا تجلب أحداً.');

        $this->assertSame(0, $this->search($admin, '799999999'),
            'رقمٌ لا يخصّ أحداً يُخرج نتيجة.');
    }

    /**
     * **⑤ والصفُّ لا يبتلع ضغطةً على عنصرٍ تفاعليّ.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **والحدُّ على الصنف لا على الأسماء عمداً.** عولج هذا النمطُ مرّتين
     * قبلها بإضافة اسمٍ إلى قائمةٍ مكتوبة (`a[data-act^="net-"]` ثمّ
     * `a[data-act="center-sec"]`)، **فعاد مع أوّل عنصرٍ جديدٍ لم يُدرَج**
     * — وهو سهمُ «فتح المركز». وقائمةٌ مكتوبةٌ تشيخ.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_row_does_not_swallow_clicks_on_interactive_controls(): void
    {
        $src = $this->hubView();

        $this->assertMatchesRegularExpression(
            "/const interactive = e\.target\.closest\(\s*\n?\s*'button, a, input, select, textarea, label, \[data-bs-toggle\]'\)/u",
            $src,
            "**عاد الصفُّ يبتلع ضغطاتِ أزراره.**\n"
            .'فزرٌّ بلا `data-act` — كسهم القائمة — يجعل `btn = null`، '
            ."فيلتقط الصفُّ الضغطةَ وينقل إلى صفحة الحساب.\n"
            .'**والمستعمل يرى القائمةَ تظهر ثمّ تختفي**، ولا خطأَ في أيّ سجلّ.');

        $this->assertStringContainsString('const row = interactive ? null :', $src,
            '**فحصُ العنصر التفاعليّ موجودٌ ولا يُستعمَل في قرار الصفّ** — '
            .'وهو أسوأ من غيابه: يبدو الحرسُ قائماً وهو معطَّل.');
    }

    /**
     * **⑥ ونافذةُ التعديل تقول إن تعذّرت القراءة، ولا تتجمّد.**
     *
     * فمستطيلٌ رماديٌّ إلى الأبد يُقرأ «لم تُفتح» — وهو ما وصل بنصّه.
     */
    /** @test */
    public function the_edit_modal_reports_a_failure_instead_of_freezing(): void
    {
        $src = $this->hubView();

        $this->assertStringContainsString('await loadEditInner();', $src,
            'تعبئةُ النافذة لم تعد ملفوفةً — فأيُّ سقوطٍ فيها يضيع صامتاً.');

        $this->assertMatchesRegularExpression(
            "/catch \(err\) \{[^}]*edit-loading'\)\.classList\.add\('d-none'\)/su", $src,
            '**تُخفى شارةُ «جارٍ القراءة» عند الفشل** — وإلّا بقيت تدور '
            .'فوق رسالة الخطأ، فلا يعرف المستعمل أيَّهما يصدّق.');

        $this->assertStringContainsString('تعذّرت قراءةُ بيانات الحساب', $src,
            '**الفشلُ صامت** — والصمتُ يُرسل المستعملَ يبحث عن عطلٍ في '
            .'مكانٍ آخر، أو يعيد الضغطَ عشراً.');
    }
}
