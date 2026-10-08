<?php

namespace Tests\Feature;

use App\Models\MerchantRiskProfile;
use App\Models\User;
use App\Services\MerchantRiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-RISK-002 — **كاشفُ غسيلٍ عاجزٌ بنيويّاً عن الاشتعال.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *     analyzeReceived  → النمطُ الثالث: pass-through، ‎+٣٥ نقطة
 *                        (أقوى مؤشّراتها) ويقرأ passThroughRatio()
 *     passThroughRatio → total_transferred_out ÷ المستلَم
 *     العمودُ ذاك      → كاتبُه الوحيد `recordTransferOut`
 *     recordTransferOut→ **صفرُ مُنادٍ في المشروع كلِّه**
 *
 * ⇒ العمودُ صفرٌ أبداً ⇒ النسبةُ صفرٌ أبداً ⇒ **النمطُ لا يشتعل بحالٍ
 * من الأحوال**. لا خطأَ في أيّ سجلّ، والدالّةُ خضراءُ الاختبار منذ
 * كُتبت — **ولا شيءَ كان يسأل من يناديها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ منه أنّ الرقمَ يُعرَض.** لوحةُ مخاطر التجّار تكتب
 * `pass_through_ratio: 0.0%` لكلّ تاجر — **رقمٌ يبدو مقيساً ولم يُقَس**.
 * ومن يقرأ «صفر بالمئة» يفهم «فُحص فلم يوجد». (القاعدةُ السابعة.)
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وطبقةٌ باتّجاهٍ واحدٍ ليست مراقبة.** كانت تراقب المستلمَ إن كان
 * تاجراً ولا تراقب المرسِل — وهو شكلُ القاعدة العاشرة نفسِه («وكلّ
 * طبقةٍ باتّجاهين»). ولها **ثلاثةُ مخارج** لا مخرجٌ واحد: التحويلُ
 * للنظير، والصرفُ النقديُّ عند الوكيل، والسحبُ الذي تقرّه الإدارة.
 */
class MerchantPassThroughGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(): User
    {
        return User::factory()->create(['type' => 3]);
    }

    /** الشيفرةُ بلا تعليقاتها — فتعليقٌ يذكر الاسمَ أخفى غيابَه من قبل. */
    private function codeOnly(string $rel): string
    {
        $s = (string) file_get_contents(base_path($rel));
        $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? '';

        return preg_replace('~^[ \t]*//[^\n]*$~m', '', $s) ?? '';
    }

    // ══════════════════════════════════════════════════════════════════
    // ① الكاتبُ يعمل — وكان يعمل طوال الوقت
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function recording_a_transfer_out_moves_the_ratio(): void
    {
        $m = $this->merchant();
        $svc = app(MerchantRiskService::class);

        $svc->recordTransferOut($m->id, '400');

        $risk = MerchantRiskProfile::where('merchant_user_id', $m->id)->first();
        $risk->total_received_lifetime = '1000';
        $risk->save();

        $this->assertEqualsWithDelta(0.4, $risk->fresh()->passThroughRatio(), 0.001,
            'الكاتبُ لا يحرّك النسبة — فالكاشفُ أعمى وإن نودي');
    }

    // ══════════════════════════════════════════════════════════════════
    // ② والمخارجُ الثلاثةُ توصله — وهذا ما كان غائباً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function all_three_outbound_paths_record_the_transfer(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ومخرجٌ منسيٌّ يجعل النسبةَ أقلَّ من حقيقتها** — وحاجزٌ يقرأ
        // أقلَّ من الواقع يمرّر ما بُني ليمسكه. (القاعدةُ الرابعة:
        // ميزةٌ لها مداخلُ تُختبَر من كلّها.)
        // ══════════════════════════════════════════════════════════════
        $src = $this->codeOnly('app/Traits/TransactionTrait.php');

        $this->assertSame(3, substr_count($src, '$this->maybeRecordMerchantTransferOut('),
            'عددُ المخارج الموصولة ليس ثلاثة — والمنسيُّ منها يخفض النسبةَ عن حقيقتها');

        foreach ([
            'customer_send_money_transaction',
            'customer_cash_out_transaction',
            'accept_withdraw_transaction',
        ] as $entrance) {
            $at = strpos($src, 'function ' . $entrance);

            $this->assertNotFalse($at, "لا مدخلَ بهذا الاسم: {$entrance}");

            $next = preg_match('~\n    (?:public|protected|private) function ~',
                $src, $mm, PREG_OFFSET_CAPTURE, $at + 10) ? (int) $mm[0][1] : strlen($src);

            $this->assertStringContainsString(
                'maybeRecordMerchantTransferOut', substr($src, $at, $next - $at),
                "مخرجُ {$entrance} لا يُسجّل خروجَ المال — فنمطُ pass-through يقرأ أقلَّ من الواقع");
        }
    }

    /** @test */
    public function only_a_merchant_is_recorded(): void
    {
        // **وإنذارٌ كاذبٌ يُعوّد القارئَ على التجاهل يومَ يصدق.** ملفُّ
        // مخاطرَ لعميلٍ عاديٍّ يملأ اللوحةَ بمن لا شأنَ لها بهم.
        $src = $this->codeOnly('app/Traits/TransactionTrait.php');

        $fn = substr($src, (int) strpos($src, 'function maybeRecordMerchantTransferOut'), 900);

        $this->assertMatchesRegularExpression('~type\s*===\s*3~', $fn,
            'يُسجَّل خروجُ مال كلِّ مستخدم — فتمتلئ لوحةُ التجّار بالعملاء');
    }

    /** @test */
    public function watching_never_drops_the_payment(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والرصدُ لا يُسقط ما يرصده.** رميٌ هنا يمنع تحويلاً سليماً —
        // وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى، فيذهب الرصدُ
        // كلُّه معه.
        // ══════════════════════════════════════════════════════════════
        $src = $this->codeOnly('app/Traits/TransactionTrait.php');

        $fn = substr($src, (int) strpos($src, 'function maybeRecordMerchantTransferOut'), 900);

        $this->assertStringContainsString('catch (\Throwable', $fn,
            'سقوطُ الرصد يُسقط التحويل — فحاجزٌ يشلّ عملاً سليماً يُطفَأ');
    }

    // ══════════════════════════════════════════════════════════════════
    // ③ والكاشفُ صار قادراً على الاشتعال
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_high_pass_through_now_raises_the_risk(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذا هو العطلُ بعينه.** الشرطُ `$ratio > 0.8` مكتوبٌ ومُختبَرٌ
        // منذ كُتب، **ولم يكن يمكن أن يصدق** لأنّ بسطَه لا يُكتَب.
        // ══════════════════════════════════════════════════════════════
        $m = $this->merchant();
        $svc = app(MerchantRiskService::class);

        $svc->recordTransferOut($m->id, '95000');

        $risk = MerchantRiskProfile::where('merchant_user_id', $m->id)->first();
        $risk->total_received_lifetime = '100000';
        $risk->current_risk_score = 0;
        $risk->save();

        $this->assertGreaterThan(0.8, $risk->fresh()->passThroughRatio(),
            'النسبةُ لا تتجاوز العتبةَ ولو خرج كلُّ ما دخل — فالنمطُ ميّت');

        $svc->analyzeReceived($m->id, '100', (int) User::factory()->create()->id);

        $this->assertGreaterThan(0, (float) $risk->fresh()->current_risk_score,
            'خرج ٩٥٪ ممّا دخل ولم ترتفع درجةُ الخطر — النمطُ الثالثُ لا يشتعل');
    }

    // ══════════════════════════════════════════════════════════════════
    // ④ ولا يُقرأ المجهولُ صفراً
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_unmeasured_ratio_is_not_shown_as_zero_percent(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **القاعدةُ السابعة بنصّها.** «‎0.0%‎» تُقرأ «فُحص فلم يوجد»،
        // والحقيقةُ كانت «لم يُعَدّ شيء» — على كلّ تاجرٍ في اللوحة، منذ
        // بُني الجدول. والفرقُ بينهما تحقيقُ امتثالٍ يُفتح أو لا يُفتح.
        // ══════════════════════════════════════════════════════════════
        $m = $this->merchant();
        $svc = app(MerchantRiskService::class);

        $svc->analyzeReceived($m->id, '500', (int) User::factory()->create()->id);

        $blank = $svc->getRiskDashboard($m->id);

        $this->assertNull($blank['pass_through_ratio'],
            'يُعرَض «‎0.0%‎» ولم يُسجَّل خروجُ ريالٍ واحد — رقمٌ يبدو مقيساً ولم يُقَس');

        $svc->recordTransferOut($m->id, '250');

        $this->assertNotNull($svc->getRiskDashboard($m->id)['pass_through_ratio'],
            'قِيست النسبةُ فعلاً ولم تُعرَض — فالمقيسُ يُكتَم مع المجهول');
    }

    /** @test */
    public function the_admin_list_hides_it_too(): void
    {
        // **ونقطتان تعرضانه لا واحدة** — ولوحةٌ صادقةٌ وقائمةٌ كاذبةٌ
        // أسوأ من كاذبتين: تُقرأ الأولى فتُصدَّق الثانية.
        $src = $this->codeOnly('app/Http/Controllers/Admin/AdminMerchantRiskController.php');

        $this->assertStringContainsString('hasOutboundRecord()', $src,
            'قائمةُ التجّار ما زالت تكتب صفراً على ما لم يُقَس');
    }
}
