<?php

namespace Tests\Feature;

use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-MUWATHAQ-ORIGIN-ADMIN-001 — نافذة «تعديل الحساب» لحسابٍ فتحه موثّق.
 *
 * **ما وصل من التجربة الحيّة:** حسابٌ معتمَدٌ من موثّق (المستوى ٢) عرضت له اللوحةُ «وثائق الهويّة ناقصةٌ أو غير
 * معتمَدة» مع رفعٍ واعتمادٍ للحساب — كأنّ أميال باي هو من يوثّق. وثائقُه محفوظةٌ **لدى موثّق** (فصلُ الملكية)، فالقولُ
 * «ناقصة» كذبٌ يدفع المشغّل إلى توثيقه مرّةً ثانيةً من جهةٍ لا تملك الدليل.
 */
class AdminMuwathaqOriginReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770009101']);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    private function approvedByMuwathaq(User $u, int $tier = 2): MuwathaqAccountOpeningApplication
    {
        return MuwathaqAccountOpeningApplication::query()->create([
            'application_ulid' => strtoupper(substr(str_replace('-', '', (string) Str::uuid()) . 'ABCDEFGHJK', 0, 26)),
            'user_id' => $u->id, 'origin' => 'muwathaq', 'subject_kind' => 'customer',
            'verification_path' => 'women_progressive', 'requested_tier' => $tier,
            'status' => MuwathaqAccountOpeningApplication::STATUS_APPROVED,
            'submitted_at' => now(), 'decided_at' => now(), 'muwathaq_decided_at' => now(),
            'decision_reference' => 'MWT-TESTREF0000000000001', 'decision_context' => [], 'evidence_completed_at' => now(),
        ]);
    }

    private function readiness(User $admin, User $customer): array
    {
        return $this->actingAs($admin, 'user')
            ->getJson("/admin/amial/hub/users/{$customer->id}/readiness.json")
            ->assertSuccessful()->json('data');
    }

    /** @test */
    public function an_account_opened_by_muwathaq_is_not_reported_as_missing_documents(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'role' => 'customer', 'phone' => '967781545999', 'kyc_tier' => 2, 'is_kyc_verified' => 1]);
        $this->approvedByMuwathaq($customer);

        $d = $this->readiness($admin, $customer);

        $this->assertNotContains('DOCUMENTS_INCOMPLETE', array_column($d['blockers'], 'code'),
            'قيل «وثائق ناقصة» عن حسابٍ وثائقُه لدى موثّق');
        $this->assertSame([], $d['documents'], 'عُرضت صفوفُ وثائقَ للرفع لحسابٍ لا تُرفع وثائقُه هنا');
        $this->assertSame('MWT-TESTREF0000000000001', $d['muwathaq']['reference']);
        $this->assertSame(2, $d['muwathaq']['tier']);
    }

    /**
     * AMIAL-MUWATHAQ-ORIGIN-ADMIN-002 — **نفسُ العطل، لحسابٍ بدأ تسجيلَه داخل التطبيق**
     * وأحاله موثّق (`MuwathaqAccountOpeningService::submitFor`، لا `MuwathaqProvisioningService`).
     * هذا المسار لا يكتب `origin` إطلاقاً فيبقى على افتراضه `amial_legacy` لا `muwathaq` —
     * وهو تحديداً حساب نور السالمي (مسار PATH_WOMEN_PROGRESSIVE، مستوى ٢) الذي أبلغ عنه صاحبُ
     * المشروع: معتمَدٌ من موثّق فعلاً، واللوحةُ تقول «وثائق ناقصة».
     *
     * @test
     */
    public function an_in_app_registration_approved_by_muwathaq_is_also_not_reported_as_missing_documents(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'role' => 'customer', 'phone' => '967781545525', 'kyc_tier' => 2, 'is_kyc_verified' => 1]);

        MuwathaqAccountOpeningApplication::query()->create([
            'application_ulid' => strtoupper(substr(str_replace('-', '', (string) Str::uuid()) . 'ABCDEFGHJK', 0, 26)),
            'user_id' => $customer->id, // بلا 'origin' — يبقى على الافتراض amial_legacy
            'subject_kind' => 'customer', 'verification_path' => 'women_progressive', 'requested_tier' => 2,
            'status' => MuwathaqAccountOpeningApplication::STATUS_APPROVED,
            'submitted_at' => now(), 'decided_at' => now(), 'muwathaq_decided_at' => now(),
            'decision_reference' => 'MWT-D0FC218BDDA116F96FD3', 'decision_context' => [], 'evidence_completed_at' => now(),
        ]);

        $d = $this->readiness($admin, $customer);

        $this->assertNotContains('DOCUMENTS_INCOMPLETE', array_column($d['blockers'], 'code'),
            'قيل «وثائق ناقصة» عن حسابٍ اعتمده موثّق عبر مسار التسجيل داخل التطبيق');
        $this->assertNotNull($d['muwathaq'], 'لم تُعرض ملاحظة موثّق لحسابٍ origin فيه amial_legacy');
        $this->assertSame('MWT-D0FC218BDDA116F96FD3', $d['muwathaq']['reference']);
    }

    /** @test */
    public function a_normal_account_still_reports_its_missing_documents(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'role' => 'customer', 'phone' => '967781545998', 'kyc_tier' => 2, 'is_kyc_verified' => 1]);

        $d = $this->readiness($admin, $customer);

        $this->assertContains('DOCUMENTS_INCOMPLETE', array_column($d['blockers'], 'code'),
            'الإعفاء لحسابات موثّق وحدها — العاديُّ ما زال يُطالَب بوثائقه');
        $this->assertNull($d['muwathaq']);
        $this->assertNotEmpty($d['documents']);
    }

    /** @test */
    public function an_out_of_zone_governorate_is_still_reported_for_a_muwathaq_account(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'role' => 'customer', 'phone' => '967781545997', 'kyc_tier' => 2, 'is_kyc_verified' => 1, 'residence_governorate' => 'YE-AM']);
        $this->approvedByMuwathaq($customer);

        $codes = array_column($this->readiness($admin, $customer)['blockers'], 'code');

        $this->assertContains('GOVERNORATE_OUT_OF_ZONE', $codes, 'إعفاءُ الوثائق لا يُسكت مانعَ المحافظة خارج النطاق');
    }

    /** @test */
    public function the_view_shows_the_muwathaq_note_and_disables_approval_for_such_accounts(): void
    {
        $view = file_get_contents(resource_path('views/admin-views/amial/hub/users.blade.php'));

        $this->assertStringContainsString('edit-muwathaq-note', $view);
        $this->assertStringContainsString('edit-docs-block', $view);
        $this->assertStringContainsString('وثائق الهويّة محفوظةٌ لدى موثّق', $view);
        $this->assertStringContainsString("d.kyc === 1 || !!d.muwathaq", $view);
    }
}
