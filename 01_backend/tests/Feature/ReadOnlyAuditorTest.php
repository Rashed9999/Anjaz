<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-READONLY-AUDITOR-001 — **دورٌ يقرأ ولا يكتب، مُثبَتاً لا مُسمّىً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «مدقّقٌ داخليّ — Read Only. يقرأ Ledger وAudit والتسويات
 * وقرارات AML، ولا يستطيع تعديل أيّ شيء.»
 *
 * **وأوّلُ ما قِيس: هل ذلك ممكنٌ أصلاً؟** فوُجد أنّه لم يكن:
 *
 *   **خمسةٌ وعشرون مسارَ كتابةٍ محروسةٌ بصلاحيّاتِ قراءةٍ وحدَها.**
 *
 * فمن يُمنح `audit.view` ليقرأ السجلَّ كان **يُعدّل قواعد مكافحة غسل
 * الأموال، ويحسم بلاغَ اشتباه، ويُرسل تقريراً رقابيّاً، ويحظر عنوانَ
 * شبكةٍ**. ودورٌ اسمُه «قراءةً فقط» كان **مستحيلاً بناؤه**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحارسُ لا يُبنى على تسمية الفئة.**
 *
 * أوّلُ مِقياسٍ كتبتُه عرّف «القراءة» بحقل `category` في جدول الصلاحيّات
 * — **وهو تسميةٌ لا إنفاذ**. فـ`merchants.compliance` مصنَّفةٌ قراءةً
 * ومعناها يشمل قراراتِ امتثالٍ حقيقيّة، فبلّغ المِقياسُ عنها ثغرةً وهي
 * ليست كذلك.
 *
 * **فالثابتُ الصحيحُ واحد، وهو دقيقٌ ويُقاس من الموجِّه:**
 *
 *   > لا صلاحيّةَ يملكها `platform_auditor` تحرس مسارَ كتابةٍ واحداً.
 *
 * وهو يصدق مهما تغيّرت التسمياتُ والفئات، ويسقط لحظةَ يُمنح المدقّقُ
 * صلاحيّةً تفتح كتابةً — أو يُحرَس مسارُ كتابةٍ بصلاحيّةٍ يملكها.
 */
class ReadOnlyAuditorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **مساراتُ كتابةٍ يفحص متنُها الصلاحيّةَ فعلاً — إيجابيّاتٌ كاذبة.**
     *
     * `customer.action` تُمرَّر إلى `CustomerActionService::run()`، وفيها
     * `self::ACTIONS[$action]` تُخرج صلاحيّةَ الفعل بعينه ثمّ
     * `hasPlatformPermission` تفحصها. **فحاجزٌ عامٌّ على المسار يشلّ
     * أفعالاً لكلٍّ صلاحيّتُها** — والقياسُ الساذج يُبلّغ عنه ثغرةً.
     *
     * @var array<string,string>
     */
    private const ENFORCED_IN_SERVICE = [
        'admin.amial.customer.action' =>
            'CustomerActionService::run() تفحص صلاحيّةَ كلّ فعلٍ على حدة',
    ];

    private function auditor(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, 'platform_auditor');

        return $u->refresh();
    }

    /** @return list<string> */
    private function auditorPermissions(): array
    {
        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_auditor')->value('id');

        if ($roleId === null) {
            return [];
        }

        return DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_id', $roleId)->pluck('permissions.code')->all();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الثابت
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function nothing_the_auditor_holds_opens_a_write(): void
    {
        $held = $this->auditorPermissions();

        $this->assertNotEmpty($held,
            'دورُ التدقيق بلا صلاحيّةٍ واحدة — فلا يقرأ شيئاً، '
            . 'ودورٌ لا يفتح باباً ليس دوراً.');

        $offences = [];
        $writes = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (! array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                continue;
            }

            $writes++;
            $name = (string) $route->getName();

            if (array_key_exists($name, self::ENFORCED_IN_SERVICE)) {
                continue;
            }

            $perms = [];

            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'platform:')) {
                    $perms[] = substr($mw, strlen('platform:'));
                }
            }

            $perms = array_values(array_unique($perms));

            // بلا حارسٍ إطلاقاً؟ يمسكه `AdminDoorsAreGuardedTest`، لا هذا.
            if ($perms === []) {
                continue;
            }

            // **والشرطُ اجتماعُ الكلّ**: يكفي شرطٌ واحدٌ لا يملكه ليُردّ.
            if (! array_diff($perms, $held)) {
                $offences[] = $name . '  ←  ' . implode(' + ', $perms);
            }
        }

        $this->assertGreaterThan(100, $writes,
            "لم يُقرأ إلّا {$writes} مسارَ كتابة — المرشِّحُ لا يرى المسارات.");

        sort($offences);

        $this->assertSame([], $offences,
            "المدقّقُ «قراءةً فقط» يستطيع الكتابةَ في:\n  "
            . implode("\n  ", $offences) . "\n\n"
            . "فإمّا تُنزَع الصلاحيّةُ منه، وإمّا يُحرَس المسارُ بصلاحيّةِ\n"
            . 'قرارٍ لا بصلاحيّةِ قراءة. ودورٌ اسمُه «قراءةً فقط» ويكتب '
            . 'أسوأ من دورٍ لا يُدَّعى له ذلك.');
    }

    /** @test */
    public function the_false_positives_are_still_really_enforced(): void
    {
        // **واستثناءٌ لا يُراجَع يصير ثقباً.** فيُثبَت أنّ ما استُثني ما
        // زال يفحص الصلاحيّةَ في متنه — لا يُصدَّق التعليقُ وحدَه.
        $src = file_get_contents(app_path('Services/CustomerActionService.php'));

        $this->assertStringContainsString('hasPlatformPermission', $src,
            'استُثني `customer.action` لأنّ خدمتَه تفحص الصلاحيّة — '
            . 'ولم تعد تفحصها. فالاستثناءُ صار ثقباً.');

        $this->assertStringContainsString('self::ACTIONS[$action]', $src,
            'تغيّرت بنيةُ فحص الأفعال — يُراجَع الاستثناءُ ولا يُترَك.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② ويقرأ ما يُدقَّق به — وإلّا فهو دورٌ فارغ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_auditor_reads_the_ledger_the_audit_log_and_the_money_screens(): void
    {
        $auditor = $this->auditor();

        foreach (['platform.audit.view', 'platform.money.view',
            'platform.analytics.view', 'platform.fees.view'] as $code) {
            $this->assertTrue($auditor->hasPlatformPermission($code),
                "المدقّقُ لا يملك {$code} — فبمَ يدقّق؟");
        }

        $this->actingAs($auditor, 'user')
            ->get(route('admin.amial.audit.index'))->assertOk();
        $this->actingAs($auditor, 'user')
            ->get(route('admin.amial.ledger.page'))->assertOk();
        $this->actingAs($auditor, 'user')
            ->get(route('admin.amial.hub.finance'))->assertOk();
    }

    /** @test */
    public function the_auditor_never_reveals_personal_contact_details(): void
    {
        // كشفُ الهاتف فعلٌ يُسجَّل على فاعله، ولا يحتاجه من يدقّق قيوداً.
        // **ومدقّقٌ يكشف هواتفَ العملاء يزيد سطحَ الخطر بلا أن يزيد قدرتَه.**
        $this->assertFalse($this->auditor()
            ->hasPlatformPermission('platform.customers.pii.reveal'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ والمحقّقُ ليس صاحبَ قرار الإبلاغ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function investigating_money_laundering_is_split_from_deciding_on_it(): void
    {
        // «لا أريد أن يكون فريقُ الامتثال دوراً واحداً يقوم بـKYC وAML
        // والتحقيق وإنشاء البلاغ وإرسال البلاغ» — نصُّ صاحب المشروع.
        $this->assertDatabaseHas('permissions', ['code' => 'platform.aml.investigate']);
        $this->assertDatabaseHas('permissions', ['code' => 'platform.aml.decide']);

        $router = app('router')->getRoutes();

        $of = function (string $name): array {
            $r = app('router')->getRoutes()->getByName($name);
            $out = [];

            foreach ($r?->gatherMiddleware() ?? [] as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'platform:')) {
                    $out[] = substr($mw, strlen('platform:'));
                }
            }

            return $out;
        };

        $this->assertContains('platform.aml.investigate',
            $of('admin.amial.aml.investigations.open'),
            'فتحُ تحقيقٍ ليس خلف صلاحيّة المحقّق');

        // **وإرسالُ البلاغ الرقابيّ قرارٌ لا تحقيق.**
        $this->assertContains('platform.aml.decide',
            $of('admin.amial.aml.reports.submit'),
            'إرسالُ بلاغٍ رقابيٍّ خلف صلاحيّة المحقّق — والمحقّقُ لا يُبلّغ');

        $this->assertContains('platform.aml.decide',
            $of('admin.amial.aml.rules.update'),
            'تعديلُ قواعد الرصد خلف صلاحيّةٍ غير قرارِ الامتثال');
    }

    /** @test */
    public function reading_the_audit_log_no_longer_lets_you_change_aml_rules(): void
    {
        // **وهذا هو العطلُ الأصليُّ مقلوباً.** كان `audit.view` يفتح ١٨
        // مسارَ كتابةٍ في الامتثال والأمن.
        $auditor = $this->auditor();

        $this->assertTrue($auditor->hasPlatformPermission('platform.audit.view'));

        foreach (['platform.aml.decide', 'platform.aml.investigate',
            'platform.security.act'] as $code) {
            $this->assertFalse($auditor->hasPlatformPermission($code),
                "المدقّقُ يملك {$code} — وهو قرارٌ لا قراءة");
        }

        $this->actingAs($auditor, 'user')
            ->post(route('admin.amial.aml.rules.toggle', ['id' => 1]))
            ->assertForbidden();
    }
}
