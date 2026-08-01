<?php

namespace Tests\Feature;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'shifts_with_variance', 'variance_total', 'branches_idle',
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
}
