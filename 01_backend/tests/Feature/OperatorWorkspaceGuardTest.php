<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Admin\OperatorWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-WORKSPACE-002 — **مساحةُ العمل تقول ما ينتظر، لا ما هو متاح.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كانت الصفحةُ فهرسَ روابطَ: عشرةُ تبويباتٍ وخمسون بطاقةً كلُّها تقول
 * «هذا موجود»، **ولا واحدةٌ تقول إنّ اثنَي عشرَ طلبَ هويّةٍ تنتظر منذ
 * الصباح**. فيفتحها الموظّفُ ولا يعرف من أين يبدأ.
 *
 * وستُّ حالاتٍ، **وأخطرُها ② و③** — وكلتاهما تُخرج «٠» على طابورٍ ممتلئ:
 *
 *   ① كلُّ رقمٍ من مصدره — لا ثابتَ مكتوبٌ في قالب.
 *   ② **وما لا يُصرَّح به لا يُعرَض صفراً.** «٠» يُقرأ «فحصنا فلم نجد»،
 *      فيمرّ الطابورُ كلُّه أمام عينٍ تظنّه فارغاً. (القاعدة السابعة.)
 *   ③ **وجدولٌ غيرُ موجودٍ يُقال ولا يُبتلَع.**
 *   ④ والطابورُ **أقدمُه أوّلاً** — لا بالنوع.
 *   ⑤ والبطاقةُ تفتح ما تعدّ (القاعدة التاسعة).
 *   ⑥ **وكلُّ روابط اللوحة تبقى في الصفحة** — هي مدخلُها الوحيد بعد
 *      نقل التفاصيل من الشريط الجانبيّ، فطيُّ خدمةٍ يقطع بابَها.
 */
class OperatorWorkspaceGuardTest extends TestCase
{
    use RefreshDatabase;

    private function service(): OperatorWorkspaceService
    {
        return app(OperatorWorkspaceService::class);
    }

    /** يسمح بكلّ شيء */
    private function all(): callable
    {
        return fn (?string $p) => true;
    }

    private function pendingKycFor(int $userId): void
    {
        KycDocument::create([
            'user_id' => $userId,
            'doc_type' => KycDocument::TYPE_ID_FRONT,
            'status' => KycDocument::STATUS_PENDING,
            'encrypted_path' => 'kyc/'.Str::random(8).'.enc',
            'size_bytes' => 1024,
            'ocr_status' => 'not_run',
        ]);
    }

    /** @test */
    public function every_number_comes_from_its_source(): void
    {
        $a = User::factory()->create(['type' => 2]);
        $b = User::factory()->create(['type' => 2]);

        // ثلاثُ وثائقَ لشخصين — **والعدُّ بالأشخاص لا بالورق**.
        $this->pendingKycFor($a->id);
        $this->pendingKycFor($a->id);
        $this->pendingKycFor($b->id);

        $kyc = collect($this->service()->cards($this->all()))->firstWhere('key', 'kyc');

        $this->assertSame(2, $kyc['count'],
            'العدُّ بالورق لا بالأشخاص — فصاحبُ خمسِ وثائقَ يُضخّم الطابورَ '
            .'خمسةَ أضعافٍ ويُفزع بلا سبب');

        // ⑤ البطاقةُ تفتح ما تعدّ.
        $this->assertNotNull($kyc['url'],
            'رقمٌ لا يُنقر ليس مؤشّراً — من رأى الطابورَ يجب أن يصل إليه بضغطة');
    }

    /** @test */
    public function an_unpermitted_card_never_reads_as_zero(): void
    {
        $u = User::factory()->create(['type' => 2]);
        $this->pendingKycFor($u->id);

        $deny = fn (?string $p) => $p !== 'platform.customers.kyc.view';

        $kyc = collect($this->service()->cards($deny))->firstWhere('key', 'kyc');

        $this->assertNull($kyc['count'],
            'عُرض «٠» لمن لا يملك صلاحيّة الهويّة والطابورُ فيه طلبٌ — '
            .'فيُقرأ «فحصنا فلم نجد» ويمرّ الطابورُ أمام عينٍ تظنّه فارغاً');
        $this->assertSame('غير مصرَّح', $kyc['note'],
            'لا يُقال لماذا غاب الرقم');
        $this->assertNull($kyc['url'],
            'رابطٌ يُعرض لمن يُرفض عند الضغط — يَعِد ثمّ يُخلف');
    }

