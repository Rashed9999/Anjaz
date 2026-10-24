<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CashierService;
use App\Services\Retail\ProductCatalogService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-VARIANT-PARENT-001 — **«أضفتُ متغيّراً فصار كلُّ شيءٍ نافداً».**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما وصل صاحبَ المشروع:** يضيف متغيّراتٍ لمنتج، ثمّ يفتح الكاشيرَ فإذا
 * **«نفذ المخزون»** — ولا يستطيع بيعَ شيء.
 *
 * وسببُه **ثلاثةُ أعطالٍ متراكبة**، وكلٌّ منها وحدَه يكفي:
 *
 * ① **الأبُ يبقى في قائمة البيع.** تعليقُ النموذج يقول بنصّه «الأبُ لا
 *    يُباع ولا يُخزَّن»، **و`listProducts` كانت تجلبه كسائر الأصناف**.
 *
 * ② **ومخزونُه يبقى عليه.** تاجرٌ عنده عشرةُ قمصانٍ يولّد المتغيّرات،
 *    فتصير العشرةُ على صفٍّ **لا يُباع**، والمتغيّراتُ صفراً. **فعشرُ
 *    قطعٍ حقيقيّةٍ تخرج من البيع بضغطةٍ واحدة** ولا خطأَ في أيّ سجلّ.
 *
 * ③ **والمتغيّراتُ تُعرَض باسم الأب.** تسعةُ صفوفٍ اسمُها «قميص»، ولا
 *    يُعرف أيُّها الأحمرُ مقاسُ L.
 *
 * **ولا يمسك شيءٌ منها مُصرِّفٌ ولا مُحلِّل**: الصفوفُ سليمة، والاستعلامُ
 * يعمل، والأرقامُ تُقرأ. لا يظهر إلّا لمن يبيع فعلاً.
 */
