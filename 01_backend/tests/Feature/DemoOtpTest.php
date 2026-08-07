<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-DEMO-OTP — الرمز التجريبي الثابت.
 *
 * سبب وجود هذه الاختبارات: مسار الالتفاف مكتوب بالكامل وصحيح في
 * RegisterController وcheckPhone، ويقرأ config('app.amial_demo_otp') —
 * وكان معرَّفاً بـ env('AMIAL_DEMO_OTP') بلا قيمة افتراضية. المتغيّر غير
 * مضبوط على الخادم ⇒ null ⇒ الالتفاف ميّت. ومع بوابة SMS غير مضبوطة لم
 * يكن يصل رمز حقيقي أيضاً، فيقف التسجيل عند الخطوة الأخيرة بلا مخرج.
 *
 * وكان checkPhone يخزّن '1234' بينما التسجيل يقبل '123456' — رمزان
 * مختلفان في مسار واحد.
 */
class DemoOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => 1]
        );
    }

    /**
     * AMIAL-OTP-SPLIT-001: **رقمُ عرضٍ معلوم، لا عشوائيّ.**
     *
     * كانت هذه الدالّة تُرجع رقماً عشوائيّاً، فتُثبّت الاختباراتُ أنّ
     * `123456` يُقبل من **أيّ رقم** — أي توثّق الثغرة كأنّها عقد. وصار
     * الرمزُ الثابت لأرقام العرض وحدها.
     */
    private function phone(): string
    {
        return '967777100001';
    }

    /** رقمٌ حقيقيٌّ ليس في قائمة العرض — يُستعمل في اختبارات النفي. */
    private function realPhone(): string
    {
        return '7' . random_int(10000000, 99999999);
    }

    /** الحمولة الحقيقية التي يرسلها معالج التسجيل في التطبيق. */
    private function payload(string $phone, string $otp): array
    {
        return [
            'f_name' => 'راشد',
            'l_name' => 'المهدي',
            'gender' => 'male',
            'dial_country_code' => '+967',
            'phone' => $phone,
            'password' => '1234',   // العقد: min:4|max:4 (رمز PIN لا كلمة مرور)
            'otp' => $otp,
        ];
    }

    public function test_config_key_exists_and_defaults_to_the_documented_value(): void
    {
        // العطل الأصلي بعينه: بلا قيمة افتراضية ⇒ null ⇒ الالتفاف ميّت.
        $this->assertNotNull(config('app.amial_demo_otp'));
        $this->assertSame('123456', config('app.amial_demo_otp'));
    }

    public function test_registration_succeeds_with_the_demo_otp(): void
    {
        $phone = $this->phone();

        $this->postJson('/api/v1/customer/auth/register', $this->payload($phone, '123456'))
            ->assertSuccessful();

        $this->assertTrue(
            User::whereIn('phone', \App\Support\Phone::variants('+967' . $phone))->exists(),
            'يجب أن يُنشأ الحساب فعلاً لا أن يمرّ التحقّق وحده'
        );
    }

    public function test_registration_rejects_a_wrong_otp(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->payload($this->phone(), '999999'))
            ->assertJsonFragment(['code' => 'otp']);
    }

    public function test_demo_otp_can_be_switched_off_for_real_production(): void
    {
        // البوابة الحقيقية: AMIAL_DEMO_OTP فارغاً يُعطّل الالتفاف تماماً،
        // فيعود النظام لاشتراط رمز حقيقي من بوابة SMS.
        config(['amial.otp.demo_code' => '']);

        $this->postJson('/api/v1/customer/auth/register', $this->payload($this->phone(), '123456'))
            ->assertJsonFragment(['code' => 'otp']);
    }

    public function test_check_phone_hands_the_demo_code_to_the_app(): void
    {
        // التطبيق يعبّئ الحقل تلقائياً من demo_otp — بدونه يبقى المستخدم يحدس.
        $this->postJson('/api/v1/customer/auth/check-phone', ['phone' => $this->phone()])
            ->assertOk()
            ->assertJsonPath('demo_otp', '123456');
    }

    public function test_self_registration_still_awaits_admin_review(): void
    {
        // الرمز التجريبي يفتح التسجيل، ولا يجوز أن يفتح التوثيق معه.
        $phone = $this->phone();

        $this->postJson('/api/v1/customer/auth/register', $this->payload($phone, '123456'))
            ->assertSuccessful();

        $user = User::whereIn('phone', \App\Support\Phone::variants('+967' . $phone))->first();
        $this->assertNotNull($user);
        $this->assertSame(0, (int) $user->is_kyc_verified);
    }

    public function test_check_phone_survives_a_missing_verification_setting(): void
    {
        // إعداد phone_verification غير موجود أصلاً في قاعدة نظيفة؛ قراءته
        // بلا حارس كانت ترمي 500 فيرى المستخدم «خطأ» بلا سبب مفهوم.
        DB::table('business_settings')->where('key', 'phone_verification')->delete();

        $this->postJson('/api/v1/customer/auth/check-phone', ['phone' => $this->phone()])
            ->assertStatus(200);
    }
}
