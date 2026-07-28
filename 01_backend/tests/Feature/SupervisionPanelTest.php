<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditDecision;
use App\Models\User;
use App\Services\SupervisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-SUPERVISION-002 — لوحة الإشراف: أنماطٌ لا أحداث.
 *
 * سجلّ التدقيق يجيب عن «ماذا حدث لهذه المعاملة؟» — سؤال تحقيقٍ بعد شكوى.
 * والإشراف يجيب عن «هل يعمل الفريق كما ينبغي الآن؟» — قبل الشكوى لا بعدها.
 *
 * فالمفحوص هنا ليس أن الصفحة تُبنى، بل أن **الإشارات تظهر**: أن يُرى
 * الانتظار الطويل، وأن يُرى الموظّف الشاذّ عن زملائه، وأن يُكشف العبث
 * بالسجلّ الذي تُبنى عليه الأرقام كلّها.
 */
class SupervisionPanelTest extends TestCase
{
    use RefreshDatabase;

    private SupervisionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(SupervisionService::class);
    }

    private function operator(?string $roleCode = null): User
    {
        $user = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        if ($roleCode) {
            DB::table('admin_user_roles')->insert([
                'user_id' => $user->id,
                'role_id' => DB::table('roles')->whereNull('merchant_user_id')
                    ->where('code', $roleCode)->value('id'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user->fresh();
    }

    private function decide(User $who, string $severity = 'critical', ?string $at = null): AuditDecision
    {
        $d = AuditDecision::create([
            'decision_id' => (string) Str::ulid(),
            'actor_type' => 'admin',
            'actor_user_id' => $who->id,
            'subject_type' => 'safe_payment',
            'subject_id' => (string) random_int(1, 9999),
            'action' => 'resolved_release',
            'decision_code' => 'TEST',
            'reason' => 'قرار اختبار',
            'severity' => $severity,
            'zone_code' => 'SOUTH',
        ]);

        if ($at) {
            AuditDecision::withoutEvents(fn () => DB::table('audit_decisions')
                ->where('id', $d->id)->update(['created_at' => $at]));
        }

        return $d;
    }

    // ── ما ينتظر ───────────────────────────────────────────────────────

    /**
     * طلبٌ ينتظر ثلاثة أيام مشكلةُ فريق. والعدد وحده لا يقولها.
     */
    public function test_a_three_day_old_approval_is_flagged_as_breaching(): void
    {
        $maker = $this->operator();
        $subject = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        $req = ApprovalRequest::create([
            'request_number' => 'REQ-1',
            'action_type' => 'unfreeze',
            'subject_user_id' => $subject->id,
            'maker_admin_id' => $maker->id,
            'reason' => 'سبب كافٍ الطول',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);
        DB::table('approval_requests')->where('id', $req->id)
            ->update(['created_at' => now()->subDays(3)]);

        $approvals = collect($this->svc->waiting())->firstWhere('kind', 'approvals');

        $this->assertSame(1, $approvals['count']);
        $this->assertGreaterThanOrEqual(72, $approvals['oldest_hours']);
        $this->assertSame(1, $approvals['breaching'],
            'انتظارٌ ثلاثة أيام لم يُعدّ تجاوزاً — والعدد وحده يُطمئن');
    }

    /** وطلبٌ عمره ساعة يومُ عملٍ عاديّ لا إنذار. */
    public function test_a_fresh_approval_is_not_breaching(): void
    {
        $maker = $this->operator();
        ApprovalRequest::create([
            'request_number' => 'REQ-2',
            'action_type' => 'unfreeze',
            'subject_user_id' => User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH'])->id,
            'maker_admin_id' => $maker->id,
            'reason' => 'سبب كافٍ الطول',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);

        $approvals = collect($this->svc->waiting())->firstWhere('kind', 'approvals');

        $this->assertSame(1, $approvals['count']);
        $this->assertSame(0, $approvals['breaching'],
            'إنذارٌ كاذب — يُفقد الشاشة قيمتها بعد أسبوع');
    }

    // ── توزيع العمل ────────────────────────────────────────────────────

    /**
     * موظّفٌ يقرّر ضعف زملائه: إمّا عبءٌ غير عادل وإمّا قلّة تأنٍّ.
     *
     * والرقمان يبدوان سواءً حتى تُقارن — وهذا ما تفعله الشاشة.
     */
    public function test_an_operator_deciding_twice_the_median_is_marked(): void
    {
        $busy = $this->operator('platform_supervisor');
        $calm = $this->operator('platform_supervisor');

        for ($i = 0; $i < 20; $i++) $this->decide($busy);
        for ($i = 0; $i < 4; $i++) $this->decide($calm);

        $rows = collect($this->svc->operatorActivity(now()->subDay(), now()));

        $this->assertTrue($rows->firstWhere('user_id', $busy->id)['outlier'],
            'لم يُميَّز من يقرّر خمسة أضعاف زميله');
        $this->assertFalse($rows->firstWhere('user_id', $calm->id)['outlier']);
    }

    /** وفريقٌ متقارب لا يُوصَم أحدٌ فيه — الوصم بلا سبب يُفقد الإشارة معناها. */
    public function test_an_evenly_working_team_flags_nobody(): void
    {
        foreach (range(1, 3) as $_) {
            $op = $this->operator();
            for ($i = 0; $i < 5; $i++) $this->decide($op);
        }

        $rows = collect($this->svc->operatorActivity(now()->subDay(), now()));

        $this->assertCount(3, $rows);
        $this->assertEmpty($rows->where('outlier', true)->all());
    }

    /** والأدوار تظهر بجانب الاسم: «من فعل» بلا «بأيّ صفة» نصفُ جواب. */
    public function test_each_operator_row_carries_their_roles(): void
    {
        $sup = $this->operator('platform_supervisor');
        $this->decide($sup);

        $row = collect($this->svc->operatorActivity(now()->subDay(), now()))
            ->firstWhere('user_id', $sup->id);

        $this->assertContains('فريق الإشراف', $row['roles']);
    }

    // ── سلامة السجلّ ───────────────────────────────────────────────────

    /**
     * كل رقم في الصفحة مأخوذ من السجلّ. فإن عُبث به صارت الشاشة تطمئن
     * المشرف إلى ما يجب أن يُقلقه — ولهذا يُفحص أوّلاً.
     */
    public function test_tampering_with_the_log_is_detected(): void
    {
        $op = $this->operator();
        for ($i = 0; $i < 5; $i++) $this->decide($op);

        $this->assertTrue($this->svc->chainStatus()['intact']);

        // عبثٌ مباشر بقاعدة البيانات — يتجاوز حماية النموذج
        $middle = DB::table('audit_decisions')->orderBy('id')->skip(2)->first();
        DB::table('audit_decisions')->where('id', $middle->id)
            ->update(['prev_hash' => str_repeat('0', 64)]);

        $status = $this->svc->chainStatus();

        $this->assertFalse($status['intact'], 'عُبث بالسجلّ ولم يُكشف');
        $this->assertNotNull($status['broken_at']);
    }

    /** ونظامٌ بلا سجلّات يُعدّ سليماً لا معطوباً — أوّل يوم ليس عطلاً. */
    public function test_an_empty_log_is_intact(): void
    {
        $status = $this->svc->chainStatus();

        $this->assertTrue($status['intact']);
        $this->assertSame(0, $status['checked']);
    }

    // ── الاطّلاع ───────────────────────────────────────────────────────

    /** فتحُ ملفّ واحد عشر مرّات في أسبوع ليس عملَ دعم. */
    public function test_repeated_access_to_one_customer_is_flagged(): void
    {
        $support = $this->operator('platform_support');
        $customer = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);

        for ($i = 0; $i < 11; $i++) {
            DB::table('pii_access_logs')->insert([
                'actor_user_id' => $support->id,
                'subject_type' => 'user', 'subject_id' => $customer->id,
                'field_name' => 'support_customer_file', 'access_type' => 'view',
                'created_at' => now(),
            ]);
        }

        $row = collect($this->svc->piiAccess())->firstWhere('subject_id', $customer->id);

        $this->assertSame(11, $row['views']);
        $this->assertTrue($row['suspicious'], 'تكرارٌ غير معتاد لم يُميَّز');
    }

    // ── الصلاحيات ──────────────────────────────────────────────────────

    public function test_a_supervisor_can_open_the_panel(): void
    {
        $this->actingAs($this->operator('platform_supervisor'), 'user')
            ->get('/admin/amial/supervision')
            ->assertOk()
            ->assertSee('لوحة الإشراف');
    }

    /** الدعم والصيانة لا يفتحانها: الرقابة على العمل ليست جزءاً من العمل. */
    public function test_support_and_maintenance_cannot_open_it(): void
    {
        foreach (['platform_support', 'platform_maintenance'] as $role) {
            $this->actingAs($this->operator($role), 'user')
                ->get('/admin/amial/supervision')
                ->assertStatus(403);
        }
    }

    /** والصفحة تُبنى على نظام فارغ — أوّل يوم لا يجب أن ينهار. */
    public function test_the_panel_builds_on_an_empty_system(): void
    {
        $this->actingAs($this->operator('platform_admin'), 'user')
            ->get('/admin/amial/supervision')
            ->assertOk()
            ->assertSee('سلسلة السجلّ سليمة');
    }
}
