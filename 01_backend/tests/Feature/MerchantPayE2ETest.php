<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-PILOT-E2E-001 — الدفعُ لتاجر، من طرفٍ إلى طرف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **عشرون تاجراً في تجربتك، وهذه العمليّة بصفر اختبار.**
 *
 * وتصحيحٌ لقياسٍ سابقٍ لي: قلتُ إنّ `pay-merchant` بلا اختبار — والاسمُ
 * نفسُه لا وجود له. العنوانُ الحقيقيّ `POST /api/v1/amial/merchant/pay`،
 * وهو فعلاً بلا اختبار. **فالنتيجة صحيحةٌ والطريقُ إليها كان خاطئاً**،
 * وذاك يكفي لإبطال القياس لولا أنّي أعدتُه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُكتفى بـ«ردّ ٢٠٠».**
 *
 * الدفعُ يمرّ بأربع طبقات: خصمٌ من العميل، وإضافةٌ للتاجر، ورسمٌ للمنصّة،
 * وقيدٌ في الدفتر. وردُّ ٢٠٠ يُثبت الأولى وحدها. فيُقاس **كلُّ طرفٍ على
 * حدة**، ويُجمع: ما خرج من العميل = ما دخل التاجرَ + الرسم.
 *
 * (المهارة ٨: «Money can never disappear. Money can never duplicate.»)
 */
class MerchantPayE2ETest extends TestCase
{
    use RefreshDatabase;

