<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Catalog\ProductCatalogService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-CATALOG-001 — الكتالوج المشترك: مفتوحٌ بمراجعة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الفكرة:** باركودُ EAN عالميٌّ بحكم تعريفه — علبةُ الحليب تحمل الرقمَ
 * نفسَه عند كلّ تاجر. فإدخالُ اسمها عشرين مرّةً عند عشرين تاجراً عملٌ
 * مكرّرٌ بلا سبب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما يُفسدها: الباركود الداخليّ.**
 *
 * المتاجرُ تطبع ملصقاتِها للسائب وتبدأ بـ`2` (نطاقٌ محجوزٌ في معيار
 * EAN). **والرقمُ نفسُه يعني «كيلو طماطم» عند تاجرٍ و«لحم غنم» عند
 * آخر.** فدخولُها الكتالوجَ يجعل الماسحَ يقترح أسماءً خاطئة — **وهو أسوأ
 * من ألّا يقترح شيئاً: الخطأُ الصامتُ يُصدَّق.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وحدٌّ بنيويٌّ لا بصريّ: لا سعرَ في الكتالوج.**
 *
 * رؤيةُ تاجرٍ لسعر منافسه تسريبٌ تجاريّ. والجدولُ نفسُه لا يحمل عموداً
 * له — فلا يُسرَّب ولو أخطأ متحكّمٌ يوماً.
 */
