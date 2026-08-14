<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-I18N-001 · AMIAL-UI-DIALOGS-002 — **لوحةٌ عربيّة، ونافذةٌ واحدة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * شكويان من صاحب المشروع في رسالةٍ واحدة:
 *
 *   ④ «بعض الصفحات لا تزال بالإنجليزيّة» — و**القوالبُ مترجَمةٌ أصلاً**:
 *      `translate()` منادىً في ٥١ موضعاً في الشاشتين المصوَّرتين وحدهما.
 *      لكنّ عطلين اجتمعا: `translate()` ترتدّ إلى `'en'` حين لا لغةَ في
 *      الجلسة (حالُ كلّ زيارةٍ أولى)، و`lang/ar/messages.php` **فارغٌ
 *      تماماً — صفر مفتاح**. فحتّى من بدّل إلى العربيّة كان يقرأ
 *      الإنجليزيّة.
 *
 *   ⑤ «نافذةُ المتصفّح تعمل ونافذةُ المشروع لا» — ورآهما في ضغطتين
 *      متتاليتين: `prompt()` بيضاءُ من المتصفّح، ثمّ نجاحٌ في نافذة
 *      المشروع. والسببُ أنّ `_app_dialogs` كان يستبدل `alert` وحدَه.
 */
class AdminLocaleAndDialogGuardTest extends TestCase
{
    use RefreshDatabase;

    private const VIEWS = __DIR__ . '/../../resources/views';

    private function operator(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① اللغة
    // ══════════════════════════════════════════════════════════════════

    /** **بلا جلسةٍ، اللوحةُ عربيّة** — وهو حالُ كلّ زيارةٍ أولى. */
    public function test_the_default_locale_is_arabic(): void
    {
        session()->forget('local');

        $this->assertSame('سجلّ تدقيق النظام', translate('System Audit Log'),
            'الافتراضُ ما زال إنجليزيّاً — وأوّلُ ما يراه المديرُ إنجليزيّ');
    }

    /** **والقاموسُ ليس فارغاً** — فارغُه يجعل كلّ مفتاحٍ يرتدّ إلى نفسه. */
    public function test_the_arabic_dictionary_covers_the_screens_he_photographed(): void
    {
        $ar = include base_path('resources/lang/ar/messages.php');

        $this->assertIsArray($ar);
        $this->assertGreaterThan(100, count($ar),
            'قاموسُ العربيّة شبه فارغ — و`translate()` ترتدّ إلى المفتاح '
            . 'الإنجليزيّ حين لا تجده، فتبدو اللوحةُ غيرَ مترجَمة');

        foreach (['System Audit Log', 'Executive Dashboard', 'Decisions',
                  'Severity', 'Payments today (volume)', 'Total wallet balances',
                  'Revenue', 'Filter', 'Action'] as $key) {
            $this->assertArrayHasKey($key, $ar, "مفتاحٌ مرئيٌّ بلا ترجمة: «{$key}»");

            $this->assertDoesNotMatchRegularExpression('/^[A-Za-z ()\/]+$/', $ar[$key],
                "«{$key}» تُرجم إلى إنجليزيّةٍ أخرى لا إلى عربيّة");
        }
    }

    /** **والتبديلُ يعمل** — الحرّيّةُ التي طلبها، لا فرضُ العربيّة. */
    public function test_an_operator_can_switch_to_english_and_back(): void
    {
        $admin = $this->operator();

        $this->actingAs($admin, 'user')
            ->post('/admin/amial/locale', ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('en', session('local'));

        $this->actingAs($admin, 'user')
            ->post('/admin/amial/locale', ['locale' => 'ar'])
            ->assertRedirect();

        $this->assertSame('ar', session('local'));
    }

    /** **ولا تُقبل لغةٌ لا قاموسَ لها** — وإلّا فُرِّغت اللوحةُ بقيمةٍ من الطلب. */
    public function test_an_unknown_locale_is_refused(): void
    {
        $this->actingAs($this->operator(), 'user')
            ->post('/admin/amial/locale', ['locale' => 'fr'])
            ->assertStatus(422);
    }

    /** **وللمبدّل زرٌّ يُضغط** — لا مسارٌ يعرفه من قرأ الشيفرة. */
    public function test_the_switcher_has_a_button_in_the_layout(): void
    {
        $layout = (string) file_get_contents(self::VIEWS . '/layouts/admin/app.blade.php');

        $this->assertStringContainsString('admin.amial.locale', $layout,
            'مسارُ التبديل مسجَّلٌ ولا زرَّ يقود إليه (القاعدة ١٢)');

        $this->assertStringContainsString('data-testid="locale-toggle"', $layout);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② النوافذ
    // ══════════════════════════════════════════════════════════════════

    /** **العقدُ كاملٌ**: تُخبر، وتسأل نعم/لا، وتسأل بجواب. */
    public function test_the_project_dialog_offers_the_full_contract(): void
    {
        $partial = (string) file_get_contents(self::VIEWS . '/partials/_app_dialogs.blade.php');

        foreach (['show', 'ask', 'request'] as $fn) {
            $this->assertMatchesRegularExpression('/\b' . $fn . '\b\s*[,:}]/', $partial,
                "نافذةُ المشروع بلا «{$fn}» — فيبقى نداءٌ يستعمل نافذة المتصفّح");
        }

        $this->assertStringContainsString('window.amialDialog', $partial);
    }

    /**
     * **والوعدُ يُحسم عند الإغلاق بأيّ سبب.**
     *
     * فوعدٌ لا يُحسم يترك الزرَّ الذي ينتظره متجمّداً: يُضغط «×» فلا يحدث
     * شيءٌ أبداً — ولا رسالةَ ولا خطأ. (القاعدة التاسعة.)
     */
    public function test_dismissing_the_dialog_settles_the_promise(): void
    {
        $partial = (string) file_get_contents(self::VIEWS . '/partials/_app_dialogs.blade.php');

        $this->assertStringContainsString('data-amial-dialog-dismiss', $partial);
        $this->assertStringContainsString("e.key === 'Escape'", $partial,
            'الإغلاق بـEsc لا يحسم الوعد — فيتجمّد الزرّ');

        $this->assertStringContainsString('const done = settle; settle = null;', $partial,
            'لا حسمَ للوعد عند الإغلاق');
    }

    /**
     * **وشاشةُ التجميد — الصورةُ التي أرسلها — لا تنادي نافذةَ المتصفّح.**
     *
     * ولا يُدّعى أنّ الـ٦٣ موضعاً حُوّلت: هذا الحارسُ يحرس ما حُوّل،
     * ويسقط إن رجع.
     */
    public function test_the_account_freeze_screen_uses_the_project_dialog(): void
    {
        $view = self::VIEWS . '/admin-views/amial/hub/account.blade.php';

        $code = preg_replace('#^\s*//.*$#m', '', (string) file_get_contents($view)) ?? '';

        $this->assertStringNotContainsString('prompt(', $code,
            'شاشةُ التجميد عادت إلى نافذة المتصفّح — وهي الصورةُ التي أُرسلت');

        $this->assertStringContainsString('amialDialog.request', $code);

        // **والإلغاءُ يُحترم**: `prompt() || ''` كانت تجمّد الحسابَ بسببٍ
        // فارغ ولو ضغط المديرُ «إلغاء».
        $this->assertStringContainsString('if (reason === null) return;', $code,
            'الإلغاءُ لا يُوقف العمليّة — فيُجمَّد حسابٌ لم يُطلب تجميدُه');
    }
}
