<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoAccountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-DEMO-CREDENTIALS-001 — **كلمةُ مرورِ إدارةٍ منشورةٌ وتُعاد كلَّ نشرة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس، وهو أخطرُ ما أخرجه التدقيق كلُّه:**
 *
 *     docker/entrypoint.sh:265-270
 *       if [ "$DB_OK" -eq 1 ]; then       ← **لا شرطَ بيئةٍ إطلاقاً**
 *           php artisan amial:ensure-demo-staff
 *
 *     EnsureDemoStaff:
 *       admin@amialpay.com · 967777000001 · Pass@2026 · ADMIN · نشِط
 *
 * الكلمةُ **مكتوبةٌ في المستودع**، والأمرُ يجري في **كلّ إقلاع حاوية** على
 * الإنتاج. ومن ملكها ملك اللوحةَ كلَّها.
 *
 * **وأسوأُ ما فيه أنّه يُعيد الضبط لا أنّه يُنشئ:** فلو غيّرها صاحبُ
 * المشروع اليوم **أعادها النشرُ غداً**، ولا سطرَ في أيّ سجلٍّ يقول ذلك.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولم يمسكه شيء:** ليس عطلاً في المنطق — الأمرُ يفعل ما كُتب له بالضبط،
 * وتعليقُه يقول القصدَ صراحةً: «**«ضمان» يعني بيانات دخول معلومة دوماً**».
 * قرارُ راحةٍ في التطوير، **انتقل إلى الإنتاج مع سطرٍ في `entrypoint.sh`**.
 *
 * ولم يخرج من Snyk أيضاً: قائمتُه تُصنّف «كلمةُ مرورٍ مضمَّنة» درجةً دنيا
 * ومعها ١٠٢ أخرى أكثرُها بذورٌ واختبارات. **وُجد بتتبُّع الصنف إلى ما
 * يعمل فعلاً** — لا بقراءة القائمة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لا تُحذَف الأوامر:** `EnsureDemoStaff` هو مسارُ إنشاء الأدمن
 * الوحيد خارج بذرةٍ محروسة. فحذفُه يترك قاعدةً جديدةً **بلا بابِ إدارة** —
 * وفقدُ الوصول أسوأ من كلمةٍ معلومة، ولا يُصلَح إلّا بيدٍ على القاعدة.
 */
class DemoCredentialsGuardTest extends TestCase
{
    use RefreshDatabase;

    private function inProduction(?string $optIn = null): void
    {
        $this->app['env'] = 'production';
        app()->detectEnvironment(fn () => 'production');

        $optIn === null
            ? putenv(DemoAccountPolicy::OPT_IN)
            : putenv(DemoAccountPolicy::OPT_IN . '=' . $optIn);
    }

