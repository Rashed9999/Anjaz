<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Services\RecipientVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-TRANSFER-KIND-001 — **التحويلُ بين الأفراد لا يبلغ حسابَ وكيل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **كشفه صاحبُ المشروع بالاستعمال:** حوّل من حساب عميلٍ إلى **رقم حساب
 * وكيل** فنجح التحويل. ولم يكن في المشروع فحصٌ واحدٌ لنوع الحساب في
 * مسار التحويل — لا في `RecipientVerificationService` ولا في المتحكّم
 * ولا في `customer_send_money_transaction`.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ هي كارثةٌ لا هفوة — أربعةُ آثارٍ، كلٌّ منها كافٍ وحدَه:**
 *
 * ① **فائضٌ وهميٌّ دائمٌ في التسوية.** محرّكُ التسوية يقرأ «الفعليّ» من
 *    محافظ الوكيل وفروعه، و«المتوقَّع» من تسويات المنصّة معه. فرصيدٌ
 *    يدخل محفظةَ الوكيل من **عميل** يرفع الفعليَّ ولا يرفع المتوقَّع —
 *    فيظهر فائضٌ لا مصدرَ له، **ولا يزول أبداً**. وهو بعينه العطلُ
 *    المكتوب في القاعدة العاشرة، واقعاً من بابٍ آخر.
 *
 * ② **سحبٌ نقديٌّ بلا سجلِّ سحب.** يعطي الوكيلُ العميلَ نقداً ويقول
 *    «حوّل لي»، فيتمّ سحبٌ نقديٌّ حقيقيّ **بلا رسمٍ ولا عمولةِ وكيلٍ ولا
 *    قيدِ `cash_out` ولا حدٍّ يوميّ ولا فحصِ غسلٍ لعمليّات السحب**. وفي
 *    الدفاتر «تحويلٌ بين شخصين».
 *
 * ③ **الرصيدُ الإلكترونيّ يُخلق خارج الخزانة.** الفلوتُ يُصدَر من
 *    المنصّة مقابل سيولة؛ وهذا المسارُ يُدخل فلوتاً إلى الوكيل مقابل
 *    **لا شيءَ في الخزانة**.
 *
 * ④ **والاتّجاهُ المعاكس أسوأ**: وكيلٌ يحوّل لعميلٍ = إيداعٌ نقديٌّ بلا
 *    قيدِ `cash_in` — نقدٌ دخل جيبَ الوكيل ولم يدخل النظام.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والتاجرُ يبقى مستلِماً** — قبضُ التاجر عبر التحويل معاملةٌ مشروعة،
 * وشاشةُ التحقّق تُعلنه (`is_merchant`) لتقول للمرسِل إلى من يدفع.
 *
 * **وحسابُ الإدارة يُمنع كذلك** وإن لم يُذكر في البلاغ: حساباتُ المنصّة
 * تحمل رسومَها وخزانتَها، ودخولُ مالٍ إليها من تحويلٍ فرديٍّ يفسد
 * حسابَ الأرباح — والمنعُ هنا أرخصُ من كشفه لاحقاً في ميزان مراجعة.
 */
class PeerTransferRecipientKindGuardTest extends TestCase
{
    use RefreshDatabase;

