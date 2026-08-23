<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TransactionPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-PIN-BRUTEFORCE-001 — **بابُ تغيير الرمز بلا عدّادٍ ولا قفلٍ ولا حدّ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس، وحُسب لا خُمّن:**
 *
 *     المسارُ الحيّ : Helpers::pin_check → Hash::check مجرَّدة
 *     العدّاد      : لا شيء            القفل : لا شيء
 *     throttle     : **غيرُ مضبوطٍ إطلاقاً**
 *     وجاراه في الملفّ نفسِه: check-otp ٥/د · verify-otp ١٠/د
 *
 * ورمزُ العمليّات أربعةُ أرقام ⇒ فضاؤه **١٠٠٠٠**. فمن ملك جلسةً — هاتفاً
 * غيرَ مقفَل أو رمزاً مسروقاً — يستنفده كلَّه:
 *
 *     على ١٢٠ طلباً في الدقيقة (حدُّ المصادَق) ⇒ **٨٣ دقيقة**
 *
 * **ولا سطرَ في أيّ سجلٍّ يقول إنّ أحداً حاول** — `pin_check` لا تكتب
 * شيئاً: لا تدقيقاً، ولا حدثَ أمنٍ في شاشة «أمان الحساب».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وتصحيحٌ يُسجَّل على قياسٍ سابقٍ في هذه الجلسة نفسِها:** إصلاحُ CGNAT
 * رفع المصادَقَ من ٦٠ إلى ١٢٠ في الدقيقة — **فضاعف سرعةَ هذا الكسر**،
 * من ١٦٦ دقيقةً إلى ٨٣. البابُ كان مكسوراً قبله، والإصلاحُ وسّعه.
 * ويُقال لأنّ أثرَ التغيير جزءٌ منه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحمايةُ كانت مبنيّةً منذ كُتبت الخدمة:** `verify()` تعدّ وتقفل
 * وتُدوّن في التدقيق وفي «أمان الحساب»، و`changePin()` تناديها وتمنع
 * إعادةَ الرمز نفسِه. **وقِيس أنّ لا مُنادِيَ لها في المشروع كلِّه** —
 * أخرجها جردُ ساهر (`SERVICE_METHOD_UNREACHED`) لا مجموعةُ الاختبارات:
 * الدالّةُ **مُختبَرةٌ** وخضراء، ولا شيءَ كان يسأل **من يناديها**.
 */
class PinBruteForceGuardTest extends TestCase
{
    use RefreshDatabase;

    private function customerWithPin(string $pin = '1357'): User
    {
        $u = User::factory()->create(['type' => 2, 'is_active' => 1]);
        app(TransactionPinService::class)->setPin($u, $pin);

        return $u->fresh();
    }

    // ══════════════════════════════════════════════════════════════════
    // القفل — على الحساب
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function repeated_wrong_old_pins_lock_the_account(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه، مُصاغاً اختباراً.** كان كلُّ خطأٍ يُردّ
        // ٤٠١ ولا يترك أثراً، فالمحاولةُ التالية تبدأ من حيث بدأت الأولى.
        // ══════════════════════════════════════════════════════════════
        $u = $this->customerWithPin();
        $svc = app(TransactionPinService::class);

        for ($i = 0; $i < 8; $i++) {
            $svc->changePin($u->fresh(), (string) (2600 + $i), '2846');
        }

        $u->refresh();

        $this->assertNotNull($u->pin_locked_until,
            'ثمانِ محاولاتٍ خاطئةٍ ولا قفل — فالرمزُ ذو الأربعة أرقام يُستنفَد كلُّه');

        $this->assertTrue($u->pin_locked_until->isFuture(),
            'القفلُ مكتوبٌ في الماضي — أي لا قفل');
    }

    /** @test */
    public function a_locked_account_refuses_even_the_correct_old_pin(): void
    {
        // **وقفلٌ يُفتح بالرمز الصحيح ليس قفلاً**: المهاجمُ يجرّب حتّى
        // يصيب، والقفلُ يرفع عن نفسِه في اللحظة التي يجب أن يمسك فيها.
        $u = $this->customerWithPin('1357');
        $svc = app(TransactionPinService::class);

        for ($i = 0; $i < 8; $i++) {
            $svc->changePin($u->fresh(), (string) (2600 + $i), '2846');
        }

        $this->assertFalse($svc->changePin($u->fresh(), '1357', '2846'),
            'الرمزُ الصحيح مرّ والحسابُ مقفول — فالقفلُ زينة');
    }

