<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-RECON-NIGHTLY-001 — المصالحة تُقرأ من اللوحة، لا من القاعدة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **القاعدة الثانية عشرة هي سببُ هذا الملفّ.**
 *
 * بُنيت المصالحة الليليّة وكُتب جدولُها، **ولم يكن يُقرأ من أيّ مكان**.
 * ومصالحةٌ ماليّةٌ لا يراها أحدٌ هي `مبنيٌّ ولا يُوصَل إليه` في أخطر
 * موضعٍ ممكن: تعمل كلّ ليلةٍ وتكتب، ولا أحدَ يعلم.
 *
 * ولا يُكتفى بوجود المسار: **المسارُ المسجَّل ليس ظهوراً.** فيُفحص أنّ
 * التبويب في الصفحة، وأنّ نقطة النهاية تردّ، وأنّ ما تردّه يظهر.
 */
class ReconciliationScreenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'مدير', 'l_name' => 'الدفتر', 'phone' => '967770005001',
            'email' => 'ledger@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u->fresh();
    }

    private function seedRun(string $status, string $gap = '0', ?string $ranAt = null): void
    {
        DB::table('reconciliation_runs')->insert([
            'ran_at' => $ranAt ?? now(), 'status' => $status,
            'wallets_checked' => 12, 'wallets_diverged' => $status === 'clean' ? 0 : 1,
            'wallets_gap' => $gap, 'unbalanced_entries' => 0, 'ledger_net' => 0,
            'tills_checked' => 0, 'tills_diverged' => 0, 'tills_gap' => 0,
            'blind_spots' => json_encode([['service' => 'InstallmentService', 'why' => 'لا تُرحَّل']], JSON_UNESCAPED_UNICODE),
            'duration_ms' => 42, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @test
     *
     * **التبويبُ موجودٌ في الصفحة — لا المسارُ وحده.**
     */
    public function the_ledger_page_carries_a_reconciliation_tab(): void
    {
        $html = $this->actingAs($this->admin(), 'user')
            ->get('/admin/amial/ledger')->assertOk()->getContent();

        $this->assertStringContainsString('lg-tab-runs', $html,
            'لا تبويبَ للمصالحات — المصالحة تعمل ولا يراها أحد');

        $this->assertStringContainsString('lg-runs-list', $html,
            'التبويبُ موجودٌ وجسدُه مفقود');
    }

    /**
     * @test
     *
     * **ونقطةُ النهاية تردّ بالصفوف المحفوظة.**
     */
    public function the_endpoint_returns_the_recorded_runs(): void
    {
        $this->seedRun('diverged', '1500');

        $j = $this->actingAs($this->admin(), 'user')
            ->getJson('/admin/amial/ledger/reconciliation-runs')
            ->assertOk()->json();

        $this->assertCount(1, $j['meta']['rows'] ?? []);
        $this->assertSame('diverged', $j['meta']['rows'][0]['status']);
        $this->assertNotEmpty($j['meta']['blind_spots'],
            'الردُّ بلا إعلانِ عمى — يُقرأ إحاطةً كاملة وليس كذلك');
    }

    /**
     * @test
     *
     * **وليلةٌ لم تجرِ تُقال — لا تُقرأ سلامة.**
     *
     * فصمتُ الإنذار قد يعني أنّ المهمّة توقّفت. (القاعدة السابعة.)
     */
    public function a_stale_run_is_declared_stale(): void
    {
        $this->seedRun('clean', '0', now()->subDays(3)->toDateTimeString());

        $j = $this->actingAs($this->admin(), 'user')
            ->getJson('/admin/amial/ledger/reconciliation-runs')->assertOk()->json();

        $this->assertTrue($j['meta']['stale'],
            'آخرُ مصالحةٍ عمرُها ثلاثةُ أيّامٍ ولم تُعلَن قديمة');
    }

    /**
     * @test
     *
     * **ولا مصالحاتٍ إطلاقاً = قديمٌ أيضاً، لا «سليم».**
     *
     * وهذا هو الفرق الذي بُني الجدولُ لأجله: الغيابُ ليس صفراً.
     */
    public function no_runs_at_all_is_also_stale(): void
    {
        $j = $this->actingAs($this->admin(), 'user')
            ->getJson('/admin/amial/ledger/reconciliation-runs')->assertOk()->json();

        $this->assertTrue($j['meta']['stale'],
            'لا مصالحةَ إطلاقاً وقيل إنّ الحال حديث');
        $this->assertNull($j['meta']['last_run_at']);
    }

    /**
     * @test
     *
     * **ومصالحةٌ حديثةٌ ليست قديمة.**
     *
     * وبلا هذا الطرف يمرّ الحارسُ ولو كان `stale` يُرجع `true` دائماً —
     * وذاك إنذارٌ دائمٌ يُصمَّت بعد أسبوع.
     */
    public function a_fresh_run_is_not_stale(): void
    {
        $this->seedRun('clean');

        $j = $this->actingAs($this->admin(), 'user')
            ->getJson('/admin/amial/ledger/reconciliation-runs')->assertOk()->json();

        $this->assertFalse($j['meta']['stale']);
    }

    /**
     * @test
     *
     * **والصفحةُ محروسةٌ بالصلاحيّة.**
     *
     * فحركةُ المال كلُّها تُقرأ منها — وموظّفُ الدعم لا يملك
     * `platform.audit.view`.
     */
    public function support_staff_cannot_read_the_reconciliation_history(): void
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'دعم', 'l_name' => 'فنّيّ', 'phone' => '967770005002',
            'email' => 'sup@amialpay.test', 'type' => ADMIN_TYPE,
            'password' => Hash::make('admin12345'), 'is_active' => 1,
        ])->save();

        app(PlatformRoleService::class)->assign($u, PlatformRoleService::SUPPORT);

        $this->actingAs($u->fresh(), 'user')
            ->getJson('/admin/amial/ledger/reconciliation-runs')
            ->assertForbidden();
    }
}