    private function walletFor(User $u, string $balance = '50000.0000'): void
    {
        EMoney::create([
            'user_id' => $u->id, 'current_balance' => $balance,
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    private function user(int $type, string $phone): User
    {
        $u = User::factory()->create([
            'type' => $type, 'phone' => $phone, 'zone_code' => 'SOUTH',
            'is_active' => 1,
        ]);

        $this->walletFor($u);

        return $u->refresh();
    }

    /**
     * @test
     *
     * **حسابُ الوكيل لا يُقبل مستلِماً — ويُقال لماذا.**
     *
     * والرسالةُ تُرشد إلى الطريق الصحيح: الشبّاك. فرفضٌ صامتٌ يُرسل
     * العميلَ إلى الدعم، ورفضٌ يقول «اذهب إلى الوكيل نقداً» يُنهي الأمر.
     */
    public function a_customer_cannot_transfer_into_an_agent_account(): void
    {
        $customer = $this->user(CUSTOMER_TYPE, '967771800001');
        $agent = $this->user(AGENT_TYPE, '967771800002');

        $this->expectException(\RuntimeException::class);

        app(RecipientVerificationService::class)
            ->verifyRecipient('967771800002', $customer->id);
    }

    /**
     * @test
     *
     * **ولا حسابُ الإدارة.**
     */
    public function a_customer_cannot_transfer_into_a_platform_account(): void
    {
        $customer = $this->user(CUSTOMER_TYPE, '967771800003');
        $this->user(ADMIN_TYPE, '967771800004');

        $this->expectException(\RuntimeException::class);

        app(RecipientVerificationService::class)
            ->verifyRecipient('967771800004', $customer->id);
    }

    /**
     * @test
     *
     * **والعميلُ والتاجرُ يمرّان — وإلّا كان الحارسُ قفلاً على الجميع.**
     *
     * (القاعدة الرابعة بوجهها الآخر: حارسٌ يمنع من يحقّ له ليس حارساً
     * بل عطل.)
     */
    public function customers_and_merchants_remain_valid_recipients(): void
    {
        $sender = $this->user(CUSTOMER_TYPE, '967771800005');
        $this->user(CUSTOMER_TYPE, '967771800006');
        $this->user(MERCHANT_TYPE, '967771800007');

        $service = app(RecipientVerificationService::class);

        $toCustomer = $service->verifyRecipient('967771800006', $sender->id);
        $this->assertNotEmpty($toCustomer['verification_token'],
            'التحويلُ بين عميلين مُنع — الحارسُ صار قفلاً');
        $this->assertFalse($toCustomer['is_merchant']);

        $toMerchant = $service->verifyRecipient('967771800007', $sender->id);
        $this->assertNotEmpty($toMerchant['verification_token'],
            'الدفعُ لتاجرٍ مُنع — وهو معاملةٌ مشروعة');
        $this->assertTrue($toMerchant['is_merchant'],
            'التاجرُ لا يُعلَن تاجراً — فلا يعرف المرسِلُ إلى من يدفع');
    }

    /**
     * @test
     *
     * **والبابُ الثاني: مسارُ المال نفسُه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * شاشةُ التحقّق تُصدر رمزاً، **ومن ينادي الدالّةَ مباشرةً لا يمرّ
     * بها**: لوحةُ إدارةٍ، أو أمرٌ مجدول، أو مسارٌ يُضاف غداً. فحارسٌ في
     * الشاشة وحدَها يُغلق الباب الذي يراه ويترك الذي لا يراه.
     *
     * **ويُقاس بالمال لا بالاستثناء**: يُشغَّل التحويلُ ثمّ يُقرأ رصيدُ
     * الوكيل — فرفضٌ يرمي استثناءً **ويكون قد حرّك المال قبله** ليس
     * رفضاً. (القاعدة السادسة.)
     * ══════════════════════════════════════════════════════════════════
     */
    public function the_money_path_itself_refuses_an_agent_recipient(): void
    {
        $customer = $this->user(CUSTOMER_TYPE, '967771800008');
        $agent = $this->user(AGENT_TYPE, '967771800009');

        $before = (string) EMoney::where('user_id', $agent->id)->value('current_balance');

        $runner = new class { use \App\Traits\TransactionTrait; };

        try {
            $runner->customer_send_money_transaction(
                from_user_id: $customer->id,
                to_user_id: $agent->id,
                amount: '1000',
                charge: '0',
            );

            $this->fail('**مرّ التحويلُ إلى حساب وكيل من مسار المال مباشرةً** — '
                . 'والحارسُ في شاشة التحقّق وحدَها لا يمنع من لا يمرّ بها.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('وكيل', $e->getMessage(),
                'رُفض لسببٍ آخر — فالحارسُ المقصودُ قد لا يكون هو من ردّ');
        }

        $this->assertSame($before,
            (string) EMoney::where('user_id', $agent->id)->value('current_balance'),
            '**رُفض التحويلُ بعد أن تحرّك المال** — والرفضُ بعد الحركة ليس رفضاً');

        $this->assertSame('50000.0000',
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'),
            'خُصم من المرسِل ولم يصل أحداً — مالٌ ضاع بين طرفين');
    }


    /**
     * @test
     *
     * **والاتّجاهُ المعاكس: وكيلٌ يُرسِل إلى عميل.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهو الوجهُ الآخرُ للثقب نفسِه: فلوتٌ يخرج من الوكيل إلى عميلٍ **بلا
     * قيدِ إيداعٍ نقديّ**. أي أنّ الوكيلَ قبض نقداً من العميل وأودعه له
     * إلكترونيّاً، **ولا شيءَ في النظام يقول إنّ نقداً دخل** — فينقص
     * الفعليُّ في التسوية بلا سببٍ ظاهر، وتضيع عمولةُ الإيداع وحدُّه
     * وفحصُه.
     *
     * **ويُقاس ولا يُفترض:** إن كان الوكيلُ لا يبلغ هذا المسارَ أصلاً
     * فالباب مغلقٌ من قبلُ، ويُقال ذلك. وإن بلغه فهو ثقبٌ ثانٍ.
     * ══════════════════════════════════════════════════════════════════
     */
    public function an_agent_cannot_push_float_out_as_a_peer_transfer(): void
    {
        $agent = $this->user(AGENT_TYPE, '967771800010');
        $customer = $this->user(CUSTOMER_TYPE, '967771800011');

        $before = (string) EMoney::where('user_id', $customer->id)->value('current_balance');

        $runner = new class { use \App\Traits\TransactionTrait; };

        try {
            $runner->customer_send_money_transaction(
                from_user_id: $agent->id,
                to_user_id: $customer->id,
                amount: '1000',
                charge: '0',
            );

            $this->fail('**وكيلٌ أخرج فلوتاً بتحويلٍ فرديّ** — إيداعٌ نقديٌّ '
                . 'بلا قيدِ إيداع: لا عمولةَ ولا حدَّ ولا أثرَ لدخول النقد.');
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame($before,
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'),
            'رُفض بعد أن تحرّك المال — والرفضُ بعد الحركة ليس رفضاً');
    }

}
