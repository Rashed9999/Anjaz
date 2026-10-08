<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\MerchantService;
use App\Support\Access\AccessConstants as A;
use App\Traits\TransactionTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-RECEIVE-LIMIT-002 — **تحويلٌ إلى تاجرٍ: يُقال ويُحَدّ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤال بنصّه:** «هل التاجر يستطيع استقبال تحويلات عادية دون فواتير؟
 * قد يؤثّر ذلك على تنظيم المحفظة الماليّة بتحويلات دون مبيعات. قد أوقفنا
 * سابقاً أرقام الوكلاء لا تستقبل تحويلات، والآن ماذا عن التجّار؟»
 *
 * **وقِيس فخرج ثقبان لا واحد:**
 *
 * ① **`send_money` كان في قائمة مصادر «مبيعات اليوم»** — فمالٌ يرسله
 *   قريبٌ إلى التاجر يُكتب بيعاً في بطاقته. والرقمُ الذي يبني عليه
 *   يومَه يحمل ما لم يُبَع، ولا شيءَ في الشاشة يقول ذلك.
 *
 * ② **حدُّ استلام التاجر كان يُفرَض على `pay_merchant` وحدَها.** فمن
 *   أراد تجاوزَ حدٍّ أرسل تحويلاً عاديّاً — والحدُّ يُضبَط في اللوحة
 *   ويُعرَض في مركز ٣٦٠ **ولا يعمل على هذا الباب**. وحاجزٌ يُعرَض ولا
 *   يعمل أسوأ من غيابه.
 *
 * **ولم يُمنَع التحويلُ إلى التاجر** — بخلاف الوكيل. والفرقُ مقيسٌ لا
 * مذوق: محفظةُ الوكيل طرفٌ في معادلة تسوية (فلوتٌ مقابل نقد)، فمالٌ
 * يدخلها بلا قيدِ إيداعٍ يُنتج **فائضاً وهميّاً دائماً**. ومحفظةُ
 * التاجر ليست طرفاً في معادلةٍ كهذه: القيدُ المزدوج يتوازن في الحالين.
 * فالعلاجُ **الصدقُ والحدّ** لا المنع.
 */
class MerchantTransfersAreNotSalesGuardTest extends TestCase
{
    use RefreshDatabase, TransactionTrait;

    /**
     * **بوّابةُ مقعد الجهاز تُطفَأ هنا عمداً — ويُقال لماذا.**
     *
     * `EnsurePosDevice` تردّ ٤٠٣ لكلّ موظّفٍ لا ربطَ لجلسته بجهاز،
     * والربطُ يُقرأ من معرِّف رمز Passport و`actingAs` لا تُصدر رمزاً.
     * فلو تُرك الإنفاذُ لَخرج الفحصُ ⑤ أخضرَ **لأنّ الطلبَ رُدّ عند
     * الباب** — والحقلُ المقصودُ لم يُفحَص أصلاً. وهي محروسةٌ في موضعها
     * (`PosDeviceEnforcementTest`).
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('amial.pos_devices.enforce_session_binding', false);
    }

    private function merchant(string $single = '1000000', string $daily = '5000000'): User
    {
        $user = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $user->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
            'single_receive_limit' => $single, 'daily_receive_limit' => $daily,
        ]);

        return $user;
    }

    private function customerWith(string $balance): User
    {
        $user = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'is_active' => 1,
            'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        EMoney::updateOrCreate(['user_id' => $user->id],
            ['current_balance' => $balance, 'zone_code' => 'SOUTH']);

        return $user;
    }

    /**
     * **① تحويلٌ إلى تاجرٍ لا يُكتب بيعاً.**
     */
    /** @test */
    public function a_plain_transfer_is_not_counted_as_a_sale(): void
    {
        $merchant = $this->merchant();
        $sender = $this->customerWith('500000');

        $this->customer_send_money_transaction($sender->id, $merchant->id, '50000', '0');

        $stats = app(MerchantService::class)->getDailyStats($merchant->fresh());

        $this->assertSame(0.0, (float) $stats['today_sales'],
            'تحويلٌ عاديٌّ ظهر في «مبيعات اليوم» — ورقمٌ يبني عليه صاحبُ '
            . 'المتجر يومَه يحمل ما لم يُبَع');

        $this->assertSame(50000.0, (float) $stats['today_transfers_in'],
            'ولا يُطوى المال: يُعرَض تحويلاً وارداً باسمه (القاعدة السابعة)');
    }