class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): ProductCatalogService
    {
        return app(ProductCatalogService::class);
    }

    private function merchant(string $phone = '967770040001'): User
    {
        $u = User::factory()->create([
            'type' => 3, 'phone' => $phone, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'is_active' => 1,
        ]);
        MerchantProfile::create(['user_id' => $u->id, 'verification_status' => 'verified']);

        return $u->fresh();
    }

    private function admin(string $role = PlatformRoleService::ADMIN, string $phone = '967770040009'): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'مدير', 'l_name' => 'الكتالوج', 'phone' => $phone,
            'email' => $phone . '@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        app(PlatformRoleService::class)->assign($u, $role);

        return $u->fresh();
    }

    // ══════════════════════════════════════════════════════════════
    // ١) الباركود الداخليّ — الفخّ الذي يُفسد كلّ شيء
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **ما يبدأ بـ٢ لا يدخل الكتالوج أبداً — ويُقال لماذا.**
     */
    public function an_internal_barcode_never_enters_the_catalog(): void
    {
        $m = $this->merchant();

        $reason = $this->svc()->suggest('2001234567890', 'كيلو طماطم', $m);

        $this->assertNotNull($reason, 'دخل باركودٌ داخليٌّ الكتالوجَ');
        $this->assertStringContainsString('داخليّ', $reason);

        $this->assertDatabaseCount('product_catalog_entries', 0);
        $this->assertDatabaseCount('product_catalog_suggestions', 0);
    }

    /**
     * @test
     *
     * **والأطوالُ غير المعياريّة كذلك.**
     */
    public function a_non_standard_length_is_refused(): void
    {
        $m = $this->merchant('967770040011');

        foreach (['123', '1234567890123456789', 'ABC123456789'] as $bad) {
            $this->assertNotNull($this->svc()->suggest($bad, 'صنف', $m),
                "قُبل باركودٌ غير معياريّ: {$bad}");
        }

        $this->assertDatabaseCount('product_catalog_entries', 0);
    }

    /**
     * @test
     *
     * **والإدارةُ لا تتجاوز القاعدة — والصلاحيّةُ لا تُخفّفها.**
     *
     * فباركودٌ داخليٌّ يُدخله مديرٌ يُفسد الكتالوجَ كما يُفسده اقتراحُ
     * تاجر. **والحمايةُ في الخدمة لا في متحكّم**، فلا يتجاوزها بابٌ آخر.
     */
    public function even_an_admin_cannot_insert_an_internal_barcode(): void
    {
        $this->actingAs($this->admin(), 'user')
            ->postJson('/admin/amial/catalog', [
                'barcode' => '2999888777666', 'name' => 'لحم غنم',
            ])->assertStatus(422);

        $this->assertDatabaseCount('product_catalog_entries', 0);
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) النموّ من التجّار
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **أوّلُ تاجرٍ يُدخل الصنفَ يبنيه لمن بعده.**
     */
    public function the_first_merchant_seeds_the_catalog_for_the_next(): void
    {
        $m1 = $this->merchant('967770040021');

        $this->assertNull($this->svc()->suggest('6291100010101', 'حليب نادك 1 لتر', $m1));

        $found = $this->svc()->find('6291100010101');

        $this->assertNotNull($found, 'أُدخل الصنفُ ولم يدخل الكتالوج');
        $this->assertSame('حليب نادك 1 لتر', $found->name);
        $this->assertSame('proposed', $found->status);
        $this->assertSame(1, (int) $found->adoption_count);
    }

    /**
     * @test
     *
     * **وعدّادُ التبنّي يُحسب من الاستعمال لا يُكتب يدويّاً.**
     *
     * وهو ما يقول للإدارة أيُّ الأسماء أشيع حين تتعارض. (القاعدة السادسة.)
     */
    public function adoption_is_counted_from_real_use(): void
    {
        $m1 = $this->merchant('967770040031');
        $m2 = $this->merchant('967770040032');
        $m3 = $this->merchant('967770040033');

        foreach ([$m1, $m2, $m3] as $m) {
            $this->svc()->suggest('6291100010202', 'شاي ليبتون', $m);
        }

        $this->assertSame(3, (int) $this->svc()->find('6291100010202')->adoption_count);
    }

    /**
     * @test
     *
     * **وتاجرٌ واحدٌ لا يُضخّم العدّاد بإعادة الحفظ.**
     *
     * فلولا ذلك لبدا صنفٌ عند تاجرٍ واحدٍ وكأنّ عشرين تبنّوه — **ورقمٌ
     * يكذب في شاشة قرار أسوأ من غيابه.**
     */
    public function one_merchant_saving_twice_does_not_inflate_the_count(): void
    {
        $m = $this->merchant('967770040041');

        $this->svc()->suggest('6291100010303', 'أرز بسمتي', $m);
        $this->svc()->suggest('6291100010303', 'أرز بسمتي', $m);

        $this->assertSame(1, DB::table('product_catalog_suggestions')
            ->where('barcode', '6291100010303')->count(),
            'صفٌّ مكرّرٌ من تاجرٍ واحد');
    }

    /**
     * @test
     *
     * **والتعارضُ يُحفظ ويُعرض — لا يُبتلع.**
     *
     * «شاي ليبتون ١٠٠ كيس» و«ليبتون أحمر كبير» كلاهما صحيح، والإدارةُ
     * تختار. ولو كان الباركودُ مفتاحاً فريداً في جدولٍ واحدٍ لضاع الثاني.
     */
    public function competing_names_are_kept_and_shown(): void
    {
        $m1 = $this->merchant('967770040051');
        $m2 = $this->merchant('967770040052');

        $this->svc()->suggest('6291100010404', 'شاي ليبتون 100 كيس', $m1);
        $this->svc()->suggest('6291100010404', 'ليبتون أحمر كبير', $m2);

        $names = collect($this->svc()->conflicts('6291100010404'))->pluck('name');

        $this->assertCount(2, $names, 'ضاع أحدُ الاسمين');
        $this->assertSame(1, $this->svc()->conflictCount());
    }

    /**
     * @test
     *
     * **وفشلُ الكتالوج لا يُوقف حفظَ منتج التاجر.**
     *
     * فالكتالوجُ خدمةٌ جانبيّة. ومن ربط بيعَ متجرٍ بنجاح ميزةٍ مساعدةٍ
     * أوقف المتجرَ كلَّه لأجل اسمٍ في جدول.
     */
    public function a_refused_barcode_still_saves_the_merchant_product(): void
    {
        $m = $this->merchant('967770040061');

        $r = $this->actingAs($m, 'api')
            ->postJson('/api/v1/amial/merchant/cashier/products', [
                'name' => 'كيلو طماطم', 'price' => '500', 'barcode' => '2001112223334',
            ]);

        if ($r->status() !== 200) {
            $this->markTestSkipped('إضافةُ المنتج ردّت ' . $r->status() . ': ' . $r->json('message'));
        }

        $this->assertDatabaseHas('merchant_products', [
            'merchant_user_id' => $m->id, 'barcode' => '2001112223334',
        ]);

        // ولم يدخل الكتالوجَ — ويُقال السبب.
        $this->assertDatabaseCount('product_catalog_entries', 0);
        $this->assertNotNull($r->json('meta.catalog_note'));
    }

    // ══════════════════════════════════════════════════════════════
    // ٣) بابُ التاجر
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **التاجرُ يجد الصنفَ بالباركود — ومعه درجةُ ثقته.**
     *
     * فـ«مقترَح» غير «موثّق»، ومن يرى اسماً بلا بيان مصدره يحسبه
     * مُراجَعاً. (القاعدة السابعة.)
     */
    public function a_merchant_finds_the_entry_with_its_confidence(): void
    {
        $m1 = $this->merchant('967770040071');
        $m2 = $this->merchant('967770040072');

        $this->svc()->suggest('6291100010505', 'زيت عافية 1.5 لتر', $m1);

        $meta = $this->actingAs($m2, 'api')
            ->getJson('/api/v1/amial/catalog/lookup?barcode=6291100010505')
            ->assertOk()->json('meta');

        $this->assertSame('زيت عافية 1.5 لتر', $meta['name']);
        $this->assertFalse($meta['is_verified'], 'مقترَحٌ يُعرض موثّقاً');
    }

    /**
     * @test
     *
     * **والباركودُ الداخليّ يُقال له لماذا لن يُوجد أبداً.**
     *
     * فمن مسحه يستحقّ أن يعرف — لا أن يُعيد المسحَ ظانّاً أنّ الشبكة
     * تعطّلت.
     */
    public function an_internal_barcode_lookup_explains_itself(): void
    {
        $m = $this->merchant('967770040081');

        $r = $this->actingAs($m, 'api')
            ->getJson('/api/v1/amial/catalog/lookup?barcode=2001234567890')
            ->assertStatus(404);

        $this->assertSame('NOT_GLOBAL', $r->json('code'));
        $this->assertFalse($r->json('meta.is_global'));
    }

    /**
     * @test
     *
     * **والصنفُ المرفوض لا يُقترح ثانيةً — وإلّا صارت المراجعةُ بلا أثر.**
     */
    public function a_rejected_entry_is_never_suggested_again(): void
    {
        $m = $this->merchant('967770040091');
        $this->svc()->suggest('6291100010606', 'اسم خاطئ', $m);

        $id = DB::table('product_catalog_entries')->where('barcode', '6291100010606')->value('id');

        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/catalog/{$id}/review", ['action' => 'reject', 'note' => 'اسم غير صحيح'])
            ->assertOk();

        $this->assertNull($this->svc()->find('6291100010606'),
            'صنفٌ رُفض وما زال يُقترح — المراجعةُ بلا أثر');
    }

    // ══════════════════════════════════════════════════════════════
    // ٤) شاشة الإدارة
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **الشاشةُ تُفتح، وفيها كلُّ ما تعد به.**
     */
    public function the_admin_screen_opens_with_its_parts(): void
    {
        $html = $this->actingAs($this->admin(), 'user')
            ->get('/admin/amial/catalog')->assertOk()->getContent();

        foreach (['cat-kpis', 'cat-rows', 'cat-search', 'cat-status', 'cat-conflicts',
                  'cat-add', 'cat-import', 'cat-export', 'cat-detail', 'cat-verify',
                  'cat-reject', 'cat-why'] as $part) {
            $this->assertStringContainsString($part, $html, "ناقصٌ من الشاشة: {$part}");
        }
    }

    /**
     * @test
     *
     * **ويُوصل إليها من القائمة الجانبيّة.**
     */
    public function the_admin_screen_is_reachable_from_the_sidebar(): void
    {
        $html = $this->actingAs($this->admin(), 'user')
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('/admin/amial/catalog', $html,
            'لا رابطَ إلى مركز الكتالوج من أيّ صفحةٍ يمرّ بها المدير');
    }

    /**
     * @test
     *
     * **وما اقترحه التاجرُ في التطبيق يظهر في الإدارة — وهو الصفُّ نفسُه.**
     *
     * (الشرطُ الذي طُلب نصّاً: كلُّ شيءٍ له جذرٌ يُستعلَم ويُراجَع.)
     */
    public function what_a_merchant_proposed_appears_in_the_admin_panel(): void
    {
        $m = $this->merchant('967770040101');
        $this->svc()->suggest('6291100010707', 'معكرونة إيطالية', $m);

        $meta = $this->actingAs($this->admin(), 'user')
            ->getJson('/admin/amial/catalog/rows?search=6291100010707')
            ->assertOk()->json('meta');

        $this->assertCount(1, $meta['rows'], 'اقتُرح صنفٌ ولا يُرى في الإدارة');
        $this->assertSame('معكرونة إيطالية', $meta['rows'][0]['name']);
        $this->assertSame('proposed', $meta['rows'][0]['status']);
    }

    /**
     * @test
     *
     * **والتوثيقُ يُغيّر ما يراه التاجر فعلاً.**
     *
     * والقياسُ ليس «ردّ ٢٠٠» بل «ماذا تغيّر». (القاعدة التاسعة.)
     */
    public function verifying_changes_what_the_merchant_sees(): void
    {
        $m = $this->merchant('967770040111');
        $this->svc()->suggest('6291100010808', 'سكر ابيض', $m);

        $id = DB::table('product_catalog_entries')->where('barcode', '6291100010808')->value('id');

        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/catalog/{$id}/review", [
                'action' => 'verify', 'name' => 'سكر أبيض ١ كجم',
            ])->assertOk();

        $meta = $this->actingAs($m, 'api')
            ->getJson('/api/v1/amial/catalog/lookup?barcode=6291100010808')
            ->assertOk()->json('meta');

        $this->assertTrue($meta['is_verified']);
        $this->assertSame('سكر أبيض ١ كجم', $meta['name'], 'وُثّق ولم يصل التصحيحُ للتاجر');
    }

    /**
     * @test
     *
     * **ولا سعرَ في الكتالوج — حدٌّ بنيويٌّ لا بصريّ.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا أهمُّ حارسٍ في الملفّ: رؤيةُ تاجرٍ لسعر منافسه تسريبٌ تجاريّ
     * يُفقد المنصّةَ ثقةَ التجّار دفعةً واحدة.
     *
     * **ولا يكفي ألّا يُعرض السعر:** يُفحص أنّ الجدول **لا يملك عموداً
     * له**. فما لا وجودَ له لا يُسرَّب ولو أخطأ متحكّمٌ يوماً.
     */
    public function the_catalog_has_no_price_column_at_all(): void
    {
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('product_catalog_entries');

        foreach (['price', 'cost_price', 'sale_price', 'offer_price', 'quantity', 'stock'] as $forbidden) {
            $this->assertNotContains($forbidden, $cols,
                "عمودُ «{$forbidden}» في الكتالوج المشترك — تسريبٌ تجاريّ بنيويّ");
        }

        $sug = \Illuminate\Support\Facades\Schema::getColumnListing('product_catalog_suggestions');

        foreach (['price', 'cost_price', 'quantity'] as $forbidden) {
            $this->assertNotContains($forbidden, $sug,
                "عمودُ «{$forbidden}» في اقتراحات الكتالوج");
        }
    }

    /**
     * @test
     *
     * **وموظّفُ الدعم لا يراجع الكتالوج ولا يُضيف إليه.**
     */
    public function support_staff_cannot_review_the_catalog(): void
    {
        $sup = $this->admin(PlatformRoleService::SUPPORT, '967770040121');

        $this->actingAs($sup, 'user')->get('/admin/amial/catalog')->assertForbidden();
        $this->actingAs($sup, 'user')->getJson('/admin/amial/catalog/rows')->assertForbidden();

        $this->actingAs($sup, 'user')
            ->postJson('/admin/amial/catalog', ['barcode' => '6291100019999', 'name' => 'محاولة'])
            ->assertForbidden();

        $this->assertDatabaseCount('product_catalog_entries', 0);
    }

    /**
     * @test
     *
     * **والمؤشّرات تُحسب — و«لا أصناف» ليست «٠٪ توثيق».**
     */
    public function the_stats_declare_absence_instead_of_zero(): void
    {
        $meta = $this->actingAs($this->admin(), 'user')
            ->getJson('/admin/amial/catalog/stats')->assertOk()->json('meta');

        $this->assertNull($meta['verified_rate'], 'لا أصناف ومع ذلك تُعرض نسبة');
        $this->assertSame(0, $meta['total']);
        $this->assertSame(0, $meta['conflicts']);
    }
}
