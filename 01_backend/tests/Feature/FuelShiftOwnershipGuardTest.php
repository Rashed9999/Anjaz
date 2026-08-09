<?php

namespace Tests\Feature;

use App\Models\FuelSale;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\FuelShiftService;
use App\Services\FuelStationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلة ٠ — **المبيعةُ تعرف ورديّتَها**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما كان:** أرقامُ الوردية تُجمَع بنافذةٍ زمنيّة —
 * `created_at >= opened_at` بلا حدٍّ أعلى — والبيعُ لا يشترط ورديّةً أصلاً.
 *
 * **فبيعةٌ تقع بين ورديّتين تسقط من الاثنتين:** بعد إغلاق الأولى وقبل
 * `opened_at` الثانية. **ونقدُها في الدرج بلا صاحب** — لا يُعدّ في إغلاق،
 * ولا يُنسب إلى صرّاف، ويظهر عجزاً في أوّل جردٍ بلا تفسير.
 *
 * ولا خطأ في أيّ سجلّ: البيعُ نجح، والإيصالُ طُبع، والرقمُ وحدَه ضاع.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذه الحرّاسُ تقيس السلوك لا نصَّ الملفّ**: تبيع فعلاً، وتُغلق فعلاً،
 * وتقرأ الرقم الذي يراه الصرّاف.
 */
class FuelShiftOwnershipGuardTest extends TestCase
{
    use RefreshDatabase;

    private FuelStationService $fuel;
    private FuelShiftService $shifts;
    private User $merchant;
    private $station;
    private $pump;
    private $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fuel = app(FuelStationService::class);
        $this->shifts = app(FuelShiftService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'verification_status' => 'verified',
        ]);

        $this->station = $this->fuel->getOrCreateStation($this->merchant, [
            'station_name' => 'محطة القياس',
        ]);
        $this->pump = $this->fuel->addPump($this->station, ['pump_number' => 1]);
        $this->product = $this->fuel->addProduct($this->station, [
            'name' => 'بنزين', 'price_per_liter' => '1000',
        ]);
    }

    private function sell(string $liters = '10'): FuelSale
    {
        return $this->fuel->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id,
            'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_liters',
            'liters' => $liters,
            'payment_method' => 'cash',
        ]);
    }

    /**
     * @test
     *
     * **لا بيعَ خارج وردية.**
     *
     * ولا نقطةَ بيعٍ في العالم تسمح به: النقدُ الذي يدخل الدرج بلا ورديّةٍ
     * لا صاحبَ له.
     */
    public function selling_without_an_open_shift_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/وردية مفتوحة/');

        $this->sell();
    }

    /**
     * @test
     *
     * **والرسالةُ تدلّ على الزرّ.**
     *
     * فرفضٌ صحيحٌ بلا طريقِ خروجٍ يوقف الصرّاف أمام العميل. (والزرُّ موجود:
     * `POST /fuel/shifts/open` · شاشة «الورديات».)
     */
    public function the_refusal_names_the_way_out(): void
    {
        try {
            $this->sell();
            $this->fail('البيعُ مرّ بلا وردية — الشرطُ غيرُ مفروض');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('الورديات', $e->getMessage(),
                "الرفضُ لا يقول ماذا يفعل الصرّاف: «{$e->getMessage()}»");
        }
    }

    /**
     * @test
     *
     * **كلُّ مبيعةٍ تحمل ورديّتَها — لا تُستنتج بعد حين.**
     */
    public function every_sale_carries_its_shift(): void
    {
        $shift = $this->shifts->openShift($this->station, $this->merchant, '0');

        $sale = $this->sell();

        $this->assertSame((int) $shift->id, (int) $sale->shift_id,
            'المبيعةُ لا تحمل ورديّتَها — والنسبةُ ستُستنتج بنافذةٍ زمنيّة');
    }

    /**
     * @test
     *
     * **الحارسُ الحقيقيّ: بيعةُ ما بين ورديّتين لا تُحسب على الثانية.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا هو العطلُ بعينه. تُباع ١٠ لترات في الوردية الأولى، ثمّ تُغلق،
     * ثمّ تُفتح ثانيةٌ ويُباع فيها ٥.
     *
     * **بالنافذة الزمنيّة** كانت الثانيةُ تجمع `>= opened_at` بلا حدٍّ
     * أعلى — فتحسب ما لها وحدَه صدفةً، **لكنّ الأولى كانت تحسب كلَّ ما بعد
     * فتحها بما فيه بيعُ الثانية لو أُغلقت متأخّرة**.
     *
     * فيُقاس الرقمُ الذي يراه الصرّاف: نقدُ كلِّ ورديّةٍ نقدُها هي.
     */
    public function each_shift_counts_only_its_own_cash(): void
    {
        // ── الوردية الأولى: ١٠ لترات × ١٠٠٠ = ١٠٬٠٠٠
        $first = $this->shifts->openShift($this->station, $this->merchant, '0');
        $this->sell('10');

        $first = $this->shifts->closeShift(
            $first->fresh(), $this->merchant, '10000',
        );

        // ── الوردية الثانية: ٥ لترات × ١٠٠٠ = ٥٬٠٠٠
        $second = $this->shifts->openShift($this->station, $this->merchant, '0');
        $this->sell('5');

        $second = $this->shifts->closeShift(
            $second->fresh(), $this->merchant, '5000',
        );

        $this->assertSame(0, bccomp((string) $first->total_cash_sales, '10000', 4),
            sprintf('نقدُ الوردية الأولى %s والصواب ١٠٬٠٠٠', $first->total_cash_sales));

        $this->assertSame(0, bccomp((string) $second->total_cash_sales, '5000', 4),
            sprintf(
                "نقدُ الوردية الثانية %s والصواب ٥٬٠٠٠ —\n"
                . 'المبيعاتُ تُنسب بنافذةٍ زمنيّة لا بانتماءٍ مكتوب.',
                $second->total_cash_sales,
            ));

        // **ولا عجزَ وهميّ**: الفرقُ صفرٌ في الاثنتين.
        foreach (['الأولى' => $first, 'الثانية' => $second] as $name => $s) {
            $this->assertSame(0, bccomp((string) $s->variance, '0', 4),
                "الوردية {$name} تُظهر فرقاً {$s->variance} والنقدُ مطابق — "
                . 'وهذا عجزٌ وهميٌّ من خطأ نسبة.');
        }
    }

    /**
     * @test
     *
     * **ولا مبيعةَ يتيمة في قاعدة البيانات.**
     *
     * حارسٌ على البنية: `shift_id` فارغٌ يعني مالاً بلا صاحب. ويُسمح به
     * للصفوف التاريخيّة وحدَها — ولا تُنشأ صفوفٌ جديدةٌ بلا انتماء.
     */
    public function no_new_sale_is_orphaned(): void
    {
        $this->shifts->openShift($this->station, $this->merchant, '0');

        $this->sell();
        $this->sell('3');

        $orphans = FuelSale::whereNull('shift_id')->count();

        $this->assertSame(0, $orphans,
            "{$orphans} مبيعةً بلا وردية — نقدٌ في الدرج بلا صاحب");
    }
}