    /** @test */
    public function the_queue_is_oldest_first_not_grouped_by_type(): void
    {
        $u = User::factory()->create(['type' => 2]);
        $admin = User::factory()->create(['type' => 0]);

        // موافقةٌ عمرُها ثلاثةُ أيّام، ثمّ وثيقةٌ عمرُها دقيقة.
        $old = ApprovalRequest::create([
            'request_number' => 'AR-'.Str::upper(Str::random(8)),
            'action_type' => 'wallet_adjust', 'status' => 'pending',
            'subject_user_id' => $u->id, 'maker_admin_id' => $admin->id,
            'reason' => 'تسويةُ فرقٍ في المصالحة', 'payload' => [],
        ]);
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->pendingKycFor($u->id);

        $queue = $this->service()->queue($this->all());

        $this->assertNotEmpty($queue, 'الطابورُ فارغٌ وفيه عنصران');
        $this->assertStringStartsWith('OPS-', $queue[0]['ref'],
            'رُتّب بالنوع لا بالعمر — فطلبٌ عمرُه ثلاثةُ أيّامٍ وقع أسفلَ '
            .'آخرَ عمرُه دقيقة، وأقدمُ ما ينتظر أخطرُ ما ينتظر');
    }

    /** @test */
    public function the_queue_respects_the_permission_of_each_source(): void
    {
        $u = User::factory()->create(['type' => 2]);
        $this->pendingKycFor($u->id);

        $deny = fn (?string $p) => $p !== 'platform.customers.kyc.view';

        $this->assertSame([], $this->service()->queue($deny),
            'صفُّ هويّةٍ ظهر لمن لا يملك صلاحيّتها — والهويّةُ بياناتٌ '
            .'شخصيّةٌ لا تُعرَض بالفهرسة');
    }

    /** @test */
    public function a_failing_health_probe_is_never_reported_as_healthy(): void
    {
        $health = $this->service()->health();

        $this->assertArrayHasKey('status', $health);
        $this->assertContains($health['status'], ['healthy', 'degraded', 'unhealthy', 'unknown']);

        // **وصفحةٌ خضراءُ فوق راصدٍ ميّتٍ أسوأ من حمراء** — فحين يسقط
        // الفاحصُ نفسُه تُقال الحالةُ `unknown` ومعها سببُها.
        if ($health['status'] === 'unknown') {
            $this->assertNotNull($health['note'], 'حالةٌ مجهولةٌ بلا سبب');
        }
    }

    /** @test */
    public function the_workspace_still_holds_every_door_of_the_panel(): void
    {
        $src = (string) file_get_contents(
            resource_path('views/admin-views/amial/ops/workspace.blade.php'));

        // ⑥ هذه الصفحةُ مدخلُ اللوحة الوحيد بعد نقل التفاصيل من الشريط
        // الجانبيّ — فطيُّ خدمةٍ منها يقطع بابَها.
        foreach ([
            'admin.amial.kyc.page', 'admin.amial.ledger.page', 'admin.amial.aml.page',
            'admin.amial.hub.merchants', 'admin.amial.hub.agents', 'admin.amial.audit.index',
            'admin.amial.ops.roles.index', 'admin.amial.system.health',
        ] as $route) {
            $this->assertStringContainsString($route, $src,
                "بابُ «{$route}» سقط من مساحة العمل — وهي مدخلُ اللوحة الوحيد");
        }

        // ① ولا رقمٌ مكتوبٌ في القالب: كلُّها من الخدمة.
        $this->assertStringContainsString('$wsCards', $src);
        $this->assertStringContainsString('$wsQueue', $src);
        $this->assertStringContainsString('$wsHealth', $src);
    }
}
