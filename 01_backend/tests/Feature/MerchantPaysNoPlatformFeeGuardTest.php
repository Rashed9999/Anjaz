<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\User;
use App\Services\FeeService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FEE-MERCHANT-FREE-001 — **قبولُ الدفع مجّانيٌّ على التاجر.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **قرارُ صاحب المشروع، ٢٩ أغسطس ٢٠٢٦، بنصّه:**
 *
 *     «لا ناخذ رسوم على التاجر.. فقط الاشتراكات»
 *
 * فدخلُ المنصّة من التاجر **اشتراكُ الباقة وحدَه**، ولا استقطاعَ على
 * البيعة. وهذا الحارسُ يُثبّت القرارَ في ثلاثة مواضعَ يفترق فيها عادةً:
 *
 *   ① **التسعيرُ صفرٌ صريحٌ لا مفقود.** والفرقُ ليس شكليّاً: المفقودُ
 *      يرفع «تسعيرٌ مفقود» في مركز الأعطال مع كلّ بيعة — إنذارٌ كاذبٌ
 *      يُعوّد القارئَ تجاهلَ اللافتة يومَ تصدق.
 *   ② **والشيفرةُ تحترم المتحمِّل.** كانت تخصم من التاجر دائماً مهما
 *      قال `bearer` — ولم يعضّ لأنّ النسخة مفقودةٌ فالرسمُ صفر.
 *   ③ **ويُقاس بالمال لا بالقراءة.** رقمٌ في مصفوفةٍ ليس دليلاً؛
 *      الدليلُ أن يُشغَّل الدفعُ ويُقرأ الرصيدُ بعده.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وما لم يشمله القرار يبقى، ويُقال لئلّا يُمسّ بحسن نيّة:** رسومُ
 * العميل — التحويلُ والسحبُ والإيداعُ عبر الوكيل — اقتصادُ شبكةِ
 * الوكلاء، وقرارُ التاجر لم يتناولها.
 */
class MerchantPaysNoPlatformFeeGuardTest extends TestCase
{
    use RefreshDatabase;

    /** رموزُ قبول الدفع لدى التاجر. */
    private const MERCHANT_CODES = ['MERCHANT_QR', 'MERCHANT_POS'];

    private function seedFees(): void
    {
        $this->seed(\Database\Seeders\FeeSchemeSeeder::class);
    }

    /**
     * @test
     *
     * **① التسعيرُ صفرٌ صريحٌ — لا مفقود.**
     */
    public function accepting_payment_is_priced_at_an_explicit_zero(): void
    {
        $this->seedFees();

        foreach (self::MERCHANT_CODES as $code) {
            $breakdown = app(FeeService::class)->calculate($code, '10000', [
                'applies_to' => 'merchant',
            ]);

            $this->assertSame('explicit_zero', $breakdown['pricing_state'], sprintf(
                '«%s» حالتُه «%s» لا «صفرٌ صريح». والمفقودُ يرفع عطلَ '
                . '«تسعيرٌ مفقود» مع كلّ بيعة — وهو إنذارٌ كاذبٌ يُفقد '
                . 'اللافتةَ قيمتَها يومَ تصدق.',
                $code, $breakdown['pricing_state']));

            $this->assertSame('0.0000', MoneyService::normalize($breakdown['fee']),
                "«{$code}» يأخذ رسماً — والقرار: لا رسمَ على التاجر");
        }
    }

