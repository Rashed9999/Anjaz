<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-AGENT-STAFF-002 — عقدُ ما بين الشاشة والخادم.
 *
 * **عطلان وقعا معاً وسببهما واحد: الشاشة تفترض ولا تتحقّق.**
 *
 * ١. صارت التبويبات تختلف بالدور، فاختفت عناصر الشبّاك من صفحة الإدارة.
 *    وكان السكربت يربط `#ag-find` مباشرةً، فيُرمى TypeError عند أوّل عنصرٍ
 *    غائب **ويتوقّف الملفّ كلّه**: لا مؤشّرات ولا فروع ولا أيّ تبويب. عطلٌ
 *    في سطرٍ واحدٍ عطّل اللوحة كلّها.
 *
 * ٢. كانت شاشة الموظّفين تقرأ الفروع من `j.data.branches`، والغلاف يضعها
 *    في `j.meta.branches`. وقراءةُ المفتاح الخطأ **لا تُخطئ بصوتٍ عالٍ**:
 *    تُعيد `[]` بهدوء، فتُخفى قائمة الفروع، ثمّ يرفض الخادم التعيين بـ«اختر
 *    الفرع» — فيقف المستعمل أمام رسالةٍ تطلب حقلاً لا يراه.
 *
 * والمشترك بينهما أنّ الخادم كان سليماً في الحالتين، وكلّ اختبارٍ عليه
 * يمرّ. فالمفقود طبقةُ فحصٍ للعقد نفسه: أنّ ما تقرؤه الشاشة موجودٌ فيما
 * يُرسله الخادم، وأنّ ما تربطه موجودٌ فيما تعرضه.
 */
class AgentPortalContractTest extends TestCase
{
    use RefreshDatabase;

