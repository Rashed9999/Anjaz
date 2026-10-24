<?php

namespace Tests\Feature;

use App\Models\FeeScheme;
use App\Services\FeeService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AMIAL-FEE-PLAN-001 — **«رسمٌ على باقة البداية ٠٫٢٪ — هل يُطبَّق؟»**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الجوابُ قبل هذا العمل كان: لا.** أبعادُ الرسم ثلاثةٌ —
 * `code × zone_code × applies_to` — **ولا ذكرَ للباقة في المحرّك ولا في
 * النموذج ولا في سياسة الخصم**. فحقلٌ يُضاف في الشاشة كان سيتجاهله
 * المحرّكُ صامتاً: يضبط الأدمنُ سعراً ويراه فعّالاً ولا يُخصَم منه ريال.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقاعدةُ المختارة: تخصيصٌ لا أولويّةٌ اعتباطيّة.**
 *
 * `zone_code` مطابقةٌ تامّةٌ في كلّ صفّ (لا صفَّ «كلُّ المناطق»)، فلا
 * منافسةَ بينه وبين الباقة. والمحورُ الجديدُ وحدَه هو الباقة:
 *
 *   `plan = 'starter'`  →  «البداية» وحدَها
 *   `plan = NULL`       →  كلُّ الباقات — وهو ما كان قائماً قبل اليوم
 *
 * **والأخصُّ يفوز**، فمن ضبط سعراً عامّاً ثمّ استثنى باقةً عمل استثناؤه
 * ولم يُلغِ العامّ.
 */
class FeeSchemeByPlanGuardTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'MERCHANT_QR';

    private FeeService $fees;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->fees = app(FeeService::class);
    }

    /** نسخةٌ نشطةٌ — والباقةُ `null` تعني «كلُّ الباقات». */
    private function scheme(string $percent, ?string $plan, int $version): FeeScheme
    {
        return FeeScheme::create([
            'code' => self::CODE,
            'label' => 'قياس',
            'zone_code' => 'SOUTH',
            'applies_to' => 'merchant',
            'plan' => $plan,
            'fee_type' => 'percent',
            'percent_rate' => $percent,
            'fixed_amount' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'version' => $version,
            'is_active' => true,
            'effective_from' => now()->subDay(),
        ]);
    }

    private function feeFor(?string $plan): string
    {
        Cache::flush();   // القياسُ يفحص المحرّكَ لا الخزن

        return $this->fees->calculate(self::CODE, '10000', [
            'applies_to' => 'merchant', 'zone_code' => 'SOUTH', 'plan' => $plan,
        ])['fee'];
    }

    /**
     * **① السؤالُ كما وصل: ٠٫٢٪ على «البداية» — أتُطبَّق؟**
     */
    /** @test */
    public function a_plan_specific_price_actually_applies_to_that_plan(): void
    {
        $this->scheme('1', null, 1);                  // العامّ: ١٪
        $this->scheme('0.2', A::PLAN_FREE, 1);        // «البداية»: ٠٫٢٪

        $this->assertSame('20.0000', $this->feeFor(A::PLAN_FREE), sprintf(
            "**ضُبط ٠٫٢٪ على «البداية» ولم يُطبَّق.**\n"
            .'فيرى الأدمنُ السعرَ فعّالاً في الشاشة، ويُخصَم من التاجر غيرُه.'));
    }

    /**
     * **② والعامُّ يبقى لِمن سواها.**
     *
     * فنسخةُ باقةٍ تُلغي العامَّ تُسقط سعرَ كلِّ الباقات الأخرى بضغطةٍ
     * واحدة — **ولا رسالةَ تقول ذلك**.
     */
    /** @test */
    public function the_general_price_still_serves_every_other_plan(): void
    {
        $this->scheme('1', null, 1);
        $this->scheme('0.2', A::PLAN_FREE, 1);

        $this->assertSame('100.0000', $this->feeFor(A::PLAN_BUSINESS),
            '**نسخةُ «البداية» ابتلعت السعرَ العامّ** — فباقةُ الأعمال '
            .'صارت تدفع سعرَ المجّانيّة.');
    }

    /**
     * **③ وبلا باقةٍ في السياق يُقرأ العامُّ وحدَه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فتسرُّبُ نسخةِ باقةٍ إلى من لا باقةَ له (‏عميلٌ · وكيل) يجعل سعرَ
     * «البداية» يسري على العملاء كافّةً — **وهو أوسعُ أثراً من الخطأ
     * المعاكس**.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function no_plan_in_context_reads_only_the_general_version(): void
    {
        $this->scheme('1', null, 1);
        $this->scheme('0.2', A::PLAN_FREE, 1);

        $this->assertSame('100.0000', $this->feeFor(null),
            '**تسرّبت نسخةُ باقةٍ إلى نداءٍ بلا باقة.**');
    }

    /**
     * **④ ونسخةُ باقةٍ بلا نسخةٍ عامّةٍ تعمل وحدَها.**
     *
     * (حارسٌ يفحص التقديمَ ولا يفحص الوجودَ المنفرد يقبل «اشترط العامّ
     *  دائماً» علاجاً — وهو يمنع ضبطَ سعرٍ لباقةٍ واحدةٍ ابتداءً.)
     */
    /** @test */
    public function a_plan_price_works_even_with_no_general_version(): void
    {
        $this->scheme('0.5', A::PLAN_BUSINESS, 1);

        $this->assertSame('50.0000', $this->feeFor(A::PLAN_BUSINESS));

        // ولا يُخترع سعرٌ لِمن لا نسخةَ له — الغيابُ غيابٌ ويُقال.
        $q = $this->fees->calculate(self::CODE, '10000', [
            'applies_to' => 'merchant', 'zone_code' => 'SOUTH', 'plan' => A::PLAN_FREE,
        ]);

        $this->assertSame('missing_config', $q['pricing_state'],
            '**سعرُ باقةٍ تسرّب إلى باقةٍ لا نسخةَ لها** — والغيابُ يُقال '
            .'ولا يُملأ بأقربِ رقمٍ موجود.');
    }

    /**
     * **⑤ والخزنُ يعرف الباقة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * مفتاحٌ لا يذكرها يجعل **أوّلَ تاجرٍ يسأل يُثبّت سعرَه لكلّ
     * الباقات** لمدّة الخزن: يدفع صاحبُ «الأعمال» سعرَ «البداية»، ولا
     * خطأَ في أيّ سجلّ. **وهو عطلُ خزنٍ يُقرأ عطلَ تسعير** — ولا يظهر
     * في اختبارٍ يمسح الخزنَ بين النداءين.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_cache_key_separates_plans(): void
    {
        $this->scheme('1', null, 1);
        $this->scheme('0.2', A::PLAN_FREE, 1);

        Cache::flush();

        // **بلا مسحٍ بينهما** — كما يقع في الإنتاج.
        $ask = fn (?string $plan) => $this->fees->calculate(self::CODE, '10000', [
            'applies_to' => 'merchant', 'zone_code' => 'SOUTH', 'plan' => $plan,
        ])['fee'];

        $first = $ask(A::PLAN_FREE);
        $second = $ask(A::PLAN_BUSINESS);

        $this->assertSame('20.0000', $first);
        $this->assertSame('100.0000', $second,
            '**السائلُ الأوّلُ ثبّت سعرَه للجميع** — فمفتاحُ الخزن لا يذكر '
            .'الباقة، وباقةُ الأعمال تدفع سعرَ المجّانيّة حتّى تنتهي المدّة.');
    }

    /**
     * **⑥ والنسختان تتعايشان في القاعدة.**
     *
     * فالقيدُ الفريدُ `active_key` كان ثلاثيّاً، ولو بقي كذلك **لامتنع
     * وجودُ نسخةٍ عامّةٍ ونسخةِ باقةٍ نشطتين معاً** — أي لَما بُنيت
     * الميزةُ أصلاً، ولَظهر ذلك خطأَ قاعدةٍ غامضاً عند أوّل حفظ.
     */
    /** @test */
    public function a_general_and_a_plan_version_can_both_be_active(): void
    {
        $this->scheme('1', null, 1);
        $this->scheme('0.2', A::PLAN_FREE, 1);

        $this->assertSame(2, FeeScheme::where('code', self::CODE)
            ->where('is_active', true)->count(),
            '**لم تتعايش النسختان** — فالقيدُ الفريدُ لم يتّسع للباقة.');
    }

    /**
     * **⑦ ونسخةٌ جديدةٌ لباقةٍ لا تُبطل العامّة.**
     *
     * وهذا مسارُ الشاشة لا القاعدة: `createVersion` كانت تُبطل «النسخةَ
     * النشطةَ لهذا الرمز والمنطقة والجهة» — وبلا قيد الباقة **تُبطل
     * العامّةَ عند إنشاء نسخةِ باقة**، فيسقط سعرُ كلِّ الباقات الأخرى.
     */
    /** @test */
    public function creating_a_plan_version_through_the_service_keeps_the_general_one(): void
    {
        $this->fees->createVersion([
            'code' => self::CODE, 'zone_code' => 'SOUTH', 'applies_to' => 'merchant',
            'plan' => null, 'fee_type' => 'percent', 'percent_rate' => '1',
            'fixed_amount' => '0', 'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0', 'bearer' => 'sender',
        ]);

        $this->fees->createVersion([
            'code' => self::CODE, 'zone_code' => 'SOUTH', 'applies_to' => 'merchant',
            'plan' => A::PLAN_FREE, 'fee_type' => 'percent', 'percent_rate' => '0.2',
            'fixed_amount' => '0', 'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0', 'bearer' => 'sender',
        ]);

        $this->assertSame('100.0000', $this->feeFor(A::PLAN_BUSINESS),
            '**أُبطلت النسخةُ العامّةُ عند إنشاء نسخةِ باقة** — فسقط سعرُ '
            .'كلِّ الباقات الأخرى بضغطةٍ واحدة، ولا رسالةَ تقول ذلك.');

        $this->assertSame('20.0000', $this->feeFor(A::PLAN_FREE));
    }

    /**
     * **⑧ والتخصيصُ يسبق الحداثة — لا العكس.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذه الحالةُ وُلدت من تجربةٍ عكسيّةٍ مرّت.** نُزع
     * `orderByRaw('plan IS NULL')` فبقيت الحالاتُ السبعُ خضراء — لأنّ
     * النسختين في مُثبَّتاتها **برقمٍ واحد**، فالترتيبُ لم يُختبَر أصلاً
     * وإنّما وقع صدفةً كما يريد.
     *
     * **والحالةُ الحقيقيّةُ أخطر:** الأدمنُ يعدّل السعرَ العامَّ مرّاتٍ
     * فيصير رقمُه ٩، ونسخةُ «البداية» ما زالت ١. فبلا تقديمِ المخصَّص
     * يفوز العامُّ بالحداثة — **وتختفي أسعارُ الباقات كلُّها بلا أن
     * يلمسها أحد**، ولا رسالةَ تقول ذلك.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_much_newer_general_version_still_does_not_beat_a_plan_price(): void
    {
        $this->scheme('0.2', A::PLAN_FREE, 1);   // نسخةُ الباقة — قديمة
        $this->scheme('1', null, 9);             // العامّةُ — أحدثُ بكثير

        $this->assertSame('20.0000', $this->feeFor(A::PLAN_FREE), sprintf(
            "**فازت النسخةُ العامّةُ بالحداثة على نسخةِ الباقة.**\n\n"
            .'فالأدمنُ يعدّل السعرَ العامَّ فترتفع نسختُه، **فتختفي أسعارُ '
            .'الباقات كلُّها** بلا أن يلمسها أحد ولا رسالةَ تقول ذلك.'));
    }
}