    protected function tearDown(): void
    {
        putenv(DemoAccountPolicy::OPT_IN);

        foreach (['ADMIN', 'AGENT', 'MERCHANT', 'CUSTOMER'] as $r) {
            putenv("AMIAL_BOOTSTRAP_{$r}_PASSWORD");
        }

        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════
    // السياسة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function production_refuses_the_repository_password_by_default(): void
    {
        // **هذا هو العطلُ بعينه.** كلمةٌ في مستودعٍ على حسابِ إدارةٍ نشِط.
        $this->inProduction();

        $this->assertFalse(DemoAccountPolicy::knownPasswordAllowed(),
            'الإنتاجُ يقبل كلمةَ المرور المكتوبةَ في المستودع بلا موافقةٍ صريحة');

        $this->assertFalse(DemoAccountPolicy::mayResetExisting(),
            'الإنتاجُ يُعيد ضبطَ كلمةِ حسابٍ قائم — فتغييرُ صاحب المشروع يُمحى بالنشر');
    }

    /** @test */
    public function an_explicit_opt_in_restores_the_demo_behaviour(): void
    {
        // **ومنعٌ لا مخرجَ منه يُلتَفّ عليه.** العرضُ والتجربةُ يحتاجان
        // بياناتِ دخولٍ معلومة، فيُفتَح البابُ بمفتاحٍ يُكتب مرّةً ويُقرأ.
        $this->inProduction('true');

        $this->assertTrue(DemoAccountPolicy::knownPasswordAllowed());
        $this->assertTrue(DemoAccountPolicy::mayResetExisting());
    }

    /** @test */
    public function development_is_untouched(): void
    {
        // **ولا يُشلّ التطوير.** خارج الإنتاج تبقى البياناتُ معلومةً —
        // وإلّا صار كلُّ مطوّرٍ يبحث عن كلمةٍ مولَّدةٍ في سجلّ.
        $this->assertTrue(DemoAccountPolicy::knownPasswordAllowed(),
            'بيئةُ الاختبار تُعامَل كالإنتاج — فالتطويرُ يُشلّ بلا سبب');
    }

    /** @test */
    public function a_generated_password_is_not_the_repository_one(): void
    {
        $this->inProduction();

        $generated = DemoAccountPolicy::passwordForNewAccount('Pass@2026', 'AMIAL_BOOTSTRAP_ADMIN_PASSWORD');

        $this->assertNotSame('Pass@2026', $generated,
            'الكلمةُ المولَّدةُ هي المنشورةُ نفسُها');

        $this->assertGreaterThanOrEqual(20, strlen($generated),
            'كلمةٌ مولَّدةٌ قصيرةٌ تُخمَّن — والحسابُ إدارة');
    }

    /** @test */
    public function a_configured_bootstrap_password_wins_over_generation(): void
    {
        // **ومن ضبطها يريدها هو** — فتوليدٌ يتجاهله يُنتج حساباً لا يعرف
        // صاحبُ المشروع كلمتَه، ثمّ مكالمةً.
        $this->inProduction();
        putenv('AMIAL_BOOTSTRAP_ADMIN_PASSWORD=Chosen#12345678');

        $this->assertSame('Chosen#12345678',
            DemoAccountPolicy::passwordForNewAccount('Pass@2026', 'AMIAL_BOOTSTRAP_ADMIN_PASSWORD'));
    }

    /** @test */
    public function the_log_never_prints_a_password_that_was_not_written(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وسطرٌ يطبع كلمةً لم تُكتَب يكذب مرّتين**: يُوهم القارئَ أنّها
        // تعمل، ويُفشيها وهي غيرُ ذات صلة. وسجلُّ النشر يُقرأ ويُشارَك.
        // ══════════════════════════════════════════════════════════════
        $this->inProduction();

        $this->assertStringContainsString('لم تُغيَّر',
            DemoAccountPolicy::describe(null, 'AMIAL_BOOTSTRAP_ADMIN_PASSWORD'));

        putenv('AMIAL_BOOTSTRAP_ADMIN_PASSWORD=Chosen#12345678');

        $this->assertStringNotContainsString('Chosen#12345678',
            DemoAccountPolicy::describe('Chosen#12345678', 'AMIAL_BOOTSTRAP_ADMIN_PASSWORD'),
            'السجلُّ يطبع كلمةً ضبطها صاحبُ المشروع — وهو يعرفها ولا يحتاجها مطبوعة');
    }

    // ══════════════════════════════════════════════════════════════════
    // التوصيل — والسياسةُ بلا مُنادٍ ليست سياسة
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     * @dataProvider commands
     */
    public function every_demo_command_asks_the_policy_before_writing(string $file): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ويُقرأ من الشيفرة بلا تعليقاتها** — فالتعليقُ الذي يشرح
        // الحمايةَ كان يُخفي غيابَها مرّتين في هذه الجلسة.
        // ══════════════════════════════════════════════════════════════
        $code = $this->codeOnly("app/Console/Commands/{$file}.php");

        preg_match_all('~^.*?->password\s*=\s*Hash::make\(.*$~m', $code, $writes);

        $this->assertNotEmpty($writes[0], "{$file}: لا موضعَ كتابةِ كلمةٍ — تغيّرت البنية");

        foreach ($writes[0] as $line) {
            $this->assertTrue($this->comesFromPolicy($code, $line),
                "{$file}: سطرٌ يكتب كلمةَ مرورٍ بلا سياسة — «" . trim($line) . '»');
        }
    }

    public static function commands(): array
    {
        return [
            'الإدارة والوكيل' => ['EnsureDemoStaff'],
            'التجّار' => ['EnsureDemoMerchants'],
            'العملاء' => ['EnsureDemoUsers'],
        ];
    }

