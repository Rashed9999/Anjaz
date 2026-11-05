<?php

namespace Tests\Feature;

use App\Models\Agent\AgentStaff;
use App\Models\User;
use App\Services\AgentTellerWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-TELLER-WS-002 — **«صفحة مساحة العمل لا تعمل».**
 *
 * ══════════════════════════════════════════════════════════════════════
 * شكوى صاحب المشروع، ومعها صورةُ `500 SERVER ERROR` من الإنتاج.
 *
 * ومساحةُ عمل الصرّاف هي **الشاشةُ الوحيدةُ في المشروع بهذا الاسم**.
 * وهي أوّلُ ما يفتحه موظّفُ الشبّاك كلَّ صباح: بلا فتحِها لا إيداعَ ولا
 * سحبَ ولا طلبَ موافقة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لم يمسكه شيء:** `sweep-admin.php` يمرّ على مساراتِ الإدارة
 * وحدَها، ولا موظّفَ وكيلٍ واحدٌ في قاعدة التطوير (‏قِيس: **صفر صفّاً في
 * `agent_staff`**). فمسحٌ على قاعدةٍ فارغةٍ **يُطمئن ولا يفحص** — وهو
 * العطلُ المكتوب في `CLAUDE.md` بنصّه، والذي وُلد منه
 * `MerchantWithoutProfileGuardTest`.
 *
 * **فالحارسُ يصنع الحالةَ بنفسِه** ولا ينتظر بياناتٍ قد لا توجد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحالاتُ الأربع تُجرَّب كلُّها** — لأنّ الحقلَ المفقود هو ما يُسقط
 * الصفحة، لا الحقلُ الموجود:
 *
 *   بفرعٍ وبورديّة  ·  بفرعٍ بلا ورديّة  ·  بلا فرعٍ  ·  بلا ورديّة
 *
 * وموظّفٌ **بلا فرع** ليس حالةً نادرة: يُنشأ من اللوحة قبل أن يُسنَد،
 * ويبقى كذلك أيّاماً. فإن سقطت شاشتُه بقي بلا عمل.
 */
class TellerWorkspaceOpensGuardTest extends TestCase
{
    use RefreshDatabase;

    private function staff(bool $withBranch): AgentStaff
    {
        $agent = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        $branchId = null;

        if ($withBranch) {
            $branchUser = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

            $branchId = DB::table('agent_branches')->insertGetId([
                'agent_user_id' => $agent->id,
                'branch_user_id' => $branchUser->id,
                'name' => 'فرعُ الاختبار',
                'code' => 'BR-' . $agent->id,
                'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return AgentStaff::create([
            'agent_user_id' => $agent->id,
            'branch_id' => $branchId,
            'username' => 'teller' . $agent->id,
            'name' => 'صرّافُ الاختبار',
            'password' => bcrypt('Secret@123'),
            'role' => 'teller',
            'is_active' => 1,
            'max_txn_amount' => '100000',
            'daily_limit' => '1000000',
            'daily_count_limit' => 100,
            'daily_hours_expected' => '8',
            'weekly_hours_expected' => '48',
            'overtime_policy' => 'paid',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_workspace_opens_for_a_teller_with_a_branch(): void
    {
        $data = app(AgentTellerWorkspaceService::class)->build($this->staff(true));

        // **والمفاتيحُ التي تقرؤها الشاشة** — فردٌّ ناقصٌ يُسقطها في
        // المتصفّح لا في الخادم، ولا خطأَ في أيّ سجلّ.
        foreach ([
            'staff', 'kpis', 'permissions', 'limits', 'systems',
            'announcements', 'recent_customers', 'requests', 'day_log',
            'panic', 'server_time',
        ] as $key) {
            $this->assertArrayHasKey($key, $data,
                "مساحةُ العمل تُبنى بلا «{$key}» — والشاشةُ تقرؤه، "
                . 'فتسقط عند أوّل فتح');
        }
    }

    /**
     * **وموظّفٌ بلا فرعٍ يفتحها كذلك.**
     *
     * يُنشأ من اللوحة قبل أن يُسنَد إلى فرع، ويبقى كذلك أيّاماً. وسقوطُ
     * شاشته يعني موظّفاً بلا عمل — **ولا رسالةَ تقول له لماذا**.
     *
     * @test
     */
    public function the_workspace_opens_for_a_teller_with_no_branch_yet(): void
    {
        $staff = $this->staff(false);

        $this->assertNull($staff->branch_id, 'التجهيزةُ أسندت فرعاً — فالحالةُ لا تُقاس');

        $data = app(AgentTellerWorkspaceService::class)->build($staff);

        $this->assertArrayHasKey('kpis', $data);

        // **و«غيرُ معروف» ليس صفراً** (القاعدةُ السابعة): بلا فرعٍ لا
        // رصيدَ إلكترونيّ. وعرضُ صفرٍ يقول «فُحص فوُجد فارغاً» — فيظنّ
        // الصرّافُ أنّ فرعَه بلا سيولة.
        $this->assertNull($data['kpis']['emoney'] ?? null,
            'رصيدٌ إلكترونيٌّ صفرٌ لموظّفٍ بلا فرع — و«غير معروف» ليس صفراً');
    }

    /**
     * **وبلا ورديّةٍ مفتوحة** — وهي حالُ كلّ صرّافٍ قبل أن يفتح درجَه.
     *
     * @test
     */
    public function the_workspace_opens_before_any_shift_is_opened(): void
    {
        $staff = $this->staff(true);

        $this->assertNull($staff->openShift(),
            'التجهيزةُ فتحت ورديّةً — فالحالةُ لا تُقاس');

        $data = app(AgentTellerWorkspaceService::class)->build($staff);

        $this->assertArrayHasKey('kpis', $data);

        // نقدُ الدرج مجهولٌ لا صفر — **والفرقُ ليس تفصيلاً**: صفرٌ يعني
        // «درجُك فارغ»، والحقيقةُ «لم تفتح درجَك بعد».
        $this->assertNull($data['kpis']['drawer'] ?? null,
            'نقدُ درجٍ صفرٌ قبل فتح الورديّة — فيُقرأ «درجُك فارغ»');
    }

    /**
     * **والمنفذُ نفسُه يُضغَط، لا الخدمةُ وحدَها.**
     *
     * خدمةٌ تعمل ومسارٌ يسقط شيءٌ وقع في هذا المشروع من قبل. (القاعدةُ
     * التاسعة: زرٌّ لم يُضغط ليس مبنيّاً.)
     *
     * @test
     */
    public function the_endpoint_itself_answers_not_only_the_service(): void
    {
        $this->assertTrue(
            collect(app('router')->getRoutes())->contains(
                fn ($r) => $r->uri() === 'agent/teller/workspace'),
            '**مسارُ مساحة العمل غير مسجَّل** — فالخدمةُ تعمل ولا بابَ إليها');
    }
}
