<?php

namespace Tests\Feature;

use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-STOCK-DRIFT-001 — **عمودٌ يُقرأ ولا يُقارَن بمصدره.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `product_stocks.on_hand` رقمٌ **مخزَّن**، ومصدرُه مجموعُ
 * `stock_movements.quantity_delta`. و`StockService::computedFromMovements`
 * تحسبه من مصدره — **وقِيس أنّ لا مُنادِيَ لها في المشروع كلِّه**.
 *
 * فلا شيءَ يقارن العمودَ بحركاته. وأيُّ مسارٍ يكتب أحدَهما دون الآخر
 * **يُحدث فرقاً يكبر بصمتٍ إلى الأبد**: يرى التاجرُ رقماً لا يطابق رفَّه،
 * ولا سطرَ في أيّ سجلٍّ يقول متى بدأ الفرق.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهي القاعدةُ السادسة بنصّها** — «الرقم يُحسب من مصدره لا من عمودٍ
 * مخزَّن». وكانت مطبَّقةً على المحافظ والدفتر وخزائن النقد، **وغائبةً
 * عن المخزون وحدَه**.
 *
 * **ولا أمرٌ ثانٍ**: البنيةُ قائمةٌ وناضجة (`amial:reconcile-nightly`
 * و`ReconciliationCaseService`)، فوُصل المخزونُ بها. وأمرٌ ثانٍ يعني
 * جدولين وتقريرين وشاشتين لحقيقةٍ واحدة.
 */
class StockDriftReconciliationGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:int,1:int} */
    private function shelf(string $stored, string $moved): array
    {
        $merchant = \App\Models\User::factory()->create(['type' => 3]);

        $product = DB::table('merchant_products')->insertGetId([
            'merchant_user_id' => $merchant->id,
            'name' => 'صنفٌ يُتتبَّع',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $location = DB::table('merchant_locations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'kind' => 'store',
            'name' => 'الفرعُ الرئيسيّ',
            'code' => 'MAIN-' . $merchant->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('product_stocks')->insert([
            'product_id' => $product,
            'location_id' => $location,
            'on_hand' => $stored,
            'reserved' => '0',
            'reorder_level' => '0',
            'max_level' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($moved !== '') {
            DB::table('stock_movements')->insert([
                'uuid' => (string) Str::uuid(),
                'merchant_user_id' => $merchant->id,
                'product_id' => $product,
                'location_id' => $location,
                'quantity_delta' => $moved,
                'reason' => 'purchase_receive',
                // **و`balance_after` مطلوبٌ بلا افتراضيّ** — قِيس من
                // `SHOW COLUMNS` بعد أن سقط الإدراجُ بـ1364.
                'balance_after' => $moved,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$product, $location];
    }

    /**
     * **ولا تُسمّى `run`** — نهائيّةٌ في `TestCase`، وتجاوزُها خطأٌ قاتلٌ
     * يُسقط الملفَّ كلَّه. وهذه ثالثةُ مرّةٍ في هذه الجلسة بعد `call()`
     * و`Command::configure()` — فالأسماءُ المحجوزةُ تُتفقَّد لا تُفترَض.
     */
    private function sweep(): array
    {
        return app(ReconciliationService::class)->stock();
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_column_that_disagrees_with_its_movements_is_caught(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه.** عشرةٌ في العمود وسبعةٌ في الحركات:
        // فرقٌ قدرُه ثلاثة، وكان يكبر بصمتٍ ولا يقارنه شيء.
        // ══════════════════════════════════════════════════════════════
        $this->shelf(stored: '10', moved: '7');

        $r = $this->sweep();

        $this->assertSame(1, $r['diverged'],
            'عمودٌ يخالف حركاتِه ولم يُكتشَف — فالفرقُ يكبر إلى الأبد');

        $this->assertSame('3.000', $r['rows'][0]['drift'],
            'الفرقُ محسوبٌ خطأً — ورقمٌ خاطئٌ في تقرير مصالحةٍ أسوأ من غيابه');
    }

    /** @test */
    public function a_shelf_that_agrees_raises_nothing(): void
    {
        // **وإنذارٌ كاذبٌ يُعوّد القارئَ على التجاهل يومَ يصدق** — وهو
        // ما دفع المشروعُ ثمنَه في سلسلة التدقيق.
        $this->shelf(stored: '7', moved: '7');

        $this->assertSame(0, $this->sweep()['diverged'],
            'رُفع فرقٌ على رفٍّ متطابق');
    }

    /** @test */
    public function every_row_is_checked_not_a_page_of_them(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ومصالحةٌ تفحص مئةً وتسكت عن الباقي تكذب**: تقول «لا فرق»
        // وهي لم تنظر. (وهو التعليقُ المكتوبُ فوق `wallets()` نفسِها.)
        // ══════════════════════════════════════════════════════════════
        for ($i = 0; $i < 3; $i++) {
            $this->shelf(stored: '5', moved: '5');
        }

        $this->shelf(stored: '9', moved: '1');

        $r = $this->sweep();

        $this->assertSame(4, $r['checked'], 'لم تُفحَص كلُّ الصفوف');
        $this->assertSame(1, $r['diverged']);

        $src = (string) file_get_contents(
            app_path('Services/Reconciliation/ReconciliationService.php'));

        $fn = substr($src, (int) strpos($src, 'function stock('), 1800);

        $this->assertStringNotContainsString('->limit(', $fn,
            'حدٌّ للعدد في المصالحة — فتقول «لا فرق» وهي لم تنظر إلى الباقي');
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_nightly_run_actually_includes_it(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ودالّةٌ صحيحةٌ لا يناديها المجدوِلُ ليست موصولة** — وهو
        // العطلُ الذي جاءت لتُصلحه، فلا يُعاد بثوبٍ أطول.
        // ══════════════════════════════════════════════════════════════
        $this->shelf(stored: '10', moved: '7');

        $r = app(ReconciliationService::class)->run();

        $this->assertArrayHasKey('stock', $r,
            'الجولةُ الليليّةُ لا تفحص المخزون — فالدالّةُ مبنيّةٌ ولا تُنادى');

        $this->assertSame(1, $r['stock']['diverged']);

        $this->assertSame('diverged', $r['status'],
            'فرقُ مخزونٍ لا يجعل الليلةَ منحرفة — فلا يُرفَع إنذارٌ ولا تُفتَح قضيّة');
    }

    /** @test */
    public function a_missing_table_is_skipped_not_fatal(): void
    {
        // **والمصالحةُ تمسّ المنصّةَ كلَّها**؛ فرميٌ هنا على تاجرٍ بلا
        // مخزونٍ يُسقط فحصَ المحافظ والدفتر معه — أي عقوبةٌ على غيابِ
        // ميزةٍ بفقدِ المصالحة كلِّها.
        $src = (string) file_get_contents(
            app_path('Services/Reconciliation/ReconciliationService.php'));

        $fn = substr($src, (int) strpos($src, 'function stock('), 900);

        $this->assertStringContainsString("Schema::hasTable('product_stocks')", $fn,
            'لا حارسَ لغياب الجدول — فمصالحةُ الليلة كلُّها تسقط');
    }
}
