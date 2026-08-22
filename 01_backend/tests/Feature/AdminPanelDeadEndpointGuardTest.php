<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-REACH-002 — نقطةُ نهايةٍ لا تستدعيها لوحتها ميتة.
 *
 * **لماذا لم يكفِ الحارس الأوّل.** `AdminPanelReachabilityGuardTest` يفحص
 * أنّ الصفحة تُفتح وأنّ رابطاً يصل إليها. وهذا يمسك «لوحةً بلا رابط»، ولا
 * يمسك «لوحةً فيها زرٌّ ناقص»: تُفتح الصفحة، ويُسجَّل المسار، ولا شيء في
 * القالب يستدعيه.
 *
 * **وأنّه لا يكفي ثبت بالتجربة لا بالتحليل.** بعد ساعةٍ واحدة من كتابة
 * الحارس الأوّل، وقعتُ في العطل نفسه ثلاث مرّات في اللوحات التي كتبتُها
 * توّاً:
 *
 *   • `partner-settlements/dashboard` — مسجَّل، والقالب لا يستدعيه
 *     (والوثيقة تطلب لوحة المؤشّرات أعلى الصفحة صراحةً).
 *   • `aml/users/{id}/profile` — ملفّ خطر العميل بلا زرّ يفتحه.
 *   • `aml/users/{id}/override` — القائمة البيضاء/السوداء بلا واجهة، فيبقى
 *     الاستثناء يُطبَّق من قاعدة البيانات مباشرةً.
 *
 * والدرس ليس «أُدقّق أكثر». الانضباط الشخصيّ فشل بعد ساعة من كتابته بيدي،
 * فالحلّ أن تصير القاعدة آلةً تسقط وحدها لا عادةً يُعتمد عليها.
 *
 * **كيف يفحص:** لكلّ لوحة، تُجمع المسارات المسجَّلة تحت بادئتها، ويُطلب أن
 * ترد المقاطع الثابتة من كلّ مسار في قالب اللوحة. والمقاطع المتغيّرة
 * (`{id}`) تُتجاهَل لأنّها تُبنى في الشيفرة ولا تُكتب حرفيّاً.
 */
class AdminPanelDeadEndpointGuardTest extends TestCase
{
    /**
     * اللوحة => قالبها، أو قوالبها إن كانت مقسَّمة على أجزاء.
     *
     * القائمة تقبل مصفوفةً لأنّ لوحةً كبيرةً تُقسَّم على `@include` طبيعيّاً،
     * وحصرُ الفحص في ملفٍّ واحدٍ كان سيجعل كلّ نقطةِ نهايةٍ يستدعيها جزءٌ
     * تبدو ميتةً — فيُسكَت الحارس بإعفاءاتٍ كاذبة بدل أن يُوسَّع نطاقه.
     *
     * @var array<string, string|array<string>>
     */
    private const PANELS = [
        'admin/amial/aml' => 'admin-views/amial/aml/index.blade.php',
        // AMIAL-PROFILE-CHANGE-004 — **ولوحةُ الهويّة صارت شاشتين.**
        //
        // طلباتُ تحديث البيانات تُسجَّل تحت البادئة نفسِها وتخدمها شاشةٌ
        // ثانية. **والصوابُ توسيعُ النطاق لا كتابةُ إعفاء** — وهو نصُّ ما
        // يقوله شرحُ هذه القائمة: «فيُسكَت الحارس بإعفاءاتٍ كاذبة بدل أن
        // يُوسَّع نطاقه».
        'admin/amial/kyc' => [
            'admin-views/amial/kyc/index.blade.php',
            'admin-views/amial/kyc/change-requests.blade.php',
        ],
        'admin/amial/partner-settlements' => 'admin-views/amial/settlements_partners/index.blade.php',
        'admin/amial/ledger' => 'admin-views/amial/ledger/index.blade.php',
        'admin/amial/hub/agents' => [
            'admin-views/amial/hub/users.blade.php',
            'admin-views/amial/hub/_agent_network_cards.blade.php',
            'admin-views/amial/hub/_agent_tabs.blade.php',
        ],
    ];

    /**
     * ما يُسجَّل عمداً بلا مستدعٍ في القالب — والسبب مكتوب.
     *
     * الإعفاء يُكتب بسببه لا باسمه: من يضيف سطراً هنا عليه أن يقول لماذا،
     * ومن يقرأه بعد سنة يعرف إن كان السبب ما زال قائماً. (وقد كُتب في هذا
     * المشروع سببُ إعفاءٍ خطأً مرّةً وقبِله الحارس — فالحارس يمنع الصمت لا
     * الكذب، وهذا حدُّه الذي يجب أن يُعرف.)
     *
     * @var array<string, string>
     */
    private const EXEMPT = [];

