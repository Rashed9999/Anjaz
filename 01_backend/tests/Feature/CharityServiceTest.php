<?php

namespace Tests\Feature;

use App\Models\CharityCampaign;
use App\Models\CharityCategory;
use App\Models\CharityOrganization;
use App\Models\CharitySettlement;
use App\Models\Donation;
use App\Models\EMoney;
use App\Models\User;
use App\Services\CharityService;
use App\Services\DonationsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-DONATIONS-001 (v1.2)
 */
class CharityServiceTest extends TestCase
{
    use RefreshDatabase;

    private CharityService $service;
    private DonationsService $donations;
    private User $admin;
    private CharityOrganization $org;
    private CharityCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('amial.donations.fee_percent', '2.0');

        $this->service = app(CharityService::class);
        $this->donations = app(DonationsService::class);
        $this->admin = User::factory()->create();

        // AMIAL-CHARITY-META-001 — **التصنيفاتُ صارت مزروعةً بهجرة**،
        // فإنشاؤها هنا يصطدم بمفتاحِ الرمز الفريد. و`updateOrCreate` تُبقي
        // الاختبارَ يعمل في القاعدتين: التي زُرعت والتي لم تُزرع بعد.
        $this->category = CharityCategory::updateOrCreate(['code' => 'medical'], [
            'code' => 'medical',
            'name_ar' => 'علاج',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->org = CharityOrganization::create([
            'org_ulid' => 'CHTEST0001000000000000000A',
            'name_ar' => 'منظمة الإغاثة',
            'license_number' => 'LIC-T-001',
            'description_ar' => 'وصف',
            'contact_phone' => '+967100000099',
            'verification_status' => 'pending_verification',
            'is_active' => true,
            'zone_code' => 'SOUTH',
        ]);
    }

    /** @test */
    public function verify_organization_changes_status_and_records_admin()
    {
        $this->service->verifyOrganization($this->org, $this->admin);

        $this->org->refresh();
        $this->assertEquals('verified', $this->org->verification_status);
        $this->assertEquals($this->admin->id, $this->org->verified_by_admin_id);
        $this->assertNotNull($this->org->verified_at);
    }

    /** @test */
    public function reject_organization_deactivates_it()
    {
        $this->service->rejectOrganization($this->org, $this->admin, 'License not valid');

        $this->org->refresh();
        $this->assertEquals('rejected', $this->org->verification_status);
        $this->assertFalse($this->org->is_active);
        $this->assertStringContainsString('License', $this->org->rejection_reason);
    }

    /** @test */
    public function suspend_organization_pauses_its_campaigns()
    {
        $this->service->verifyOrganization($this->org, $this->admin);

        $campaign = CharityCampaign::create([
            'campaign_ulid' => 'CMPTEST0001000000000000000',
            'org_id' => $this->org->id,
            'category_id' => $this->category->id,
            'title_ar' => 'Test',
            'description_ar' => 'Test',
            'target_amount' => '1000.0000',
            'status' => 'active',
            'start_at' => now(),
            'zone_code' => 'SOUTH',
        ]);

        $this->service->suspendOrganization($this->org, $this->admin, 'Compliance investigation');

        $this->org->refresh();
        $campaign->refresh();
        $this->assertEquals('suspended', $this->org->verification_status);
        $this->assertEquals('paused', $campaign->status);
    }

    /** @test */
    public function cannot_create_campaign_for_unverified_org()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verified');

        $this->service->createCampaign($this->org, [
            'category_id' => $this->category->id,
            'title_ar' => 'Test',
            'description_ar' => 'Test',
            'target_amount' => '500.0000',
        ], $this->admin);
    }

    /** @test */
    public function approve_campaign_makes_it_active()
    {
        $this->service->verifyOrganization($this->org, $this->admin);
        $campaign = $this->service->createCampaign($this->org, [
            'category_id' => $this->category->id,
            'title_ar' => 'علاج طفل',
            'description_ar' => 'حملة لعلاج طفل',
            'target_amount' => '5000.0000',
        ], $this->admin);

        $this->assertEquals('pending_approval', $campaign->status);

        $campaign = $this->service->approveCampaign($campaign, $this->admin);
        $this->assertEquals('active', $campaign->status);
        $this->assertNotNull($campaign->approved_at);
    }