    /**
     * @test
     *
     * **② والشيفرةُ تحترم المتحمِّل ولا تفترضه.**
     *
     * يُسعَّر الرمزُ عمداً بنسبةٍ **وبـ`bearer = sender`**، ثمّ يُقاس من
     * يدفع. فلو أعادت الشيفرةُ الحسابَ بيدها لَخُصم من التاجر — وهو
     * العطلُ الكامن الذي لا يظهر ما دام الرسمُ صفراً.
     */
    public function when_a_fee_exists_the_scheme_decides_who_bears_it(): void
    {
        $this->seedFees();

        // **والنسخةُ القديمةُ تُوقَف أوّلاً** — قيدُ `fee_one_active`
        // يمنع نسختين نشطتين لرمزٍ ومنطقةٍ واحدة، وهو الصواب: تسعيرٌ
        // مزدوجٌ نشطٌ يجعل «كم الرسم؟» سؤالاً بجوابين.
        FeeScheme::where('code', 'MERCHANT_QR')->update(['is_active' => false]);

        // نسخةٌ أحدثُ بنسبةٍ ومتحمِّلُها الدافع.
        FeeScheme::create([
            'code' => 'MERCHANT_QR', 'label' => 'اختبار المتحمِّل',
            'zone_code' => 'SOUTH', 'applies_to' => 'merchant',
            'fee_type' => 'percent', 'percent_rate' => '2.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 2, 'is_active' => true,
            'effective_from' => now()->subMinute(),
        ]);

        $breakdown = app(FeeService::class)->calculate('MERCHANT_QR', '10000', [
            'applies_to' => 'merchant',
        ]);

        $this->assertSame('200.0000', MoneyService::normalize($breakdown['fee']),
            'لم تُقرأ النسخةُ الأحدث — الاختبار يفحص فراغاً');

        // **المتحمِّلُ الدافع** ⇒ التاجرُ يستلم كاملاً والعميلُ يدفع المبلغ + الرسم.
        $this->assertSame('10000.0000', MoneyService::normalize($breakdown['net_credit']),
            'التاجرُ لم يستلم المبلغَ كاملاً و`bearer=sender` — الرسمُ وقع عليه');
        $this->assertSame('10200.0000', MoneyService::normalize($breakdown['total_debit']),
            'الدافعُ لم يتحمّل الرسمَ وهو المتحمِّل المُعلَن');

        // ══════════════════════════════════════════════════════════════
        // **ولا يكفي أن تحسبها `FeeService` — المسارُ هو من يخصم.**
        //
        // جُرّب هذا بالعكس فمرّ في صياغته الأولى: أُعيدت الشيفرةُ إلى
        // `net = amount - fee` **ولم يسقط** — لأنّ الرسمَ صفرٌ في
        // البذرة، و`amount - 0 == amount`. أي أنّه كان يحرس الحسابَ
        // ولا يحرس الخصم.
        //
        // **فيُشغَّل الدفعُ هنا بتسعيرٍ حقيقيٍّ قائم**، ويُقاس الرصيدان.
        // (القاعدة السادسة: الرقمُ من مصدره — والمصدرُ المحفظةُ بعد
        // العمليّة لا ما تعِد به دالّة.)
        // ══════════════════════════════════════════════════════════════
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        $merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);

        foreach ([$customer->id => '50000.0000', $merchant->id => '0.0000'] as $uid => $bal) {
            EMoney::create([
                'user_id' => $uid, 'current_balance' => $bal,
                'held_balance' => '0.0000', 'pending_balance' => '0.0000',
                'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
            ]);
        }

        (new class { use \App\Traits\TransactionTrait; })
            ->merchant_payment_transaction(
                customer_user_id: $customer->id,
                merchant_user_id: $merchant->id,
                amount: '10000',
                channel: 'qr',
            );

        $this->assertSame('10000.0000',
            (string) EMoney::where('user_id', $merchant->id)->value('current_balance'),
            '**الرسمُ خُصم من التاجر والمتحمِّلُ المُعلَن هو الدافع** — '
            . 'المسارُ يعيد الحسابَ بيده ويتجاهل `bearer`.');

        $this->assertSame('39800.0000',
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'),
            'الدافعُ لم يُخصم منه المبلغُ + الرسم وهو المتحمِّل');
    }

    /**
     * @test
     *
     * **③ والدليلُ مالٌ تحرّك — لا رقمٌ في مصفوفة.**
     *
     * (القاعدة السادسة: الرقمُ يُحسب من مصدره. وهنا المصدرُ الرصيدُ بعد
     * العمليّة، لا ما تعِد به دالّةُ الحساب.)
     */
    public function a_real_payment_leaves_the_merchant_whole(): void
    {
        $this->seedFees();

        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
        $merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);

        foreach ([$customer->id => '50000.0000', $merchant->id => '0.0000'] as $uid => $bal) {
            EMoney::create([
                'user_id' => $uid, 'current_balance' => $bal,
                'held_balance' => '0.0000', 'pending_balance' => '0.0000',
                'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
            ]);
        }

        // يُشغَّل المسارُ نفسُه الذي يستعمله الدفعُ الحقيقيّ.
        $runner = new class { use \App\Traits\TransactionTrait; };

        $runner->merchant_payment_transaction(
            customer_user_id: $customer->id,
            merchant_user_id: $merchant->id,
            amount: '10000',
            channel: 'qr',
        );

        $this->assertSame('10000.0000',
            (string) EMoney::where('user_id', $merchant->id)->value('current_balance'),
            '**خُصم من التاجر شيءٌ عند قبول الدفع** — والقرار: لا رسمَ عليه');

        $this->assertSame('40000.0000',
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'),
            'خُصم من العميل غيرُ المبلغ — ولا رسمَ معلَنٌ عليه');
    }
}
