<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\PharmacySaleService;
use App\Services\PharmacyService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-ACCEPT-PHARMACY-001 — **اختبارُ قبولٍ لا اختبارُ شاشات.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الفرقُ الذي وُلد منه هذا الملفّ:** في المجموعة آلافُ الاختبارات، وكلُّ
 * واحدٍ منها يمسك **حلقةً واحدة**. والقبولُ يسأل سؤالاً آخر: **أتمشي
 * السلسلةُ كاملةً من إنشاء الصنف إلى القيد المحاسبيّ؟**
 *
 * فالحلقاتُ قد تكون سليمةً كلُّها والوصلُ بينها مكسور — وذاك ما يظهر في
 * يد صاحب المشروع لا في تقرير.
 *
 * **ويُقاس هنا ما يُقاس آليّاً وحدَه.** والطباعةُ على ورقٍ حقيقيّ
 * وبلوتوث وIP:9100 وفتحُ الدرج **لا يُقاس بلا جهاز**، فيُقال ذلك صراحةً
 * ولا يُدّعى. (القاعدة السابعة: الغيابُ يُقال ولا يُقرأ صفراً.)
 */
class PharmacyProductionAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private $pharmacy;
    private PharmacyService $svc;
    private PharmacySaleService $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(PharmacyService::class);
        $this->sales = app(PharmacySaleService::class);

        $this->owner = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'phone' => '967771000001',
        ]);

        MerchantProfile::create([
            'user_id' => $this->owner->id,
            'business_type' => A::BIZ_PHARMACY,
            'business_name' => 'صيدليّة القبول',
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
            'subscription_expires_at' => now()->addYear(),
        ]);

        EMoney::create([
            'user_id' => $this->owner->id, 'current_balance' => '0.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        $this->pharmacy = $this->svc->getOrCreatePharmacy($this->owner, [
            'name' => 'صيدليّة القبول',
        ]);
    }

    /** صنفٌ كاملُ الحقول ودفعتان: قريبةُ الانتهاء أوّلاً. */
    private function productWithTwoBatches(): array
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'باراسيتامول ٥٠٠',
            'generic_name' => 'Paracetamol',
            'strength' => '500mg',
            'dosage_form' => 'tablet',
            'category_name' => 'مسكّنات',
            'barcode' => '6291000000017',
            'purchase_price' => '100',
            'sale_price' => '150',
        ]);

        // **الأقربُ انتهاءً تُنشأ ثانيةً عمداً** — فترتيبُ الخصم يجب أن
        // يتبع تاريخَ الانتهاء لا ترتيبَ الإدخال. (FEFO)
        $far = $this->svc->addBatch($product, [
            'batch_number' => 'FAR-01',
            'expiry_date' => now()->addYears(2)->toDateString(),
            'manufactured_at' => now()->subMonths(2)->toDateString(),
            'quantity_received' => 100,
        ]);

        $near = $this->svc->addBatch($product, [
            'batch_number' => 'NEAR-01',
            'expiry_date' => now()->addDays(20)->toDateString(),
            'manufactured_at' => now()->subYear()->toDateString(),
            'quantity_received' => 10,
        ]);

        return [$product, $near, $far];
    }

    // ═════════════════════════════════════════════════════════════════
    // ١) البيع والمخزون
    // ═════════════════════════════════════════════════════════════════

    /**
     * **① الصنفُ يُنشأ بكلّ حقوله، والبيعُ يخصم الدفعةَ الأقربَ انتهاءً.**
     *
     * فخصمٌ من الدفعة البعيدة يترك القريبةَ تنتهي على الرفّ — خسارةُ مالٍ
     * صامتة، ولا خطأَ في أيّ سجلّ.
     */
    /** @test */
    public function a_sale_deducts_the_nearest_expiring_batch_first(): void
    {
        [$product, $near, $far] = $this->productWithTwoBatches();

        $this->assertSame('6291000000017', $product->barcode,
            'الباركود لم يُحفظ — فلا يُمسح الصنفُ في الكاشير.');
        $this->assertSame('150.0000', (string) $product->sale_price);
        $this->assertNotNull($product->category_id, 'التصنيفُ لم يُنشأ.');

        $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 4]],
            ['payment_method' => 'cash'],
        );

        $near->refresh();
        $far->refresh();

        $this->assertSame(6.0, (float) $near->quantity_remaining, sprintf(
            '**خُصم من غير الدفعة الأقربِ انتهاءً.** القريبةُ %s والبعيدةُ %s — '
            .'فتبقى القريبةُ على الرفّ حتّى تنتهي، وهي خسارةُ مالٍ صامتة.',
            $near->quantity_remaining, $far->quantity_remaining));

        $this->assertSame(100.0, (float) $far->quantity_remaining,
            'مُسّت الدفعةُ البعيدةُ وفي القريبة كفاية.');
    }

    /**
     * **② والمنتهي لا يُباع.**
     *
     * وهذا حدٌّ دوائيٌّ لا محاسبيّ: بيعُ دواءٍ منتهٍ ضررٌ على المريض قبل
     * أن يكون خطأً في دفتر.
     */
    /** @test */
    public function an_expired_batch_is_never_sold(): void
    {
        [$product, $near] = $this->productWithTwoBatches();

        // تُقدَّم الصلاحيّةُ إلى الأمس مباشرةً في القاعدة — فالخدمةُ ترفض
        // إنشاءَ دفعةٍ منتهية، والحالةُ المقيسةُ هي **دفعةٌ انتهت على الرفّ**.
        DB::table('pharmacy_batches')->where('id', $near->id)
            ->update(['expiry_date' => now()->subDay()->toDateString()]);

        $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 3]],
            ['payment_method' => 'cash'],
        );

        $near->refresh();

        $this->assertSame(10.0, (float) $near->quantity_remaining,
            '**بِيع من دفعةٍ منتهية.** والضررُ على المريض قبل الدفتر.');
    }

    // ═════════════════════════════════════════════════════════════════
    // ٢) العميل والآجل
    // ═════════════════════════════════════════════════════════════════

    /**
     * **③ والبيعُ الآجلُ لا يقع بلا عميل.**
     *
     * فآجلٌ بلا اسمٍ دَينٌ على مجهول — لا يُحصَّل ولا يُطالَب به أحد.
     */
    /** @test */
    public function a_credit_sale_without_a_customer_is_refused(): void
    {
        [$product] = $this->productWithTwoBatches();

        $this->expectException(\InvalidArgumentException::class);

        $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 1]],
            ['payment_method' => 'credit'],
        );
    }

    /**
     * **④ والآجلُ ليس مالاً محصَّلاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا أخطرُ رقمٍ في القائمة كلِّها: فاتورةٌ آجلةٌ تُقرأ نقداً تجعل
     * تقريرَ اليوم يقول للصيدليّ إنّه قبض ما لم يقبضه — **فيُقفل ورديّته
     * على عجز**. (القاعدة السادسة: الرقمُ يُحسب من مصدره.)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_credit_sale_is_not_counted_as_collected_money(): void
    {
        [$product] = $this->productWithTwoBatches();

        $customer = $this->svc->addCustomer($this->pharmacy, [
            'full_name' => 'مريضُ الآجل',
            'phone' => '967773000009',
        ]);

        $walletBefore = (string) EMoney::where('user_id', $this->owner->id)
            ->value('current_balance');

        $sale = $this->sales->recordSale($this->owner, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 2]],
            ['payment_method' => 'credit', 'customer_id' => $customer->id],
        );

        $walletAfter = (string) EMoney::where('user_id', $this->owner->id)
            ->value('current_balance');

        $this->assertSame($walletBefore, $walletAfter, sprintf(
            '**بيعٌ آجلٌ حرّك محفظةَ الصيدليّة** (%s ← %s). والآجلُ دَينٌ '
            .'لم يُقبَض بعد — فعدُّه مالاً يجعل التقريرَ يَعِد الصيدليَّ '
            .'بما ليس في يده.', $walletBefore, $walletAfter));

        $this->assertSame('credit', $sale->payment_method);
        $this->assertGreaterThan(0, (float) $sale->total_amount);
    }

    // ═════════════════════════════════════════════════════════════════
    // ٣) المال والاعتمادية
    // ═════════════════════════════════════════════════════════════════

    /**
     * **⑤ ونفسُ الطلب مرّتين عمليّةٌ واحدة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهي الحالةُ التي تقع في الدنيا لا في المعمل: **الشبكةُ تنقطع بعد
     * وصول الطلب وقبل وصول الردّ**، فيضغط الكاشيرُ ثانية. فإن لم يُمسَك
     * التكرارُ خرجت **فاتورتان وخُصم المخزونُ مرّتين** — والزبونُ دفع مرّة.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_same_sale_sent_twice_is_one_operation(): void
    {
        [$product, $near] = $this->productWithTwoBatches();

        \Laravel\Passport\Passport::actingAs($this->owner);

        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash',
        ];

        // **المفتاحُ هو هو في الطلبين** — كما يفعل التطبيقُ حين تنقطع
        // الشبكةُ بعد وصول الطلب وقبل وصول الردّ: `_inFlightKeys` تحتفظ
        // بالمفتاح عند الاستثناء، فتحمل الإعادةُ مفتاحَ الأصل.
        $key = ['Idempotency-Key' => 'ACCEPT-'.\Illuminate\Support\Str::uuid()];

        $first = $this->postJson('/api/v1/amial/merchant/pharmacy/sales', $payload, $key);
        $second = $this->postJson('/api/v1/amial/merchant/pharmacy/sales', $payload, $key);

        $first->assertSuccessful();

        $count = \DB::table('pharmacy_sales')
            ->where('pharmacy_id', $this->pharmacy->id)->count();

        $this->assertSame(1, $count, sprintf(
            '**الطلبُ نفسُه أنتج %d فاتورة.** والكاشيرُ يعيد الضغطَ حين '
            .'تنقطع الشبكةُ قبل الردّ — فتُسجَّل بيعتان والزبونُ دفع مرّة. '
            .'(ردُّ الثاني: %d)', $count, $second->status()));

        $near->refresh();

        $this->assertSame(8.0, (float) $near->quantity_remaining, sprintf(
            '**خُصم المخزونُ مرّتين** — بقي %s والمتوقَّع ٨. فالجردُ '
            .'يُظهر نقصاً لا سببَ له، ويُطارَد فيه سارقٌ لا وجودَ له.',
            $near->quantity_remaining));
    }

    /**
     * **⑥ وبيعتان مشروعتان متطابقتان تبقيان اثنتين.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهو الوجهُ الآخرُ من الحاجز، **وإغفالُه يقلب الحمايةَ عطلاً**:
     * مريضان يشتريان الصنفَ نفسَه بالكمّيّة نفسِها دقيقةً بعد دقيقة —
     * فلو دُمجتا لضاعت بيعةٌ ولَبقي مخزونُها في الدفتر وليس على الرفّ.
     *
     * والفرقُ **مفتاحٌ جديد**: التطبيقُ يمسحه بعد أيّ ردّ (`_inFlightKeys
     * .remove`)، ويُبقيه عند انقطاع الشبكة وحدَه. فيُقاس الطرفان معاً.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function two_genuine_identical_sales_stay_two(): void
    {
        [$product, $near] = $this->productWithTwoBatches();

        \Laravel\Passport\Passport::actingAs($this->owner);

        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash',
        ];

        foreach (['A', 'B'] as $k) {
            $this->postJson('/api/v1/amial/merchant/pharmacy/sales', $payload,
                ['Idempotency-Key' => 'ACCEPT-DISTINCT-'.$k])->assertSuccessful();
        }

        $count = \DB::table('pharmacy_sales')
            ->where('pharmacy_id', $this->pharmacy->id)->count();

        $this->assertSame(2, $count,
            '**بيعتان مشروعتان صارتا واحدة.** فضاعت بيعةٌ وبقي مخزونُها '
            .'في الدفتر وليس على الرفّ — والحمايةُ انقلبت عطلاً.');

        $near->refresh();
        $this->assertSame(6.0, (float) $near->quantity_remaining,
            'خُصمت كمّيّةُ بيعةٍ واحدةٍ من بيعتين.');
    }

    // ═════════════════════════════════════════════════════════════════
    // ٦) الباقات والصلاحيات
    // ═════════════════════════════════════════════════════════════════

    /**
     * **⑥ والحاجزُ في الخادم لا في الشاشة.**
     *
     * فإخفاءُ زرٍّ ليس منعاً: من فتح المسارَ بأيّ أداةٍ يتجاوزه.
     */
    /** @test */
    public function a_paid_pharmacy_feature_is_refused_by_the_server_on_the_free_plan(): void
    {
        // **وتُقاس قدرةٌ مدفوعةٌ فعلاً.** قِستُ أوّلاً `pharmacy/alerts`
        // فخرجت ٢٠٠ **وهي صحيحة**: باقتُها `PLAN_FREE` في السجلّ عمداً
        // (تنبيهُ انتهاء صلاحيّةٍ حدٌّ دوائيٌّ لا ميزةٌ تُباع). فالخطأُ
        // كان في اختياري لا في الحاجز — و«عملاءُ الصيدلية»
        // (`PLAN_BUSINESS`) هي المدفوعةُ ذاتُ المسار.

        MerchantProfile::where('user_id', $this->owner->id)
            ->update(['subscription_plan' => A::PLAN_FREE]);

        \Laravel\Passport\Passport::actingAs($this->owner);

        $status = $this->getJson('/api/v1/amial/merchant/pharmacy/customers')->status();

        $this->assertGreaterThanOrEqual(400, $status, sprintf(
            '**ميزةٌ مدفوعةٌ فُتحت على الباقة المجّانيّة (%d).** وإخفاءُ '
            .'الزرّ في الشاشة ليس حاجزاً — من فتح المسارَ بأيّ أداةٍ '
            .'يتجاوزه.', $status));
    }

    /**
     * **⑦ وموظّفُ نقطة البيع لا يقرأ محفظةَ صاحبه.**
     */
    /** @test */
    public function a_pos_employee_never_reads_the_owner_wallet(): void
    {
        $posLogin = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'zone_code' => 'SOUTH',
            'phone' => '967774000004',
        ]);

        PosUser::create([
            'user_id' => $posLogin->id,
            'merchant_user_id' => $this->owner->id,
            'pos_number' => 'ACC-POS-1',
            'display_name' => 'كاشير القبول',
            'is_active' => true,
        ]);

        config(['amial.pos_devices.enforce_session_binding' => false]);

        \Laravel\Passport\Passport::actingAs($posLogin);

        $stats = $this->getJson('/api/v1/amial/merchant/daily-stats')
            ->assertOk()
            ->json('meta') ?? [];

        $this->assertArrayNotHasKey('current_balance', $stats, sprintf(
            '**رصيدُ المتجر وصل إلى الكاشير.** والردُّ ٢٠٠ وصيغتُه صحيحة، '
            .'والرقمُ ليس له: %s', json_encode($stats, JSON_UNESCAPED_UNICODE)));
    }

    /**
     * **⑧ وتاجرٌ آخر لا يقرأ بياناتِ هذه الصيدليّة.**
     *
     * فعزلُ المستأجرين شرطُ صحّةِ المنصّة كلِّها — وتسريبُه ليس عطلَ
     * واجهةٍ بل خرقُ بيانات.
     */
    /** @test */
    public function another_merchant_cannot_read_this_pharmacy(): void
    {
        [$product] = $this->productWithTwoBatches();

        $stranger = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'phone' => '967775000005',
        ]);

        MerchantProfile::create([
            'user_id' => $stranger->id,
            'business_type' => A::BIZ_PHARMACY,
            'business_name' => 'صيدليّةٌ أخرى',
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
            'subscription_expires_at' => now()->addYear(),
        ]);

        \Laravel\Passport\Passport::actingAs($stranger);

        $res = $this->getJson('/api/v1/amial/merchant/pharmacy/products');

        // **ويُثبَت أنّه بلغ الباب** — قِيس فإذا الغريبُ يُردّ بـ٢٠٠
        // وقائمةٍ فارغة، وهو العزلُ الصحيح. **ولولا هذا الشرطُ لمرّ
        // الفحصُ على ٤٠٣ من سببٍ آخر** (لا ملفَّ · لا باقة · لا صيدليّة)
        // فيُقرأ عزلاً وهو غيابُ وصول — وحارسٌ يمرّ بلا أن يفحص أسوأُ
        // من غيابه. (القاعدة الثانية.)
        $res->assertSuccessful();

        $body = $res->json();
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString((string) $product->barcode, $raw,
            '**تاجرٌ يقرأ أصنافَ صيدليّةٍ ليست له** — وهذا خرقُ بيانات '
            .'لا عطلُ واجهة.');

        $this->assertStringNotContainsString('باراسيتامول ٥٠٠', $raw,
            '**اسمُ صنفِ صيدليّةٍ أخرى ظهر في ردّها.**');
    }
}
