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
        config([
            'app.amial_demo_otp' => '123456',
            'amial.otp.demo_code' => '123456',
            'amial.otp.demo_numbers' => [$this->phone()],
            'amial.otp.pilot_customer_phone_enabled' => false,
        ]);
        \App\Services\Otp\OtpPolicy::forget();
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
            'father_name' => 'محمد',
            'grandfather_name' => 'عوض',
            'family_name' => 'المهدي',
            'l_name' => 'المهدي',
            'gender' => 'male',
            'dial_country_code' => '+967',
            'phone' => $phone,
            'email' => 'demo-otp-registration@example.test',
            'password' => '1234',   // العقد: min:4|max:4 (رمز PIN لا كلمة مرور)
            'otp' => $otp,
        ];
    }

    public function test_demo_configuration_is_explicit_in_the_test_fixture(): void
    {
        // لا يقبل الإنتاج رمزاً من قيمة افتراضية عامة؛ الاختبار هو من يصرّح
        // بمسار العرض الذي يقيسه.
        $this->assertSame('123456', config('app.amial_demo_otp'));
    }

    public function test_legacy_amial_registration_is_retired_even_with_the_demo_otp(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->payload($this->phone(), '123456'))
            ->assertStatus(410)
            ->assertJsonPath('code', 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ');
    }

    public function test_legacy_registration_cannot_disclose_otp_validation_outside_muwathaq(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->payload($this->phone(), '999999'))
            ->assertStatus(410)
            ->assertJsonPath('code', 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ');
    }

    public function test_demo_otp_can_be_switched_off_for_real_production(): void
    {
        config([
            'app.amial_demo_otp' => '',
            'amial.otp.demo_code' => '',
            'amial.otp.pilot_customer_phone_enabled' => false,
        ]);
        \App\Services\Otp\OtpPolicy::forget();

        $this->postJson('/api/v1/customer/auth/check-phone', ['phone' => $this->phone()])
            ->assertOk()
            ->assertJsonMissing(['demo_otp' => '123456']);
    }

    public function test_check_phone_hands_the_demo_code_to_the_app(): void
    {
        // التطبيق يعبّئ الحقل تلقائياً من demo_otp — بدونه يبقى المستخدم يحدس.
        $this->postJson('/api/v1/customer/auth/check-phone', ['phone' => $this->phone()])
            ->assertOk()
            ->assertJsonPath('demo_otp', '123456');
    }

    public function test_no_amial_endpoint_can_create_a_customer_account(): void
    {
        $this->postJson('/api/v1/customer/auth/register', $this->payload($this->phone(), '123456'))
            ->assertStatus(410)
            ->assertJsonPath('muwathaq_url', '/open-account?type=customer');

        $this->assertDatabaseCount('users', 0);
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
