<?php

namespace Tests\Feature;

use App\Models\MerchantSale;
use App\Models\Retail\StockReservation;
use App\Models\User;
use App\Services\Retail\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-RETAIL-RESERVATION-002 — **مهلةٌ مكتوبةٌ لا تنتهي أبداً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *     holdForSale     → يُنادى ✓  (ويكتب `expires_at` بعد ١٥ دقيقة)
 *     consumeForSale  → يُنادى ✓  (عند نجاح الدفع)
 *     releaseExpired  → **صفرُ مُنادٍ في المشروع كلِّه**
 *
 * والتعليقُ عند نقطة الحجز في `CashierService` يَعِد بنصّه: «الحجزُ
 * يُبقيها موجودةً وغيرَ متاحة، حتّى ينجح الدفعُ **أو تنتهي المهلة**».
 * **فالمهلةُ موعودةٌ في تعليقٍ ومكتوبةٌ في عمودٍ ولا تنتهي.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والأثرُ نزفٌ صامتٌ في مخزون التاجر.** الدفعُ بأميال باي غيرُ متزامن:
 * يُنشأ الحجزُ ثمّ يُنتظَر. فإن هجر الزبونُ السلّة، أو انقطعت شبكتُه، أو
 * سقط الدفع — يبقى الصفُّ `HELD` إلى الأبد. و`computedReserved` تطرح
 * المحجوزَ من المتاح، **فالمتاحُ ينكمش مع كلّ سلّةٍ مهجورة**.
 *
 * ولا خطأَ في أيّ سجلّ، ولا شاشةَ تقول لماذا: يرى التاجرُ «٠ متاح»
 * والرفُّ ملآن. **وفي تجربةٍ بألفَي مستخدمٍ هذا نزفٌ يوميّ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **و`releaseForSale` لا تُحرَس هنا وتبقى بلا مُنادٍ عن قصد** — لا مسارَ
 * إلغاءِ بيعةٍ في المنتج أصلاً، فهي مساعدُ ميزةٍ لم تُبنَ لا عطلٌ. وقِيس
 * ذلك ولم يُفترَض. **والمهلةُ تُغلق النزفَ كلَّه** دون حاجةٍ إليها.
 */
class StockReservationExpiryGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * حجزٌ قائم — **وأعمدتُه منسوخةٌ من مسار الإدراج الحقيقيّ** في
     * `holdForSale`، لا مُخمَّنةٌ من أسمائها. (خمّنتُ أوّلَ مرّةٍ فسقط
     * ثلاثةٌ بـ1364 على `sale_ulid` و`quantity`.)
     */
    private function heldSale(int $minutesAgo): StockReservation
    {
        $merchant = User::factory()->create(['type' => 3]);

        // **ومنتَجٌ وموقعٌ حقيقيّان** — العمودان مفتاحان أجنبيّان،
        // ورقمٌ اعتباطيٌّ يسقط بـ1452. (قِيس ولم يُفترَض.)
        $product = DB::table('merchant_products')->insertGetId([
            'merchant_user_id' => $merchant->id,
            'name' => 'صنفٌ يُتتبَّع',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $location = DB::table('merchant_locations')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'kind' => 'store',
            'name' => 'الفرعُ الرئيسيّ',
            'code' => 'MAIN-' . $merchant->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sale = MerchantSale::create([
            'sale_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'status' => 'pending',
            'total_amount' => '100',
            'payment_method' => 'cash',
        ]);

        return StockReservation::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'product_id' => $product,
            'location_id' => $location,
            'sale_id' => $sale->id,
            'sale_ulid' => $sale->sale_ulid,
            'quantity' => '2',
            'status' => StockReservation::HELD,
            'expires_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // ① الأمرُ موجودٌ ويفكّ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_expired_reservation_is_released(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه.** `releaseExpired` صحيحةٌ ومُختبَرةٌ منذ
        // كُتبت، ولا شيءَ يناديها — فالمهلةُ عمودٌ يُكتَب ولا يُقرأ.
        // ══════════════════════════════════════════════════════════════
        $res = $this->heldSale(minutesAgo: 30);

        $this->assertSame(0, Artisan::call('amial:release-expired-reservations'));

        $this->assertSame(StockReservation::RELEASED, $res->fresh()->status,
            'حجزٌ مضت مهلتُه بنصف ساعةٍ ما زال قائماً — والمخزونُ محبوسٌ أبداً');
    }

    /** @test */
    public function a_live_reservation_is_left_alone(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى.** فكُّ حجزٍ حيٍّ
        // يبيع آخرَ حبّةٍ لزبونين معاً — وهو العطلُ الذي وُجد الحجزُ لمنعه.
        $res = $this->heldSale(minutesAgo: -30); // ينتهي بعد نصف ساعة

        Artisan::call('amial:release-expired-reservations');

        $this->assertSame(StockReservation::HELD, $res->fresh()->status,
            'فُكَّ حجزٌ لم تنتهِ مهلتُه — فتُباع البضاعةُ مرّتين');
    }

    /** @test */
    public function a_consumed_reservation_is_not_reopened(): void
    {
        // **والمستهلَكُ لا يُمَسّ.** بيعةٌ دُفعت وخُصم مخزونُها؛ وإعادةُ
        // فتحِ حجزها تحبس بضاعةً بيعت فعلاً.
        $res = $this->heldSale(minutesAgo: 30);
        $res->update(['status' => StockReservation::CONSUMED]);

        Artisan::call('amial:release-expired-reservations');

        $this->assertSame(StockReservation::CONSUMED, $res->fresh()->status,
            'مُسَّ حجزٌ مستهلَك — فبضاعةٌ بيعت تُحسَب محجوزة');
    }

    // ══════════════════════════════════════════════════════════════════
    // ② والمجدوِلُ يناديه — وهذا ما كان غائباً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_scheduler_actually_runs_it(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وأمرٌ لا يجدوله شيءٌ ليس موصولاً.** بناءُ الأمر وحدَه يستبدل
        // «دالّةٌ بلا مُنادٍ» بـ«أمرٌ بلا مُنادٍ» — نفسُ العطل باسمٍ أطول.
        // (القاعدةُ الثانية عشرة: كلُّ تغييرٍ يُقال أين يظهر.)
        // ══════════════════════════════════════════════════════════════
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($e) => (string) $e->command)
            ->filter(fn ($c) => str_contains($c, 'amial:release-expired-reservations'));

        $this->assertNotEmpty($events,
            'الأمرُ غيرُ مجدوَل — فالمهلةُ ما زالت لا تنتهي، والفرقُ اسمُ الشيء الميّت');
    }

    /** @test */
    public function it_does_not_wait_an_hour(): void
    {
        // **وحجزٌ يبقى ساعةً بعد انتهائه يمنع بيعاً حقيقيّاً.** زبونٌ
        // يُعاود الشراءَ بعد دقائقَ يجب أن يجد البضاعةَ متاحة.
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'amial:release-expired-reservations'));

        $this->assertNotNull($event);

        $this->assertSame('*/5 * * * *', $event->expression,
            'الجدولُ أبطأُ من المهلة — فالمخزونُ يبقى محبوساً بعد انتهائها');
    }

    // ══════════════════════════════════════════════════════════════════
    // ③ ولا يسقط صامتاً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_failed_sweep_is_not_silent(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وسقوطٌ صامتٌ يُعيد العطلَ نفسَه بثوبٍ آخر.** الأمرُ يجري بلا
        // عينٍ عليه؛ فإن سقط كلَّ خمس دقائقَ لم يعلم أحد، والمتاحُ ينكمش
        // كما كان قبل أن يُبنى.
        // ══════════════════════════════════════════════════════════════
        $this->swap(StockReservationService::class,
            new class(app(\App\Services\Retail\StockService::class)) extends StockReservationService
            {
                public function releaseExpired(int $limit = 500): int
                {
                    throw new \RuntimeException('القاعدةُ ساقطة');
                }
            });

        $this->assertNotSame(0, Artisan::call('amial:release-expired-reservations'),
            'خرج بصفرٍ وقد سقط — فالمجدوِلُ يراه ناجحاً');

        $this->assertDatabaseHas('system_errors', [
            'fingerprint' => hash('sha256', 'ops|retail.reservations.release_failed'),
        ]);
    }

    /** @test */
    public function a_quiet_sweep_does_not_flood_the_log(): void
    {
        // **وجولةٌ لا تجد شيئاً هي الحالُ السويّة** — وطباعتُها كلَّ خمس
        // دقائق تُغرق السجلَّ فتُخفي ما يُقرأ.
        Artisan::call('amial:release-expired-reservations');

        $this->assertSame('', trim(Artisan::output()),
            'يُطبَع سطرٌ على صفرِ نتيجة — فالسجلُّ يمتلئ بما لا يُقرأ');
    }

    // ══════════════════════════════════════════════════════════════════
    // ④ والمهلةُ تُكتَب أصلاً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_reservation_carries_a_real_expiry(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ومكنسةٌ تكنس ما لا يُوسَم لا تكنس شيئاً.** لو كان `expires_at`
        // يُترك فارغاً لبقي الأمرُ الجديدُ يخرج بصفرٍ إلى الأبد — **أخضرَ
        // وعاجزاً**، وهو الصمتُ بثوب نجاح.
        // ══════════════════════════════════════════════════════════════
        $src = (string) file_get_contents(base_path('app/Services/Retail/StockReservationService.php'));

        $this->assertMatchesRegularExpression(
            '~\$expires\s*=\s*now\(\)->addMinutes\(~', $src,
            'الحجزُ بلا مهلةٍ محسوبة — فلا شيءَ ينتهي ولا شيءَ يُفكّ');

        $this->assertGreaterThan(0, StockReservationService::DEFAULT_TTL_MINUTES,
            'المهلةُ الافتراضيّةُ صفرٌ أو سالبة');
    }
}
