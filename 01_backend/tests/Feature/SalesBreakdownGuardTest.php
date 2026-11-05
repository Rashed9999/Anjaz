<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\Retail\SaleLine;
use App\Models\User;
use App\Services\SalesBreakdownService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-SALES-BREAKDOWN-001 — **ماذا بِعتُ، وبكم، وبأيّ ربح.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان في المنتج `top_products`: **خمسةُ أسماءٍ بكمّيّاتها ليومٍ واحد**،
 * بلا إيرادٍ ولا تكلفةٍ ولا تصنيف.
 *
 * وستُّ حالاتٍ، **وأخطرُها ① و②** — وكلتاهما تُخرج رقماً يُقرأ حقيقةً
 * وهو خطأ، ولا خطأَ في أيّ سجلّ:
 *
 *   ① **المرتجعُ يُطرَح.** صنفٌ بيع ٢٠ ورُدّ ١٨ يتصدّر القائمةَ إن لم
 *      يُطرَح — **فيُعاد طلبُه**، وهو بالضبط ما يجب أن يقلّ.
 *   ② **والتكلفةُ المجهولةُ لا تُقرأ صفراً** — وإلّا صار الهامشُ ١٠٠٪
 *      على بضاعةٍ اشتُريت بمال.
 */
class SalesBreakdownGuardTest extends TestCase
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
            'verification_status' => 'verified', 'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
            'daily_receive_limit' => '5000000', 'single_receive_limit' => '1000000',
        ]);
    }

    private function product(string $name, ?string $category, string $cost = '100'): MerchantProduct
    {
        return MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id, 'name' => $name,
            'category' => $category, 'price' => '200', 'cost_price' => $cost,
            'quantity' => 1000, 'is_active' => true,
            'barcode' => 'B'.Str::random(8),
        ]);
    }

    /**
     * @param  array<int,array{0:?MerchantProduct,1:string,2:string,3:string,4:?string,5?:string}>  $lines
     *        [منتج|null, اسم, كمّيّة, مجموعُ السطر, تكلفةُ السطر|null, مرتجع]
     */
    private function sale(array $lines, string $total = '0'): MerchantSale
    {
        $sale = MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $this->merchant->id,
            'total_amount' => $total, 'payment_method' => 'cash',
            'status' => 'completed', 'items' => [],
        ]);

        foreach ($lines as $l) {
            SaleLine::create([
                'uuid' => (string) Str::uuid(),
                'merchant_user_id' => $this->merchant->id,
                'sale_id' => $sale->id, 'sale_ulid' => $sale->sale_ulid,
                'product_id' => $l[0]?->id, 'name' => $l[1],
                'quantity' => $l[2], 'unit_price' => '0', 'line_discount' => '0',
                'line_total' => $l[3], 'line_cost' => $l[4],
                'returned_quantity' => $l[5] ?? '0',
                'zone_code' => 'SOUTH',
            ]);
        }

        return $sale;
    }

    private function report(): array
    {
        return app(SalesBreakdownService::class)->report($this->merchant);
    }

    /** @test */
    public function it_reports_revenue_cost_and_margin_per_item(): void
    {
        $milk = $this->product('حليب', 'ألبان');
        $this->sale([[$milk, 'حليب', '5', '1000', '500']], '1000');

        $row = collect($this->report()['items'])->firstWhere('name', 'حليب');

        $this->assertNotNull($row, 'الصنفُ لا يظهر في التقرير إطلاقاً');
        $this->assertSame(0, bccomp($row['revenue'], '1000', 4), 'الإيراد '.$row['revenue']);
        $this->assertSame(0, bccomp($row['cost'], '500', 4), 'التكلفة '.$row['cost']);
        $this->assertSame(0, bccomp($row['profit'], '500', 4), 'الربح '.$row['profit']);
        $this->assertSame(0, bccomp($row['margin_percent'], '50', 2), 'الهامش '.$row['margin_percent']);
        $this->assertSame('5', $row['qty'], 'الكمّيّةُ تُعرض «5.000» على شاشةٍ صغيرة');
    }

    /** @test */
    public function the_returned_quantity_is_subtracted_or_the_worst_item_leads_the_list(): void
    {
        $bad = $this->product('لبن فاسد', 'ألبان');
        $good = $this->product('خبز', 'مخبوزات');

        // بيع ٢٠ ورُدّ ١٨ — صافي ٢. والإيرادُ يُطرح تناسبيّاً.
        $this->sale([[$bad, 'لبن فاسد', '20', '4000', '2000', '18']], '4000');
        $this->sale([[$good, 'خبز', '10', '1000', '400']], '1000');

        $items = $this->report()['items'];

        $this->assertSame('خبز', $items[0]['name'],
            'تصدّر القائمةَ صنفٌ رُدّ أكثرُه — فيُعاد طلبُه، وهو الذي يجب أن يقلّ');

        $badRow = collect($items)->firstWhere('name', 'لبن فاسد');
        $this->assertSame('2', $badRow['qty'], 'الكمّيّةُ لم تُطرَح: '.$badRow['qty']);
        $this->assertSame(0, bccomp($badRow['revenue'], '400', 4),
            'الإيرادُ لم يُطرَح تناسبيّاً: '.$badRow['revenue'].' — والمنتظَر ٤٠٠ (٤٠٠٠ × ٢/٢٠)');
        $this->assertSame('18', $badRow['returned_qty'],
            'المرتجعُ لا يُقال — فالتاجرُ يرى رقماً صغيراً ولا يعرف لماذا صغُر');
    }

    /** @test */
    public function an_unknown_cost_never_reads_as_a_hundred_percent_margin(): void
    {
        $p = $this->product('صنف بلا تكلفة', 'متنوّع');
        $this->sale([[$p, 'صنف بلا تكلفة', '3', '900', null]], '900');

        $r = $this->report();

        $this->assertSame(1, $r['cost_coverage']['unknown_cost_lines'],
            'السطرُ المجهولُ تكلفتُه لا يُعدّ');
        $this->assertSame(0, bccomp($r['cost_coverage']['unknown_cost_revenue'], '900', 4));
        $this->assertNotNull($r['cost_coverage']['note'],
            'لا يُقال للتاجر لماذا هامشُه ناقص');

        // **والإجماليُّ لا يزعم ربحاً على ما لا تُعرف تكلفتُه.**
        $this->assertSame(0, bccomp($r['totals']['profit'], '0', 4),
            'الربحُ '.$r['totals']['profit'].' — والتكلفةُ مجهولةٌ كلُّها، '
            .'فهامشُ ١٠٠٪ على بضاعةٍ اشتُريت بمال');
        $this->assertNull($r['totals']['margin_percent'],
            'هامشٌ على إيرادٍ لا تُعرف تكلفتُه — و«لا ينطبق» ليست صفراً');
    }

    /** @test */
    public function it_groups_by_category_and_says_when_there_is_none(): void
    {
        $a = $this->product('حليب', 'ألبان');
        $b = $this->product('جبن', 'ألبان');
        $c = $this->product('صنف غير مصنَّف', null);

        $this->sale([
            [$a, 'حليب', '2', '400', '200'],
            [$b, 'جبن', '1', '300', '150'],
            [$c, 'صنف غير مصنَّف', '1', '100', '50'],
        ], '800');

        $cats = collect($this->report()['categories']);

        $dairy = $cats->firstWhere('category', 'ألبان');
        $this->assertNotNull($dairy, 'لا تجميعَ بالتصنيف إطلاقاً');
        $this->assertSame(0, bccomp($dairy['revenue'], '700', 4), 'إيرادُ الألبان '.$dairy['revenue']);
        $this->assertSame(2, $dairy['items'], 'عددُ أصناف التصنيف خطأ');

        // ③ «بلا تصنيف» تصنيفٌ يُقال — ومن رأى نصفَ مبيعاته فيه صنّف.
        $this->assertNotNull($cats->firstWhere('category', SalesBreakdownService::NO_CATEGORY),
            'الأصنافُ غيرُ المصنَّفة طُويت — فيُقرأ التقريرُ كاملاً وهو ناقص');
    }

    /** @test */
    public function a_deleted_product_does_not_erase_yesterdays_sale(): void
    {
        // سطرٌ بلا `product_id` (منتجٌ حُذف، أو بيعٌ حرّ) — الاسمُ لقطةٌ في السطر.
        $this->sale([[null, 'صنف محذوف', '4', '800', '400']], '800');

        $row = collect($this->report()['items'])->firstWhere('name', 'صنف محذوف');

        $this->assertNotNull($row, 'حذفُ منتجٍ اليومَ محا ما بيع منه أمس');
        $this->assertSame(SalesBreakdownService::NO_CATEGORY, $row['category']);
        $this->assertSame(0, bccomp($row['revenue'], '800', 4));
    }

    /** @test */
    public function the_endpoint_answers_and_states_the_range_it_understood(): void
    {
        $p = $this->product('حليب', 'ألبان');
        $this->sale([[$p, 'حليب', '2', '400', '200']], '400');

        $r = $this->actingAs($this->merchant, 'api')
            ->getJson('/api/v1/amial/merchant/cashier/sales-breakdown?from='.now()->subDays(6)->toDateString())
            ->assertOk()->json('meta');

        // ⑤ المدى يُقال — «١٢٠٬٠٠٠ ريالاً» بلا مدىً ليست رقماً.
        $this->assertSame(now()->subDays(6)->toDateString(), $r['range']['from']);
        $this->assertSame(now()->toDateString(), $r['range']['to']);
        $this->assertSame(7, $r['range']['days']);
        $this->assertSame(1, $r['totals']['items_count']);
    }
}