    /** @test */
    public function the_entrypoint_documents_that_these_run_on_every_boot(): void
    {
        // **وسطرٌ في `entrypoint.sh` هو الذي نقل قرارَ التطوير إلى
        // الإنتاج.** فيُقال عنده صراحةً، لئلّا يُعاد بحسن نيّة.
        $sh = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString('ensure-demo-staff', $sh,
            'اختفى الأمرُ من الإقلاع — وقاعدةٌ جديدةٌ تبقى بلا بابِ إدارة');

        $this->assertStringContainsString(DemoAccountPolicy::OPT_IN, $sh,
            '`entrypoint.sh` لا يذكر مفتاحَ الموافقة — فمن يقرؤه يظنّ '
            . 'البياناتِ المعلومةَ تُكتب دائماً كما كانت');
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_live_admin_still_exists_after_the_policy_refuses(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وفقدُ الوصول أسوأ من كلمةٍ معلومة.** المنعُ يقع على **إعادة
        // الضبط** لا على الإنشاء — فقاعدةٌ جديدةٌ تخرج ببابِ إدارةٍ
        // موجود، بكلمةٍ مولَّدةٍ أو مضبوطة.
        // ══════════════════════════════════════════════════════════════
        $this->inProduction();

        $this->artisan('amial:ensure-demo-staff')->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@amialpay.com',
            'type' => 0,
            'is_active' => 1,
        ]);

        $admin = User::where('email', 'admin@amialpay.com')->first();

        $this->assertFalse(Hash::check('Pass@2026', (string) $admin->password),
            'الحسابُ أُنشئ بالكلمة المنشورة رغم أنّ الإنتاج يرفضها');
    }

    /** @test */
    public function an_existing_admin_password_survives_a_deploy(): void
    {
        // **وهذا هو الضررُ الذي وُلد منه كلُّ ما سبق.**
        $this->inProduction();

        $this->artisan('amial:ensure-demo-staff')->assertExitCode(0);

        $admin = User::where('email', 'admin@amialpay.com')->first();
        $admin->password = Hash::make('OwnerChosen#99');
        $admin->saveQuietly();

        // نشرةٌ ثانية — الحاويةُ تُقلع فيجري الأمرُ من جديد.
        $this->artisan('amial:ensure-demo-staff')->assertExitCode(0);

        $this->assertTrue(Hash::check('OwnerChosen#99', (string) $admin->fresh()->password),
            'أعاد النشرُ ضبطَ كلمةٍ غيّرها صاحبُ المشروع — بصمت، وفي كلّ مرّة');
    }

    // ── أدوات ─────────────────────────────────────────────────────────

    private function codeOnly(string $rel): string
    {
        $s = (string) file_get_contents(base_path($rel));
        $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? '';

        return preg_replace('~^[ \t]*//[^\n]*$~m', '', $s) ?? '';
    }

    /**
     * أتأتي القيمةُ المكتوبةُ من السياسة — مباشرةً أو عبر متغيّرٍ قريب؟
     *
     * ══════════════════════════════════════════════════════════════════
     * **ثلاثُ صورٍ مقبولة، ولا رابعة:**
     *
     *   ١) النداءُ في السطر نفسِه
     *   ٢) القيمةُ مرفوعةٌ إلى متغيّرٍ أُسند من النداء قبلَه بأسطر
     *   ٣) السطرُ داخل `if (DemoAccountPolicy::mayResetExisting())`
     *
     * وكُتب أوّلَ مرّةٍ بالصورتين الأولى والثالثة وحدَهما — **فسقط على
     * شيفرةٍ صحيحة**: القيمةُ رُفعت إلى `$adminPass` لتُطبع أيضاً، فصار
     * سطرُ الكتابة خالياً من اسم السياسة.
     *
     * **وحارسٌ يفرض شكلاً بعينه يمنع تحسيناً سليماً** — فيُقاس المصدرُ
     * لا الصياغة.
     * ══════════════════════════════════════════════════════════════════
     */
    private function comesFromPolicy(string $code, string $line): bool
    {
        if (str_contains($line, 'DemoAccountPolicy::passwordForNewAccount')) {
            return true;
        }

        $at = strpos($code, $line);

        if ($at === false) {
            return false;
        }

        // **ويُقاس القربُ لا الوجود في الملفّ** — نداءٌ في دالّةٍ أخرى
        // لا يحرس هذا السطر.
        $before = substr($code, max(0, $at - 400), min(400, $at));

        if (str_contains($before, 'DemoAccountPolicy::mayResetExisting()')) {
            return true;
        }

        // المتغيّرُ المُمرَّر — أُسند من السياسة قريباً؟
        if (preg_match('~Hash::make\(\s*(\$[A-Za-z_][A-Za-z0-9_]*)\s*\)~', $line, $m)) {
            $assign = preg_quote($m[1], '~') . '\s*=\s*DemoAccountPolicy::passwordForNewAccount';

            return (bool) preg_match('~' . $assign . '~', $before);
        }

        return false;
    }
}