    /** @test */
    public function the_same_pin_cannot_be_re_set(): void
    {
        // **وإعادةُ الرمز نفسِه تُقرأ «غُيّر» ولم يتغيّر شيء** — فيطمئنّ
        // من ظنّ أنّه بدّل رمزاً مكشوفاً.
        $u = $this->customerWithPin('1357');

        $this->assertFalse(
            app(TransactionPinService::class)->changePin($u, '1357', '1357'),
            'قُبل الرمزُ الجديد مطابقاً للقديم');
    }

    // ══════════════════════════════════════════════════════════════════
    // التوصيل — والبابان معاً (القاعدةُ الرابعة)
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     * @dataProvider doors
     */
    public function each_pin_door_goes_through_the_locking_service(string $relPath): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ويُقرأ من الشيفرة لا من تعليقها.** أوّلُ قلبٍ لحارسٍ أخٍ في
        // هذه الجلسة مرّ لأنّ التعليقَ العربيَّ يذكر اسمَ الدالّة —
        // وهو العطلُ المسجَّل في `CLAUDE.md`: «التعليقُ الذي يصف العطلَ
        // كان يُخفيه».
        // ══════════════════════════════════════════════════════════════
        $src = $this->codeOnly($relPath);

        $fn = substr($src, (int) strpos($src, 'function changePin'));
        $fn = substr($fn, 0, 4000);

        $this->assertStringContainsString('changePin($user', $fn,
            "بابُ الرمز في {$relPath} لا يمرّ بالخدمة القافلة — "
            . 'فكلُّ خطأٍ بلا عدّادٍ ولا قفلٍ ولا أثر');

        // ══════════════════════════════════════════════════════════════
        // **ولا يُمنع `pin_check` منعاً**, بل يُحصر في فرعٍ واحد.
        //
        // كُتب أوّلَ مرّةٍ `assertStringNotContainsString` — **فسقط على
        // تحسينٍ صحيح**: من لا رمزَ له بعدُ يُصادَق بكلمة مروره، ولا
        // سبيلَ إلى ذلك إلّا `pin_check` (و`verify` تسقط عليه لانقضاء
        // `FALLBACK_DEADLINE`).
        //
        // **وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى** — فيُقاس
        // الموضعُ لا الوجود: نداءٌ واحدٌ فقط، وداخل فرع «لا رمزَ بعد».
        // ══════════════════════════════════════════════════════════════
        $this->assertSame(1, substr_count($fn, 'Helpers::pin_check'),
            "{$relPath}: `pin_check` في أكثر من موضعٍ — فأحدُهما يتخطّى القفل");

        $firstTime = strpos($fn, 'empty($user->transaction_pin)');
        $legacy = strpos($fn, 'Helpers::pin_check');

        $this->assertNotFalse($firstTime,
            "{$relPath}: `pin_check` بلا شرطِ «لا رمزَ بعد» — فكلُّ حسابٍ يتخطّى القفل");

