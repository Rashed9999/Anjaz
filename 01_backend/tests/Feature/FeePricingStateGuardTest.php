<?php

namespace Tests\Feature;

use App\Models\FeeScheme;
use App\Services\FeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-FEE-TRUTH-008 — **مجّانيٌّ عمداً ≠ إعدادٌ مفقود.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع في هذا المشروع بالذات:**
 *
 * كان `AGENT_DEPOSIT` يُطلب بحروفٍ صغيرة ولا يُطابق أيّ نسخة، فيرجع
 * المحرّكُ صفراً صامتاً — **فصارت كلُّ عمليّات الوكيل مجّانيّةً شهوراً**.
 * ولا خطأَ في أيّ سجلّ: الردُّ سليمٌ والرقمُ صفر.
 *
 * فالصفرُ يُقرأ «قرّرنا ألّا نأخذ»، وهو في الحقيقة «لم نجد ما نأخذ به».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا الملفُّ يُثبت أنّ الحالتين صارتا مفترقتين، وأنّ الفرقَ يُقال.**
 */
class FeePricingStateGuardTest extends TestCase
{
    use RefreshDatabase;

    private function fees(): FeeService
    {
        return app(FeeService::class);
    }

    private function scheme(array $overrides = []): FeeScheme
    {
        return FeeScheme::create(array_merge([
            'code' => 'SEND_MONEY',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'fixed',
            'percent_rate' => '0',
            'fixed_amount' => '10.0000',
            'bearer' => 'sender',
            'version' => 1,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @test
     *
     * **① غيابُ النسخة يُقال «إعدادٌ مفقود» لا يُقرأ مجّانيّة.**
     */
    public function a_missing_scheme_is_reported_as_missing_config(): void
    {
        $r = $this->fees()->calculate('SEND_MONEY', '1000');

        $this->assertSame('0.0000', $r['fee'], 'الضابط: بلا نسخةٍ لا رسم');

        $this->assertSame('missing_config', $r['pricing_state'] ?? null,
            '**الغيابُ يُقرأ مجّانيّةً صامتة** — وهو ما جعل عمليّات الوكيل '
            . 'مجّانيّةً شهوراً بلا أن يقرّر أحد');
    }

    /**
     * @test
     *
     * **وغيابُ التسعير يُرفع عطلاً يراه الأدمن.**
     *
     * فحالةٌ في الردّ لا يقرؤها إنسانٌ ليست إنذاراً.
     */
    public function a_missing_scheme_raises_an_operational_alert(): void
    {
        $before = DB::table('system_errors')->count();

        $this->fees()->calculate('CASH_OUT', '500');

        $this->assertGreaterThan($before, DB::table('system_errors')->count(),
            'تسعيرٌ مفقودٌ مرّ بلا أثرٍ في مركز الأعطال — **فلا أحدَ يعلم**');
    }

    /**
     * @test
     *
     * **② والمجّانيُّ عمداً نسخةٌ صريحةٌ بصفر، ويُقال إنّه كذلك.**
     */
    public function an_explicit_zero_scheme_is_distinguishable(): void
    {
        $this->scheme(['fee_type' => 'fixed', 'fixed_amount' => '0.0000']);

        $r = $this->fees()->calculate('SEND_MONEY', '1000');

        $this->assertSame('0.0000', $r['fee']);

        $this->assertSame('explicit_zero', $r['pricing_state'] ?? null,
            'نسخةٌ مجّانيّةٌ صريحةٌ تُقرأ «إعداداً مفقوداً» — فالقرارُ '
            . 'التجاريُّ يُقرأ عطلاً');
    }

    /**
     * @test
     *
     * **③ والمسعَّرُ يُقال «مسعَّر».**
     */
    public function a_priced_scheme_says_so(): void
    {
        $this->scheme();

        $r = $this->fees()->calculate('SEND_MONEY', '1000');

        $this->assertSame('10.0000', $r['fee']);
        $this->assertSame('priced', $r['pricing_state'] ?? null);
    }

    /**
     * @test
     *
     * **④ ولا صافيَ استلامٍ سالب — رسمٌ يفوق المبلغ يُرفض.**
     *
     * ══════════════════════════════════════════════════════════════
     * وهذه أخطرُ حالةٍ في المواصفة: يتحمّل **المستلمُ** الرسمَ ويكون
     * الرسمُ أكبرَ من المبلغ، فيخرج `net_credit` سالباً — أي **يُرسَل له
     * مالٌ فيَنقص رصيدُه**.
     *
     * ولا يُقصُّ الرقمُ صامتاً عند الصفر: ذاك يُخفي الإعدادَ الخاطئ ويجعل
     * المحصَّلَ غيرَ المُعلَن فينكسر الدفتر. **بل يُرفض ويُقال السبب.**
     */
    public function a_fee_larger_than_the_amount_is_refused_when_the_receiver_bears_it(): void
    {
        $this->scheme(['bearer' => 'receiver', 'fixed_amount' => '50.0000']);

        $this->expectException(\InvalidArgumentException::class);

        $this->fees()->calculate('SEND_MONEY', '10');
    }

    /**
     * @test
     *
     * **وضابطُها: المرسِلُ يتحمّل، فالرسمُ الكبيرُ يمرّ.**
     *
     * فرفضٌ يقع على الحالتين يُعطّل تسعيراتٍ سليمة — ورسمٌ يفوق مبلغاً
     * صغيراً على **المرسِل** قرارٌ تجاريٌّ مشروع (‏حدٌّ أدنى للرسم).
     */
    public function the_same_fee_passes_when_the_sender_bears_it(): void
    {
        $this->scheme(['bearer' => 'sender', 'fixed_amount' => '50.0000']);

        $r = $this->fees()->calculate('SEND_MONEY', '10');

        $this->assertSame('50.0000', $r['fee']);
        $this->assertSame('10.0000', $r['net_credit'],
            'المستلمُ يأخذ المبلغَ كاملاً حين يتحمّل المرسِلُ الرسم');
    }

    /**
     * @test
     *
     * **وحدُّ الرسم الأدنى يخضع للقاعدة نفسِها.**
     *
     * فالخطرُ لا يأتي من `fixed_amount` وحدَه: نسبةٌ صغيرةٌ بحدٍّ أدنى
     * كبيرٍ تُنتج الأثرَ نفسَه على مبلغٍ صغير — **وهو البابُ الذي يُنسى**.
     */
    public function a_min_fee_above_the_amount_is_refused_for_a_receiver_bearer(): void
    {
        $this->scheme([
            'bearer' => 'receiver',
            'fee_type' => 'percent',
            'percent_rate' => '1',
            'fixed_amount' => '0',
            'min_fee' => '80.0000',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->fees()->calculate('SEND_MONEY', '20');
    }
}
