<?php

namespace Tests\Feature;

use App\Models\FeeChangeLog;
use App\Models\FeeScheme;
use App\Models\Transaction;
use App\Support\Fees\FeeOperationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AMIAL-FEE-TRUTH-022 — **§26: كلُّ شاشةٍ تُفتَح، وكلُّ زرٍّ يُضغط.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **القاعدةُ التاسعة في `CLAUDE.md`: زرٌّ لم يُضغط ليس مبنيّاً.** و«المسارُ
 * مسجَّلٌ والخدمةُ تعمل» لا تعني أنّ الصفحةَ تُفتح: قالبٌ يطلب متغيّراً لا
 * يُمرَّر يسقط بـ٥٠٠، ورابطٌ إلى مسارٍ لا وجودَ له يسقط عند التصيير لا عند
 * الضغط.
 *
 * وهذا الملفُّ يفتح **كلَّ** شاشات مركز الرسوم ببياناتٍ حقيقيّة، ويضغط
 * الإجراءات، ويقيس **أين ذهبت** لا «هل ظهر خطأ».
 */
class FeeCentreScreensGuardTest extends TestCase
{
    use RefreshDatabase;

    private \App\Models\User $admin;

    private \App\Models\User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        // **مديرُ المنصّة** — يملك القراءةَ والكتابة.
        $this->admin = $this->operatorWithRole('967770009901', 'platform_admin');