        $this->assertGreaterThan($firstTime, $legacy,
            "{$relPath}: `pin_check` خارجَ فرع التعيين الأوّل — أي على كلّ حساب");
    }

    /**
     * @test
     * @dataProvider doors
     */
    public function each_pin_door_says_how_long_the_lock_lasts(string $relPath): void
    {
        // **ورفضٌ لا يقول متى ينتهي يُنتج محاولةً كلَّ ثانيةٍ ثمّ مكالمةَ
        // دعم.** «الرمز خطأ» على حسابٍ مقفولٍ رسالةٌ كاذبة.
        $src = $this->codeOnly($relPath);
        $fn = substr($src, (int) strpos($src, 'function changePin'), 4000);

        $this->assertStringContainsString('pin_locked_until', $fn,
            "{$relPath} لا يفرّق بين «الرمز خطأ» و«الحساب مقفول»");

        $this->assertMatchesRegularExpression('/\{\$mins\}\s*دقيقة/u', $fn,
            'الرفضُ لا يذكر المدّةَ الباقية');
    }

    public static function doors(): array
    {
        return [
            'العميل' => ['app/Http/Controllers/Api/V1/Customer/Auth/CustomerAuthController.php'],
            'الوكيل' => ['app/Http/Controllers/Api/V1/Agent/AgentController.php'],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // الحدّ — على الباب
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function both_pin_doors_carry_a_rate_limit(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والقفلُ على الحساب لا يُغني عن الحدّ على الباب.** ذاك يحمي
        // رقماً واحداً؛ وهذا يمنع مسحاً عريضاً — رمزاً واحداً شائعاً
        // (١٢٣٤) على آلاف الحسابات من جلسةٍ واحدة، ولا حسابَ يبلغ عدّادَه.
        // ══════════════════════════════════════════════════════════════
        $found = [];

        foreach (Route::getRoutes() as $r) {
            if (! str_ends_with($r->uri(), 'change-pin')) {
                continue;
            }

            // ══════════════════════════════════════════════════════
            // **ولا يُقرأ حدُّ المجموعة حدّاً على الباب.**
            //
            // كُتب أوّلَ مرّةٍ بـ`gatherMiddleware()`، وهي تجمع وسائطَ
            // المجموعة أيضاً — ومجموعةُ api صار عليها `throttle:api`
            // (١٢٠/د للمصادَق) بعد إصلاح CGNAT في هذه الجلسة نفسِها.
            // **فمرّ القلبُ ونُزع الحدُّ عن الباب والحارسُ أخضر.**
            //
            // ومئةٌ وعشرون في الدقيقة تستنفد ١٠٠٠٠ في ٨٣ دقيقة — أي
            // «محميّ» بحدٍّ لا يمنع الكسر. فيُقرأ وسيطُ المسار وحدَه.
            // ══════════════════════════════════════════════════════
            $own = (array) ($r->getAction('middleware') ?? []);
            $limited = false;

            foreach (array_filter($own, 'is_string') as $m) {
                if (! preg_match('~^(throttle|amial\.rate-limit):(.+)$~', $m, $mm)) {
                    continue;
                }

                // **والحدُّ يُقاس رقماً لا وجوداً.** `throttle:api` على
                // الباب لا يختلف عن الذي على المجموعة.
                if (preg_match('~(\d+)~', $mm[2], $n) && (int) $n[1] <= 10) {
                    $limited = true;
                }
            }

            $found[$r->uri()] = $limited;
        }

        $this->assertNotEmpty($found, 'لا مسارَ تغييرِ رمزٍ مسجَّلٌ إطلاقاً');

        foreach ($found as $uri => $limited) {
            $this->assertTrue($limited,
                "المسار «{$uri}» بلا حدِّ معدّلٍ خاصٍّ به (١٠/د فأقلّ) — ورمزٌ من أربعة أرقامٍ "
                . 'يُمسَح على آلاف الحسابات من جلسةٍ واحدة');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // المهلةُ المنقضية
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_expired_fallback_window_is_announced_not_swallowed(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **مهلةٌ انقضت بلا أن يعلم أحد.** `FALLBACK_DEADLINE` تاريخُه
        // ٢٠٢٦-٠٦-١٥، ومرّ. فكلُّ حسابٍ بلا `transaction_pin` يُرفض في
        // كلّ مسارٍ يطلب الرمز — **ويقرأ صاحبُه «الرمز خطأ»**.
        //
        // ورفضٌ يُسمّي سبباً غيرَ سببه يُرسل الدعمَ خلف عطلٍ لا وجودَ له.
        // فالمطلوبُ أثرٌ يقول ما وقع، لا تمديدُ المهلة — ذاك قرارُ أمانٍ
        // لصاحب المشروع.
        // ══════════════════════════════════════════════════════════════
        $deadline = \Carbon\Carbon::parse(
            \App\Services\TransactionPinService::FALLBACK_DEADLINE);

        if ($deadline->isFuture()) {
            $this->markTestSkipped('المهلةُ لم تنقضِ بعد — ' . $deadline->toDateString());
        }

        $u = User::factory()->create(['type' => 2, 'is_active' => 1]);
        $u->transaction_pin = null;
        $u->saveQuietly();

        $this->assertFalse(app(TransactionPinService::class)->verify($u->fresh(), 'whatever'),
            'حسابٌ بلا رمزٍ مرّ بعد انقضاء المهلة — فالإغلاقُ لم يقع');

        // **والبصمةُ مُجزَّأةٌ لا خام** — تُحسب كما تحسبها الخدمة، لا
        // كما أتخيّلها. (وأوّلُ كتابةٍ لهذا الاختبار طابقت المفتاحَ
        // الخام فسقطت على شيفرةٍ صحيحة.)
        $this->assertDatabaseHas('system_errors', [
            'fingerprint' => hash('sha256', 'ops|pin.fallback.window_closed'),
        ]);
    }

    // ── أدوات ─────────────────────────────────────────────────────────

    /** الشيفرةُ بلا تعليقاتها — مرحلتان لا تعبيرٌ واحدٌ جشع. */
    private function codeOnly(string $relPath): string
    {
        $s = (string) file_get_contents(base_path($relPath));
        $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? '';

        return preg_replace('~^[ \t]*//[^\n]*$~m', '', $s) ?? '';
    }
}
