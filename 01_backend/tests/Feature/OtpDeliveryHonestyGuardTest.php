<?php

namespace Tests\Feature;

use App\Services\Otp\OtpPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-OTP-DELIVERY-001 — **البابُ الأوّل كان يقول «أُرسل» ولا يُرسل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *     OtpPolicy::needsDelivery      →  صفرُ مُنادٍ
 *     OtpPolicy::deliveryReady      →  صفرُ مُنادٍ
 *     OtpPolicy::unavailableMessage →  صفرُ مُنادٍ
 *
 * وثلاثتُها مبنيّةٌ **لحالةٍ واحدةٍ بعينها**: رقمٌ حقيقيٌّ يطلب رمزاً ولا
 * قناةَ تُوصله. والمسارُ كان يولّد الرمزَ ويخزّنه ثمّ يردّ
 * `otp: active` بـ٢٠٠ — **بلا أن يسأل أثمّة قناة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأرقامُ العرض قائمةٌ محدَّدة** (`isDemo` يطابق آخرَ تسعة أرقام)، فكلُّ
 * رقمٍ حقيقيٍّ خارجَها يحتاج إرسالاً فعليّاً. أي أنّ العطلَ يقع على
 * **كلّ مستخدمٍ حقيقيٍّ في التجربة**: يُدخل رقمَه، ويرى «أُرسل الرمز»،
 * وينتظر رسالةً لا تصل.
 *
 * **ولا خطأَ في أيّ سجلّ** — لأنّ لا خطأَ وقع: لم يُطلَب الإرسالُ من أحد.
 * وهو الصمتُ يُقرأ نجاحاً، على البابِ الذي يمرّ به كلُّ عميل.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحاجزُ لا يكذب** — وقِيس ذلك قبل شحنه: `deliveryReady()` تقرأ
 * `ProviderRegistry`، و`SmsModule::send` واجهةٌ رقيقةٌ فوق **السجلّ
 * نفسِه**، و`addon_published_status('Gateways')` صفرٌ فلا مسارَ ثالث.
 * فالمصدرُ واحد: ما يقوله الحاجزُ هو ما سيفعله المُرسِل. **وحاجزٌ يقرأ
 * مصدراً غيرَ مصدرِ الفعل يُقفل بابَ نظامٍ عامل** — وذاك أسوأ من الصمت.
 */
class OtpDeliveryHonestyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function ask(string $phone)
    {
        return $this->postJson('/api/v1/customer/auth/check-phone', ['phone' => $phone]);
    }

    // ══════════════════════════════════════════════════════════════════
    // ① الحالةُ التي وُجدت الدوالُّ لها
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_real_number_is_told_the_truth_when_no_channel_exists(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه.** لا واتساب ولا رسائل قصيرة مفعّلة في
        // هذه البيئة — وكان الردُّ ٢٠٠ و«active».
        // ══════════════════════════════════════════════════════════════
        $this->assertFalse(app(OtpPolicy::class)->deliveryReady(),
            'القناةُ مفعّلةٌ هنا — فالحالةُ المفحوصةُ ليست هي');

        $r = $this->ask('967771500006')->assertStatus(503);

        $this->assertStringContainsString('غير مهيّأة', (string) $r->json('message'),
            'صمتٌ في وجه رقمٍ حقيقيّ — ينتظر رسالةً لا تصل ولا يعرف لماذا');
    }

    /** @test */
    public function no_code_is_stored_for_a_message_that_cannot_be_sent(): void
    {
        // **وصفٌّ مخزَّنٌ لرمزٍ لم يُرسَل يُقفل الرقمَ على لا شيء**: نافذةُ
        // إعادة الإرسال تُقاس من `created_at`، فيُردّ «حاول بعد دقيقة» على
        // من لم يصله شيءٌ أصلاً.
        $this->ask('967771500006');

        $this->assertDatabaseMissing('phone_verifications', ['phone' => '967771500006']);
    }

    /** @test */
    public function the_operations_centre_learns_registration_is_shut(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وتسجيلٌ مقفلٌ على كلّ الأرقام الحقيقيّة عطلٌ من الدرجة
        // الأولى** — ولا يُكتشَف بشكوى عميلٍ بعد يومين. فيُكتَب في مركز
        // الأعطال أوّلَ مرّةٍ يقع.
        // ══════════════════════════════════════════════════════════════
        $this->ask('967771500006');

        $this->assertDatabaseHas('system_errors', [
            'fingerprint' => hash('sha256', 'ops|otp.delivery.unavailable'),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // ② وأرقامُ العرض لا تُمَسّ — قرارُ صاحب المشروع
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_demo_number_still_works_with_no_channel(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى.** قرارُ صاحب
        // المشروع أنّ `AMIAL_DEMO_OTP` يبقى طوال التجربة، وأرقامُ العرض
        // **لا تحتاج قناةً أصلاً** — فلو منعها الحاجزُ لأقفل التجربةَ
        // كلَّها بدل أن يفتحها.
        // ══════════════════════════════════════════════════════════════
        $r = $this->ask('967777100001')->assertOk();

        $this->assertSame('active', $r->json('otp'),
            'رقمُ عرضٍ مُنع — والحاجزُ أقفل التجربةَ التي جاء ليحميها');

        $this->assertNotEmpty($r->json('demo_otp'));
    }

    /** @test */
    public function a_real_number_never_gets_its_code_disclosed(): void
    {
        // **والعقدُ القديمُ يبقى محروساً**: لا يُفصح عن رمزِ رقمٍ حقيقيٍّ
        // بحال — فالإفصاحُ يُبطل التحقّقَ من أصله («أثبت أنّك تملك الرقم»
        // ← «انسخ ما أعطيناك»).
        $this->assertNull($this->ask('967771500006')->json('demo_otp'));
    }

    // ══════════════════════════════════════════════════════════════════
    // ③ والحاجزُ يقرأ مصدرَ الفعل نفسَه
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_guard_and_the_sender_read_one_source(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وحاجزٌ يقرأ مصدراً غيرَ مصدرِ الفعل يُقفل بابَ نظامٍ عامل** —
        // وذاك أسوأ من الصمت الذي جاء يمنعه. فيُقاس أنّ `SmsModule`
        // واجهةٌ فوق `ProviderRegistry` لا مسارٌ ثانٍ.
        // ══════════════════════════════════════════════════════════════
        $sender = (string) file_get_contents(app_path('CentralLogics/SmsModule.php'));

        $this->assertStringContainsString('ProviderRegistry', $sender,
            'المُرسِلُ لا يقرأ السجلَّ الذي يقرؤه الحاجز — فقد يُمنَع إرسالٌ ممكن');

        $policy = (string) file_get_contents(app_path('Services/Otp/OtpPolicy.php'));

        $this->assertStringContainsString('registry->hasEnabled', $policy,
            'الحاجزُ لا يسأل السجلَّ — فجوابُه ظنٌّ لا قياس');
    }

    /** @test */
    public function the_door_actually_asks_before_it_promises(): void
    {
        // **ويُقرأ من الشيفرة بلا تعليقاتها** — فتعليقٌ يذكر الاسمَ أخفى
        // غيابَه أربعَ مرّاتٍ في هذه الجلسة.
        $s = (string) file_get_contents(app_path(
            'Http/Controllers/Api/V1/Customer/Auth/CustomerAuthController.php'));
        $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? '';
        $s = preg_replace('~^[ \t]*//[^\n]*$~m', '', $s) ?? '';

        $at = strpos($s, 'needsDelivery');
        $gen = strpos($s, 'codeFor(');

        $this->assertNotFalse($at, 'البابُ لا يسأل أثمّة قناةٌ تُوصل الرمز');
        $this->assertNotFalse($gen);

        $this->assertLessThan($gen, $at,
            'السؤالُ بعد توليد الرمز — فيُخزَّن ما لا يُرسَل، ويُقفَل الرقمُ على لا شيء');
    }
}
