<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-OTP-BRUTEFORCE-001 — **رمزٌ من أربعة أرقام بلا عدّادٍ ولا أجل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `OTPController::verifyOtp` كانت تسأل «أيوجد صفٌّ بهذا الهاتف وهذا
 * الرمز؟» ثمّ تنتهي:
 *
 *   ① لا عدّادَ محاولات — والمساحةُ عشرةُ آلاف، والحدُّ العامّ مئةٌ في
 *      الدقيقة. تُستنفد المساحةُ كلُّها في ساعةٍ ونصف بلا تنبيهٍ واحد.
 *   ② ولا أجلَ — `created_at` مكتوبٌ ولا يُقرأ، فرمزُ الشهر الماضي
 *      يُقبل اليوم.
 *
 * **والأعمدةُ اللازمة في الجدول منذ البداية** (`otp_hit_count` و
 * `is_temp_blocked` و`temp_block_time`)، ويستعملها مسارُ استعادة كلمة
 * المرور. وهذا المسارُ وحدَه لا يقرؤها — حمايةٌ مبنيّةٌ ونصفُ موصولة.
 */
class OtpBruteForceGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'phone' => '+967777200001', 'type' => CUSTOMER_TYPE,
            'role' => 'customer', 'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);

        Passport::actingAs($this->user);

        // ══════════════════════════════════════════════════════════════
        // **حدُّ المسار يُعطَّل هنا وحدَه — وطبقتان مختلفتان لا واحدة.**
        //
        // الطبقتان:
        //   ① حدُّ المسار (`throttle:10,1`) — يحمي من يجرّب أرقاماً كثيرة.
        //   ② عدّادُ الجدول (`otp_hit_count`) — يحمي رقماً واحداً، ويحظر.
        //
        // وهذه الاختباراتُ تقيس الثانية: تحتاج ستَّ محاولاتٍ متتاليةً على
        // رقمٍ واحد. والأولى تقطعها بـ٤٢٩ قبل أن تبلغ الحظر — **فلا
        // تُقاس الثانيةُ أبداً**، ويُظنّ أنّها تعمل لأنّ شيئاً منع.
        //
        // وتعطيلُها هنا ليس تسامحاً: الطبقةُ الأولى مفحوصةٌ نصّاً في
        // `test_the_otp_routes_carry_a_tight_rate_limit` أدناه. **وكلٌّ
        // تُقاس حيث تعمل.**
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // جهازٌ مسجَّلٌ نشِط — `CheckDeviceId` يشترطه، وهو ما يقع فعلاً
        // بعد أوّل دخول. (القاعدة الرابعة: يُجرَّب المسار كما يسلكه
        // المستعمل، لا بتعطيل الوسيط ليمرّ.)
        \App\Models\UserLogHistory::create([
            'user_id' => $this->user->id,
            'device_id' => 'test-device-001',
            'is_active' => 1,
            'is_blocked' => 0,
        ]);
    }

    private function seedOtp(string $otp = '1234', ?string $createdAt = null): void
    {
        DB::table('phone_verifications')->updateOrInsert(['phone' => $this->user->phone], [
            'otp' => $otp,
            'otp_hit_count' => 0,
            'is_temp_blocked' => 0,
            'temp_block_time' => null,
            'created_at' => $createdAt ?? now(),
            'updated_at' => now(),
        ]);
    }

    private function try(string $otp)
    {
        // CheckDeviceId يشترط ترويسة device-id — وهي ما يرسله التطبيق فعلاً.
        // (القاعدة الرابعة: يُجرَّب المسار كما يسلكه المستعمل لا كما يسهل.)
        return $this->withHeaders(['device-id' => 'test-device-001'])
            ->postJson('/api/v1/customer/verify-otp', ['otp' => $otp]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① العدّاد
    // ══════════════════════════════════════════════════════════════════

    public function test_a_correct_code_still_works(): void
    {
        // حارسٌ لا يُثبت أنّ الطريقَ السليم يعمل يُغلق البابَ على الجميع.
        $this->seedOtp('4321');

        $this->try('4321')->assertOk();

        $this->assertDatabaseMissing('phone_verifications', ['phone' => $this->user->phone]);
    }

    public function test_wrong_attempts_are_counted(): void
    {
        $this->seedOtp('4321');

        $this->try('0000')->assertStatus(404);

        $this->assertSame(1, (int) DB::table('phone_verifications')
            ->where('phone', $this->user->phone)->value('otp_hit_count'),
            'المحاولةُ الخاطئة لا تُعدّ — والمساحةُ عشرةُ آلافٍ تُمسح بلا أثر');
    }

    public function test_the_account_is_blocked_at_the_threshold(): void
    {
        $this->seedOtp('4321');

        for ($i = 0; $i < 5; $i++) {
            $this->try('000' . $i);
        }

        $this->assertSame(1, (int) DB::table('phone_verifications')
            ->where('phone', $this->user->phone)->value('is_temp_blocked'));
    }

    public function test_after_the_block_even_the_right_code_is_refused(): void
    {
        // **وهذا هو معنى الحظر.** حظرٌ يُرفع بإدخال الرمز الصحيح ليس
        // حظراً — هو ما يبحث عنه المهاجم أصلاً.
        $this->seedOtp('4321');

        for ($i = 0; $i < 5; $i++) {
            $this->try('000' . $i);
        }

        $this->try('4321')->assertStatus(403);
    }

    public function test_the_refusal_says_how_long_to_wait(): void
    {
        // (الدرس المكتوب: رفضٌ لا يقول سببه يُرسل المستعمل إلى الدعم.)
        $this->seedOtp('4321');

        for ($i = 0; $i < 5; $i++) {
            $this->try('000' . $i);
        }

        $body = $this->try('4321')->json('errors.0');

        $this->assertSame('otp_block_time', $body['code']);
        $this->assertNotEmpty($body['message']);
    }

    public function test_the_user_is_told_how_many_attempts_remain(): void
    {
        $this->seedOtp('4321');

        $msg = $this->try('0000')->json('errors.0.message');

        $this->assertStringContainsString('4', $msg, 'لا يُقال كم بقي من محاولة');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② الأجل
    // ══════════════════════════════════════════════════════════════════

    public function test_an_expired_code_is_refused_even_when_correct(): void
    {
        $lifetime = (int) config('amial.otp.lifetime_seconds', 600);

        $this->seedOtp('4321', now()->subSeconds($lifetime + 60)->toDateTimeString());

        $this->try('4321')->assertStatus(410);
    }

    public function test_an_expired_code_is_destroyed_not_left_lying(): void
    {
        // رمزٌ منتهٍ يبقى في الجدول يظلّ هدفاً للتخمين — ولا فائدة منه.
        $lifetime = (int) config('amial.otp.lifetime_seconds', 600);

        $this->seedOtp('4321', now()->subSeconds($lifetime + 60)->toDateTimeString());
        $this->try('4321');

        $this->assertDatabaseMissing('phone_verifications', ['phone' => $this->user->phone]);
    }

    public function test_a_fresh_code_inside_its_lifetime_still_works(): void
    {
        $this->seedOtp('4321', now()->subSeconds(30)->toDateTimeString());

        $this->try('4321')->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ الغيابُ يُقال
    // ══════════════════════════════════════════════════════════════════

    public function test_verifying_without_ever_requesting_says_so(): void
    {
        // (القاعدة ٧) لا يُقال «الرمز خطأ» لمن لم يطلب رمزاً — الجوابان
        // مختلفان، والخلطُ يُرسل المستعمل يبحث عن رسالةٍ لم تُرسَل.
        $body = $this->try('4321')->assertStatus(404)->json('errors.0.message');

        $this->assertStringContainsString('لم يُطلب', $body);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ حدُّ المعدّل على المسار نفسه
    // ══════════════════════════════════════════════════════════════════

    /**
     * **العدّادُ في الجدول يحمي رقماً واحداً**، والحدُّ على المسار يحمي
     * من يجرّب أرقاماً كثيرة. وأحدُهما بلا الآخر نصفُ حماية.
     */
    public function test_the_otp_routes_carry_a_tight_rate_limit(): void
    {
        $src = file_get_contents(base_path('routes/api/v1/api.php'));

        foreach (["'verifyOtp'", "'checkOtp'"] as $needle) {
            $at = strpos($src, $needle);
            $this->assertNotFalse($at, "{$needle} اختفى — حدِّث هذا الحارس");

            $window = substr($src, $at, 160);

            $this->assertStringContainsString('throttle:', $window,
                "{$needle} بلا حدٍّ خاصّ — والحدُّ العامّ ١٠٠/دقيقة يستنفد "
                . 'مساحةَ أربعة أرقامٍ في ساعةٍ ونصف');
        }
    }
}