    private User $company;
    private AgentStaff $hq;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = new User();
        $this->company->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => '967771800001',
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1,
        ])->save();
        EMoney::create(['user_id' => $this->company->id, 'current_balance' => '0']);

        $this->hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($this->company, 'hq123456');
    }

    private function addBranch(string $code = 'MKL'): AgentBranch
    {
        $bu = new User();
        $bu->forceFill([
            'f_name' => 'فرع ' . $code, 'l_name' => 'فرع', 'type' => AGENT_TYPE,
            'phone' => '9677718' . random_int(10000, 99999),
            'password' => Hash::make('secret123'), 'is_active' => 1, 'is_kyc_verified' => 1,
        ])->save();
        EMoney::create(['user_id' => $bu->id, 'current_balance' => '100000']);

        DB::table('agent_profiles')->insert([
            'user_id' => $bu->id, 'parent_agent_id' => $this->company->id, 'agent_level' => 2,
            'business_name' => 'فرع ' . $code, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $b = AgentBranch::create([
            'agent_user_id' => $this->company->id, 'branch_user_id' => $bu->id,
            'name' => 'فرع ' . $code, 'code' => $code, 'city' => 'حضرموت',
            'phone' => $bu->phone, 'is_active' => true,
        ]);
        $b->till()->create(['cash_on_hand' => '50000', 'max_cash_on_hand' => '900000', 'min_cash_alert' => '10000']);

        return $b;
    }

    // ── العقد مع الخادم ────────────────────────────────────────────────

    /** @test */
    public function the_overview_payload_carries_every_key_the_screen_reads(): void
    {
        $this->addBranch();

        $j = $this->actingAs($this->hq, 'agent_staff')
            ->getJson(route('agent.overview'))->assertOk()->json();

        // الشاشة تقرأ من `meta` — لا من `data` ولا من الجذر. وتثبيتُ ذلك هنا
        // هو ما يمنع تكرار الخطأ الصامت.
        $this->assertArrayHasKey('meta', $j);
        $this->assertArrayNotHasKey('data', $j);
        $this->assertIsArray($j['meta']['branches']);
        $this->assertCount(1, $j['meta']['branches']);

        foreach (['id', 'name', 'code'] as $k) {
            $this->assertArrayHasKey($k, $j['meta']['branches'][0],
                "قائمة الفروع في الشاشة تبني الخيارات من «{$k}»");
        }

        foreach (['cash_on_hand', 'emoney', 'branches', 'low_cash_branches'] as $k) {
            $this->assertArrayHasKey($k, $j['meta']['totals']);
        }

        // مؤشّرات اليوم — «ماذا حدث» لا «كم عندنا».
        foreach ([
            'staff_total', 'staff_active', 'tellers',
            'shifts_opened', 'shifts_open_now', 'drawers_cash',
            'deposits_count', 'deposits_total', 'withdrawals_count', 'withdrawals_total',
            'shifts_with_variance',
            'shortage_count', 'shortage_total', 'overage_count', 'overage_total',
            'branches_idle',
        ] as $k) {
            $this->assertArrayHasKey($k, $j['meta']['today'],
                "بطاقة المؤشّرات تقرأ «today.{$k}» ولا يُرسله الخادم");
        }
    }

    /** @test */
    public function a_company_with_no_branch_is_told_so_rather_than_shown_an_empty_list(): void
    {
        $j = $this->actingAs($this->hq, 'agent_staff')
            ->getJson(route('agent.overview'))->assertOk()->json('meta');

        $this->assertSame([], $j['branches']);
        $this->assertSame(0, $j['totals']['branches']);

        // والخادم يرفض التعيين بلا فرع — وهو رفضٌ صحيح. والشاشة يجب أن
        // تقوله قبل الضغط لا بعده.
        $r = $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.staff.store'), [
                'name' => 'علي حمد', 'role' => 'teller', 'password' => 'Noooor',
            ])->assertStatus(422);

        $this->assertStringContainsString('اختر الفرع', $r->json('message'));
    }

    /** @test */
    public function hiring_works_once_a_branch_exists(): void
    {
        $b = $this->addBranch('SYN');

        $r = $this->actingAs($this->hq, 'agent_staff')
            ->postJson(route('agent.staff.store'), [
                'name' => 'علي حمد', 'role' => 'teller',
                'branch_id' => $b->id, 'password' => 'Noooor',
            ])->assertOk();

        $this->assertSame('SYN-001', $r->json('username'));
        $this->assertStringContainsString('SYN-001', $r->json('message'));
    }

    // ── العقد مع الصفحة ────────────────────────────────────────────────

    /**
     * @test
     *
     * لا سطر يربط عنصراً بافتراض وجوده.
     *
     * الصفحة تتغيّر بنيتُها بالدور، فربطٌ مباشرٌ على `getElementById` يسقط
     * ويُوقف الملفّ كلّه عند أوّل عنصرٍ غائب. والحلّ أن يمرّ كلّ ربطٍ عبر
     * `on()` التي تتجاهل الغائب.
     */
    public function the_dashboard_script_never_binds_an_element_it_assumes_exists(): void
    {
        $blade = (string) preg_replace(
            ['/\{\{--.*?--\}\}/s', '~^\s*//.*$~m'],
            ' ',
            (string) file_get_contents(resource_path('views/agent-views/dashboard.blade.php')),
        );

        $this->assertSame(0, preg_match_all(
            "~document\.getElementById\('[^']+'\)\s*\.\s*(onclick|addEventListener|innerHTML|textContent|value)~",
            $blade, $m,
        ), "ربطٌ مباشرٌ يسقط على عنصرٍ غائب:\n" . implode("\n", $m[0] ?? []));
    }

    /**
     * @test
     *
     * كلّ سكربتٍ في البوّابة يُحلَّل نحويّاً.
     *
     * **فحصٌ أُدخل بسبب خطأٍ وقع أثناء كتابة هذا الملفّ نفسه.** حاولتُ نزع
     * الربط المباشر بتعبيرٍ نمطيّ جشع (`[^;]+`)، فقطع دالّةَ سهمٍ متعدّدةَ
     * الأسطر عند أوّل فاصلةٍ منقوطة داخلها، وأنتج `prompt('اسم الفرع:'))`
     * — قوسٌ زائدٌ يُعطّل الملفّ كلّه.
     *
     * وBlade لا يفحص جافاسكربت، وPHP لا يفحصها، والاختبارات لا تفتح متصفّحاً.
     * فكان الخطأ سيصل إلى الإنتاج بلا اعتراضٍ من أيّ طبقة.
     *
     * (وأوّل صياغةٍ لهذا الفحص عدّت الأقواس بدل أن تُحلّل، فأنذرت على ملفٍّ
     * سليم — لأنّ الأقواس داخل النصوص العربيّة والتعابير النمطيّة تُحسَب.
     * وحارسٌ يُنذر كذباً يُسكَت بعد مرّتين، فيصير أسوأ من غيابه. فاستُبدل
     * بمحلّلٍ حقيقيّ.)
     */
    public function every_agent_script_block_parses_as_javascript(): void
    {
        exec('node --version 2>/dev/null', $out, $code);

        if ($code !== 0) {
            $this->markTestSkipped('node غير متوفّر — لا يمكن تحليل الجافاسكربت هنا');
        }

        foreach (['dashboard', '_staff', '_counter', 'login'] as $view) {
            $blade = (string) file_get_contents(resource_path("views/agent-views/{$view}.blade.php"));

            // تعابير Blade تُستبدل **قبل** تقسيم الوسوم لا بعده: `{{ ... }}`
            // قد تحوي `>` (مثل `request()->attributes`)، فيقف `[^>]*` عندها
            // ويُقتطع وسم `<script>` في منتصفه فيدخل جزءٌ منه في الشيفرة.
            // (وقع ذلك في أوّل تشغيلٍ لهذا الفحص، فأنذر على ملفٍّ سليم.)
            $blade = (string) preg_replace('~\{\{[^}]*\}\}~', 'X', $blade);

            preg_match_all('~<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>~s', $blade, $blocks);

            foreach ($blocks[1] as $i => $code_js) {
                $tmp = tempnam(sys_get_temp_dir(), 'amial_js_') . '.js';
                file_put_contents($tmp, $code_js);

                $err = [];
                $status = 0;
                exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $err, $status);
                @unlink($tmp);

                $this->assertSame(0, $status,
                    "سكربت لا يُحلَّل في {$view}.blade.php (كتلة #{$i}) — الصفحة ستُحمَّل وأزرارُها ميّتة:\n"
                    . implode("\n", array_slice($err, 0, 6)));
            }
        }
    }

    /**
     * @test
     *
     * إدارة الفروع والنقد تعمل بحساب الشركة **وبرمز موظّف الإدارة** معاً.
     *
     * **العطل الذي أدخل هذا الاختبار، وهو أسوأ ما وقع في هذه الميزة.**
     *
     * كان الكود يستعمل `$request->user()` في ستّة مواضع. وهي تقرأ الحارس
     * الافتراضيّ (`user`)، وموظّف شركة الصرافة يدخل بحارس `agent_staff` —
     * فترجع `null`. والنتيجة أنّ إنشاء الفرع وشحن رصيده وتوريد النقد وجرد
     * الدرج **تسقط كلّها بـ500** لمن يدخل برمز موظّف، وتعمل كلّها لمن يدخل
     * بهاتف الشركة.
     *
     * ولم يكشفه شيء لأنّ اختبار السلسلة الكامل دخل **بالهاتف**. أي أنّني
     * جرّبتُ المسار السليم، والمستعمل يسلك المسار الآخر. والدرس أنّ ميزةً
     * لها مدخلان تحتاج أن تُختبر من مدخليها لا من أحدهما.
     */
    public function branch_management_works_under_the_portal_guard_and_never_500s(): void
    {
        $b = $this->addBranch('DUO');

        // (١) حارس `user` **مرفوضٌ من البوّابة** — وهذا هو الإصلاح:
        //     جلسةٌ عليه كانت تُعطّل لوحة الإدارة بحلقة إعادة توجيه.
        $this->actingAs($this->company, 'user')
            ->postJson(route('agent.branch.fund', ['id' => $b->id]),
                ['amount' => '1', 'note' => 'اختبار المدخل الأوّل'])
            ->assertStatus(401);

        // (٢) وحارس البوّابة يعمل. والمطلوب هنا **ألّا** يكون الردّ 500:
        //     أيّ ردٍّ منطقيّ مقبول، والانهيار وحده مرفوض.
        foreach ([
            ['agent.branch.create', [], ['name' => 'فرع ثانٍ', 'code' => 'TWO',
                'phone' => '967771700123', 'password' => 'branchpass1']],
            ['agent.branch.fund', ['id' => $b->id], ['amount' => '1', 'note' => 'اختبار المدخل الثاني']],
            ['agent.branch.cash', ['id' => $b->id], ['amount' => '1', 'direction' => 'in', 'note' => 'توريد اختباريّ']],
            ['agent.branch.count', ['id' => $b->id], ['counted' => '50000', 'note' => 'جرد اختباريّ كامل']],
        ] as [$name, $params, $body]) {
            $status = $this->actingAs($this->hq, 'agent_staff')
                ->postJson(route($name, $params), $body)->getStatusCode();

            $this->assertLessThan(500, $status,
                "«{$name}» تنهار لمن يدخل برمز موظّف (ردّت {$status}) — "
                . 'وهي العمليّات التي كانت ميّتةً كلّها تحت هذا الحارس.');
        }
    }

    /**
     * @test
     *
     * إنشاء الفرع نموذجٌ واحد لا ستّ نوافذ `prompt` متتالية.
     *
     * كان الحقل يُطلب في نافذةٍ منفصلة: اسم، رمز، هاتف، كلمة سرّ، مدينة،
     * عنوان. ومن يخطئ في الثالثة يبدأ من الأولى، ولا يرى ما أدخله، ولا
     * يعرف كم بقي. وعلى الهاتف كلّ نافذةٍ تُغطّي الشاشة.
     */
    public function creating_a_branch_uses_one_form_not_a_chain_of_prompts(): void
    {
        $blade = (string) preg_replace(
            ['/\{\{--.*?--\}\}/s', '~^\s*//.*$~m'], ' ',
            (string) file_get_contents(resource_path('views/agent-views/dashboard.blade.php')),
        );

        $this->assertStringContainsString('id="br-modal"', $blade,
            'لا نموذج لإنشاء الفرع');

        foreach (['br-name', 'br-code', 'br-phone', 'br-pass'] as $field) {
            $this->assertStringContainsString('id="' . $field . '"', $blade, "الحقل {$field} غائب");
        }

        // لا يبقى `prompt` في مسار إنشاء الفرع.
        $handler = substr($blade, strpos($blade, "\$el('ag-new-branch')"), 1800);
        $this->assertStringNotContainsString('prompt(', $handler,
            'ما زال إنشاء الفرع يسأل عبر prompt');
    }

    /**
     * @test
     *
     * جلسةُ بوّابة الوكيل لا تُعطّل لوحة الإدارة.
     *
     * **العطل الذي أدخل هذا الاختبار عطّل النظام كلّه، لا ميزةً فيه.**
     *
     * كانت البوّابة تفتح جلسة صاحب الشركة على حارس `user` — وهو **نفس حارس
     * لوحة الإدارة**. فمن يدخل البوّابة ثمّ يفتح لوحة الإدارة في المتصفّح
     * نفسه يقع في:
     *
     *     /admin            → «لستَ مديراً» → /admin/auth/login
     *     /admin/auth/login → «أنت داخلٌ»   → /admin
     *
     * `ERR_TOO_MANY_REDIRECTS` — ولوحة الإدارة كلّها معطَّلة، بلا سطرٍ واحدٍ
     * في السجلّ يشرح السبب. وأسوأ ما فيه أنّه **لا يظهر إلّا لمن يستعمل
     * البوّابتين معاً**، أي لصاحب المشروع وحده في هذه المرحلة.
     *
     * ويُفحص هنا الطرفان: أنّ البوّابة لا تلمس حارس الإدارة، وأنّ لوحة
     * الإدارة لا تدور مهما وُجدت جلسةٌ غريبة على حارسها.
     */
    public function using_the_agent_portal_never_breaks_the_admin_panel(): void
    {
        // (١) الدخول إلى البوّابة بهاتف الشركة
        $this->post(route('agent.login.submit'), [
            'username' => $this->company->phone, 'password' => 'secret123',
        ])->assertRedirect(route('agent.dashboard'));

        // البوّابة تفتح جلستها على حارسها هي، لا على حارس الإدارة.
        $this->assertTrue(Auth::guard('agent_staff')->check(),
            'البوّابة لم تفتح جلسةً على حارسها');
        $this->assertGuest('user',
            'البوّابة فتحت جلسةً على حارس لوحة الإدارة — وهذا مصدر الحلقة');

        // (٢) ثمّ فتح لوحة الإدارة بالجلسة نفسها: تحويلةٌ واحدة إلى الدخول،
        //     وصفحةُ الدخول تُفتح ولا تُعيد التحويل.
        $this->get('/admin')->assertRedirect(route('admin.auth.login'));
        $this->get(route('admin.auth.login'))->assertOk();
    }

    /**
     * @test
     *
     * ولا تدور اللوحة حتى لو تسرّبت جلسةٌ غريبة إلى حارسها.
     *
     * أُصلح مصدر التسرّب، لكنّ الحلقة عيبٌ في الوسيط يبقى قائماً لأيّ تسرّبٍ
     * آخر — عميلٌ أو تاجرٌ يدخل من الويب. فتُقطع من طرفها.
     */
    public function the_admin_panel_does_not_loop_on_a_non_admin_session(): void
    {
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'phone' => '967773800001',
        ]);

        $this->actingAs($customer, 'user')
            ->get('/admin')->assertRedirect(route('admin.auth.login'));

        // الجلسة أُنهيت، فصفحة الدخول تُفتح بدل أن تُعيد التحويل إلى /admin.
        $this->assertGuest('user');
        $this->get(route('admin.auth.login'))->assertOk();
    }

    /**
     * @test
     *
     * الموظّف يعرف من أين يدخل — العنوان مكتوبٌ في الشاشة.
     *
     * كنّا نُعطي الوكيل رمزاً (`MKL-001`) ولا نقول له من أين يُستعمل. فبقي
     * الرمز في يده بلا باب، وكان أوّل سؤالٍ يُطرح: «كيف يدخل الموظّف؟».
     */
    public function the_staff_screen_tells_the_agent_where_employees_log_in(): void
    {
        $html = $this->actingAs($this->hq, 'agent_staff')
            ->get(route('agent.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('agent.login'), $html,
            'شاشة الموظّفين لا تعرض عنوان الدخول');
        $this->assertStringContainsString('الموظّف يدخل من', $html);
    }
}