    /**
     * تُنزع التعليقات قبل المطابقة — والسببُ عطلٌ وقع في هذا الحارس نفسه.
     *
     * أوّل صياغةٍ منه بحثت عن الكلمة في الملفّ كلّه، فمرَّت لأنّ «dashboard»
     * وردت في **تعليقٍ عربيّ** يشرح أنّ نقطة النهاية غير موصولة. أي أنّ
     * التعليق الذي يصف العطل كان يُخفيه.
     *
     * وكُشف ذلك بتجربة العكس — قُطع الاستدعاء عمداً فمرّ الحارس. ولولا تلك
     * التجربة لبقي حارساً يمرّ للسبب الخطأ، وهو أسوأ من غيابه: يُطمئن ولا
     * يحرس.
     */
    private function codeOnly(string $blade): string
    {
        return (string) preg_replace(
            [
                '/\{\{--.*?--\}\}/s',   // تعليقات Blade
                '~/\*.*?\*/~s',         // تعليقات JS/CSS متعدّدة الأسطر
                '~^\s*//.*$~m',         // تعليقات JS سطريّة
            ],
            ' ',
            $blade,
        );
    }

    /** @test */
    public function no_admin_panel_registers_an_endpoint_its_own_screen_never_calls(): void
    {
        $dead = [];

        foreach (self::PANELS as $prefix => $views) {
            $blade = '';

            foreach ((array) $views as $view) {
                $path = resource_path('views/' . $view);
                $this->assertFileExists($path, "قالب اللوحة «{$prefix}» غير موجود: {$view}");
                $blade .= $this->codeOnly((string) file_get_contents($path)) . "\n";
            }

            // ══════════════════════════════════════════════════════════
            // **والشريطُ الجانبيُّ جزءٌ من كلّ شاشة، لا ملفٌّ خارجَها.**
            //
            // الحارسُ يستثني **جذرَ** اللوحة صراحةً لأنّ «القائمةَ الجانبيّة
            // هي التي تناديه». والعلّةُ نفسُها تنطبق على **صفحةٍ ثانيةٍ
            // داخل اللوحة**: تُبلَغ من الشريط لا من قالبِ أختها.
            //
            // فبلا هذا يُبلَّغ عن كلّ شاشةٍ ثانيةٍ في أيّ لوحةٍ أنّها ميّتة
            // — **وهي حيّةٌ يفتحها المستعملُ كلَّ يوم**. وسلبيّةٌ كاذبةٌ
            // تدفع لكتابة إعفاءٍ على شيفرةٍ سليمة، ثمّ يصير الإعفاءُ عادةً
            // فيُقبَل الحقيقيُّ بلا نظر.
            //
            // **ولا يُضعف هذا الحارس:** رابطٌ في الشريط نداءٌ حقيقيّ —
            // يُرسَم على الصفحة ويُضغَط. وبلوغُ الصفحة نفسِها يفحصه
            // `AdminPanelReachabilityGuardTest`.
            // ══════════════════════════════════════════════════════════
            $blade .= $this->codeOnly((string) file_get_contents(resource_path(
                'views/admin-views/amial/partials/_sidebar.blade.php'))) . "\n";

            foreach (Route::getRoutes() as $route) {
                $uri = $route->uri();

                if (!str_starts_with($uri, $prefix)) {
                    continue;
                }

                $tail = trim(substr($uri, strlen($prefix)), '/');

                // الصفحة نفسها: لا يستدعيها قالبُها، بل القائمة الجانبية —
                // وذلك يفحصه الحارس الأوّل.
                if ($tail === '') {
                    continue;
                }

                if (isset(self::EXEMPT[$uri])) {
                    continue;
                }

                // ══════════════════════════════════════════════════
                // **والقالبُ ينادي بالاسم لا بالمقاطع.**
                //
                // Blade يكتب `route('admin.amial.kyc.changes.open')`، ولا
                // يرد فيه مقطعُ `change-requests` أبداً. فمطابقةُ المقاطع
                // وحدَها **تُخرج سلبيّةً كاذبة**: نقطةٌ يناديها القالبُ
                // فعلاً تُعَدّ ميّتة.
                //
                // **وسلبيّةٌ كاذبةٌ في حارسٍ أسوأ من ثغرةٍ فيه**: تدفع
                // لكتابة إعفاءٍ على شيفرةٍ سليمة، ثمّ يصير الإعفاءُ
                // عادةً — فيُقبَل الإعفاءُ الحقيقيُّ بلا نظر.
                //
                // فيُقبَل الاسمُ دليلَ نداءٍ كالمقاطع سواءً بسواء. وقوّتُه
                // نفسُها: كلاهما نصٌّ يجب أن يرد في الشيفرة لا في تعليق.
                // ══════════════════════════════════════════════════
                $name = (string) $route->getName();

                if ($name !== '' && str_contains($blade, $name)) {
                    continue;
                }

                $segments = array_filter(
                    explode('/', $tail),
                    static fn (string $s): bool => $s !== '' && !str_starts_with($s, '{'),
                );

                $missing = array_values(array_filter(
                    $segments,
                    static fn (string $s): bool => !str_contains($blade, $s),
                ));

                if ($missing !== []) {
                    $dead[] = "  • {$uri} — لا يستدعيه القالب (المقاطع الغائبة: "
                        . implode('، ', $missing) . ')';
                }
            }
        }

        $this->assertSame([], $dead,
            "نقاط نهاية مسجَّلة تحت لوحة ولا تستدعيها اللوحة — مبنيّة وميتة:\n"
            . implode("\n", $dead)
            . "\n\nإمّا تُوصَّل بزرٍّ في القالب، وإمّا تُحذف، وإمّا تُعفى في EXEMPT بسببٍ مكتوب.",
        );
    }
}