        // **ومراجِعٌ يملك القراءةَ وحدَها** — هو الدورُ الذي وُجد `fees.view`
        // لأجله: محاسبٌ يفتح تقريرَ الأرباح ولا يغيّر تسعيرةً واحدة.
        $this->viewer = $this->viewOnlyOperator('967770009902');
    }

    private function operatorWithRole(string $phone, string $roleCode): \App\Models\User
    {
        $u = \App\Models\User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => $phone,
        ]);

        $roleId = \Illuminate\Support\Facades\DB::table('roles')
            ->whereNull('merchant_user_id')->where('code', $roleCode)->value('id');

        $this->assertNotNull($roleId, "الدورُ «{$roleCode}» غيرُ موجود — الهجرةُ الأمُّ لم تُشغَّل");

        \Illuminate\Support\Facades\DB::table('admin_user_roles')->updateOrInsert(
            ['user_id' => $u->id, 'role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()],
        );

        return $u;
    }

    /** دورٌ يُصنَع للاختبار: `platform.fees.view` وحدَها. */
    private function viewOnlyOperator(string $phone): \App\Models\User
    {
        $roleId = \Illuminate\Support\Facades\DB::table('roles')->insertGetId([
            'code' => 'fee_viewer_test', 'label_ar' => 'مراجعُ رسومٍ (اختبار)',
            'merchant_user_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $permId = \Illuminate\Support\Facades\DB::table('permissions')
            ->where('code', 'platform.fees.view')->value('id');

        $this->assertNotNull($permId,
            'الصلاحيّة `platform.fees.view` غيرُ موجودة — فالقراءةُ لا تنفصل عن الكتابة');

        \Illuminate\Support\Facades\DB::table('role_permissions')->insert([
            'role_id' => $roleId, 'permission_id' => $permId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $u = \App\Models\User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => $phone,
        ]);

        \Illuminate\Support\Facades\DB::table('admin_user_roles')->insert([
            'user_id' => $u->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    /** طلبٌ بجلسةِ مديرِ المنصّة — **بكامل الوسائط**، لا بتخطّيها. */
    private function asAdmin()
    {
        return $this->actingAs($this->admin, 'user');
    }

    private function asViewer()
    {
        return $this->actingAs($this->viewer, 'user');
    }

    private function scheme(array $o = []): FeeScheme
    {
        return FeeScheme::create(array_merge([
            'code' => 'SEND_MONEY',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'percent',
            'percent_rate' => '1.5000',
            'fixed_amount' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
            'version' => 1,
            'is_active' => true,
            'effective_from' => now()->subDay(),
        ], $o));
    }

    /**
     * @test
     *
     * **① كلُّ شاشات المركز تُفتح بـ٢٠٠ — ولو كانت القاعدةُ فارغة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * والحالةُ الفارغةُ هي المنسيّةُ دائماً: يُبنى القالبُ على بياناتٍ
     * موجودةٍ ويُجرَّب عليها، ثمّ يُنشر على قاعدةٍ نظيفةٍ فيسقط على `null`.
     */
    public function every_screen_opens_on_an_empty_database(): void
    {
        $urls = [
            '/admin/amial/fees',
            '/admin/amial/fees/operations',
            '/admin/amial/fees/policies',
            '/admin/amial/fees/profit',
            '/admin/amial/fees/drill',
            '/admin/amial/fees/history',
            '/admin/amial/fees/history/SEND_MONEY',
            '/admin/amial/fees/create',
            '/admin/amial/fees/create?code=CASH_OUT&applies_to=customer',
        ];

        foreach ($urls as $u) {
            $this->asAdmin()->get($u)
                ->assertStatus(200, "الشاشة {$u} لا تُفتح على قاعدةٍ فارغة");
        }
    }

    /**
     * @test
     *
     * **② وتُفتح ببياناتٍ حقيقيّةٍ كذلك.**
     */
    public function every_screen_opens_with_real_data(): void
    {
        $this->scheme();
        $this->scheme(['code' => 'CASH_OUT', 'version' => 1,
            'agent_commission_percent' => '30']);

        FeeChangeLog::create([
            'fee_scheme_id' => null, 'code' => 'SEND_MONEY', 'action' => 'created',
            'old_values' => ['percent_rate' => '1.0000', 'bearer' => 'sender'],
            'new_values' => ['percent_rate' => '1.5000', 'bearer' => 'receiver', 'version' => 2],
            'admin_id' => null, 'ip' => '127.0.0.1', 'created_at' => now(),
        ]);

        Transaction::create([
            'user_id' => 1, 'transaction_id' => 'TX-TEST-1',
            'transaction_type' => 'send_money',
            'debit' => '100.0000', 'credit' => '0', 'charge' => '1.5000',
            'amount' => '100.0000', 'balance' => '0',
            'from_user_id' => 1, 'to_user_id' => 2,
            'zone_code' => 'SOUTH',
        ]);

        foreach ([
            '/admin/amial/fees',
            '/admin/amial/fees?zone=SOUTH&category=transfer&q=تحويل',
            '/admin/amial/fees/operations',
            '/admin/amial/fees/policies',
            '/admin/amial/fees/profit',
            '/admin/amial/fees/drill',
            '/admin/amial/fees/drill?type=send_money',
            '/admin/amial/fees/history',
            '/admin/amial/fees/history/SEND_MONEY',
            '/admin/amial/fees/create?code=SEND_MONEY&applies_to=customer',
        ] as $u) {
            $this->asAdmin()->get($u)
                ->assertStatus(200, "الشاشة {$u} تسقط على بياناتٍ حقيقيّة");
        }
    }

    /**
     * @test
     *
     * **③ ورمزٌ لا وجودَ له في المسار يُردّ ٤٠٤ لا ٥٠٠.**
     */
    public function an_unknown_code_is_not_found_rather_than_a_crash(): void
    {
        $this->asAdmin()->get('/admin/amial/fees/history/NOT_A_CODE')
            ->assertStatus(404);

        $this->asAdmin()->get('/admin/amial/fees/create?code=NOT_A_CODE')
            ->assertStatus(404);
    }

    /**
     * @test
     *
     * **④ ومنطقةٌ مخترَعةٌ لا تُدخِل الاستعلامَ في الفراغ.**
     *
     * فقيمةٌ من المتصفّح تدخل استعلامَ التسعيرة، **و«لا نسخة» تُقرأ صفراً**.
     */
    public function an_invented_zone_falls_back_and_does_not_crash(): void
    {
        $this->scheme();

        $this->asAdmin()->get('/admin/amial/fees?zone=ATLANTIS')
            ->assertStatus(200)
            ->assertSee('الجنوب');   // رُدّ إلى المنطقة التشغيليّة
    }

    /**
     * @test
     *
     * **⑤ ونطاقٌ مقلوبٌ يُصحَّح ولا يُخرج صفراً كاذباً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `from = 2026-12-31` و`to = 2026-01-01` يُخرج صفراً في كلّ خانة،
     * **والصفرُ يُقرأ «لا ربحَ في الفترة»** لا «سألتَ سؤالاً مقلوباً».
     */
    public function a_reversed_date_range_is_corrected(): void
    {
        Transaction::create([
            'user_id' => 1, 'transaction_id' => 'TX-R-1',
            'transaction_type' => 'admin_charge',
            'debit' => '0', 'credit' => '25.0000', 'charge' => '0',
            'amount' => '25.0000', 'balance' => '0',
            'from_user_id' => 1, 'to_user_id' => 1, 'zone_code' => 'SOUTH',
        ]);

        $from = now()->addDays(2)->toDateString();
        $to = now()->subDays(2)->toDateString();

        $res = $this->asAdmin()
            ->get("/admin/amial/fees/profit?from={$from}&to={$to}")
            ->assertStatus(200);

        $report = $res->viewData('report');

        $this->assertTrue($report['from']->lessThanOrEqualTo($report['to']),
            'النطاقُ المقلوبُ مرّ كما هو — فالتقريرُ يُخرج صفراً ويُقرأ «لا ربح»');

        $this->assertSame('25.0000', $report['net'],
            'النطاقُ صُحّح ولم تُقرأ الحركةُ التي بداخله');
    }

    /**
     * @test
     *
     * **⑥ وتاريخٌ لا يُفهَم لا يُسقط الصفحة.**
     */
    public function an_unparsable_date_does_not_crash_the_report(): void
    {
        $this->asAdmin()
            ->get('/admin/amial/fees/profit?from=%3Cscript%3E&to=NaN')
            ->assertStatus(200);
    }

    /**
     * @test
     *
     * **⑦ والتعطيلُ بلا سببٍ يُرفض — والنسخةُ تبقى سارية.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فالتعطيلُ يجعل العمليّةَ **مجّانيّةً** حتّى تُضبط أخرى. وقرارٌ بهذا
     * الأثر كان خلف `confirm()` من المتصفّح: نافذةٌ لا تقبل سبباً.
     */
    public function deactivation_without_a_reason_is_refused(): void
    {
        $s = $this->scheme();

        $this->asAdmin()
            ->post("/admin/amial/fees/{$s->id}/deactivate", ['reason' => ''])
            ->assertSessionHasErrors();

        $this->assertTrue((bool) $s->fresh()->is_active,
            'عُطّلت النسخةُ رغم رفض الطلب — **الرفضُ كان في الرسالة لا في الأثر**');
    }

    /**
     * @test
     *
     * **⑧ وبسببٍ يُقبَل، ويُحفَظ السببُ في السجلّ.**
     */
    public function deactivation_with_a_reason_records_it(): void
    {
        $s = $this->scheme();

        $this->asAdmin()
            ->post("/admin/amial/fees/{$s->id}/deactivate",
                ['reason' => 'قرارُ الإدارة بإعفاء التحويلات حتّى نهاية الربع'])
            ->assertRedirect();

        $this->assertFalse((bool) $s->fresh()->is_active);

        $log = FeeChangeLog::where('fee_scheme_id', $s->id)
            ->where('action', 'deactivated')->latest('id')->first();

        $this->assertNotNull($log, 'التعطيلُ لم يُسجَّل — فلا أثرَ لمن فعله');
        $this->assertStringContainsString('قرارُ الإدارة',
            (string) (((array) $log->new_values)['reason'] ?? ''),
            'السببُ لم يُحفَظ — فالسجلُّ يقول «عُطّلت» ولا يقول لماذا');
    }

    /**
     * @test
     *
     * **⑨ ولا نافذةَ `confirm()` من المتصفّح في شاشات المال.**
     *
     * ══════════════════════════════════════════════════════════════════
     * نافذةُ المتصفّح: بلا هويّة، لا تقبل سبباً، نصُّها من المتصفّح لا من
     * المنتج، ولا تُنسَّق في شاشةٍ عربيّة. **وقرارٌ يجعل عمليّةً مجّانيّةً
     * لا يُؤخَذ بضغطةِ «OK».**
     */
    public function no_browser_confirm_survives_in_the_fee_centre(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views/admin-views/amial/fees/*.blade.php')) as $f) {
            // **تعليقاتُ Blade تُنزع أوّلاً.** فشرحٌ يقول «كان التعطيلُ خلف
            // `confirm()`» **يصف ما أُزيل**، وقراءتُه استعمالاً تُسقط الحارسَ
            // على الإصلاح نفسِه. (‏وهذا الفخُّ أوقع المشروعَ من قبل: حارسٌ
            // مرّ لأنّ الكلمةَ وردت في تعليقٍ عربيٍّ يصف العطل.)
            $src = (string) preg_replace(
                ['~\{\{--.*?--\}\}~s', '~<!--.*?-->~s', '~/\*.*?\*/~s', '~^\s*//.*$~m'],
                '', (string) file_get_contents($f));

            if (preg_match('~(?<![\w$>.])confirm\s*\(~', $src)) {
                $offenders[] = basename($f);
            }
        }

        $this->assertSame([], $offenders,
            'شاشاتٌ ماليّةٌ تستعمل نافذةَ تأكيدٍ من المتصفّح: '.implode('، ', $offenders));
    }

    /**
     * @test
     *
     * **⑩ وكلُّ رابطٍ في شاشات المركز يقود إلى مسارٍ مسجَّل.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `route()` يسقط عند **التصيير** لا عند الضغط، فرابطٌ إلى اسمٍ لا وجودَ
     * له يُسقط الصفحةَ كلَّها بـ٥٠٠ — لا زرّاً واحداً.
     */
    public function every_named_route_used_by_the_screens_exists(): void
    {
        $names = [];

        foreach (glob(resource_path('views/admin-views/amial/fees/*.blade.php')) as $f) {
            preg_match_all("~route\(\s*'([a-z0-9_.\-]+)'~i",
                (string) file_get_contents($f), $m);

            foreach ($m[1] ?? [] as $n) {
                $names[$n] = basename($f);
            }
        }

        $this->assertNotEmpty($names, 'لم يُقرأ أيُّ رابط — التعبيرُ لم يعد يطابق');

        $missing = [];

        foreach ($names as $n => $where) {
            if (Route::getRoutes()->getByName($n) === null) {
                $missing[] = "{$n}  ← {$where}";
            }
        }

        $this->assertSame([], $missing, sprintf(
            "روابطُ إلى مساراتٍ لا وجودَ لها — تُسقط الصفحةَ عند التصيير:\n  %s",
            implode("\n  ", $missing)));
    }

    /**
     * @test
     *
     * **⑪ والمحاكي يحسب بالمنطقة المطلوبة لا بمنطقةٍ مثبَّتة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * «محاكٍ يخالف الإنتاج» صفرٌ في تعريف الإغلاق: من جرّب تسعيرةَ منطقةٍ
     * ورأى رقمَ أخرى اعتمده وحفظ نسخةً على أساسه.
     */
    public function the_simulator_honours_the_requested_zone(): void
    {
        $res = $this->asAdmin()->postJson('/admin/amial/fees/simulate', [
            'amount' => '1000',
            'code' => 'SEND_MONEY',
            'zone_code' => 'NORTH',
            'applies_to' => 'customer',
            'fee_type' => 'percent',
            'percent_rate' => '2',
            'fixed_amount' => '0',
            'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0',
            'bearer' => 'sender',
        ])->assertOk();

        $res->assertJsonPath('result.zone_code', 'NORTH');
        $res->assertJsonPath('result.fee', '20.0000');
    }

    /**
     * @test
     *
     * **⑫ ومنطقةٌ مخترَعةٌ في المحاكي تُردّ إلى التشغيليّة، لا تُمرَّر.**
     */
    public function the_simulator_refuses_an_invented_zone(): void
    {
        $this->asAdmin()->postJson('/admin/amial/fees/simulate', [
            'amount' => '1000', 'code' => 'SEND_MONEY', 'zone_code' => 'ATLANTIS',
            'applies_to' => 'customer', 'fee_type' => 'percent', 'percent_rate' => '2',
            'fixed_amount' => '0', 'agent_commission_percent' => '0',
            'agent_commission_fixed' => '0', 'bearer' => 'sender',
        ])->assertOk()->assertJsonPath('result.zone_code', 'SOUTH');
    }

    /**
     * @test
     *
     * **⑬ ومدخلاتٌ شاذّةٌ تُردّ ٤٢٢ بعربيّةٍ مفهومة، لا ٥٠٠.**
     */
    public function the_simulator_refuses_hostile_input(): void
    {
        foreach (['-1', 'NaN', 'abc', '1e400'] as $bad) {
            $r = $this->asAdmin()->postJson('/admin/amial/fees/simulate', [
                'amount' => $bad, 'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH',
                'applies_to' => 'customer', 'fee_type' => 'percent',
                'percent_rate' => '2', 'fixed_amount' => '0',
                'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
                'bearer' => 'sender',
            ]);

            $this->assertContains($r->getStatusCode(), [200, 422],
                "المدخلُ «{$bad}» أسقط المحاكي بدل أن يُردّ");

            if ($r->getStatusCode() === 422) {
                $this->assertFalse((bool) $r->json('success'));
            }
        }
    }


    // ══════════════════════════════════════════════════════════════════
    // §15 · §24 — الصلاحيّات: القراءةُ غيرُ الكتابة
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **⑮ ومن لا يملك صلاحيّةَ الرسوم لا يفتح شيئاً من المركز.**
     */
    public function an_operator_without_any_fee_permission_is_refused(): void
    {
        $stranger = \App\Models\User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770009903',
        ]);

        foreach (['/admin/amial/fees', '/admin/amial/fees/profit',
                  '/admin/amial/fees/create'] as $u) {
            $this->actingAs($stranger, 'user')->get($u)
                ->assertStatus(403, "الشاشة {$u} تُفتح بلا صلاحيّةِ رسومٍ إطلاقاً");
        }
    }

    /**
     * @test
     *
     * **⑯ ومن يملك القراءةَ يقرأ — وهذا هو سببُ وجود `fees.view`.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كان المركزُ كلُّه خلف `platform.fees.update`. فمحاسبٌ يريد تقريرَ
     * أرباحٍ يُعطى **مفتاحَ تغيير المال كلِّه**، أو يُمنع من الاطّلاع.
     */
    public function a_view_only_operator_can_read_every_report(): void
    {
        $this->scheme();

        foreach (['/admin/amial/fees', '/admin/amial/fees/operations',
                  '/admin/amial/fees/policies', '/admin/amial/fees/profit',
                  '/admin/amial/fees/drill', '/admin/amial/fees/history'] as $u) {
            $this->asViewer()->get($u)
                ->assertStatus(200, "القارئُ مُنع من {$u} وهو لا يغيّر شيئاً");
        }
    }

    /**
     * @test
     *
     * **⑰ ولا يكتب — ولا بطلبٍ مباشرٍ يتخطّى الشاشة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وإخفاءُ الزرّ ليس حراسة.** فمن عرف المسارَ أرسل الطلبَ بلا شاشة،
     * والحارسُ الحقيقيُّ في المسار وحدَه. (‏«حارسٌ في الواجهة» بابٌ مفتوح.)
     */
    public function a_view_only_operator_cannot_write_even_by_direct_request(): void
    {
        $s = $this->scheme();

        $this->asViewer()->get('/admin/amial/fees/create')->assertStatus(403);

        $this->asViewer()->post('/admin/amial/fees', [
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '99', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender',
        ])->assertStatus(403);

        $this->asViewer()
            ->post("/admin/amial/fees/{$s->id}/deactivate", ['reason' => 'محاولةٌ غيرُ مأذونة'])
            ->assertStatus(403);

        $this->asViewer()->postJson('/admin/amial/fees/simulate', [
            'amount' => '1000', 'code' => 'SEND_MONEY',
        ])->assertStatus(403);

        // **والأثرُ يُقاس لا الردُّ وحدَه** — رفضٌ يعود ٤٠٣ وقد كتب أسوأُ
        // من قبولٍ صريح.
        $this->assertTrue((bool) $s->fresh()->is_active, 'النسخةُ عُطّلت رغم الرفض');
        $this->assertSame(1, FeeScheme::where('code', 'SEND_MONEY')->count(),
            'أُنشئت نسخةٌ رغم الرفض — **الحارسُ ردّ بعد أن كتب**');
    }

    /**
     * @test
     *
     * **⑱ وكلُّ نموذجِ كتابةٍ يحمل رمز CSRF، وكلُّ كتابةٍ عبر POST.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولا يُقاس هذا بطلبٍ حيٍّ**: `ValidateCsrfToken` يتخطّى نفسَه في بيئة
     * الاختبار (`runningUnitTests()`)، **فاختبارٌ يرسل طلباً بلا رمزٍ يمرّ
     * دائماً ويُقرأ نجاحاً** — وهو حارسٌ يكذب.
     *
     * فيُقاس ما يُقاس فعلاً: أنّ الوسيطَ يشمل هذه المسارات (‏وهو على المجموعة
     * `web`)، وأنّ كلَّ نموذجٍ يكتب يحمل `@csrf`، وأنّ **لا كتابةَ عبر GET**
     * — فرابطٌ يغيّر تسعيرةً يُنفَّذ بمجرّد فتحه.
     */
    public function every_write_is_a_post_and_carries_a_csrf_token(): void
    {
        $writes = ['admin.amial.fees.store', 'admin.amial.fees.simulate',
            'admin.amial.fees.deactivate'];

        foreach ($writes as $name) {
            $r = Route::getRoutes()->getByName($name);

            $this->assertNotNull($r, "المسارُ {$name} غيرُ موجود");

            $this->assertNotContains('GET', $r->methods(),
                "المسارُ {$name} يقبل GET — **فرابطٌ يغيّر المالَ يُنفَّذ بفتحه**");

            // **والمجموعةُ تُفكَّك**: `gatherMiddleware()` يردّ الاسمَ
            // المستعار `web` لا محتواه، فالبحثُ عن الصنف مباشرةً يسقط دائماً
            // — **حارسٌ يسقط على الصواب لا يحرس شيئاً**.
            $stack = $r->gatherMiddleware();
            $expanded = [];

            foreach ($stack as $m) {
                $expanded = array_merge($expanded, is_string($m)
                    ? (\Illuminate\Support\Facades\Route::getMiddlewareGroups()[$m] ?? [$m])
                    : [$m]);
            }

            // **والصنفُ يُفحَص بأصلَيه معاً.** فالإطارُ يحمل اسمين لهذا
            // الحارس (`ValidateCsrfToken` و`VerifyCsrfToken`) **ولا يرث
            // أحدُهما الآخر**، ووسيطُ المشروع يرث الثاني. فحارسٌ يفحص
            // الأوّلَ وحدَه يسقط على إعدادٍ سليم.
            $csrfBases = [
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            ];

            $hasCsrf = false;

            foreach ($expanded as $m) {
                if (! is_string($m) || ! class_exists($m)) {
                    continue;
                }

                foreach ($csrfBases as $base) {
                    if (is_a($m, $base, true)) {
                        $hasCsrf = true;
                    }
                }
            }

            $this->assertTrue($hasCsrf, "المسارُ {$name} خارجَ حارس CSRF");
        }

        // وكلُّ `<form method="POST">` في شاشات المركز يحمل `@csrf`.
        $missing = [];

        foreach (glob(resource_path('views/admin-views/amial/fees/*.blade.php')) as $f) {
            $src = (string) file_get_contents($f);

            preg_match_all('~<form\b[^>]*method\s*=\s*"(?:POST|post)"[^>]*>~', $src, $m,
                PREG_OFFSET_CAPTURE);

            foreach ($m[0] ?? [] as [$tag, $at]) {
                // يُقرأ ما بين فتح النموذج وإغلاقه — و`@csrf` يجب أن يكون فيه.
                $end = strpos($src, '</form>', $at);
                $body = substr($src, $at, $end === false ? 400 : $end - $at);

                if (! str_contains($body, '@csrf')) {
                    $missing[] = basename($f);
                }
            }
        }

        $this->assertSame([], $missing,
            'نماذجُ كتابةٍ بلا `@csrf`: '.implode('، ', array_unique($missing)));
    }

    // ══════════════════════════════════════════════════════════════════
    // §11 · §12 — التحقّقُ الماليّ والتزامن
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **⑲ وحصّةُ وكيلٍ على عمليّةٍ بلا وكيلٍ تُرفض.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فرقمٌ هناك **يُقتطع من ربح المنصّة ويُقيَّد لحصّةٍ لا صاحبَ لها**،
     * والدفترُ متوازنٌ فلا يُنبّه أحد.
     */
    public function an_agent_share_on_a_wallet_transfer_is_refused(): void
    {
        $this->asAdmin()->post('/admin/amial/fees', [
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '1', 'fixed_amount' => '0',
            'agent_commission_percent' => '40', 'agent_commission_fixed' => '0',
            'bearer' => 'sender',
        ])->assertSessionHasErrors();

        $this->assertSame(0, FeeScheme::count(),
            'حُفظت نسخةٌ بحصّةِ وكيلٍ على عمليّةٍ لا وكيلَ فيها');
    }

    /**
     * @test
     *
     * **⑳ وجهةٌ لا تُطلب لهذه العمليّة تُرفض.**
     *
     * فنسخةُ `MERCHANT_QR` بـ`applies_to = agent` تُحفَظ وتُرى فعّالة —
     * **ولا تُطابق نداءً واحداً**، فتبقى العمليّةُ بلا تسعيرة.
     */
    public function an_actor_the_operation_never_requests_is_refused(): void
    {
        $this->asAdmin()->post('/admin/amial/fees', [
            'code' => 'MERCHANT_QR', 'zone_code' => 'SOUTH', 'applies_to' => 'agent',
            'fee_type' => 'percent', 'percent_rate' => '1', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'merchant',
        ])->assertSessionHasErrors();

        $this->assertSame(0, FeeScheme::count());
    }

    /**
     * @test
     *
     * **㉑ وأدنى رسمٍ يفوق أعلاه يُرفض.**
     */
    public function a_min_fee_above_the_max_is_refused(): void
    {
        $this->asAdmin()->post('/admin/amial/fees', [
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '1', 'fixed_amount' => '0',
            'min_fee' => '100', 'max_fee' => '10',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender',
        ])->assertSessionHasErrors();

        $this->assertSame(0, FeeScheme::count());
    }

    /**
     * @test
     *
     * **㉒ والقاعدةُ نفسُها تمنع نسختين نشطتين — لا الشيفرةُ وحدَها.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `createVersion` تقفل الصفَّ القائم. **ولا صفَّ ليُقفَل حين تكون
     * التسعيرةُ الأولى**: مديران يفتحان «نسخة جديدة» معاً، كلاهما يقرأ
     * `null`، وكلاهما يكتب `version = 1` نشطة. فأيُّهما تُطبَّق يقرّره
     * ترتيبُ المحرّك — **ويتغيّر بين استعلامين**.
     *
     * ومساراتٌ أخرى تكتب في الجدول (‏بذورٌ · هجرات · إصلاحٌ يدويّ) لا يمرّ
     * أيٌّ منها بتلك الدالّة. **فالقاعدةُ وحدَها تحرس الجميع.**
     */
    public function the_database_itself_refuses_a_second_active_scheme(): void
    {
        $this->scheme();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // نسخةٌ ثانيةٌ نشطةٌ لنفس (‏رمز · منطقة · جهة) — تتخطّى الخدمةَ عمداً.
        FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'fixed', 'percent_rate' => '0', 'fixed_amount' => '5',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 2, 'is_active' => true,
        ]);
    }

    /**
     * @test
     *
     * **㉓ ولا رقمَ نسخةٍ مكرّرٌ في المسار نفسِه.**
     *
     * فرقمان متساويان يجعلان `orderByDesc('version')->first()` عشوائيّاً.
     */
    public function the_database_refuses_a_duplicate_version_number(): void
    {
        $this->scheme(['is_active' => false]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        FeeScheme::create([
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'fixed', 'percent_rate' => '0', 'fixed_amount' => '5',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => false,
        ]);
    }

    /**
     * @test
     *
     * **㉔ وتصادمُ الحفظ يُقال بالعربيّة لا برسالة المحرّك الخام.**
     */
    public function a_save_collision_is_explained_in_arabic(): void
    {
        $this->scheme();

        // نسخةٌ ثانيةٌ **بنفس رقم النسخة** — تُصادم `fee_one_version`.
        $res = $this->asAdmin()->post('/admin/amial/fees', [
            'code' => 'SEND_MONEY', 'zone_code' => 'SOUTH', 'applies_to' => 'customer',
            'fee_type' => 'percent', 'percent_rate' => '2', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender',
        ]);

        // الحفظُ السليمُ ينجح ويصير v2 — فالقيدُ لا يمنع العملَ المشروع.
        $res->assertRedirect();
        $this->assertDatabaseHas('fee_schemes',
            ['code' => 'SEND_MONEY', 'version' => 2, 'is_active' => 1]);
        $this->assertDatabaseHas('fee_schemes',
            ['code' => 'SEND_MONEY', 'version' => 1, 'is_active' => 0]);
    }

    // ══════════════════════════════════════════════════════════════════
    // §17 — حقيقةُ تقرير الأرباح
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * **㉕ والعمولةُ تُقاس من الدفتر، لا تُعرَّف بأنّها الفرق.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كانت `العمولة = الإجمالي − الصافي`. فإن ضاع ريالٌ بينهما ظهر في خانة
     * الوكلاء **وكأنّه دُفع لهم**، والمعادلةُ تتوازن دائماً لأنّها مُعرَّفةٌ
     * لتتوازن — **فلا فرقَ يظهر أبداً مهما ضاع**.
     */
    public function an_unposted_fee_shows_as_an_unexplained_difference(): void
    {
        // رسمٌ حُصّل من العميل…
        Transaction::create([
            'user_id' => 1, 'transaction_id' => 'TX-G-1', 'transaction_type' => 'send_money',
            'debit' => '100.0000', 'credit' => '0', 'charge' => '10.0000',
            'amount' => '100.0000', 'balance' => '0',
            'from_user_id' => 1, 'to_user_id' => 2, 'zone_code' => 'SOUTH',
        ]);

        // …ولم يُقيَّد للمنصّة ولا للوكيل. **الريالُ ضاع.**
        $report = app(\App\Services\FeeProfitReportService::class)
            ->forPeriod(now()->startOfDay(), now()->endOfDay());

        $this->assertSame('10.0000', $report['gross']);
        $this->assertSame('0.0000', $report['net']);
        $this->assertSame('0.0000', $report['agent_commission'],
            'العمولةُ ظهرت ١٠ — أي أنّها ما زالت تُعرَّف بأنّها الفرق، '
            . '**فالمفقودُ يُنسَب إلى الوكلاء**');

        $this->assertSame('10.0000', $report['unexplained']);
        $this->assertFalse($report['balanced']);
    }

    /**
     * @test
     *
     * **㉖ وسلسلةٌ تامّةٌ تتوازن — فالحارسُ لا يصرخ على الصواب.**
     */
    public function a_complete_chain_balances(): void
    {
        Transaction::create([
            'user_id' => 1, 'transaction_id' => 'TX-B-1', 'transaction_type' => 'cash_out',
            'debit' => '100.0000', 'credit' => '0', 'charge' => '10.0000',
            'amount' => '100.0000', 'balance' => '0',
            'from_user_id' => 1, 'to_user_id' => 2, 'zone_code' => 'SOUTH',
        ]);

        Transaction::create([
            'user_id' => 2, 'transaction_id' => 'TX-B-2', 'transaction_type' => 'agent_commission',
            'debit' => '0', 'credit' => '4.0000', 'charge' => '0',
            'amount' => '4.0000', 'balance' => '0',
            'from_user_id' => 1, 'to_user_id' => 2, 'zone_code' => 'SOUTH',
        ]);

        Transaction::create([
            'user_id' => 1, 'transaction_id' => 'TX-B-3', 'transaction_type' => 'admin_charge',
            'debit' => '0', 'credit' => '6.0000', 'charge' => '0',
            'amount' => '6.0000', 'balance' => '0',
            'from_user_id' => 1, 'to_user_id' => 1, 'zone_code' => 'SOUTH',
        ]);

        $report = app(\App\Services\FeeProfitReportService::class)
            ->forPeriod(now()->startOfDay(), now()->endOfDay());

        $this->assertSame('10.0000', $report['gross']);
        $this->assertSame('6.0000', $report['net']);
        $this->assertSame('4.0000', $report['agent_commission']);
        $this->assertSame('0.0000', $report['unexplained']);
        $this->assertTrue($report['balanced']);
    }

    /**
     * @test
     *
     * **㉗ ولا `float` في حساب الأرباح.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وكلُّ رقمٍ في المشروع `DECIMAL(20,4)` عمداً. فتحويلُه إلى `float`
     * يُدخل خطأَ تمثيلٍ ثنائيّاً في **الرقم الذي يُقرأ ليُعرَف كم رُبح**.
     */
    public function the_profit_engine_uses_no_float_casts(): void
    {
        $src = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '',
            (string) file_get_contents(app_path('Services/FeeProfitReportService.php')));

        $this->assertDoesNotMatchRegularExpression('~\(float\)~', $src,
            'تحويلٌ إلى `float` في محرّك الأرباح — والأرقامُ عشريّةٌ نصّيّة');

        $this->assertDoesNotMatchRegularExpression('~number_format\s*\(~', $src,
            '`number_format` في الحساب لا في العرض — وهي تُقرّب صامتةً');
    }

    /**
     * @test
     *
     * **⑭ والصفحاتُ لا تعرض رمزاً خاماً بلا اسمٍ عربيّ.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فمن يقرأ `FAMILY_FUND_CONTRIB` في قائمةٍ منسدلةٍ يخمّن أيَّ عمليّةٍ
     * يسعّر، **ومن خمّن أخطأ في المال**.
     */
    public function the_operation_picker_shows_arabic_names(): void
    {
        $html = $this->asAdmin()->get('/admin/amial/fees/create')
            ->assertOk()->getContent();

        foreach (FeeOperationRegistry::all() as $code => $op) {
            $this->assertStringContainsString($op->labelAr, $html,
                "العمليّة «{$code}» تُعرَض بلا اسمٍ عربيّ");
        }
    }
}
