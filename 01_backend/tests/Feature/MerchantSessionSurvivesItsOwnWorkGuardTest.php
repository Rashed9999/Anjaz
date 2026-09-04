<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-SESSION-001 — **عملُ التاجر يُحتسَب نشاطاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع، بنصّ صاحب المشروع:** «في محفظة التجار عند الضغط
 * على تحويل يتم الخروج من الحساب».
 *
 * **وقِيس فإذا العطلُ ليس في الزرّ:**
 *
 *   تاجرٌ آخرُ نشاطٍ له قبل ٤٥ دقيقة
 *   GET /api/v1/amial/merchant/daily-stats   → 200، وآخرُ نشاطه **لم يتحرّك**
 *   GET /api/v1/customer/get-customer        → 401 {"message":"Token Expired"}
 *
 * `trackLastActiveAt` كان على مجموعتَي العميل والوكيل ولم يكن على
 * مجموعة `amial/*`. **فالتاجرُ يعمل ساعةً في شاشاته والخادمُ يحسبه
 * نائماً.** ثمّ تُنادي شاشةُ التحويل ملفَّ العميل — وهي المجموعةُ التي
 * تفحص الخمول — فيحذف `InactiveAuthCheck` **رموزَه كلَّها** ويردّ ٤٠١،
 * ويقرؤها `ApiChecker` خروجاً من الحساب.
 *
 * **وهذا الحارسُ يمسك الطرفين:** أنّ العملَ يُنعش النشاط، وأنّ شاشات
 * التاجر لا تنادي مساراتٍ تردّه ٤٠٣ أصلاً.
 */
class MerchantSessionSurvivesItsOwnWorkGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(?\DateTimeInterface $lastActive = null): User
    {
        $user = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
            'last_active_at' => $lastActive,
        ]);

        MerchantProfile::create([
            'user_id' => $user->id, 'tier' => 'small',
            'verification_status' => 'verified', 'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
            'single_receive_limit' => '5000000', 'daily_receive_limit' => '50000000',
        ]);

        return $user;
    }

    /**
     * **① العملُ في شاشات التاجر يُنعش آخرَ نشاطه.**
     *
     * وهو الفحصُ الجذر: بدونه كلُّ ما بعده مسألةُ وقتٍ لا مسألةُ زرّ.
     */
    /** @test */
    public function working_in_the_merchant_screens_refreshes_the_session(): void
    {
        $merchant = $this->merchant(now()->subMinutes(45));

        $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/daily-stats')->assertOk();

        $fresh = $merchant->fresh()->last_active_at;

        $this->assertNotNull($fresh);
        $this->assertLessThan(120, now()->diffInSeconds($fresh, absolute: true),
            'التاجرُ عمل في شاشته والخادمُ ما زال يحسبه نائماً — '
            . 'وبعد الحدّ يُحذَف رمزُه ويُطرَد من حسابه');
    }

    /**
     * **② فلا يُطرَد عند أوّل نداءٍ إلى مسارٍ قديم.**
     *
     * وهذه هي الضغطةُ التي وصلت صاحبَ المشروع بعينها.
     */
    /** @test */
    public function pressing_transfer_after_a_long_shift_does_not_end_the_session(): void
    {
        $merchant = $this->merchant(now()->subMinutes(45));

        // ورديّةٌ في شاشات التاجر
        $this->actingAs($merchant, 'api')->getJson('/api/v1/amial/merchant/daily-stats');

        // ثمّ «تحويل» — وكان هنا ٤٠١ يحذف الرموزَ كلَّها
        $r = $this->actingAs($merchant, 'api')->getJson('/api/v1/customer/get-customer');

        $this->assertNotSame(401, $r->status(),
            'ضغطةُ «تحويل» أنهت جلسةَ التاجر — Token Expired على حسابٍ يعمل الآن');
    }

    /**
     * **③ ولا تُنادي شاشاتُ محفظة التاجر مساراتِ العميل.**
     *
     * وهي تردّ ٤٠٣ لكلّ تاجرٍ دائماً (وسيطُ `customerAuth`)، فالقائمةُ
     * فارغةٌ أبداً و«لا حركةَ في المحفظة بعد» تُعرَض على متجرٍ يبيع —
     * **غيابٌ يُقرأ صفراً** (القاعدة السابعة).
     */
    /** @test */
    public function the_merchant_wallet_reads_merchant_routes_only(): void
    {
        $repo = file_get_contents(base_path(
            '../02_flutter_app/lib/features/merchant/domain/repositories/merchant_repo.dart'));

        // **والتعليقُ يُنزَع قبل القياس.** وقع في هذا المشروع أن مرّ حارسٌ
        // لأنّ الكلمةَ وردت في تعليقٍ عربيٍّ يشرح العطل — أي أنّ الشرحَ
        // أخفاه. وهذه صورتُه المقلوبة: الشرحُ يُسقط حارساً سليماً. وفي
        // الحالين القياسُ على **الشيفرة المنفَّذة** لا على نصّ الملفّ.
        $code = preg_replace('~^\s*(//|///|\*|/\*).*$~m', '', $repo);

        foreach (['customer/get-customer', 'customer/transaction-history'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "محفظةُ التاجر تنادي مساراً يردّها ٤٠٣ دائماً: {$forbidden}");
        }

        $this->assertStringContainsString('/api/v1/amial/merchant/ledger', $code,
            'حركاتُ المحفظة بلا مصدرٍ — والقائمةُ تبقى فارغةً بلا سبب');
    }

    /**
     * **④ ومسارُ الحركات الذي تناديه يعمل للتاجر فعلاً.**
     *
     * ولا يُكتفى بأنّ النصَّ تغيّر: مسارٌ مكتوبٌ صحيحاً وغيرُ مسجَّلٍ
     * يردّ ٤٠٤، والقائمةُ تبقى فارغةً كما كانت.
     */
    /** @test */
    public function the_ledger_route_answers_the_merchant(): void
    {
        $merchant = $this->merchant(now());

        $r = $this->actingAs($merchant, 'api')
            ->getJson('/api/v1/amial/merchant/ledger')->assertOk();

        $this->assertIsArray($r->json('meta.entries'),
            'الردُّ لا يحمل `entries` — والتطبيقُ يقرؤها بهذا الاسم');
        $this->assertArrayHasKey('current_balance', $r->json('meta.account'));
    }

    /**
     * **⑤ و٤٢٩ لا تُنهي جلسةً في التطبيق.**
     *
     * حدُّ محاولاتٍ يمرّ بعد ثوانٍ عُومل معاملةَ رمزٍ منتهٍ، فيُطرَد
     * المستعملُ على ضغطتين متتاليتين ولا يفهم لماذا.
     */
    /** @test */
    public function a_rate_limit_is_not_an_expired_session(): void
    {
        $checker = file_get_contents(base_path('../02_flutter_app/lib/data/api/api_checker.dart'));

        $this->assertStringNotContainsString('statusCode == 401 || response.statusCode == 429', $checker,
            '٤٢٩ ما زالت تحذف رمزَ الدخول — وهي حدُّ معدّلٍ لا انتهاءُ جلسة');
        $this->assertStringContainsString('statusCode == 429', $checker,
            'و٤٢٩ لا تُترك بلا رسالةٍ كذلك — الصمتُ يُرسل صاحبَه يجرّب شبكتَه');
    }
}