class VariantsReachTheCashierGuardTest extends TestCase
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

    private function product(string $name, string $qty = '10'): MerchantProduct
    {
        return MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => $name, 'price' => '2000', 'cost_price' => '1200',
            'quantity' => $qty, 'is_active' => true,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① الأبُ لا يُعرَض في الكاشير، والمتغيّراتُ تُعرَض.**
     */
    /** @test */
    public function the_variant_parent_disappears_from_the_cashier_and_variants_appear(): void
    {
        $shirt = $this->product('قميص');
        app(ProductCatalogService::class)->generateVariants(
            $this->merchant, $shirt->id, ['اللون' => ['أحمر', 'أزرق']]);

        $list = app(CashierService::class)->listProducts($this->merchant);
        $ids = $list->pluck('id')->all();

        $this->assertNotContains($shirt->id, $ids,
            '**الصنفُ الأبُ ما زال معروضاً في الكاشير** — وهو مِظلّةٌ لا '
            .'تُباع («الأبُ لا يُباع ولا يُخزَّن»). ويُقرأ «نفذ» لأنّ '
            .'مخزونَه انتقل، فيظنّ البائعُ أنّ البضاعةَ نفدت.');

        $this->assertCount(2, $list, 'المتغيّرانِ لم يظهرا في قائمة البيع');
    }

    /**
     * **② ومخزونُ الأب لا يضيع صامتاً — يُصفَّر ويُقال.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا أخطرُ الثلاثة: **بضاعةٌ حقيقيّةٌ تخرج من البيع** بلا أثر. ولو
     * تُرك الرقمُ على الأب لظنّ التاجرُ أنّه بخير حتّى يأتيَ الجرد.
     *
     * **ولا تُوزَّع تلقائيّاً**: قسمةُ عشرةٍ على تسعةِ متغيّراتٍ اختراعٌ
     * لا يعرفه إلّا التاجر (كم أحمرَ وكم أزرق؟). فيُقال العددُ صراحةً.
     * (القاعدة السابعة: الغيابُ يُقال ولا يُبتلع.)
     */
    /** @test */
    public function the_parents_stock_is_zeroed_and_reported_not_stranded(): void
    {
        $shirt = $this->product('قميص', '10');

        $unallocated = null;
        app(ProductCatalogService::class)->generateVariants(
            $this->merchant, $shirt->id, ['اللون' => ['أحمر', 'أزرق']], $unallocated);

        $this->assertSame('0', (string) (float) $shirt->fresh()->quantity,
            '**مخزونُ الأب باقٍ على صفٍّ لا يُباع** — عشرُ قطعٍ حقيقيّةٍ '
            .'خرجت من البيع بلا أثر، ولا تُكتشَف إلّا في الجرد.');

        $this->assertNotNull($unallocated, 'لم يُبلَّغ عن المخزون غير الموزَّع');
        $this->assertSame(0, bccomp((string) $unallocated, '10', 3),
            "**العددُ المُبلَّغُ خاطئ**: {$unallocated} بدل ١٠.");
    }

    /**
     * **③ ويُقال في ردّ نقطة النهاية نفسِها.**
     *
     * فرسالةٌ لا تصل الشاشةَ ليست رسالة. (القاعدة الثانية عشرة.)
     */
    /** @test */
    public function the_endpoint_tells_the_merchant_about_the_pending_stock(): void
    {
        $shirt = $this->product('قميص', '10');

        $r = $this->actingAs($this->merchant, 'api')->postJson(
            "/api/v1/amial/merchant/retail/products/{$shirt->id}/variants",
            ['axes' => ['اللون' => ['أحمر', 'أزرق']]]
        );

        // **ومفتاحُ الردّ في هذا المتحكّم `data` لا `meta`** — وقد قرأتُه
        // `meta` أوّلَ صياغةٍ فسقط الحارسُ على شكلِ الردّ لا على العطل.
        // (والعقدُ لا يُغيَّر لأجل حارس: المتحكّمُ كلُّه على `data`.)
        $this->assertSame(200, $r->status(), 'رُفض التوليد: '.$r->json('message'));
        $this->assertSame(2, $r->json('data.created'));

        $this->assertNotNull($r->json('data.unallocated_stock'),
            '**الردُّ لا يذكر المخزونَ المعلَّق** — فيصمت عن عشر قطع.');
        $this->assertStringContainsString('تنتظر', (string) $r->json('message'),
            'الرسالةُ لا تخبر التاجرَ أنّ عليه توزيعَ المخزون');
    }

    /**
     * **④ والمتغيّرُ يُعرَض باسمٍ يميّزه.**
     */
    /** @test */
    public function each_variant_carries_a_distinguishing_display_name(): void
    {
        $shirt = $this->product('قميص');
        app(ProductCatalogService::class)->generateVariants(
            $this->merchant, $shirt->id, ['اللون' => ['أحمر', 'أزرق']]);

        $names = app(CashierService::class)->listProducts($this->merchant)
            ->pluck('display_name')->all();

        $this->assertContains('قميص · أحمر', $names,
            '**المتغيّراتُ تُعرَض باسم الأب** — صفّان اسمُهما «قميص» ولا '
            .'يُعرف أيُّهما الأحمر. و`display_name` غائبٌ عن الردّ.');
        $this->assertContains('قميص · أزرق', $names);
    }

    /**
     * **⑤ وصنفٌ عاديٌّ بلا متغيّراتٍ لم يتغيّر بحرف.**
     *
     * فأخطرُ ما في إصلاحِ استعلامٍ يقرؤه كلُّ كاشيرٍ في المنتج أن يحذف
     * أصنافاً سليمة.
     */
    /** @test */
    public function a_plain_product_is_untouched(): void
    {
        $water = $this->product('ماء', '25');

        $list = app(CashierService::class)->listProducts($this->merchant);

        $this->assertCount(1, $list, '**اختفى صنفٌ عاديّ** — المرشِّحُ أوسعُ من المطلوب');
        $this->assertSame($water->id, $list->first()->id);
        $this->assertSame('ماء', $list->first()->display_name,
            'الاسمُ المعروضُ لصنفٍ بلا متغيّراتٍ يجب أن يكون اسمَه');
        $this->assertSame('25', (string) (float) $list->first()->quantity,
            'تغيّر مخزونُ صنفٍ لا علاقةَ له بالمتغيّرات');
    }
}
