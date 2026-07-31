<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-REACH-001 — ضابطٌ لا يُرى ليس ضابطاً.
 *
 * **العطل الذي يمنعه هذا الحارس** تكرّر ثلاث مرّات في هذا المشروع، وشكّله
 * واحدٌ في كلّ مرّة: يُبنى المنطق، وتُسجَّل نقاط النهاية، وتُكتب الاختبارات،
 * وتمرّ كلّها — ثمّ لا تُفتح الميزة من متصفّح قطّ. لا صفحة تستدعي نقاط
 * النهاية، ولا رابط في القائمة يصل إلى الصفحة.
 *
 * ونتيجته أسوأ من ألّا تُبنى الميزة أصلاً: من يقرأ الكود أو الوثيقة يحسب
 * الضابط عاملاً فيبني عليه، بينما هو معطَّل عملياً لأنّ أحداً لا يستطيع
 * تشغيله. أوضحُ مثال: اثنتا عشرة نقطة نهاية لمكافحة غسل الأموال، مسجَّلة
 * كلّها في المسارات، ولا صفحة واحدة تفتحها — فالنظام يرصد ويعلّق، والمعلَّق
 * يبقى معلّقاً بلا أجل لأنّ لا شاشة يُعتمد منها أو يُرفض.
 *
 * **ولذلك يفحص هذا الحارس ثلاث حلقاتٍ لا حلقة واحدة:**
 *
 *   1. المسار موجود ومسجَّل.
 *   2. الصفحة تُفتح فعلاً وتردّ 200 (لا تكفي الوجود: قالبٌ مكسور يمرّ في
 *      فحص المسارات ويسقط عند أوّل فتح).
 *   3. **رابطٌ في القائمة الجانبية يصل إليها** — وهذه هي الحلقة التي انقطعت
 *      في كلّ المرّات الثلاث. صفحةٌ تعمل ولا رابط لها تساوي صفحةً غير موجودة
 *      لمن لا يحفظ عناوين URL.
 */
class AdminPanelReachabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * كلّ ضابطٍ امتثاليّ بُني في الخلفية ويجب أن يُشغَّل من لوحة.
     *
     * المفتاح اسم المسار، والقيمة سببُ وجوبه — لا وصفٌ له. من يريد حذف سطرٍ
     * من هنا عليه أن يجيب عن السبب لا أن يمحو اسماً.
     *
     * @var array<string, string>
     */
    private const MUST_BE_REACHABLE = [
        'admin.amial.kyc.page' =>
            'مستندات الهوية تصل مشفَّرة ولا تُراجَع إلّا من شاشة — وبدونها يرفع العميل ولا يبتّ أحد',

        'admin.amial.aml.page' =>
            'العمليات المعلّقة لمكافحة غسل الأموال تحتاج من يعتمدها أو يرفضها — وبلا لوحة تبقى معلّقة بلا أجل',

        'admin.amial.partner-settlements.page' =>
            'الموافقة المزدوجة تعيش على تسويات الشركاء — وبلا لوحة لا يُعرف ما ينتظر توقيعاً ثانياً',

        'admin.support-center.index' =>
            'حظر الأجهزة يُتّخذ من ملفّ العميل — وهو بديلٌ عن تجميد الحساب كلّه',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'super_admin',
            'phone' => '967770009911',
        ]);

        $this->grantPlatformAdmin($this->admin);
    }

    /**
     * دور «مدير المنصّة» يُسنَد صراحةً — ولا يُفترض من كون الحساب إدارياً.
     *
     * الهجرةُ تُسند الدور لحسابات الإدارة **القائمة وقت تشغيلها**، وحساب
     * الاختبار يُنشأ بعدها فلا يناله شيء. ولولا هذا الإسناد لبدا الفشل عطلاً
     * في الصفحة وهو غيابُ صلاحية.
     *
     * (وهذه ملاحظةٌ تسري على الإنتاج أيضاً: كلّ حساب إدارة يُنشأ بعد تلك
     * الهجرة يبدأ بلا صلاحية منصّة واحدة، وتُضبط أدواره من لوحة الأدوار.)
     */
    private function grantPlatformAdmin(User $user): void
    {
        $roleId = DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')
            ->value('id');

        $this->assertNotNull($roleId, 'دور «مدير المنصّة» غير موجود — الهجرة لم تُنفَّذ');

        DB::table('admin_user_roles')->updateOrInsert(
            ['user_id' => $user->id, 'role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()],
        );
    }

    /** @test */
    public function every_compliance_panel_has_a_registered_route(): void
    {
        foreach (self::MUST_BE_REACHABLE as $name => $why) {
            $this->assertTrue(
                Route::has($name),
                "لا مسار باسم «{$name}» — الميزة مبنيّة ولا يمكن فتحها.\nالسبب: {$why}",
            );
        }
    }

    /** @test */
    public function every_compliance_panel_actually_opens(): void
    {
        // وجودُ المسار لا يكفي: قالبٌ مكسور أو استدعاءُ مسارٍ غير معرَّف داخله
        // يمرّان في فحص التسجيل ويسقطان عند أوّل فتح حقيقيّ.
        foreach (self::MUST_BE_REACHABLE as $name => $why) {
            if (!Route::has($name)) {
                continue;   // يُبلَّغ عنه في الاختبار الأوّل — لا يُكرَّر هنا
            }

            $response = $this->actingAs($this->admin, 'user')->get(route($name));

            $this->assertSame(
                200,
                $response->getStatusCode(),
                "الصفحة «{$name}» لا تُفتح (ردّت {$response->getStatusCode()}).\nالسبب في وجوبها: {$why}",
            );
        }
    }

    /** @test */
    public function every_compliance_panel_is_linked_from_the_sidebar(): void
    {
        // الحلقة التي انقطعت في المرّات الثلاث كلّها.
        $sidebar = file_get_contents(
            resource_path('views/admin-views/amial/partials/_sidebar.blade.php'),
        );

        $this->assertNotEmpty($sidebar, 'تعذّرت قراءة القائمة الجانبية');

        foreach (self::MUST_BE_REACHABLE as $name => $why) {
            $this->assertStringContainsString(
                "route('{$name}')",
                $sidebar,
                "«{$name}» لا رابط لها في القائمة الجانبية — تعمل ولا أحد يصل إليها.\n"
                . "السبب في وجوبها: {$why}",
            );
        }
    }

    /**
     * @test
     *
     * الأجهزة حالةٌ خاصّة: لا صفحة لها وحدها، بل قسمٌ داخل ملفّ العميل في
     * مركز الدعم. فالفحص هنا على القالب لا على مسارٍ مستقلّ — وإلّا مرّ
     * الحارس بينما المسارات الثلاثة (عرض/حظر/رفع) بلا زرٍّ يستدعيها.
     */
    public function device_blocking_is_wired_into_the_support_console(): void
    {
        $console = file_get_contents(
            resource_path('views/admin-views/support/console.blade.php'),
        );

        // الإشارة المقصودة هي **الاستدعاء**، لا ورودُ الكلمة. ولذلك يُبحث عن
        // بناء المسار وعن الزرّين اللذين يُمرّران الفعل — لا عن «block» مجرّدة
        // التي قد ترد في تعليقٍ أو في اسم متغيّر ويمرّ الحارس بلا وصلة.
        foreach ([
            "'/customers/' + userId + '/devices?reason='" =>
                'قائمة أجهزة العميل لا تُطلب من أيّ مكان في اللوحة',
            '/devices/${b.dataset.row}/${b.dataset.do}' =>
                'لا استدعاء لمسار الحظر/رفع الحظر — المساران مبنيّان ولا يُستعملان',
            'data-do="block"' =>
                'لا زرّ يحظر جهازاً',
            'data-do="unblock"' =>
                'لا زرّ يرفع الحظر — فالمحظور يبقى محظوراً بلا مخرج',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $console, $why);
        }
    }

    /**
     * @test
     *
     * وضع الظلّ يجب أن يُقرأ من اللوحة لا من قاعدة البيانات.
     *
     * قاعدةٌ «مفعَّلة» في الظلّ تُحصي ولا تمنع. ومن يرى «مفعَّلة» وحدها يبني
     * قراره على حمايةٍ غير موجودة — وهذا بالضبط ما جعل الحدّ الأقصى المطلق
     * يبقى في الظلّ دون أن ينتبه أحد.
     */
    public function the_aml_panel_exposes_shadow_mode(): void
    {
        $view = file_get_contents(
            resource_path('views/admin-views/amial/aml/index.blade.php'),
        );

        $this->assertStringContainsString('shadow_mode', $view,
            'لوحة AML لا تقرأ وضع الظلّ — فقاعدةٌ لا تمنع تبدو فيها كأنها تمنع');

        $this->assertStringContainsString('لا تمنع', $view,
            'وضع الظلّ يُعرَض بلا شرح — الرمز وحده لا يقول للمشغّل إنّ القاعدة لا تمنع');
    }
}
