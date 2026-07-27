<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-CONTRACT-001 — عقد الدفع للتاجر كما يقرؤه التطبيق.
 *
 * الدفع للتاجر يختلف عن التحويل في نقطة واحدة تجعل عقده أحرج: **الطرفان
 * يريان رقمين مختلفين**. العميل يدفع مبلغاً، والتاجر يستلم أقلّ منه بعد
 * الرسوم. فالشاشة تعرض `merchant_receives` و`fee` بجانب `amount`.
 *
 * ومفتاح ناقص من هذه الثلاثة لا يُسقط شيئاً — يعرض فراغاً أو صفراً. فيرى
 * التاجر «تستلم 0» على دفعة صحيحة، أو لا يرى الرسوم فيظنّها صفراً ويكتشف
 * الفرق في التسوية. وكلاهما خلافٌ مع عميل واقف أمامه.
 *
 * المفاتيح مستخرَجة من شاشات التطبيق: meta.fee و meta.merchant_receives و
 * meta.transaction_id و meta.id.
 */
class MerchantPayPayloadContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('amial.encryption.pii_key', base64_encode(random_bytes(32)));
        config()->set('amial.encryption.blind_index_key', base64_encode(random_bytes(32)));
    }

    private function customer(string $balance = '50000'): User
    {
        $u = User::factory()->create([
            'type' => 2, 'role' => 'customer', 'zone_code' => 'SOUTH',
            'kyc_tier' => 3, 'sanction_status' => 'clear', 'sanction_checked' => true,
            'transaction_pin' => Hash::make('1234'), 'is_active' => 1,
        ]);
        EMoney::create(['user_id' => $u->id, 'current_balance' => $balance, 'zone_code' => 'SOUTH']);
        return $u;
    }

    /**
     * المعاينة قبل الدفع: العميل يرى كم يدفع وكم يستلم التاجر قبل أن يؤكّد.
     */
    public function test_the_quote_returns_what_both_sides_will_see(): void
    {
        $meta = $this->actingAs($this->customer(), 'api')
            ->postJson('/api/v1/amial/merchant/quote', ['amount' => '5000', 'channel' => 'qr'])
            ->assertOk()->json('meta');

        foreach (['fee', 'merchant_receives'] as $key) {
            $this->assertArrayHasKey($key, $meta,
                "التطبيق يقرأ meta.$key — بدونه تُعرض الرسوم صفراً أو فراغاً");
        }
    }

    /**
     * الحساب متّسق: ما يدفعه العميل = ما يستلمه التاجر + الرسوم.
     *
     * ثلاثة أرقام تُعرض معاً على شاشة واحدة، فتناقضها يُرى فوراً ويُفقد
     * الثقة أسرع من أي عطل.
     */
    public function test_the_three_numbers_add_up(): void
    {
        $meta = $this->actingAs($this->customer(), 'api')
            ->postJson('/api/v1/amial/merchant/quote', ['amount' => '5000', 'channel' => 'qr'])
            ->assertOk()->json('meta');

        $fee = (float) $meta['fee'];
        $net = (float) $meta['merchant_receives'];

        $this->assertEqualsWithDelta(5000.0, $net + $fee, 0.01,
            'المعروض لا يجمع: العميل يدفع 5000 والتاجر يستلم ' . $net . ' والرسوم ' . $fee);
        $this->assertGreaterThanOrEqual(0, $fee, 'رسوم سالبة تعني ربح العميل من الدفع');
        $this->assertLessThanOrEqual(5000.0, $net, 'التاجر لا يستلم أكثر ممّا دُفع');
    }

    /** مبلغ صفر أو سالب لا يُقبل — أوّل ما يُجرَّب على أي مسار دفع. */
    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        foreach (['0', '-100'] as $bad) {
            $this->actingAs($this->customer(), 'api')
                ->postJson('/api/v1/amial/merchant/quote', ['amount' => $bad, 'channel' => 'qr'])
                ->assertStatus(422);
        }
    }

    /** المعاينة لا تحرّك مالاً — لو حرّكته لخُصم من كل من فتح الشاشة. */
    public function test_the_quote_moves_no_money(): void
    {
        $customer = $this->customer('50000');

        $this->actingAs($customer, 'api')
            ->postJson('/api/v1/amial/merchant/quote', ['amount' => '5000', 'channel' => 'qr'])
            ->assertOk();

        $this->assertSame('50000.0000',
            (string) EMoney::where('user_id', $customer->id)->first()->current_balance,
            'المعاينة خصمت من الرصيد — وهي عرضٌ لا تنفيذ');
    }

    /** الغلاف الموحّد: التطبيق يفحص success قبل قراءة meta. */
    public function test_the_response_carries_the_standard_envelope(): void
    {
        $body = $this->actingAs($this->customer(), 'api')
            ->postJson('/api/v1/amial/merchant/quote', ['amount' => '5000', 'channel' => 'qr'])
            ->assertOk()->json();

        foreach (['success', 'code', 'message', 'meta'] as $key) {
            $this->assertArrayHasKey($key, $body, "ينقص $key من الغلاف");
        }
        $this->assertTrue($body['success']);
    }

    /** بلا مصادقة لا معاينة — الرسوم إعداد تجاري لا يُقرأ من الشارع. */
    public function test_an_anonymous_caller_gets_nothing(): void
    {
        $this->postJson('/api/v1/amial/merchant/quote', ['amount' => '5000'])
            ->assertStatus(401);
    }
}