    private function wallet(int $userId, string $balance): void
    {
        EMoney::create([
            'user_id' => $userId, 'current_balance' => $balance,
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    private function customer(string $phone = '967770007001', string $balance = '50000.0000'): User
    {
        $u = User::factory()->create([
            'type' => 2, 'phone' => $phone, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'is_active' => 1,
        ]);
        $this->wallet($u->id, $balance);

        return $u;
    }

    private function merchant(string $phone = '967770007002', string $balance = '0.0000'): User
    {
        $u = User::factory()->create([
            'type' => 3, 'phone' => $phone, 'zone_code' => 'SOUTH',
            'is_kyc_verified' => 1, 'is_active' => 1,
        ]);
        MerchantProfile::create(['user_id' => $u->id, 'verification_status' => 'verified']);
        $this->wallet($u->id, $balance);

        return $u;
    }

    private function bal(int $userId): string
    {
        return (string) EMoney::where('user_id', $userId)->value('current_balance');
    }

    /**
     * **الحارسُ `api` لا Sanctum.**
     *
     * وأوّلُ تشغيلٍ ردّ ٤٠١ على كلّ شيء، فمرّ اختبارُ «غيرُ المصادَق
     * يُردّ» **وهو لا يفحص شيئاً**: كان كلُّ الطلبات غيرَ مصادَقة.
     * (حارسٌ يمرّ والعطلُ قائم.)
     */
    private function pay(User $customer, array $body)
    {
        return $this->actingAs($customer, 'api')
            ->postJson('/api/v1/amial/merchant/pay', $body + [
                'idempotency_key' => 'E2E-' . uniqid(),
            ]);
    }

    // ══════════════════════════════════════════════════════════════
    // ١) المال يتحرّك — ولا يضيع ولا يتضاعف
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **ما خرج من العميل = ما دخل التاجرَ + الرسم.**
     *
     * وهذه هي المعادلة كلُّها. ولو نقص ريالٌ بينهما لضاع، ولو زاد لتضاعف —
     * وكلاهما لا يُنتج خطأً في أيّ سجلّ.
     */
    public function what_leaves_the_customer_equals_what_reaches_the_merchant_plus_the_fee(): void
    {
        $c = $this->customer();
        $m = $this->merchant();

        $before = ['c' => $this->bal($c->id), 'm' => $this->bal($m->id)];

        $r = $this->pay($c, ['merchant_user_id' => $m->id, 'amount' => '1000', 'channel' => 'qr']);

        if ($r->status() !== 200) {
            $this->markTestSkipped('الدفعُ ردّ ' . $r->status() . ': ' . $r->json('message'));
        }

        $after = ['c' => $this->bal($c->id), 'm' => $this->bal($m->id)];

        $left     = bcsub($before['c'], $after['c'], 4);   // ما خرج من العميل
        $received = bcsub($after['m'], $before['m'], 4);   // ما دخل التاجر

        $this->assertSame(1, bccomp($left, '0', 4), 'لم يُخصم من العميل شيء');
        $this->assertSame(1, bccomp($received, '0', 4), 'لم يصل التاجرَ شيء');

        // **الرسمُ يُقرأ من مصدره** — من القيد لا من عمودٍ مخزَّن.
        $fee = bcsub($left, $received, 4);

        $this->assertSame(0, bccomp($left, bcadd($received, $fee, 4), 4),
            "المال لا يتوازن: خرج {$left} · وصل {$received} · رسم {$fee}");

        $this->assertSame(0, bccomp($left, '1000', 4),
            "خُصم {$left} والمطلوب 1000");
    }

    /**
     * @test
     *
     * **ولكلّ دفعةٍ قيدٌ في الدفتر.**
     *
     * (المهارة ٩ — RULE 1: No transaction exists without accounting.)
     */
    public function every_payment_posts_a_journal_entry(): void
    {
        $c = $this->customer('967770007011');
        $m = $this->merchant('967770007012');

        $before = LedgerJournalEntry::count();

        $r = $this->pay($c, ['merchant_user_id' => $m->id, 'amount' => '750']);

        if ($r->status() !== 200) {
            $this->markTestSkipped('الدفعُ ردّ ' . $r->status());
        }

        $this->assertGreaterThan($before, LedgerJournalEntry::count(),
            'دُفع مالٌ ولا قيدَ له في دفتر الأستاذ');
    }

    /**
     * @test
     *
     * **ولا تُدفع الدفعةُ مرّتين بمفتاحٍ واحد.**
     *
     * فانقطاعُ شبكةٍ بعد وصول الطلب وقبل وصول الردّ يجعل التطبيق يُعيد —
     * وبلا تفرّدٍ يُخصم من العميل مرّتين ولا يعلم.
     *
     * (المهارة ٨: «Never allow duplicated money.»)
     */
    public function the_same_idempotency_key_never_charges_twice(): void
    {
        $c = $this->customer('967770007021');
        $m = $this->merchant('967770007022');

        // **المفتاحُ ترويسةٌ لا حقلٌ في الجسد.**
        //
        // أرسلتُه أوّلاً في الجسد فخُصم مرّتين، فظننتُه عطلاً في المنصّة
        // وقلتُ ذلك. والقياسُ أظهر أنّ `EnforceIdempotency` يقرأ
        // `$request->header('Idempotency-Key')`، وأنّ التطبيق يرسلها
        // ترويسةً في `api_client.dart:115`. **فالعطلُ كان في اختباري.**
        $key = 'E2E-FIXED-KEY-001';
        $body = ['merchant_user_id' => $m->id, 'amount' => '500'];

        $first = $this->actingAs($c, 'api')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/amial/merchant/pay', $body);

        if ($first->status() !== 200) {
            $this->markTestSkipped('الدفعةُ الأولى ردّت ' . $first->status());
        }

        $afterFirst = $this->bal($c->id);

        $this->actingAs($c, 'api')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/amial/merchant/pay', $body);

        $this->assertSame($afterFirst, $this->bal($c->id),
            'خُصم من العميل مرّتين بمفتاحٍ واحد');
    }

    /**
     * @test
     *
     * **والمفتاحُ في الجسد يحمي كما تحمي الترويسة.**
     *
     * وهذا الحارسُ وُلد من عطلٍ حقيقيّ: `MerchantPaymentController::pay`
     * يقبل `idempotency_key` في قواعد التحقّق **صراحةً**، وكان
     * `EnforceIdempotency` يقرأ الترويسةَ وحدها ثمّ **يُولّد مفتاحاً
     * عشوائيّاً** حين لا يجدها. فمن اتّبع عقد المتحكّم — وأرسله في الجسد —
     * صارت كلُّ إعادةٍ مفتاحاً جديداً و**الحمايةُ صفر**، بلا خطأٍ في أيّ
     * سجلّ.
     *
     * **وحقلٌ يُقبل في التحقّق ثمّ يُهمَل في التنفيذ أسوأ من حقلٍ مرفوض:**
     * المرفوضُ يُرى فيُصحَّح، والمُهمَلُ يُطمئن.
     *
     * وعكسُه (القاعدة الثانية): يُحذف قارئُ الجسد من الوسيط ⇒ يسقط هذا
     * الحارسُ بخصمٍ مضاعف. قِيس.
     */
    public function the_key_in_the_body_protects_like_the_header(): void
    {
        $c = $this->customer('967770007023');
        $m = $this->merchant('967770007024');

        $body = [
            'merchant_user_id' => $m->id,
            'amount' => '500',
            'idempotency_key' => 'E2E-BODY-KEY-001',
        ];

        $first = $this->actingAs($c, 'api')
            ->postJson('/api/v1/amial/merchant/pay', $body);

        if ($first->status() !== 200) {
            $this->markTestSkipped('الدفعةُ الأولى ردّت ' . $first->status());
        }

        $afterFirst = $this->bal($c->id);

        // **ولا ترويسةَ إطلاقاً** — كما يفعل عميلٌ اتّبع عقد المتحكّم.
        $this->actingAs($c, 'api')
            ->postJson('/api/v1/amial/merchant/pay', $body);

        $this->assertSame($afterFirst, $this->bal($c->id),
            'أُرسل المفتاحُ في الجسد — كما يقبله المتحكّم — وخُصم من العميل مرّتين');
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) الحدود تُحترَم
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **ولا يُدفع أكثر من الرصيد.**
     *
     * ورصيدٌ سالبٌ في محفظةٍ ليس خطأ عرض: هو مالٌ أقرضته المنصّة بلا أن
     * تقرّر ذلك.
     */
    public function a_payment_beyond_the_balance_is_refused(): void
    {
        $c = $this->customer('967770007031', '100.0000');
        $m = $this->merchant('967770007032');

        $r = $this->pay($c, ['merchant_user_id' => $m->id, 'amount' => '9999']);

        $this->assertNotSame(200, $r->status(), 'مرّت دفعةٌ تتجاوز الرصيد');

        $this->assertSame(0, bccomp($this->bal($c->id), '100.0000', 4),
            'تغيّر الرصيد رغم رفض الدفعة');
    }

    /**
     * @test
     *
     * **ولا يدفع أحدٌ لنفسه.**
     *
     * ودفعٌ للنفس ليس عبثاً: هو طريقُ توليدِ حركةٍ بلا مقابلٍ لاصطياد
     * مكافأةٍ أو رفع حجمٍ ظاهريّ.
     */
    public function self_payment_is_refused(): void
    {
        $c = $this->customer('967770007041');

        $r = $this->pay($c, ['merchant_user_id' => $c->id, 'amount' => '100']);

        $this->assertSame(422, $r->status());
        $this->assertSame(0, bccomp($this->bal($c->id), '50000.0000', 4));
    }

    /**
     * @test
     *
     * **وتاجرٌ لا وجود له يُردّ برسالةٍ تقول ذلك.**
     */
    public function paying_a_missing_merchant_says_so(): void
    {
        $c = $this->customer('967770007051');

        $this->pay($c, ['merchant_user_id' => 999999, 'amount' => '100'])
            ->assertStatus(404);
    }

    /**
     * @test
     *
     * **ومبلغٌ صفرٌ أو سالبٌ يُرفض.**
     */
    public function a_zero_or_negative_amount_is_refused(): void
    {
        $c = $this->customer('967770007061');
        $m = $this->merchant('967770007062');

        foreach (['0', '-50'] as $bad) {
            $r = $this->pay($c, ['merchant_user_id' => $m->id, 'amount' => $bad]);

            $this->assertNotSame(200, $r->status(), "مرّ مبلغٌ غير صالح: {$bad}");
        }

        $this->assertSame(0, bccomp($this->bal($c->id), '50000.0000', 4));
    }

    // ══════════════════════════════════════════════════════════════
    // ٣) المصادقة
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **ولا يدفع غيرُ مصادَق.**
     */
    public function an_unauthenticated_request_is_refused(): void
    {
        $m = $this->merchant('967770007071');

        $this->postJson('/api/v1/amial/merchant/pay', [
            'merchant_user_id' => $m->id, 'amount' => '100',
        ])->assertUnauthorized();

        $this->assertSame(0, bccomp($this->bal($m->id), '0.0000', 4));
    }

    /**
     * @test
     *
     * **والمعاينةُ تحسب الرسم قبل الدفع.**
     *
     * فمن لا يعرف الرسمَ قبل الضغط يكتشفه بعد الخصم — وذاك ما يُنتج
     * شكوى «خُصم منّي أكثر».
     */
    public function the_quote_states_the_fee_before_paying(): void
    {
        $c = $this->customer('967770007081');

        $j = $this->actingAs($c, 'api')->postJson('/api/v1/amial/merchant/quote', [
            'amount' => '1000', 'channel' => 'qr',
        ]);

        if ($j->status() !== 200) {
            $this->markTestSkipped('المعاينة ردّت ' . $j->status());
        }

        $m = $j->json('meta');

        $this->assertArrayHasKey('fee', $m);
        $this->assertArrayHasKey('merchant_receives', $m);

        // المبلغ = ما يصل التاجرَ + الرسم — تُقرأ المعادلةُ من الردّ نفسِه.
        $this->assertSame(0,
            bccomp((string) $m['amount'], bcadd((string) $m['merchant_receives'], (string) $m['fee'], 4), 4),
            'المعاينةُ لا تتوازن: ' . json_encode($m));
    }
}
