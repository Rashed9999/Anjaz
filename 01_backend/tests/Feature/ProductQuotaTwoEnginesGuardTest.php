<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Access\EntitlementService;
use App\Services\UsageLimitService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-ENTITLEMENTS-004 — **عدّادان لحدٍّ واحد، ولا يتّفقان.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس في مراجعة الإنتاج:**
 *
 * خمسةُ أبوابٍ تُنشئ منتجاً، **يحرسها محرّكان مختلفان**:
 *
 *   merchant/cashier/products        → capability:products  (محرّك ②)
 *   merchant/cashier/products/adopt  → capability:products  (محرّك ②)
 *   merchant/fuel/products           → amial.usage:add_product (محرّك ①)
 *   merchant/pharmacy/products       → amial.usage:add_product (محرّك ①)
 *   merchant/wholesale/products      → amial.usage:add_product (محرّك ①)
 *
 * وكلاهما يفرض `PLAN_LIMITS[...]['products']` نفسَه — **ويعدّان شيئين
 * مختلفين**:
 *
 * | المحرّك | كيف يعدّ |
 * |---|---|
 * | ① `UsageLimitService::countProducts` | **من جدول القطاع**: جملةٌ من جدولها، وصيدليّةٌ من جدولها، ووقودٌ من جدوله |
 * | ② `EntitlementService::usageFor` | **من `merchant_products` وحدَه**، أيّاً كان القطاع |
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والالتفافُ يقع في اتّجاهٍ واحدٍ ويُنتج مالاً:**
 *
 * تاجرُ جملةٍ على باقة البداية (حدُّها ١٠٠) بلغ المئةَ في جدول الجملة.
 * فبابُ الجملة يمنعه — **وبابُ الكاشير يقرأ `merchant_products` فيجده
 * صفراً فيفتح**. أي أنّه يشتري باقةً لا يحتاجها، أو لا يشتري أصلاً.
 *
 * وهذا **ليس عطلَ توصيل**: البابان محروسان كلاهما، والاختباراتُ تمرّ على
 * كلٍّ منهما وحدَه. **العطلُ في أنّ لمفهومٍ واحدٍ مصدرَي حقيقة** — وهو ما
 * لا يظهر إلّا حين يُسأل المصدران عن الحساب نفسِه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا الملفُّ يسأل السؤالَ ولا يفترض الجواب.** فإن اتّفق العدّادان
 * سقط الادّعاءُ وسُرَّ به — وإن اختلفا فالرقمان في رسالة السقوط.
 */
class ProductQuotaTwoEnginesGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $businessType, string $plan): User
    {
        $u = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id,
            'verification_status' => 'verified',
            'business_type' => $businessType,
            'subscription_plan' => $plan,
        ]);

        return $u->refresh();
    }

    /** كم يقول المحرّكُ ① إنّ عنده من أصناف؟ */
    private function engineOne(User $m): int
    {
        return (int) (app(UsageLimitService::class)->usageSnapshot($m)['products']['current'] ?? -1);
    }

    /** وكم يقول المحرّكُ ②؟ */
    private function engineTwo(User $m): int
    {
        $state = app(EntitlementService::class)->state($m, A::F_PRODUCTS);

        return (int) ($state['usage']['used'] ?? -1);
    }

    /**
     * @test
     *
     * **على تاجرِ تجزئةٍ يتّفقان** — وهذا يُثبت أنّ القياسَ نفسَه سليم.
     *
     * ولولا هذا لكان اختلافُهما في الجملة قابلاً لأن يُعزى إلى خطأٍ في
     * طريقة القياس لا إلى خلافٍ حقيقيّ.
     */
    public function the_two_engines_agree_for_a_retail_merchant(): void
    {
        $m = $this->merchant(A::BIZ_RETAIL, A::PLAN_STARTER);

        DB::table('merchant_products')->insert([
            'merchant_user_id' => $m->id, 'name' => 'صنفٌ للقياس',
            'price' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($this->engineOne($m), $this->engineTwo($m),
            'العدّادان اختلفا على تاجرِ تجزئةٍ — فالقياسُ نفسُه مشكوكٌ فيه');
    }

    /**
     * @test
     *
     * **وعلى تاجر الجملة؟**
     *
     * هذا هو السؤال. تاجرُ جملةٍ أصنافُه في جدول الجملة، و`merchant_products`
     * عنده فارغ. فإن قال المحرّكُ ① «عنده أصناف» وقال ② «صفر»، فبابُ
     * الكاشير يفتح لمن أغلقه بابُ الجملة.
     *
     * **ويُقرأ الرقمان في رسالة السقوط** — فلا يُقال «اختلفا» بلا عدد.
     */
    public function the_two_engines_agree_for_a_wholesale_merchant(): void
    {
        $m = $this->merchant(A::BIZ_WHOLESALE, A::PLAN_STARTER);

        // صنفٌ في عالم الجملة — ولا شيءَ في `merchant_products`.
        $this->seedWholesaleProduct($m);

        // ══════════════════════════════════════════════════════════
        // **البذرُ يُثبَت قبل المقارنة — وإلّا كانت المقارنةُ صمتاً.**
        //
        // بذرٌ يسقط يجعل العدّادين يقولان صفراً فيتّفقان، **فيُقرأ ذلك
        // ›لا تعارض‹ وهو ›لم يُسأل‹**. وقد وقع: البوّابةُ أخرجت أخضرَ
        // مرّتين والسؤالُ لم يُطرح.
        $this->assertGreaterThan(0, $this->engineOne($m),
            'العدّادُ ① يقول صفراً بعد البذر — فالبذرُ لم يقع، '
            . '**والمقارنةُ التاليةُ ستكون صمتاً لا جواباً**');

        // ══════════════════════════════════════════════════════════
        // **البذرُ يُثبَت قبل المقارنة — وإلّا كانت المقارنةُ صمتاً.**
        //
        // بذرٌ يسقط يجعل العدّادين يقولان صفراً فيتّفقان، **فيُقرأ ذلك
        // ›لا تعارض‹ وهو ›لم يُسأل‹**. وقد وقع: البوّابةُ أخرجت أخضرَ
        // مرّتين والسؤالُ لم يُطرح.
        $this->assertGreaterThan(0, $this->engineOne($m),
            'العدّادُ ① يقول صفراً بعد البذر — فالبذرُ لم يقع، '
            . '**والمقارنةُ التاليةُ ستكون صمتاً لا جواباً**');

        $one = $this->engineOne($m);
        $two = $this->engineTwo($m);

        $this->assertSame($one, $two, sprintf(
            "عدّادان لحدٍّ واحدٍ لا يتّفقان على تاجر جملة:\n"
            . "  المحرّك ① (‏UsageLimitService · باب الجملة)      : %d\n"
            . "  المحرّك ② (‏EntitlementService · باب الكاشير)   : %d\n\n"
            . 'فمن بلغ حدَّه في الباب الأوّل يفتح له الثاني — **حدُّ باقةٍ '
            . 'يُلتَفّ عليه بتغيير الباب، لا بتغيير الباقة**.',
            $one, $two));
    }

    /**
     * @test
     *
     * **وعلى الصيدليّة؟** — الصنفُ نفسُه من العلّة.
     */
    public function the_two_engines_agree_for_a_pharmacy_merchant(): void
    {
        $m = $this->merchant(A::BIZ_PHARMACY, A::PLAN_STARTER);

        $this->seedPharmacyProduct($m);

        $one = $this->engineOne($m);
        $two = $this->engineTwo($m);

        $this->assertSame($one, $two, sprintf(
            'عدّادان لحدٍّ واحدٍ لا يتّفقان على صيدليّة: ① = %d · ② = %d', $one, $two));
    }

    // ══════════════════════════════════════════════════════════════════
    //  البذر — **ويُخطَّى صراحةً إن غاب الجدول، ولا يُعدّ نجاحاً.**
    //
    //  (القاعدة السابعة: «غير معروف» ليس صفراً. واختبارٌ يمرّ لأنّه لم
    //  يبذر شيئاً يُطمئن ولا يفحص.)
    // ══════════════════════════════════════════════════════════════════

    private function seedWholesaleProduct(User $m): void
    {
        // **الأعمدةُ الإلزاميّةُ تُقرأ من الهجرة لا تُخمَّن.**
        // أوّلُ صيغةٍ أسقطت البذرَ لأنّ `base_price` و`business_name` بلا
        // افتراضيّ — فسقط الاختبارُ على أداته وأعلن «عطلاً» ليس عطلاً.
        $this->seedInto('wholesale_products', [
            'wholesale_business_id' => $this->wholesaleBusinessId($m),
            'name' => 'صنفُ جملةٍ للقياس',
            'base_price' => 100,
        ]);
    }

    private function seedPharmacyProduct(User $m): void
    {
        $this->seedInto('pharmacy_products', [
            'merchant_user_id' => $m->id,
            'trade_name' => 'دواءٌ للقياس',
            'sale_price' => 100,
        ]);
    }

    /**
     * **والبذرُ يُخطَّى ولا يُخطئ.**
     *
     * أوّلُ تشغيلٍ سقط بـ`QueryException` من `insertGetId` — أي أنّ
     * الاختبارَ أعلن «عطلاً» وهو عطلُ أداتِه لا عطلُ المنتج. ولو تُرك
     * كذلك لأرسل من يقرؤه خلف عطلٍ لا وجودَ له.
     *
     * فصار كلُّ بذرٍ محاطاً: يُخطَّى صراحةً ويُقال السبب — **ومُخطّىً ليس
     * ناجحاً** (القاعدة السابعة).
     */
    private function wholesaleBusinessId(User $m): int
    {
        try {
            $existing = DB::table('wholesale_businesses')
                ->where('merchant_user_id', $m->id)->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }

            return (int) DB::table('wholesale_businesses')->insertGetId([
                'merchant_user_id' => $m->id,
                'business_name' => 'منشأةُ جملةٍ للقياس',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped(sprintf(
                'تعذّر إنشاءُ منشأةِ جملةٍ للقياس (%s) — **والسؤالُ يبقى مفتوحاً '
                . 'لا مُجاباً**', mb_substr($e->getMessage(), 0, 140)));
        }
    }

    private function seedInto(string $table, array $row): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            $this->markTestSkipped("جدولُ «{$table}» غير موجود — فالسؤالُ لا يُطرح هنا");
        }

        try {
            DB::table($table)->insert($row + ['created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $this->markTestSkipped(sprintf(
                'تعذّر بذرُ «%s» (%s) — **ويُقال مُخطّىً لا ناجحاً**',
                $table, mb_substr($e->getMessage(), 0, 120)));
        }
    }
}
