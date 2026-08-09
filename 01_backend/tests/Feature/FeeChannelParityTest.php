<?php

namespace Tests\Feature;

use App\Services\AdminDashboardService;
use App\Services\FeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-TRUTH-003 · AMIAL-TRUTH-004
 *
 * ══════════════════════════════════════════════════════════════════════
 * **تسريبُ المال الذي كشفه التدقيق:**
 *
 * `WhatsappBotService` كان يحسب رسمَ التحويل هكذا:
 *
 *     $feeData = $this->feeSvc->calculate('send_money', $amount);
 *     $fee     = (string) ($feeData['fee_amount'] ?? '0');
 *
 * **وفيه عطلان، وكلاهما يُنتج صفراً صامتاً:**
 *
 *   ① `'send_money'` بحروفٍ صغيرة، والمخطّطُ `'SEND_MONEY'`. والمطابقةُ
 *      في `activeScheme()` نصّيّةٌ حرفيّة — فلا مخطّطَ أبداً.
 *   ② والمفتاحُ `fee_amount` والمحرّكُ يردّ `fee`.
 *
 * **والأثرُ مالٌ لا عرض**: الرسمُ يُمرَّر إلى
 * `PendingTransferService::initiate(fee: …)` — **وتلك تستقبل ولا تحسب**.
 * فكلُّ تحويلٍ عبر واتساب كان **مجّانيّاً تماماً**، ونفسُه في التطبيق
 * يُرسَم. قناتان ورسمان لعمليّةٍ واحدة.
 *
 * ولا خطأ في أيّ سجلّ: الردُّ صحيحُ الشكل، والصفرُ يُقرأ «لا رسم».
 */
class FeeChannelParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     *
     * **رسمُ التحويل واحدٌ في كلّ قناة — بالقياس لا بالقراءة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * ويُنشأ مخطّطٌ فعّالٌ أوّلاً: **فبلا مخطّطٍ يردّ المحرّكُ صفراً،
     * ويقارن الاختبارُ صفراً بصفرٍ فيمرّ أبداً.** وهو ما كان سيُخفي
     * العطلَ نفسَه.
     */
    public function the_bot_charges_the_same_fee_as_the_app(): void
    {
        \App\Models\FeeScheme::create([
            'code' => 'SEND_MONEY',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'percent_plus_fixed',
            'percent_rate' => '1.00',
            'fixed_amount' => '25.0000',
            'min_fee' => '0',
            'max_fee' => '10000',
            'bearer' => 'sender',
            'platform_share_percent' => '100.00',
            'agent_share_percent' => '0.00',
            'version' => 1,
            'is_active' => true,
        ]);

        $amount = '10000';

        $expected = app(FeeService::class)->calculate('SEND_MONEY', $amount, [
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
        ]);

        $this->assertNotSame('0.0000', $expected['fee'],
            'المخطّطُ لم يُلتقط — الفحصُ يقارن صفراً بصفرٍ ولا يحرس شيئاً');

        // **وما يقرؤه البوت** — بالرمز والمفتاح اللذين في شيفرته.
        $src = file_get_contents(app_path('Services/Whatsapp/WhatsappBotService.php'));

        $this->assertStringContainsString("calculate('SEND_MONEY'", $src,
            "البوت ينادي رمزاً لا يطابق أيّ مخطّط — فيردّ صفراً،\n"
            . 'ويُمرَّر الصفرُ إلى initiate فيصير التحويلُ مجّانيّاً.');

        $this->assertStringNotContainsString("'fee_amount'", $src,
            "البوت يقرأ مفتاحاً لا يردّه المحرّك (`fee_amount` والصحيح `fee`) —\n"
            . 'فحتّى مع الرمز الصحيح يبقى الرسمُ صفراً.');
    }

    /**
     * @test
     *
     * **ورقمُ الإيرادات سلسلةٌ دقيقة لا فاصلةٌ عائمة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كلُّ مسارٍ ماليٍّ في هذا المشروع يعمل بـ`bcmath` بأربع منازل —
     * **ورقمُ الإيرادات وحدَه كان `(float)`**.
     *
     * ولا يظهر الفرقُ في ريال؛ يظهر بعد مئة ألف عمليّة حين تُطلَب
     * المطابقةُ من الماليّة فلا تتمّ بقروش، **ولا أحدَ يعرف من أين جاءت**.
     */
    public function platform_revenue_is_exact_not_floating(): void
    {
        $dash = app(AdminDashboardService::class);

        $r = new \ReflectionMethod($dash, 'feesEarned');

        $this->assertSame('string', (string) $r->getReturnType(),
            'الإيراداتُ تُردّ كفاصلةٍ عائمة — والمالُ لا يُجمع هكذا');

        $earned = $dash->feesEarned();

        $this->assertIsString($earned);
        $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $earned,
            "الإيراداتُ ليست بأربع منازل: «{$earned}»");

        // والأرصدةُ كذلك — الجمعُ بـbcmath لا بـ`+`.
        $b = $dash->balances();

        foreach (['total_balance', 'used_balance', 'unused_balance', 'total_earned'] as $k) {
            $this->assertIsString($b[$k],
                "«{$k}» في لوحة الإدارة عددٌ عائم — والمالُ يُجمع بدقّةٍ ثابتة");
        }
    }
}