    /** @test */
    public function generate_settlement_aggregates_donations_in_period()
    {
        // Setup: verified org + active campaign
        $this->service->verifyOrganization($this->org, $this->admin);
        $campaign = $this->service->createCampaign($this->org, [
            'category_id' => $this->category->id,
            'title_ar' => 'Test',
            'description_ar' => 'Test',
            'target_amount' => '10000.0000',
        ], $this->admin);
        $this->service->approveCampaign($campaign, $this->admin);

        // 3 donations
        $donor = User::factory()->create(['zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $donor->id, 'current_balance' => '1000.0000']);

        $this->donations->donate($donor, $campaign, '100.0000');
        $this->donations->donate($donor, $campaign, '200.0000');
        $this->donations->donate($donor, $campaign, '300.0000');

        // Generate settlement
        $settlement = $this->service->generateSettlement(
            $this->org,
            Carbon::now()->subDay(),
            Carbon::now()->addDay(),
            $this->admin,
        );

        $this->assertEquals(3, $settlement->donation_count);
        $this->assertEquals('600.0000', (string)$settlement->total_donations);
        $this->assertEquals('12.0000', (string)$settlement->total_platform_fees); // 2% of 600
        $this->assertEquals('588.0000', (string)$settlement->payable_amount);
        $this->assertEquals('pending', $settlement->status);

        // Donations marked as settled
        $settledCount = Donation::where('campaign_id', $campaign->id)->where('status', 'settled')->count();
        $this->assertEquals(3, $settledCount);
    }

    /** @test */
    public function generate_settlement_throws_when_no_donations()
    {
        $this->service->verifyOrganization($this->org, $this->admin);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No unsettled donations');

        $this->service->generateSettlement(
            $this->org,
            Carbon::now()->subMonth(),
            Carbon::now(),
            $this->admin,
        );
    }

    /** @test */
    public function settlement_does_not_include_donations_already_in_other_settlement()
    {
        $this->service->verifyOrganization($this->org, $this->admin);
        $campaign = $this->service->createCampaign($this->org, [
            'category_id' => $this->category->id,
            'title_ar' => 'Test', 'description_ar' => 'Test',
            'target_amount' => '10000.0000',
        ], $this->admin);
        $this->service->approveCampaign($campaign, $this->admin);

        $donor = User::factory()->create(['zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $donor->id, 'current_balance' => '1000.0000']);
        $this->donations->donate($donor, $campaign, '100.0000');

        $settlement1 = $this->service->generateSettlement(
            $this->org, Carbon::now()->subDay(), Carbon::now()->addDay(), $this->admin,
        );
        $this->assertEquals(1, $settlement1->donation_count);

        // إنشاء تسوية ثانية بنفس الفترة → يجب أن تفشل (لا donations)
        $this->expectException(\RuntimeException::class);
        $this->service->generateSettlement(
            $this->org, Carbon::now()->subDay(), Carbon::now()->addDay(), $this->admin,
        );
    }

    /** @test */
    public function mark_settlement_transferred_records_bank_reference()
    {
        $this->service->verifyOrganization($this->org, $this->admin);
        $campaign = $this->service->createCampaign($this->org, [
            'category_id' => $this->category->id,
            'title_ar' => 'X', 'description_ar' => 'X',
            'target_amount' => '5000.0000',
        ], $this->admin);
        $this->service->approveCampaign($campaign, $this->admin);

        $donor = User::factory()->create(['zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $donor->id, 'current_balance' => '1000.0000']);
        $this->donations->donate($donor, $campaign, '500.0000');

        $settlement = $this->service->generateSettlement(
            $this->org, Carbon::now()->subDay(), Carbon::now()->addDay(), $this->admin,
        );

        $settlement = $this->service->markSettlementTransferred(
            $settlement, $this->admin,
            bankReference: 'BNK-REF-2026-001',
            notes: 'Transferred via Yemen Bank',
        );

        $this->assertEquals('transferred', $settlement->status);
        $this->assertEquals('BNK-REF-2026-001', $settlement->bank_transfer_reference);
        $this->assertNotNull($settlement->transferred_at);
    }
}
