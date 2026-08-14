<?php

namespace Tests\Feature;

use App\Models\CharityCampaign;
use App\Models\CharityCategory;
use App\Models\CharityOrganization;
use App\Models\Donation;
use App\Models\EMoney;
use App\Models\User;
use App\Services\DonationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-DONATIONS-001 (v1.2) — اختبارات.
 */
class DonationsServiceTest extends TestCase
{
    use RefreshDatabase;

    private DonationsService $service;
    private User $donor;
    private CharityOrganization $org;
    private CharityCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('amial.donations.fee_percent', '1.0');
        config()->set('amial.donations.min_amount', '1.0000');
        config()->set('amial.donations.max_amount', '50000.0000');

        $this->service = app(DonationsService::class);

        $this->donor = User::factory()->create([
            'zone_code' => 'SOUTH',
            'phone' => '+967700005001',
        ]);
        EMoney::create(['user_id' => $this->donor->id, 'current_balance' => '5000.0000']);

        $category = CharityCategory::updateOrCreate(['code' => 'food'], [
            'code' => 'food',
            'name_ar' => 'طعام',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->org = CharityOrganization::create([
            'org_ulid' => 'TEST01ORG00000000000000000',
            'name_ar' => 'منظمة الإغاثة اليمنية',
            'license_number' => 'LIC-001',
            'description_ar' => 'منظمة خيرية تجريبية',
            'contact_phone' => '+967100000001',
            'verification_status' => 'verified',
            'verified_at' => now(),
            'is_active' => true,
            'zone_code' => 'SOUTH',
        ]);

        $this->campaign = CharityCampaign::create([
            'campaign_ulid' => 'TEST01CAMPAIGN000000000000',
            'org_id' => $this->org->id,
            'category_id' => $category->id,
            'title_ar' => 'إغاثة محتاجين في عدن',
            'description_ar' => 'مساعدة عاجلة لـ 100 أسرة',
            'target_amount' => '10000.0000',
            'current_amount' => '0',
            'platform_fee_collected' => '0',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'deadline_at' => now()->addMonth(),
            'zone_code' => 'SOUTH',
        ]);
    }

    /** @test */
    public function it_donates_successfully_with_fee_calculated()
    {
        $donation = $this->service->donate(
            donor: $this->donor,
            campaign: $this->campaign,
            amount: '100.0000',
        );

        $this->assertInstanceOf(Donation::class, $donation);
        $this->assertEquals('100.0000', (string)$donation->amount);
        $this->assertEquals('1.0000', (string)$donation->platform_fee); // 1%
        $this->assertEquals('99.0000', (string)$donation->net_to_charity);
        $this->assertEquals('completed', $donation->status);

        // المتبرع خُصم
        $donorWallet = EMoney::where('user_id', $this->donor->id)->first();
        $this->assertEquals('4900.0000', (string)$donorWallet->current_balance);

        // الحملة ارتفعت بمقدار net_to_charity فقط (لا fee)
        $this->campaign->refresh();
        $this->assertEquals('99.0000', (string)$this->campaign->current_amount);
        $this->assertEquals('1.0000', (string)$this->campaign->platform_fee_collected);
        $this->assertEquals(1, $this->campaign->donor_count);
    }

    /** @test */
    public function anonymous_donation_is_flagged_but_user_id_preserved()
    {
        $donation = $this->service->donate(
            donor: $this->donor,
            campaign: $this->campaign,
            amount: '50.0000',
            isAnonymous: true,
        );

        $this->assertTrue($donation->is_anonymous);
        $this->assertEquals($this->donor->id, $donation->donor_user_id); // مُسجَّل للـ audit
        $this->assertEquals('متبرع مجهول', $donation->public_donor_name);
    }

    /** @test */
    public function non_anonymous_shows_donor_name()
    {
        $this->donor->forceFill(['f_name' => 'محمد', 'l_name' => 'الأحمدي'])->save();

        $donation = $this->service->donate(
            donor: $this->donor,
            campaign: $this->campaign,
            amount: '50.0000',
            isAnonymous: false,
        );

        $this->assertFalse($donation->is_anonymous);
        $this->assertEquals('محمد الأحمدي', $donation->public_donor_name);
    }

    /** @test */
    public function insufficient_balance_prevents_donation()
    {
        $this->expectException(\App\Exceptions\InsufficientBalanceException::class);

        $this->service->donate(
            donor: $this->donor,
            campaign: $this->campaign,
            amount: '10000.0000', // أكثر من 5000
        );

        $this->assertEquals(0, Donation::count());
        $this->campaign->refresh();
        $this->assertEquals('0.0000', (string)$this->campaign->current_amount);
    }

    /** @test */
    public function cannot_donate_to_paused_campaign()
    {
        $this->campaign->update(['status' => 'paused']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not currently accepting');

        $this->service->donate($this->donor, $this->campaign, '50.0000');
    }

    /** @test */
    public function cannot_donate_after_deadline()
    {
        $this->campaign->update(['deadline_at' => now()->subDay()]);

        $this->expectException(\RuntimeException::class);
        $this->service->donate($this->donor, $this->campaign, '50.0000');
    }

    /** @test */
    public function cannot_donate_to_unverified_organization()
    {
        $this->org->update(['verification_status' => 'pending_verification']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not verified');

        $this->service->donate($this->donor, $this->campaign, '50.0000');
    }

    /** @test */
    public function non_south_user_cannot_donate()
    {
        $north = User::factory()->create(['zone_code' => 'NORTH']);
        EMoney::create(['user_id' => $north->id, 'current_balance' => '1000.0000']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOUTH');

        $this->service->donate($north, $this->campaign, '50.0000');
    }

    /** @test */
    public function campaign_auto_completes_when_target_reached()
    {
        $this->campaign->update(['target_amount' => '100.0000']);
        EMoney::where('user_id', $this->donor->id)->update(['current_balance' => '200.0000']);

        // 102 ر.س = 102 + 1.02 fee ≈ 100.98 net → بدفعة واحدة تتجاوز الهدف
        $this->service->donate($this->donor, $this->campaign, '102.0000');

        $this->campaign->refresh();
        $this->assertEquals('completed', $this->campaign->status);
    }

    /** @test */
    public function multiple_donations_from_same_user_count_donor_once()
    {
        $this->service->donate($this->donor, $this->campaign, '50.0000');
        $this->service->donate($this->donor, $this->campaign, '30.0000');
        $this->service->donate($this->donor, $this->campaign, '20.0000');

        $this->campaign->refresh();
        $this->assertEquals(3, Donation::where('campaign_id', $this->campaign->id)->count());
        $this->assertEquals(1, $this->campaign->donor_count); // unique donor
    }

    /** @test */
    public function org_total_collected_increments_correctly()
    {
        $this->service->donate($this->donor, $this->campaign, '100.0000');

        $this->org->refresh();
        $this->assertEquals('99.0000', (string)$this->org->total_collected); // net only
    }

    /** @test */
    public function refund_reverses_balances()
    {
        $admin = User::factory()->create();
        $donation = $this->service->donate($this->donor, $this->campaign, '200.0000');

        $this->assertEquals('4800.0000',
            (string)EMoney::where('user_id', $this->donor->id)->value('current_balance'));

        $donation = $this->service->refundDonation($donation, $admin, 'Test refund reason for compliance');

        $this->assertEquals('refunded', $donation->status);

        // المتبرع استعاد كل المبلغ
        $this->assertEquals('5000.0000',
            (string)EMoney::where('user_id', $this->donor->id)->value('current_balance'));

        // الحملة تراجعت
        $this->campaign->refresh();
        $this->assertEquals('0.0000', (string)$this->campaign->current_amount);
    }

    /** @test */
    public function cannot_refund_settled_donation()
    {
        $admin = User::factory()->create();
        $donation = $this->service->donate($this->donor, $this->campaign, '100.0000');

        // simulate settlement (settlement_id يحدّد الحالة المُسوّاة) — نعطّل FK للمعرّف الوهمي
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        $donation->update(['settlement_id' => 999]);
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already settled');

        $this->service->refundDonation($donation->fresh(), $admin, 'Some reason here for the test');
    }

    /** @test */
    public function donation_below_minimum_rejected()
    {
        $this->expectException(\RuntimeException::class);
        $this->service->donate($this->donor, $this->campaign, '0.5000');
    }

    /** @test */
    public function donation_above_maximum_rejected()
    {
        EMoney::where('user_id', $this->donor->id)->update(['current_balance' => '100000.0000']);

        $this->expectException(\RuntimeException::class);
        $this->service->donate($this->donor, $this->campaign, '60000.0000'); // > 50k max
    }
}
