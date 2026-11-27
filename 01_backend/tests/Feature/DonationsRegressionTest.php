<?php

namespace Tests\Feature;

use App\Models\CharityCampaign;
use App\Models\CharityCategory;
use App\Models\CharityOrganization;
use App\Models\Donation;
use App\Models\EMoney;
use App\Models\User;
use App\Services\CharityService;
use App\Services\DonationsService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * اختبارات انحدار التبرعات والجمعيات الخيرية:
 * - استرداد التبرع وحماية إعادة الطلب من الإرجاع المزدوج أو النقصان السالب
 * - إنشاء حملة بتاريخ انتهاء دون تاريخ بداية
 * - منع تفعيل أو عرض حملات لجمعيات معلّقة أو غير موثّقة
 * - التسوية ليوم واحد ومنع خطأ 500 عند تكرار الفترة
 */
class DonationsRegressionTest extends TestCase
{
    use RefreshDatabase;

    private DonationsService $donationsService;
    private CharityService $charityService;
    private User $donor;
    private User $admin;
    private CharityCategory $category;
    private CharityOrganization $org;
    private CharityCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('amial.donations.fee_percent', '1.0');
        config()->set('amial.donations.min_amount', '1.0000');
        config()->set('amial.donations.max_amount', '50000.0000');

        $this->donationsService = app(DonationsService::class);
        $this->charityService = app(CharityService::class);

        $this->donor = User::factory()->create([
            'zone_code' => 'SOUTH',
            'phone' => '+967702000001',
            'is_kyc_verified' => 1,
            'is_active' => 1,
            'type' => CUSTOMER_TYPE,
        ]);
        EMoney::create(['user_id' => $this->donor->id, 'current_balance' => '2000.0000']);

