<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * AMIAL-PENTEST-2FA-001 — **اختبارُ اختراقٍ للبوّابة الثانية، بلا رمز.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤالُ كما وصل:** «تسجيلُ دخول الأدمن يتطلّب رمزَ مصادقةٍ من ستّة
 * أرقام — أتستطيع اختبارَ اختراقٍ وأنت لا تملك الرمز؟»
 *
 * **والجوابُ نعم، وهو دفاعٌ عن هذا النظام لا هجومٌ على غيره**: يُهاجَم
 * الحاجزُ من موضع مهاجمٍ لا يعرف السرَّ، بكلّ المتّجهات المعروفة، **من
 * الاختبار الآليّ لا من الخادم الحيّ** — فالخادمُ يخدم مالاً حقيقيّاً
 * ولا يُضرَب.
 *
 * وقراءةُ الشيفرة تُظهر النيّةَ، **والاختبارُ يُظهر الصمود**: حاجزٌ
 * مكتوبٌ بعقلٍ أمنيٍّ قد ينهار على متّجهٍ لم يخطر لكاتبه. (القاعدة
 * الثانية: كلُّ حارسٍ يُجرَّب — وهنا يُجرَّب من عشرة أبواب.)
 *
 * **كلُّ اختبارٍ هنا هجومٌ يجب أن يفشل.** فنجاحُ الاختبار = فشلُ الهجوم.
 */
class AdminTwoFactorPenetrationTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorAuthService $svc;
    private User $admin;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(TwoFactorAuthService::class);

        // مديرٌ فعّل المصادقةَ الثنائيّة — الهدفُ الذي نحاول اختراقَه.
        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin',
            'phone' => '967700000001', 'is_active' => 1,
            'password' => bcrypt('CorrectHorse#2026'),
        ]);

        // **تُنظَّف الذاكرةُ والحدُّ** — فحدُّ المحاولات يتراكم بين
        // الاختبارات في العمليّة الواحدة، فيُخرج «تجاوزتَ المحاولات»
        // على أوّل مدخلٍ في اختبارٍ لاحق. (وهذا خطأُ تجهيزٍ لا ثغرة.)
        Cache::flush();
        Cache::forget('2fa_attempts:'.$this->admin->id);

        $setup = $this->svc->setup($this->admin);
        $this->secret = $setup['secret'];
        $this->admin->two_factor_enabled = true;
        $this->admin->two_factor_confirmed_at = now();
        $this->admin->save();
    }

    // ═════════════════════════════════════════════════════════════════
    // متّجهُ ①: الوصولُ المباشرُ إلى ما بعد الباب
    // ═════════════════════════════════════════════════════════════════

    /**
     * **① لوحةُ الإدارة لا تُفتح بلا مرورٍ بالبابين معاً.**
     *
     * أشهرُ اختراقٍ لا يكسر الرمزَ بل يتخطّاه: يُطلَب عنوانُ اللوحة
     * مباشرةً. فإن كانت الجلسةُ تُفتح بكلمة المرور وحدَها، فالرمزُ زينة.
     */
    /** @test */
    public function the_dashboard_is_unreachable_without_passing_both_doors(): void
    {
        $res = $this->get(route('admin.dashboard'));

        $this->assertContains($res->status(), [302, 401, 403], sprintf(
            '**لوحةُ الإدارة فُتحت بلا مصادقةٍ إطلاقاً (%d).** فالرمزُ '
            .'كلُّه لا يحرس شيئاً.', $res->status()));

        // ومن أُعيد توجيهُه، لا يُعاد إلى اللوحة نفسِها.
        $location = $res->headers->get('Location') ?? '';
        $this->assertStringNotContainsString('admin.dashboard', $location);
    }

    /**
     * **② ومن مرّ بكلمة المرور فقط، لا يزال خارجاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * هذا قلبُ الاختبار: الجلسةُ في حالة «التعليق» — كلمةُ المرور صحّت
     * والرمزُ لم يُدخَل. فإن حملت هذه الجلسةُ أيَّ صلاحيّةٍ للوحة، فالبابُ
     * الثاني مفتوحٌ من الداخل. (`AMIAL-2FA-DOOR-001`: «الجلسةُ لا تُفتح
     * قبل الرمز».)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_pending_session_carries_no_admin_authority(): void
    {
        // نضع الجلسةَ في حالة التعليق يدويّاً — كما يتركها LoginController
        // بعد نجاح كلمة المرور وقبل الرمز.
        $res = $this->withSession(['amial.2fa.pending_user' => $this->admin->id])
            ->get(route('admin.dashboard'));

        $this->assertContains($res->status(), [302, 401, 403], sprintf(
            '**جلسةُ «ما قبل الرمز» فتحت اللوحة (%d).** فمن عرف كلمةَ '
            .'المرور دخل، والرمزُ من ستّة أرقامٍ لا يُسأل عنه.',
            $res->status()));

        // وصراحةً: لا مستخدمَ مصادَقاً في حارس `user`.
        $this->assertFalse(auth('user')->check(),
            '**حارسُ المصادقة يرى المديرَ داخلاً وهو في التعليق.**');
    }

    // ═════════════════════════════════════════════════════════════════
    // متّجهُ ②: كسرُ الرمز نفسِه
    // ═════════════════════════════════════════════════════════════════

    /**
     * **③ الرمزُ الخطأُ يُرفَض دائماً.**
     */
    /** @test */
    public function a_wrong_code_never_verifies(): void
    {
        foreach (['000000', '123456', '111111', '999999', '654321'] as $guess) {
            // نتجنّب الاصطدامَ العرَضيَّ بالرمز الصحيح لهذه اللحظة.
            if ($guess === $this->svc->generateTotp($this->secret)) {
                continue;
            }

            $this->assertFalse($this->svc->verify($this->admin, $guess),
                sprintf('**رمزٌ مخمَّنٌ «%s» نجح.** والمساحةُ مليون، '
                    .'فالتخمينُ لا يُقبَل.', $guess));
        }
    }

    /**
     * **④ والرمزُ الفارغُ أو المشوَّهُ لا يمرّ.**
     *
     * فحاجزٌ يقبل `''` أو `null` أو نصّاً غيرَ رقميٍّ ينهار بأتفهِ مدخل.
     */
    /** @test */
    public function empty_or_malformed_codes_are_rejected(): void
    {
        Cache::flush();
        Cache::forget('2fa_attempts:'.$this->admin->id);

        foreach (['', '0', '12345', '1234567', 'abcdef', '   ', '12 34 56',
            '000000e0', "123456\n"] as $bad) {
            // **يُنظَّف الحدُّ بين كلّ مدخل** — فالمفحوصُ هنا أنّ المشوَّهَ
            // يُرفَض، لا تفاعلُه مع خنق المحاولات (وذاك مقيسٌ وحدَه في
            // `brute_force_is_throttled`). فبلا هذا يُقفل الحسابُ عند
            // المدخل الخامس ويُخفي حكمَ الأربعة الباقية.
            Cache::forget('2fa_attempts:'.$this->admin->id);

            $this->assertFalse($this->svc->verify($this->admin, $bad),
                sprintf('**مدخلٌ مشوَّهٌ «%s» قُبل.**', addslashes($bad)));
        }
    }

    /**
     * **⑤ والقوّةُ الغاشمةُ تُوقَف قبل أن تقترب.**
     *
     * ══════════════════════════════════════════════════════════════════
     * ستّةُ أرقامٍ مساحتُها مليون. فبلا حدٍّ، آلافُ المحاولاتِ في الثانية
     * تكسرها في ساعات. **فيُقاس أنّ الحدَّ يُطبَّق فعلاً** — لا أنّه
     * مكتوبٌ في ثابت. (القاعدة الثالثة: يُقاس ثمّ يُقال.)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function brute_force_is_throttled_before_it_gets_close(): void
    {
        $blocked = false;

        // مئةُ محاولةٍ متتالية — لو مرّت كلُّها بلا خنق، فالبابُ مشرَّع.
        for ($i = 0; $i < 100; $i++) {
            try {
                $ok = $this->svc->verify($this->admin, str_pad((string) $i, 6, '0', STR_PAD_LEFT));
                $this->assertFalse($ok, 'رمزٌ متسلسلٌ صادف الصحيح — احتمالٌ ضئيلٌ يُعاد.');
            } catch (\RuntimeException $e) {
                // الخدمةُ ترمي عند تجاوز الحدّ — وهو الأثرُ المطلوب.
                $blocked = true;
                break;
            }
        }

        $this->assertTrue($blocked, sprintf(
            '**مئةُ محاولةٍ مرّت بلا خنق.** فالمهاجمُ يجرّب المليونَ كلَّه '
            .'في وقتٍ قصير، والرمزُ لا يحمي.'));
    }

    // ═════════════════════════════════════════════════════════════════
    // متّجهُ ③: إعادةُ الرمز الصحيح
    // ═════════════════════════════════════════════════════════════════

    /**
     * **⑥ والرمزُ الصحيحُ لا يُستعمَل مرّتين.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فمهاجمٌ يلمح الرمزَ على كتف المدير (أو يلتقطه من شبكة) يعيد إرسالَه
     * قبل انتهاء نافذته. فبلا منع إعادةٍ، رمزٌ صحيحٌ واحدٌ مسروقٌ يفتح
     * البابَ ثانيةً. **وهذا يفترض أنّنا نملك الرمز** — لكنّ الدفاعَ ضدّ
     * السرقة جزءٌ من الاختبار.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_valid_code_cannot_be_replayed(): void
    {
        $code = $this->svc->generateTotp($this->secret);

        $this->assertTrue($this->svc->verify($this->admin, $code),
            'الرمزُ الصحيحُ لم يُقبَل — وهذا عطلٌ لا أمان.');

        // نفسُ الرمز، في نفس النافذة، مرّةً ثانية.
        $this->assertFalse($this->svc->verify($this->admin, $code),
            '**الرمزُ الصحيحُ قُبل مرّتين.** فرمزٌ مسروقٌ يُعاد استعمالُه '
            .'داخل نافذته — ومنعُ الإعادة هو الحاجز.');
    }

    /**
     * **⑦ ورمزُ الاسترداد يُستهلك بعد استعماله.**
     *
     * فرمزُ استردادٍ يعمل مرّتين ضِعفُ ما وُعد به المدير: واحدٌ مسروقٌ
     * يبقى صالحاً.
     */
    /** @test */
    public function a_recovery_code_is_single_use(): void
    {
        $fresh = $this->svc->setup($this->admin);
        $this->admin->two_factor_enabled = true;
        $this->admin->two_factor_confirmed_at = now();
        $this->admin->save();

        Cache::forget('2fa_attempts:' . $this->admin->id);
        Cache::flush();

        $recovery = $fresh['recovery_codes'][0];

        $this->assertTrue($this->svc->verify($this->admin, $recovery),
            'رمزُ الاسترداد لم يُقبَل أوّلَ مرّة.');

        $this->assertFalse($this->svc->verify($this->admin, $recovery),
            '**رمزُ الاسترداد قُبل مرّتين.** فواحدٌ مسروقٌ يبقى مفتاحاً '
            .'صالحاً بعد استعماله.');
    }

    // ═════════════════════════════════════════════════════════════════
    // متّجهُ ④: خلطُ الهويّات
    // ═════════════════════════════════════════════════════════════════

    /**
     * **⑧ ورمزُ مديرٍ لا يفتح حسابَ مديرٍ آخر.**
     *
     * فلكلٍّ سرُّه، والرمزُ المشتقُّ من سرِّ فلانٍ لا يجوز أن يمرّ على
     * سرِّ غيره — وإلّا كفى اختراقُ حسابٍ واحدٍ لفتح الباقي.
     */
    /** @test */
    public function one_admins_code_does_not_open_another(): void
    {
        $other = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin',
            'phone' => '967700000002', 'is_active' => 1,
        ]);

        $otherSetup = $this->svc->setup($other);
        $other->two_factor_enabled = true;
        $other->two_factor_confirmed_at = now();
        $other->save();

        // رمزُ «الآخر» الصحيحُ الآن — يُجرَّب على حسابنا.
        $otherCode = $this->svc->generateTotp($otherSetup['secret']);

        if ($otherCode === $this->svc->generateTotp($this->secret)) {
            $this->markTestSkipped('تصادفَ الرمزان في هذه اللحظة — يُعاد.');
        }

        $this->assertFalse($this->svc->verify($this->admin, $otherCode),
            '**رمزُ مديرٍ فتح حسابَ آخر.** فاختراقُ واحدٍ يفتح الجميع.');
    }

    /**
     * **⑨ وحسابٌ عُطِّل بين البابين لا تُفتح له جلسة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * مهاجمٌ سرق كلمةَ مرور مديرٍ، فعُطِّل الحسابُ استجابةً — ثمّ أكمل هو
     * إدخالَ الرمز. فإن أنشأت البوّابةُ جلسةً متأخّرةً لحسابٍ أُغلق،
     * فالتعطيلُ لا يحمي. (وهذا العطلُ مكتوبٌ في المتحكّم أنّه عولج — فيُقاس.)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function an_account_disabled_mid_challenge_gets_no_session(): void
    {
        $code = $this->svc->generateTotp($this->secret);

        // عُطِّل بعد كلمة المرور، قبل الرمز.
        $this->admin->update(['is_active' => 0]);

        $res = $this->withSession(['amial.2fa.pending_user' => $this->admin->id])
            ->post('/admin/two-factor', ['code' => $code]);

        $this->assertFalse(auth('user')->check(), sprintf(
            '**حسابٌ مُعطَّلٌ حصل على جلسةٍ بعد الرمز.** فالتعطيلُ '
            .'استجابةً لسرقةٍ لا يوقف الدخول. (الحالة %d)', $res->status()));
    }

    /**
     * **⑩ والرمزُ لا يُقبَل لحسابٍ لم يفعّل المصادقةَ أصلاً.**
     *
     * فحسابٌ بلا سرٍّ يجب أن يردّ كلَّ رمز — لا أن يقبل «أيَّ شيء» لأنّ
     * لا سرَّ يُقارَن به.
     */
    /** @test */
    public function verification_fails_closed_when_2fa_is_not_enabled(): void
    {
        $plain = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin',
            'phone' => '967700000003', 'is_active' => 1,
        ]);

        foreach (['000000', '123456', ''] as $guess) {
            $this->assertFalse($this->svc->verify($plain, $guess),
                '**حسابٌ بلا سرٍّ قبِل رمزاً.** فالغيابُ يُقرأ «افتح» بدل '
                .'«أغلق» — وهو الفشلُ المفتوح.');
        }
    }
}
