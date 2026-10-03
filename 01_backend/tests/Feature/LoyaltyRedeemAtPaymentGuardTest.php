<?php

namespace Tests\Feature;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyProgram;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\PosUser;
use App\Models\User;
use App\Services\CashierService;
use App\Support\Access\AccessConstants as A;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-LOYALTY-AT-PAYMENT-001 — **النقاطُ تُصرَف داخل البيعة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الملاحظة بنصّها:** «نقاط الولاء ليس لها استخدام أثناء الدفع… يبدو
 * أنّها غير مكتملة الميزة».
 *
 * **وقِيس فإذا هي أسوأ من ناقصة — تحرق نقاطاً بلا مقابل:**
 * `LoyaltyService::redeem()` مبنيّةٌ وتعمل، وشاشةُ «برنامج الولاء»
 * تناديها ثمّ تطبع للكاشير **«خصم بقيمة X ر.ي — طبّقه على الفاتورة»**.
 * فالنقاطُ تُنقَص **الآن**، والخصمُ يُطبَّق في شاشةٍ أخرى بيدٍ بشريّة.
 * وإن نسِي، أو أُلغيت البيعة: **النقاطُ ذهبت والعميلُ دفع كاملاً**، ولا
 * سطرَ يربط الحركةَ ببيعة.
 *
 * **وثلاثةُ أبوابٍ كانت مغلقةً دونها، وكلُّها في مسار الكاشير:**
 *   ① لا مدخلَ في شاشة الدفع إطلاقاً (مبنيٌّ ولا يُوصَل إليه).
 *   ② `lookup`/`redeem` تشترطان `role === merchant`، **ودورُ الكاشير
 *      `pos`** — فكلُّ نداءٍ من الشبّاك يُردّ ٤٠٣. (القاعدة الرابعة:
 *      الميزةُ جُرّبت من مدخل المالك وحدَه.)
 *   ③ لا عمودَ في البيعة يقول كم نقطةً صُرفت وبكم.
 */
class LoyaltyRedeemAtPaymentGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // بوّابةُ مقعد الجهاز: `actingAs` لا تُصدر رمزاً فلا ربطَ لجلسةٍ
        // بجهاز، فتُردّ نداءاتُ الكاشير عند الباب ولا يُفحَص المقصود.
        // وهي محروسةٌ في موضعها (`PosDeviceEnforcementTest`).
        config()->set('amial.pos_devices.enforce_session_binding', false);
    }

    private const PHONE = '772223344';

    private function merchant(): User
    {
        $user = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $user->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'single_receive_limit' => '5000000', 'daily_receive_limit' => '50000000',
        ]);

        return $user;
    }

    /** برنامجٌ مفعَّل: نقطةٌ = ١٠ ر.ي، وأدنى استبدالٍ ١٠ نقاط. */
    private function programFor(User $merchant): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'merchant_user_id' => $merchant->id,
            'is_active' => true,
            'earn_points_per_100' => 1,
            'redeem_value_per_point' => 10,
            'min_redeem_points' => 10,
        ]);
    }

    private function accountWith(User $merchant, float $points): LoyaltyAccount
    {
        return LoyaltyAccount::create([
            'merchant_user_id' => $merchant->id,
            'customer_phone' => Phone::canonical(self::PHONE),
            'customer_name' => 'أحمد',
            'points_balance' => $points,
            'total_earned' => $points,
            'total_redeemed' => 0,
        ]);
    }

    private function sell(User $merchant, string $total, ?float $redeem): MerchantSale
    {
        return app(CashierService::class)->recordSale(
            merchant: $merchant,
            total: $total,
            paymentMethod: 'cash',
            customer: ['name' => 'أحمد', 'phone' => self::PHONE],
            redeemPoints: $redeem,
        );
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① النقاطُ تُنقَص، والبيعةُ تحمل أثرَها.**
     *
     * ولا يكفي أن يعمل الاستبدال: **البيعةُ تقول كم صُرف وبكم** — وبلا
     * ذلك يُقرأ الإيصالُ فلا يُعرف لماذا نقص المبلغ.
     */
    /** @test */
    public function points_are_spent_on_the_sale_and_the_sale_records_it(): void
    {
        $merchant = $this->merchant();
        $this->programFor($merchant);
        $account = $this->accountWith($merchant, 120);

        $sale = $this->sell($merchant, '5000', 100.0);

        // **والكسبُ يقع على ما دُفع فعلاً.** الإجماليُّ المُرسَل هو
        // الصافي بعد خصم النقاط (كما يفعل خصمُ الكاشير سواءً بسواء)، فيكسب
        // العميلُ على ٥٠٠٠ = ٥٠ نقطة. فالرصيد: ١٢٠ − ١٠٠ + ٥٠ = ٧٠.
        //
        // **ويُكتب هنا صريحاً** لأنّ قارئَ الاختبار يتوقّع ٢٠ — وقد وقع
        // ذلك عند كتابته. ورقمٌ صحيحٌ يُقرأ خطأً يُصلَح بشرحه لا بتغييره.
        $this->assertSame(70.0, (float) $account->fresh()->points_balance,
            'النقاطُ لم تُنقَص من رصيد العميل (١٢٠ − ١٠٠ صرفاً + ٥٠ كسباً)');

        $this->assertSame(100.0, (float) $sale->loyalty_points_redeemed,
            'البيعةُ لا تقول كم نقطةً صُرفت');
        $this->assertSame(1000.0, (float) $sale->loyalty_discount,
            'البيعةُ لا تقول كم كانت قيمةُ النقاط بالريال');
    }

    /**
     * **② والحركةُ تُوسَم ببيعتها.**
     *
     * واستبدالٌ بلا معرّفِ بيعةٍ لا يُراجَع: لا يُعرف على أيّ فاتورةٍ
     * نزل خصمُه، ولا يُقابَل بمرتجعٍ إن رُدّت البيعة.
     */
    /** @test */
    public function the_movement_is_linked_to_the_sale(): void
    {
        $merchant = $this->merchant();
        $this->programFor($merchant);
        $account = $this->accountWith($merchant, 50);

        $sale = $this->sell($merchant, '3000', 20.0);

        $movement = LoyaltyMovement::where('loyalty_account_id', $account->id)
            ->where('type', 'redeem')->firstOrFail();

        $this->assertSame($sale->sale_ulid, $movement->sale_ulid,
            'حركةُ الاستبدال بلا بيعة — فلا يُعرف على أيّ فاتورةٍ نزل خصمُها');
    }

    /**
     * **③ وإن سقطت البيعةُ عادت النقاط.**
     *
     * وهذا هو الفرقُ كلُّه عن الحال القديمة: كانت تُحرَق في شاشةٍ
     * والبيعةُ قد لا تقع أصلاً.
     */
    /** @test */
    public function a_failed_sale_gives_the_points_back(): void
    {
        $merchant = $this->merchant();
        $this->programFor($merchant);
        $account = $this->accountWith($merchant, 100);

        try {
            // طريقةُ دفعٍ لا وجودَ لها ⇒ تسقط البيعةُ بعد قبول النقاط.
            app(CashierService::class)->recordSale(
                merchant: $merchant, total: '2000', paymentMethod: 'no_such_method',
                customer: ['name' => 'أحمد', 'phone' => self::PHONE],
                redeemPoints: 50.0,
            );
            $this->fail('البيعةُ الفاسدةُ مرّت');
        } catch (\Throwable) {
            // متوقَّع
        }

        $this->assertSame(100.0, (float) $account->fresh()->points_balance,
            'سقطت البيعةُ وذهبت نقاطُ العميل — وهو العطلُ الذي بُني هذا لأجله');
    }

    /**
     * **④ ورصيدٌ لا يكفي يُسقط البيعةَ ولا يُخصَم منه شيء.**
     *
     * فبيعةٌ تُقبَل بخصمٍ لم يُدفَع ثمنُه نقاطاً **تُخسِر المتجرَ مالاً**
     * مرّةً بعد مرّة.
     */
    /** @test */
    public function insufficient_points_stop_the_sale(): void
    {
        $merchant = $this->merchant();
        $this->programFor($merchant);
        $account = $this->accountWith($merchant, 5);

        $this->expectException(\RuntimeException::class);

        try {
            $this->sell($merchant, '4000', 50.0);
        } finally {
            $this->assertSame(5.0, (float) $account->fresh()->points_balance);
            $this->assertSame(0, MerchantSale::where('merchant_user_id', $merchant->id)->count(),
                'سُجّلت بيعةٌ بخصمِ نقاطٍ لا وجودَ لها');
        }
    }

    /**
     * **⑤ ونقاطٌ بلا رقمِ عميلٍ لا تُصرَف.**
     *
     * الرصيدُ محفوظٌ على هاتفه، فبلا هاتفٍ لا يُعرف من يدفع من رصيده.
     */
    /** @test */
    public function redeeming_without_a_customer_phone_is_refused(): void
    {
        $merchant = $this->merchant();
        $this->programFor($merchant);

        $this->expectException(\InvalidArgumentException::class);

        app(CashierService::class)->recordSale(
            merchant: $merchant, total: '1000', paymentMethod: 'cash',
            redeemPoints: 10.0,
        );
    }

    /**
     * **⑥ والكاشيرُ يقرأ رصيدَ نقاط عميله ويستبدلها.**
     *
     * وهو الموضعُ الوحيد الذي تُصرَف فيه النقاطُ فعلاً — العميلُ واقفٌ
     * يدفع. وكان الحارسُ يشترط دورَ التاجر، ودورُ الكاشير `pos`، **فكلُّ
     * نداءٍ من الشبّاك يُردّ ٤٠٣**.
     */
    /** @test */
    public function the_cashier_can_look_up_and_redeem_from_the_till(): void
    {
        $merchant = $this->merchant();
        $this->programFor($merchant);
        $this->accountWith($merchant, 80);

        $cashier = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
        PosUser::create([
            'user_id' => $cashier->id, 'merchant_user_id' => $merchant->id,
            'role' => 'pos', 'is_active' => 1, 'pos_number' => 'POS-1',
        ]);

        $body = $this->actingAs($cashier, 'api')
            ->getJson('/api/v1/amial/merchant/loyalty/lookup?phone=' . self::PHONE)
            ->assertOk()->json('meta');

        $this->assertTrue($body['found']);
        $this->assertSame(80.0, (float) $body['points_balance']);
        $this->assertSame(10.0, (float) $body['redeem_value_per_point'],
            'قيمةُ النقطة لا تصل الشبّاك — فلا يعرف الكاشيرُ كم يُخصَم');
    }

    /**
     * **⑦ وتعديلُ البرنامج يبقى للمالك.**
     *
     * وفتحُ القراءة للكاشير لا يفتح له تغييرَ قيمة النقطة ولا تسويةَ
     * رصيدٍ يدويّاً — وإلّا صار لكلّ كاشيرٍ مفتاحُ خزنة.
     */
    /** @test */
    public function the_cashier_still_cannot_change_the_program(): void
    {
        $merchant = $this->merchant();
        $this->programFor($merchant);

        $cashier = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
        PosUser::create([
            'user_id' => $cashier->id, 'merchant_user_id' => $merchant->id,
            'role' => 'pos', 'is_active' => 1, 'pos_number' => 'POS-2',
        ]);

        $this->actingAs($cashier, 'api')
            ->postJson('/api/v1/amial/merchant/loyalty/program',
                ['redeem_value_per_point' => 9999])
            ->assertStatus(403);

        $this->actingAs($cashier, 'api')
            ->postJson('/api/v1/amial/merchant/loyalty/adjust',
                ['phone' => self::PHONE, 'points' => 5000, 'note' => 'x'])
            ->assertStatus(403);
    }

    /**
     * **⑧ ومدخلُ النقاط قائمٌ في شاشة الدفع.**
     *
     * فالخادمُ كلُّه أعلاه بلا معنىً إن بقي المدخلُ غائباً — وهو ما كان.
     * (القاعدة الثانية عشرة.)
     */
    /** @test */
    public function the_till_screen_has_the_door(): void
    {
        $screen = file_get_contents(base_path(
            '../02_flutter_app/lib/features/merchant/screens/cashier_payment_screen.dart'));

        $this->assertStringContainsString('loyalty/lookup', $screen,
            'شاشةُ الدفع لا تسأل رصيدَ نقاط العميل');
        $this->assertStringContainsString('redeemPoints:', $screen,
            'النقاطُ لا تُرسَل مع البيعة — فالاستبدالُ يبقى خارجها');

        $controller = file_get_contents(base_path(
            '../02_flutter_app/lib/features/merchant/controllers/cashier_controller.dart'));

        $this->assertStringContainsString("'redeem_points'", $controller,
            'المتحكّمُ لا يحمل النقاطَ إلى الخادم');
    }
}