        $this->admin = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'admin',
            'is_active' => 1,
        ]);
        app(PlatformRoleService::class)->assign($this->admin, PlatformRoleService::ADMIN);
        $this->admin->refresh();

        $this->category = CharityCategory::updateOrCreate(['code' => 'medical'], [
            'code' => 'medical',
            'name_ar' => 'علاج',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->org = CharityOrganization::create([
            'org_ulid' => (string) Str::ulid(),
            'name_ar' => 'جمعية الشفاء الخيرية',
            'license_number' => 'LIC-REG-001',
            'description_ar' => 'جمعية موثّقة للاختبار',
            'contact_phone' => '+967100000099',
            'verification_status' => 'verified',
            'verified_at' => now(),
            'is_active' => true,
            'zone_code' => 'SOUTH',
        ]);

        $this->campaign = CharityCampaign::create([
            'campaign_ulid' => (string) Str::ulid(),
            'org_id' => $this->org->id,
            'category_id' => $this->category->id,
            'title_ar' => 'حملة دعم مستشفى عدن',
            'description_ar' => 'توفير أدوية ومستلزمات طبية',
            'target_amount' => '10000.0000',
            'current_amount' => '0.0000',
            'platform_fee_collected' => '0.0000',
            'donor_count' => 0,
            'status' => 'active',
            'start_at' => now()->subDay(),
            'deadline_at' => now()->addMonth(),
            'zone_code' => 'SOUTH',
        ]);
    }

    /** @test */
    public function admin_donation_refund_restores_wallet_and_replay_never_double_credits(): void
    {
        $donation = $this->donationsService->donate($this->donor, $this->campaign, '200.0000');
        $this->assertSame($donation->donation_ulid, $donation->wallet_transaction_id);
        $this->assertDatabaseHas('ledger_journal_entries', [
            'source_type' => 'donation',
            'source_id' => $donation->wallet_transaction_id,
        ]);
        $this->assertSame('1800.0000', (string) EMoney::where('user_id', $this->donor->id)->value('current_balance'));
        $this->assertSame(1, $this->campaign->fresh()->donor_count);

        // الاسترداد الأول عبر مسار الإدارة ينجح
        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/charity/donations/{$donation->donation_ulid}/refund", [
                'reason' => 'طلب استرداد مبرّر بسبب خطأ في الحملة',
            ])
            ->assertOk();

        $this->assertSame('refunded', $donation->fresh()->status);
        $this->assertSame('2000.0000', (string) EMoney::where('user_id', $this->donor->id)->value('current_balance'));
        $this->assertSame(0, $this->campaign->fresh()->donor_count);

        // إعادة طلب الاسترداد (Replay) يجب أن تُرفض بـ 422 ولا تضاعف الرصيد ولا تنزل العداد تحت الصفر
        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/charity/donations/{$donation->donation_ulid}/refund", [
                'reason' => 'إعادة محاولة استرداد مكررة',
            ])
            ->assertStatus(422);

        $this->assertSame('2000.0000', (string) EMoney::where('user_id', $this->donor->id)->value('current_balance'));
        $this->assertSame(0, $this->campaign->fresh()->donor_count);
    }

    /** @test */
    public function refund_guards_donor_count_against_negative_underflow(): void
    {
        $donation = $this->donationsService->donate($this->donor, $this->campaign, '100.0000');
        $this->campaign->update(['donor_count' => 0]);

        $this->donationsService->refundDonation($donation->fresh(), $this->admin, 'اختبار حماية العداد');

        $this->assertSame(0, $this->campaign->fresh()->donor_count);
        $this->assertSame('refunded', $donation->fresh()->status);
    }

    /** @test */
    public function campaign_creation_succeeds_with_deadline_when_start_at_is_omitted(): void
    {
        $this->actingAs($this->admin, 'user')
            ->postJson("/admin/amial/charity/organizations/{$this->org->org_ulid}/campaigns", [
                'category_id' => $this->category->id,
                'title_ar' => 'حملة بلا تاريخ بداية محدد',
                'description_ar' => 'تبدأ فور الاعتماد وتنتهي بعد شهر',
                'target_amount' => 5000,
                'deadline_at' => now()->addDays(20)->toDateString(),
            ])
            ->assertStatus(201);
    }

    /** @test */
    public function suspended_organization_campaign_cannot_be_approved_or_shown_publicly(): void
    {
        $this->org->update(['verification_status' => 'suspended']);
        $this->campaign->update(['status' => 'paused']);

        // لا يجوز إعادة تفعيل حملة لجمعية معلّقة
        $blocked = false;
        try {
            $this->charityService->approveCampaign($this->campaign->fresh(), $this->admin);
        } catch (\RuntimeException) {
            $blocked = true;
        }
        $this->assertTrue($blocked, 'يجب منع تفعيل حملة تابعة لجمعية معلّقة');

        // ولا تظهر في نطاق AcceptingDonations ولا عبر campaignShow
        $this->assertCount(0, CharityCampaign::acceptingDonations()->where('id', $this->campaign->id)->get());

        $this->actingAs($this->donor, 'api')
            ->getJson("/api/v1/amial/donations/campaigns/{$this->campaign->campaign_ulid}")
            ->assertNotFound();
    }

    /** @test */
    public function organizations_endpoint_includes_verification_status_and_unknown_category_returns_empty(): void
    {
        $orgs = $this->actingAs($this->donor, 'api')
            ->getJson('/api/v1/amial/donations/organizations')
            ->assertOk()
            ->json('meta.items');

        $this->assertNotEmpty($orgs);
        $this->assertSame('verified', $orgs[0]['verification_status'] ?? null);

        $campaigns = $this->actingAs($this->donor, 'api')
            ->getJson('/api/v1/amial/donations/campaigns?category=non_existent_code_xyz')
            ->assertOk()
            ->json('meta.items');

        $this->assertSame([], $campaigns);
    }

    /** @test */
    public function single_day_settlement_is_allowed_and_duplicate_period_returns_422_not_500(): void
    {
        $this->donationsService->donate($this->donor, $this->campaign, '150.0000');
        $today = now()->toDateString();

        // تسوية يوم واحد (period_start == period_end) تنجح
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/charity/settlements/generate', [
                'org_ulid' => $this->org->org_ulid,
                'period_start' => $today,
                'period_end' => $today,
            ])
            ->assertStatus(201);

        // تكرار نفس الفترة يعيد 422 وليس 500
        $this->actingAs($this->admin, 'user')
            ->postJson('/admin/amial/charity/settlements/generate', [
                'org_ulid' => $this->org->org_ulid,
                'period_start' => $today,
                'period_end' => $today,
            ])
            ->assertStatus(422);
    }
}
