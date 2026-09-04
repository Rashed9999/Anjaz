<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\Retail\MerchantAttributeTerm;
use App\Models\User;
use App\Services\Retail\AttributeLibraryService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-PRODUCT-ATTRIBUTES-001 — **السماتُ تُعرَّف مرّةً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * طلب صاحبُ المشروع تدفّقَ WooCommerce: سماتٌ عامّةٌ («اللون» · «المقاس»)
 * تُعرَّف في مكانٍ واحدٍ بقيمها، ثمّ يُختار منها لكلّ منتج.
 *
 * **والقائمُ كان يقبل المحاورَ نصّاً حرّاً في كلّ توليد.** وهو يعمل،
 * وثمنُه يظهر بعد المنتج العاشر: **الإملاءُ يفترق**. و«أحمر» و«احمر»
 * (بلا همزة) قيمتان مختلفتان تماماً — فمتغيّران للون واحدٍ ينقسم
 * مخزونُهما بينهما، **ولا يمسكه شيء**: صفّان سليمان في جدولٍ سليم، ولا
 * يُكتشَف إلّا في جردٍ لا يوازن.
 */
class AttributeLibraryGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => 'retail',
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'single_receive_limit' => '5000000', 'daily_receive_limit' => '50000000',
        ]);
    }

    private function svc(): AttributeLibraryService
    {
        return app(AttributeLibraryService::class);
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① القيمةُ لا تتكرّر بإملاءٍ مختلف — وهذا كلُّ المقصد.**
     *
     * ثلاثُ كتاباتٍ للون الأحمر تُنتج **قيمةً واحدة**. ولولاه انقسم
     * المخزونُ على ثلاثة متغيّراتٍ للون واحد.
     */
    /** @test */
    public function spelling_variants_of_the_same_value_collapse_into_one(): void
    {
        $attr = $this->svc()->addAttribute($this->merchant, 'اللون');

        $this->svc()->addTerms($this->merchant, $attr->id, [
            'أحمر',      // بالهمزة
            'احمر',      // بلا همزة
            '  أحمر  ',  // بمسافاتٍ حولها
            'أزرق',
        ]);

        $count = MerchantAttributeTerm::where('attribute_id', $attr->id)->count();

        $this->assertSame(2, $count, sprintf(
            '**«أحمر» تكرّرت %d مرّة** — فمتغيّراتٌ للون واحدٍ ينقسم '
            .'مخزونُها بينها، ولا يُكتشَف إلّا في جردٍ لا يوازن.', $count));

        // **والمعروضُ يبقى كما كتبه التاجر** — لا الشكلَ المطبَّع.
        // فـ«احمر» بلا همزةٍ نصٌّ ركيكٌ على فاتورةِ زبون.
        $values = MerchantAttributeTerm::where('attribute_id', $attr->id)
            ->pluck('value')->all();
        $this->assertContains('أحمر', $values,
            '**عُرضت الصيغةُ المطبَّعة** — والتطبيعُ للمطابقة لا للعرض.');
    }

    /**
     * **② والسمةُ لا تتكرّر كذلك.**
     */
    /** @test */
    public function an_attribute_is_not_duplicated_by_re_adding_it(): void
    {
        $a = $this->svc()->addAttribute($this->merchant, 'المقاس');
        $b = $this->svc()->addAttribute($this->merchant, ' المقاس ');

        $this->assertSame($a->id, $b->id,
            '**«المقاس» صارت سمتين** — فتنقسم قيمُها بينهما ولا تُولّد '
            .'شاشةٌ واحدةٌ كلَّ المتغيّرات.');
    }

    /**
     * **③ والاختيارُ من المكتبة يولّد المتغيّراتِ نفسَها.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **والمولِّدُ واحدٌ للمدخلين**: النصُّ الحرُّ يبقى للتوافق، والمكتبةُ
     * تُترجَم إلى محاور. ولو بُني مولّدٌ ثانٍ للمكتبة لافترق سلوكُ
     * المسارين بعد أوّل تعديل.
     */
    /** @test */
    public function selecting_from_the_library_generates_the_same_variants(): void
    {
        $color = $this->svc()->addAttribute($this->merchant, 'اللون');
        $this->svc()->addTerms($this->merchant, $color->id, ['أحمر', 'أزرق']);

        $size = $this->svc()->addAttribute($this->merchant, 'المقاس');
        $this->svc()->addTerms($this->merchant, $size->id, ['S', 'L']);

        $shirt = MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'قميص', 'price' => '2000', 'quantity' => '0', 'is_active' => true,
        ]);

        $r = $this->actingAs($this->merchant, 'api')->postJson(
            "/api/v1/amial/merchant/retail/products/{$shirt->id}/variants",
            ['attributes' => [
                ['attribute_id' => $color->id,
                 'term_ids' => MerchantAttributeTerm::where('attribute_id', $color->id)->pluck('id')->all()],
                ['attribute_id' => $size->id,
                 'term_ids' => MerchantAttributeTerm::where('attribute_id', $size->id)->pluck('id')->all()],
            ]]
        );

        $this->assertSame(200, $r->status(), 'رُفض التوليد من المكتبة: '.$r->json('message'));
        $this->assertSame(4, $r->json('data.created'),
            '**الاختيارُ من المكتبة لم يُنتج الضربَ الديكارتيّ** — لونان × مقاسان = ٤.');

        $names = MerchantProduct::where('parent_product_id', $shirt->id)->get()
            ->map(fn ($v) => $v->displayName())->all();
        $this->assertContains('قميص · أحمر · S', $names);
    }

    /**
     * **④ والنصُّ الحرُّ ما زال يعمل — فلا ينكسر ما هو قائم.**
     */
    /** @test */
    public function the_free_text_axes_path_still_works(): void
    {
        $shirt = MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'قميص', 'price' => '2000', 'quantity' => '0', 'is_active' => true,
        ]);

        $r = $this->actingAs($this->merchant, 'api')->postJson(
            "/api/v1/amial/merchant/retail/products/{$shirt->id}/variants",
            ['axes' => ['اللون' => ['أخضر']]]
        );

        $this->assertSame(200, $r->status(), 'انكسر المسارُ القديم: '.$r->json('message'));
        $this->assertSame(1, $r->json('data.created'));
    }

    /**
     * **⑤ ولا تُقرأ مكتبةُ تاجرٍ آخر.**
     *
     * (`amial-rbac`: الهويّةُ تحدّد النطاق لا القائمةُ المنسدلة —
     * والقاعدةُ الثامنة.)
     */
    /** @test */
    public function one_merchant_cannot_use_another_merchants_attributes(): void
    {
        $other = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT, 'is_active' => 1,
        ]);
        MerchantProfile::create([
            'user_id' => $other->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => 'retail',
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'single_receive_limit' => '1', 'daily_receive_limit' => '1',
        ]);

        $theirs = $this->svc()->addAttribute($other, 'اللون');
        $this->svc()->addTerms($other, $theirs->id, ['أحمر']);

        // مكتبتي فارغةٌ — ولا تظهر فيها سمتُه.
        $this->assertSame([], $this->svc()->library($this->merchant),
            '**سماتُ تاجرٍ آخر ظهرت في مكتبتي**');

        $this->expectException(\DomainException::class);
        $this->svc()->axesFromSelection($this->merchant, [
            ['attribute_id' => $theirs->id, 'term_ids' => [1]],
        ]);
    }
}
