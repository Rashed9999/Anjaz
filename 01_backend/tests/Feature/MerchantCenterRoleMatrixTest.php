<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\PlatformRoleService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-OPERATOR-RBAC-003 — **مصفوفةُ الأدوار الخمسة، مقيسةً من الباب**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * «لا يجوز أن يستطيع كلّ موظّف في أميال رؤية كلّ شيء.»
 *
 * و`PlatformPermissionExistenceGuardTest` يفحص **الجداول**: هل الرمز
 * موجود، وهل الدورُ يملكه. وهذا يفحص **الطريق**: يدخل بحسابٍ حقيقيٍّ
 * لكلّ دور، ويضغط كلّ مسارٍ في المركز، ويقيس ٢٠٠ أو ٤٠٣.
 *
 * **والفرقُ بينهما ليس تكراراً:** ربطُ الصلاحيّة بالدور في الجدول لا
 * يعني أنّ المسار يطلبها — قد يطلب رمزاً آخر، أو لا يطلب شيئاً. وقد
 * وقع هذا فعلاً: `overview` كانت محميّةً بأدنى صلاحيّة وتُرجع في
 * حمولتها المالَ والمخاطر. **فالبابُ كان محروساً وما يخرج منه أوسع.**
 *
 * ولذلك يُفحص هنا **جسدُ الردّ** لا رمزُ حالته وحده.
 */
class MerchantCenterRoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'is_active' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);
    }

    /** مشغّلٌ بدورٍ واحدٍ بعينه — **لا أكثر**، وإلّا لم تُقَس الحدود. */
    private function operator(string $roleCode): User
    {
        $u = User::factory()->create(['type' => 0, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, $roleCode);

        return $u;
    }

    private function url(string $tail = ''): string
    {
        return '/admin/amial/merchant-center/' . $this->merchant->id . $tail;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الحدُّ يُقاس بما يُمنع، لا بما يُسمح
    // ══════════════════════════════════════════════════════════════════

    public static function matrix(): array
    {
        // [الدور، ما يُفتح له (٢٠٠)، ما يُمنع عنه (٤٠٣)]
        return [
            // «Customer Support يستطيع: الملف · حالة الحساب · التذاكر ·
            //  العمليات الأساسية. ولا يستطيع: تعديل التسويات · الأرصدة ·
            //  العمولات.»
            'دعم العملاء' => [
                PlatformRoleService::SUPPORT,
                ['/overview', '/operations', '/staff', '/support', '/subscription'],
                ['/money', '/statement', '/settlements', '/risk', '/devices',
                 '/compliance', '/audit', '/operational-detail'],
            ],

            // «Finance يستطيع: العمليات المالية · كشف الحساب · التسويات ·
            //  العمولات. ولا يستطيع: تعديل KYC.»
            'المالية' => [
                PlatformRoleService::FINANCE,
                ['/overview', '/money', '/statement', '/settlements', '/operations'],
                ['/risk', '/devices', '/compliance', '/audit', '/operational-detail'],
            ],

            // «Risk يستطيع: المخاطر · العمليات · الأجهزة · Audit ·
            //  التجميد الأمني.»
            'المخاطر' => [
                PlatformRoleService::RISK,
                ['/overview', '/risk', '/devices', '/audit', '/operations', '/operational-detail'],
                ['/money', '/statement', '/settlements', '/compliance'],
            ],

            // «Compliance يستطيع: KYC · الوثائق · المراجعات · القيود.»
            'الامتثال' => [
                PlatformRoleService::COMPLIANCE,
                ['/overview', '/compliance', '/audit', '/operational-detail'],
                ['/money', '/statement', '/settlements', '/risk', '/devices'],
            ],

            // «Super Admin: صلاحيات كاملة مع مراقبة إضافية لكل عملية حساسة.»
            'مدير النظام' => [
                PlatformRoleService::ADMIN,
                ['/overview', '/money', '/statement', '/settlements', '/operations',
                 '/risk', '/devices', '/compliance', '/audit', '/staff', '/support',
                 '/subscription', '/operational-detail'],
                [],
            ],
        ];
    }

    /**
     * @dataProvider matrix
     */
    public function test_each_role_opens_only_what_its_work_needs(
        string $roleCode, array $allowed, array $denied): void
    {
        $op = $this->operator($roleCode);

        foreach ($allowed as $path) {
            $r = $this->actingAs($op, 'user')->getJson($this->url($path));

            // ٤٠٣ وحدَه هو الفشل هنا. و٤٢٣ ردٌّ مشروع على
            // `/operational-detail`: «أنت أهلٌ له وهو مقفولٌ حتّى يُفتح
            // إذن» — وهو المعنى الذي وُضع له رمزٌ خاصّ لئلّا يُخلط بالمنع.
            $this->assertNotSame(403, $r->status(),
                "الدور {$roleCode} مُنع من {$path} وهو من عمله");
        }

        foreach ($denied as $path) {
            $this->actingAs($op, 'user')->getJson($this->url($path))
                ->assertStatus(403);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② التسريبُ في الحمولة لا في الباب
    // ══════════════════════════════════════════════════════════════════

    public function test_the_overview_does_not_hand_money_to_a_role_that_cannot_open_it(): void
    {
        // **العطلُ الذي وُلد منه هذا الاختبار:** `overview` محميّةٌ
        // بـ`platform.customers.view` — أدنى صلاحيّة — وكانت تُرجع
        // `money()` و`risk()` بلا فحص. فموظّفُ الدعم يقرأ رصيد التاجر
        // من نقطةٍ لا تطلب صلاحيّة المال، وحارسُ `/money` سليمٌ ولا يمنع
        // شيئاً لأنّ الرقم وصل من بابٍ آخر.
        $support = $this->operator(PlatformRoleService::SUPPORT);

        $body = $this->actingAs($support, 'user')
            ->getJson($this->url('/overview'))->assertOk()->json('data');

        $this->assertTrue($body['money']['restricted'] ?? false,
            'موظّف الدعم يقرأ مال التاجر من النظرة العامّة');
        $this->assertArrayNotHasKey('wallet', $body['money'],
            'المحفظةُ خرجت في حمولةٍ لا تطلب صلاحيّة المال');

        $this->assertTrue($body['risk']['restricted'] ?? false,
            'موظّف الدعم يقرأ درجة مخاطر التاجر');

        // **والغيابُ يُقال** (القاعدة ٧): «ليس لك» ليست صفراً ولا فراغاً.
        $this->assertNotEmpty($body['money']['note'] ?? '');
    }

    public function test_finance_does_get_the_money_in_the_overview(): void
    {
        // حارسٌ لا يمرّ إلّا بالمنع يُثبت أنّ الشاشةَ فارغةٌ للجميع.
        $finance = $this->operator(PlatformRoleService::FINANCE);

        $body = $this->actingAs($finance, 'user')
            ->getJson($this->url('/overview'))->assertOk()->json('data');

        $this->assertArrayHasKey('wallet', $body['money'],
            'فريقُ المالية لا يرى المال — وهو عملُه');
        $this->assertArrayNotHasKey('restricted', $body['money']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ التبويباتُ تتبع الدور — «تبويبٌ يُعرض ثمّ يردّ ٤٠٣ أسوأ من غيابه»
    // ══════════════════════════════════════════════════════════════════

    public function test_the_tabs_a_role_sees_are_exactly_the_ones_it_can_open(): void
    {
        foreach ([PlatformRoleService::SUPPORT, PlatformRoleService::FINANCE,
                  PlatformRoleService::RISK, PlatformRoleService::COMPLIANCE] as $roleCode) {
            $op = $this->operator($roleCode);

            $sections = $this->actingAs($op, 'user')
                ->getJson($this->url('/overview'))->assertOk()->json('data.sections');

            $this->assertNotEmpty($sections, "الدور {$roleCode} بلا تبويبٍ واحد");

            foreach (array_keys($sections) as $key) {
                // القسمُ المعروض لا بدّ أن يُفتح فعلاً. و`profile` ليس له
                // مسارٌ مستقلّ (يُبنى من `overview`)، وكذلك `staff`/`support`
                // لهما مساراتُهما — فتُجرَّب ما له مسار.
                $path = match ($key) {
                    'profile' => null,
                    'money' => '/money', 'settlements' => '/settlements',
                    'operations' => '/operations', 'risk' => '/risk',
                    'staff' => '/staff', 'devices' => '/devices',
                    'subscription' => '/subscription', 'compliance' => '/compliance',
                    'support' => '/support', 'audit' => '/audit',
                    default => null,
                };

                if ($path === null) {
                    continue;
                }

                $this->assertNotSame(403,
                    $this->actingAs($op, 'user')->getJson($this->url($path))->status(),
                    "التبويب «{$key}» معروضٌ للدور {$roleCode} ويردّ ٤٠٣ — عرضٌ بلا فتح");
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ الأفعال
    // ══════════════════════════════════════════════════════════════════

    public function test_only_risk_and_the_super_admin_may_freeze(): void
    {
        $payload = ['reason' => 'تحقيق مخاطر — بلاغ رقم ١٢'];

        foreach ([PlatformRoleService::SUPPORT, PlatformRoleService::FINANCE,
                  PlatformRoleService::COMPLIANCE] as $roleCode) {
            $this->actingAs($this->operator($roleCode), 'user')
                ->postJson($this->url('/freeze'), $payload)
                ->assertStatus(403);
        }

        $this->actingAs($this->operator(PlatformRoleService::RISK), 'user')
            ->postJson($this->url('/freeze'), $payload)
            ->assertOk();
    }

    public function test_only_the_super_admin_may_change_the_plan(): void
    {
        // «Customer Support لا يستطيع تغيير العمولات» — والباقةُ أصلُ
        // التسعير كلِّه، فهي أولى بالمنع.
        foreach ([PlatformRoleService::SUPPORT, PlatformRoleService::FINANCE,
                  PlatformRoleService::RISK, PlatformRoleService::COMPLIANCE] as $roleCode) {
            $this->actingAs($this->operator($roleCode), 'user')
                ->postJson($this->url('/plan'), [
                    'plan' => A::PLAN_MERCHANT_PRO, 'reason' => 'ترقية بطلب التاجر',
                ])->assertStatus(403);
        }
    }

    public function test_only_support_may_open_a_ticket_from_the_centre(): void
    {
        foreach ([PlatformRoleService::FINANCE, PlatformRoleService::COMPLIANCE] as $roleCode) {
            $this->actingAs($this->operator($roleCode), 'user')
                ->postJson($this->url('/ticket'), ['subject' => 'شكوى التاجر عن تأخّر تسوية'])
                ->assertStatus(403);
        }

        $r = $this->actingAs($this->operator(PlatformRoleService::SUPPORT), 'user')
            ->postJson($this->url('/ticket'), [
                'subject' => 'شكوى التاجر عن تأخّر تسوية',
                'category' => 'other', 'priority' => 'high',
            ])->assertOk();

        $number = $r->json('data.ticket_number');
        $this->assertStringStartsWith('TKT-', $number);

        // **وتظهر في قسم الدعم فوراً** — لا في نظامٍ آخر يُبحث فيه.
        $support = $this->actingAs($this->operator(PlatformRoleService::SUPPORT), 'user')
            ->getJson($this->url('/support'))->assertOk()->json('data');

        $this->assertSame(1, $support['open']);
        $this->assertSame($number, $support['rows'][0]['number']);
    }

    public function test_an_open_ticket_that_is_being_investigated_is_still_counted_open(): void
    {
        // كانت الشاشةُ تعدّ `['open','in_progress']` — و`in_progress` ليست
        // من `SupportTicket::STATUSES` أصلاً. فتذكرةٌ «قيد التحقيق»
        // تُحسب مغلقة، والرقمُ أقلُّ من الحقيقة بلا أن يُخطئ شيء.
        SupportTicket::create([
            'ticket_number' => SupportTicket::nextTicketNumber(),
            'user_id' => $this->merchant->id,
            'category' => 'other', 'priority' => 'normal',
            'status' => 'investigating', 'subject' => 'تحقيق جارٍ',
        ]);

        $d = $this->actingAs($this->operator(PlatformRoleService::SUPPORT), 'user')
            ->getJson($this->url('/support'))->assertOk()->json('data');

        $this->assertSame(1, $d['open'], 'تذكرةٌ قيد التحقيق حُسبت مغلقة');
    }

    public function test_a_ticket_without_a_real_subject_is_refused(): void
    {
        $this->actingAs($this->operator(PlatformRoleService::SUPPORT), 'user')
            ->postJson($this->url('/ticket'), ['subject' => 'شكوى'])
            ->assertStatus(422);
    }

    public function test_lacking_a_grant_is_told_apart_from_lacking_the_right(): void
    {
        // **رمزان لمعنيين** — وكانا رمزاً واحداً. فموظّفُ المخاطر يقرأ
        // «لا تملك صلاحية هذا الإجراء» فيذهب يطلب صلاحيّةً يملكها، بدل
        // أن يضغط «فتح إذن اطّلاع» وهو على بُعد زرٍّ منه.
        $this->actingAs($this->operator(PlatformRoleService::SUPPORT), 'user')
            ->getJson($this->url('/operational-detail'))
            ->assertStatus(403);   // ليس من عملك أصلاً

        $r = $this->actingAs($this->operator(PlatformRoleService::RISK), 'user')
            ->getJson($this->url('/operational-detail'))
            ->assertStatus(423);   // من عملك — وافتح إذناً

        $this->assertSame('ACCESS_GRANT_REQUIRED', $r->json('code'));
        $this->assertNotEmpty($r->json('meta.unlock'), 'رفضٌ لا يقول كيف يُفتح');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ التذكرةُ لها بابٌ واحد
    // ══════════════════════════════════════════════════════════════════

    public function test_both_surfaces_open_tickets_through_the_same_door(): void
    {
        // نسختان من توليد رقم التذكرة تُنتجان رقمين متطابقين لطلبين
        // متزامنين — ولا يظهر ذلك إلّا يوم يتّصل عميلان برقمٍ واحد.
        $api = file_get_contents(base_path(
            'app/Http/Controllers/Api/V1/Amial/SupportConsoleController.php'));

        $this->assertStringContainsString('SupportTicketService', $api,
            'وحدةُ الدعم لا تمرّ بخدمة التذاكر — بابان لتوليد الرقم');
        $this->assertStringNotContainsString('SupportTicket::nextTicketNumber()', $api,
            'رقمُ التذكرة يُولَّد في المتحكّم أيضاً — والقفلُ لا يعني شيئاً خارج معاملة');
    }
}