    /**
     * **② والصافي لا يحمله كذلك** — «صافي المبيعات» رقمُ تشغيلٍ لا رقمُ
     * محفظة، وخلطُهما هو أصلُ المسألة.
     */
    /** @test */
    public function the_net_figure_stays_a_sales_figure(): void
    {
        $merchant = $this->merchant();
        $sender = $this->customerWith('500000');

        $this->customer_send_money_transaction($sender->id, $merchant->id, '40000', '0');

        $stats = app(MerchantService::class)->getDailyStats($merchant->fresh());

        $this->assertSame(0.0, (float) $stats['today_net']);
    }

    /**
     * **③ وحدُّ الاستلام يُفرَض على التحويل كما على الدفع.**
     *
     * وهذا هو الثقبُ الحقيقيّ: الحدُّ مضبوطٌ ومعروضٌ ولا يعمل على هذا
     * الباب، فيُتجاوَز بتحويلٍ عاديّ.
     */
    /** @test */
    public function the_merchant_receive_limit_applies_to_transfers_too(): void
    {
        $merchant = $this->merchant(single: '10000');
        $sender = $this->customerWith('500000');

        $this->expectException(\Throwable::class);

        $this->customer_send_money_transaction($sender->id, $merchant->id, '90000', '0');
    }

    /**
     * **④ ولا يمسّ ذلك التحويلَ بين الأفراد.**
     *
     * والفحصُ ليس زينةً: شرطُ «المستلِمُ تاجر» لو سقط لَصار كلُّ تحويلٍ
     * بين عميلين يسأل عن حدٍّ لا وجودَ له.
     */
    /** @test */
    public function a_customer_to_customer_transfer_is_untouched(): void
    {
        $sender = $this->customerWith('500000');
        $receiver = $this->customerWith('0');

        $this->customer_send_money_transaction($sender->id, $receiver->id, '90000', '0');

        $this->assertSame(90000.0, (float) EMoney::where('user_id', $receiver->id)
            ->value('current_balance'));
    }

    /**
     * **⑤ وموظّفُ نقطة البيع لا يرى تحويلات المالك.**
     *
     * مالٌ يصل صاحبَ المتجر من خارج الكاشير، ولا شأنَ للكاشير به وهو
     * يُقفل ورديّتَه. **ويُحذَف ولا يُصفَّر.**
     */
    /** @test */
    public function pos_staff_do_not_see_the_owners_incoming_transfers(): void
    {
        $merchant = $this->merchant();
        $sender = $this->customerWith('500000');
        $this->customer_send_money_transaction($sender->id, $merchant->id, '30000', '0');

        $pos = User::factory()->create([
            'type' => 4, 'role' => 'pos', 'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);
        \App\Models\PosUser::create([
            'user_id' => $pos->id, 'merchant_user_id' => $merchant->id,
            'role' => 'pos', 'is_active' => 1, 'pos_number' => 'POS-1',
        ]);

        $body = $this->actingAs($pos, 'api')
            ->getJson('/api/v1/amial/merchant/daily-stats')->assertOk()->json('meta');

        $this->assertArrayNotHasKey('today_transfers_in', $body,
            'تحويلاتُ المالك وصلت شاشةَ الكاشير');
        $this->assertArrayNotHasKey('current_balance', $body);
        $this->assertArrayHasKey('today_sales', $body,
            'ومبيعاتُ اليوم تبقى — يحتاجها ليُقفل ورديّتَه');
    }
}
