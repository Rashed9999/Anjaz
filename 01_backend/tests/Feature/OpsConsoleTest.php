<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OpsHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AMIAL-OPS-CONSOLE-002 — شاشة التشغيل: أن تُظهر الصمت.
 *
 * **العطل الذي وُجدت لأجله:** تنزيل الإيصالات فشل أسابيع لأن عامل الطابور
 * لا يستمع إلى `receipts`. فبقيت المهامّ تنتظر **بلا خطأ ولا سجلّ ولا مهمّة
 * فاشلة** — طابور ينمو وحده في صمت. ولم يُكتشف إلا من تسجيل شاشة.
 *
 * فأخطر الأعطال ليست التي تصرخ بل التي لا أثر لها، وهذه الشاشة تبحث عن
 * الصمت نفسه. ولهذا يفحص هذا الملفّ **الكشف** قبل العرض: أن يُرى الطابور
 * المتعثّر، لا أن تُبنى الصفحة بلا انهيار.
 */
class OpsConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $roleCode): User
    {
        $user = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', $roleCode)->value('id');
        DB::table('admin_user_roles')->insert([
            'user_id' => $user->id, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    /** مهمّة منتظرة في طابور منذ $ageSeconds. */
    private function pendingJob(string $queue, int $ageSeconds): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\GeneratePdfReceiptJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - $ageSeconds,
            'created_at' => time() - $ageSeconds,
        ]);
    }

    private function failedJob(string $queue, string $exception, string $uuid = null): string
    {
        $uuid ??= (string) \Illuminate\Support\Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\GeneratePdfReceiptJob']),
            'exception' => $exception,
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    // ── الكشف ──────────────────────────────────────────────────────────

    /**
     * الحالة التي أخفقنا في رؤيتها أسابيع: مهمّة واحدة عمرها ساعة.
     *
     * العدد وحده لا يكشفها — «1» يبدو سليماً. العمر هو الذي يفضح.
     */
    public function test_a_single_hour_old_job_is_reported_as_stalled(): void
    {
        $this->pendingJob('receipts', 3600);

        $queues = collect(app(OpsHealthService::class)->queues());
        $receipts = $queues->firstWhere('queue', 'receipts');

        $this->assertTrue($receipts['stalled'],
            'مهمّة عمرها ساعة لم تُعدّ تعثّراً — وهذا بالضبط ما أخفى العطل أسابيع');
        $this->assertGreaterThanOrEqual(3600, $receipts['oldest_seconds']);
    }

    /** وطابورٌ مزدحم بمهامّ حديثة نظامٌ مشغول لا معطَّل. */
    public function test_many_fresh_jobs_are_not_a_stall(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->pendingJob('notifications', 5);
        }

        $q = collect(app(OpsHealthService::class)->queues())->firstWhere('queue', 'notifications');

        $this->assertSame(50, $q['pending']);
        $this->assertFalse($q['stalled'], 'ازدحامٌ لحظيّ عُدّ عطلاً — إنذارٌ كاذب يُفقد الشاشة قيمتها');
    }

    /**
     * طابور معروف بلا صفوف يُعرض صفراً لا يغيب.
     *
     * غيابُه يُقرأ «سليم» وهو في الحقيقة «لا نعلم» — وهو الفرق نفسه الذي
     * جعل الطابور المعطّل غير مرئي.
     */
    public function test_known_queues_appear_even_when_empty(): void
    {
        $names = collect(app(OpsHealthService::class)->queues())->pluck('queue');

        foreach (['receipts', 'notifications', 'default'] as $q) {
            $this->assertContains($q, $names->all(), "الطابور $q غائب عن الشاشة");
        }
    }

    /** الفاشلة تُجمَّع بسببها: عشرون فشلاً بسبب واحد سطرٌ واحد لا عشرون. */
    public function test_failures_are_grouped_by_their_cause(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->failedJob('receipts', "RuntimeException: mpdf temp dir not writable\n#0 frame");
        }
        $this->failedJob('receipts', "PDOException: deadlock\n#0 frame");

        $summary = app(OpsHealthService::class)->failedSummary();

        $this->assertSame(13, $summary['total']);
        $this->assertCount(2, $summary['groups'], 'لم تُجمَّع — الشاشة تصير قائمةً لا تشخيصاً');

        $biggest = collect($summary['groups'])->sortByDesc('count')->first();
        $this->assertSame(12, $biggest['count']);
        $this->assertStringContainsString('mpdf', $biggest['error']);
    }

    /** الكتابة تُجرَّب فعلاً — وجود المجلّد لا يعني إمكان الكتابة فيه. */
    public function test_storage_is_probed_by_actually_writing(): void
    {
        Storage::fake('local');

        $dirs = collect(app(OpsHealthService::class)->storage());

        $this->assertCount(5, $dirs);
        $this->assertTrue($dirs->firstWhere('dir', 'documents')['writable']);

        // ولا يُترك أثر: ملفّات فحص متراكمة تُفسد عدّاد المستندات.
        $this->assertSame([], Storage::disk('local')->files('documents'));
    }

    // ── الصلاحيات ──────────────────────────────────────────────────────

    public function test_maintenance_can_open_the_console(): void
    {
        $this->actingAs($this->operator('platform_maintenance'), 'user')
            ->get('/admin/amial/ops')
            ->assertOk()
            ->assertSee('حالة التشغيل');
    }

    /** الدعم لا يفتحها: ليست من عمله، وفتحُها يُطلعه على ما لا يخصّه. */
    public function test_support_cannot_open_the_console(): void
    {
        $this->actingAs($this->operator('platform_support'), 'user')
            ->get('/admin/amial/ops')
            ->assertStatus(403);
    }

    /**
     * المراقبة غير التدخّل: من يرى الشاشة لا يُعيد المهامّ بالضرورة.
     *
     * الإشراف يملك ops.view ولا يملك ops.retry — فيراقب ولا يغيّر.
     */
    public function test_a_supervisor_may_watch_but_not_retry(): void
    {
        $supervisor = $this->operator('platform_supervisor');
        $uuid = $this->failedJob('receipts', "RuntimeException: x\n#0");

        $this->actingAs($supervisor, 'user')->get('/admin/amial/ops')->assertOk();

        $this->actingAs($supervisor, 'user')
            ->post('/admin/amial/ops/retry', ['uuid' => $uuid, 'scope' => 'one'])
            ->assertStatus(403);
    }

    // ── الفعل يُسجَّل ──────────────────────────────────────────────────

    /**
     * إعادة تشغيل مهمّة فعلٌ يُغيّر حال النظام، ومن حقّ من يأتي بعده أن
     * يعرف من أعادها ومتى.
     */
    public function test_a_retry_is_written_to_the_audit_trail(): void
    {
        $ops = $this->operator('platform_maintenance');
        $uuid = $this->failedJob('receipts', "RuntimeException: mpdf\n#0");

        $this->actingAs($ops, 'user')
            ->post('/admin/amial/ops/retry', ['uuid' => $uuid, 'scope' => 'one'])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_decisions', [
            'actor_user_id' => $ops->id,
            'subject_type' => 'failed_job',
            'action' => 'ops_retry',
        ]);
    }

    /** معرّف غير موجود لا يُسقط الصفحة — يُردّ برسالة. */
    public function test_an_unknown_uuid_is_handled_gracefully(): void
    {
        $this->actingAs($this->operator('platform_maintenance'), 'user')
            ->post('/admin/amial/ops/retry', ['uuid' => 'no-such-uuid', 'scope' => 'one'])
            ->assertRedirect();

        $this->assertDatabaseMissing('audit_decisions', ['action' => 'ops_retry']);
    }

    /** الصفحة تُبنى على نظام فارغ تماماً — أوّل يوم لا يجب أن ينهار. */
    public function test_the_page_builds_on_a_completely_empty_system(): void
    {
        $this->actingAs($this->operator('platform_admin'), 'user')
            ->get('/admin/amial/ops')
            ->assertOk()
            ->assertSee('كل شيء يعمل');
    }
}
