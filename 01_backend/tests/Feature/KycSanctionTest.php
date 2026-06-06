<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\KycTierService;
use App\Services\SanctionScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-KYC-TIERS-001 + AMIAL-SANCTION-001 (v1.9) — اختبارات.
 */
class KycSanctionTest extends TestCase
{
    use RefreshDatabase;

    private KycTierService $kyc;
    private SanctionScreeningService $sanction;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('amial.encryption.pii_key', base64_encode(random_bytes(32)));
        config()->set('amial.encryption.blind_index_key', base64_encode(random_bytes(32)));
        $this->kyc = app(KycTierService::class);
        $this->sanction = app(SanctionScreeningService::class);
    }

    // ============ KYC Tiers ============

    /** @test */
    public function tier_0_blocks_all_transactions()
    {
        $user = User::factory()->create(['kyc_tier' => 0]);
        $this->expectException(\RuntimeException::class);
        $this->kyc->assertTransactionAllowed($user, '100', 'send_money');
    }

    /** @test */
    public function tier_1_allows_small_transactions()
    {
        $user = User::factory()->create(['kyc_tier' => 1]);
        // 1000 ضمن حد 5000 لـ tier 1
        $this->kyc->assertTransactionAllowed($user, '1000', 'send_money');
        $this->assertTrue(true); // لم يُرمَ exception
    }

    /** @test */
    public function tier_1_rejects_large_single_transaction()
    {
        $user = User::factory()->create(['kyc_tier' => 1]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('حد العملية الواحدة');
        // 10000 > حد 5000
        $this->kyc->assertTransactionAllowed($user, '10000', 'send_money');
    }

    /** @test */
    public function tier_1_blocks_safe_payment_feature()
    {
        $user = User::factory()->create(['kyc_tier' => 1]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('مستوى توثيق أعلى');
        // safe_payment غير مسموح في tier 1
        $this->kyc->assertTransactionAllowed($user, '1000', 'safe_payment');
    }

    /** @test */
    public function tier_2_allows_safe_payment()
    {
        $user = User::factory()->create(['kyc_tier' => 2]);
        $this->kyc->assertTransactionAllowed($user, '1000', 'safe_payment');
        $this->assertTrue(true);
    }

    /** @test */
    public function tier_3_allows_all_features()
    {
        $user = User::factory()->create(['kyc_tier' => 3]);
        foreach (['send_money', 'safe_payment', 'donations', 'family_fund'] as $feature) {
            $this->kyc->assertTransactionAllowed($user, '1000', $feature);
        }
        $this->assertTrue(true);
    }

    /** @test */
    public function balance_limit_is_enforced()
    {
        $user = User::factory()->create(['kyc_tier' => 1]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('الرصيد سيتجاوز الحد');
        // 100000 > حد رصيد tier 1 (50000)
        $this->kyc->assertBalanceAllowed($user, '100000');
    }

    /** @test */
    public function upgrade_tier_works()
    {
        $user = User::factory()->create(['kyc_tier' => 0]);
        $this->kyc->upgradeTier($user, 2);
        $user->refresh();
        $this->assertEquals(2, $user->kyc_tier);
        $this->assertNotNull($user->kyc_tier_updated_at);
    }

    /** @test */
    public function upgrade_rejects_invalid_tier()
    {
        $user = User::factory()->create();
        $this->expectException(\RuntimeException::class);
        $this->kyc->upgradeTier($user, 5);
    }

    /** @test */
    public function tier_info_includes_next_tier()
    {
        $user = User::factory()->create(['kyc_tier' => 1]);
        $info = $this->kyc->getUserTierInfo($user);
        $this->assertEquals(1, $info['current_tier']);
        $this->assertNotNull($info['next_tier']);
        $this->assertEquals(2, $info['next_tier']['tier']);
    }

    // ============ Sanction Screening ============

    /** @test */
    public function clean_name_passes_screening()
    {
        $result = $this->sanction->screenName('أحمد محمد علي');
        $this->assertEquals('clear', $result['result']);
    }

    /** @test */
    public function exact_match_is_confirmed()
    {
        DB::table('sanction_list_entries')->insert([
            'list_source' => 'OFAC',
            'entry_type' => 'individual',
            'full_name' => 'Bad Actor',
            'normalized_name' => $this->sanction->normalizeName('Bad Actor'),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->sanction->screenName('Bad Actor');
        $this->assertEquals('confirmed_match', $result['result']);
        $this->assertEquals(100.0, $result['score']);
    }

    /** @test */
    public function national_id_match_is_confirmed()
    {
        $nid = '1234567890';
        DB::table('sanction_list_entries')->insert([
            'list_source' => 'UN',
            'entry_type' => 'individual',
            'full_name' => 'Some Name',
            'normalized_name' => 'some name',
            'national_id_hash' => hash('sha256', $nid),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->sanction->screenName('Different Name', ['national_id' => $nid]);
        $this->assertEquals('confirmed_match', $result['result']);
        $this->assertEquals('national_id', $result['details']['match_type']);
    }

    /** @test */
    public function name_normalization_handles_arabic()
    {
        // الألف بأشكالها → ا، التاء المربوطة → ه
        $a = $this->sanction->normalizeName('أحمد');
        $b = $this->sanction->normalizeName('احمد');
        $this->assertEquals($a, $b);
    }

    /** @test */
    public function screening_logs_result()
    {
        $user = User::factory()->create(['f_name' => 'نظيف', 'l_name' => 'تماماً']);
        $this->sanction->screenUser($user, 'registration');

        $this->assertDatabaseHas('sanction_screening_logs', [
            'user_id' => $user->id,
            'result' => 'clear',
        ]);
        $user->refresh();
        $this->assertTrue((bool)$user->sanction_checked);
    }

    /** @test */
    public function confirmed_match_blocks_user()
    {
        DB::table('sanction_list_entries')->insert([
            'list_source' => 'OFAC',
            'entry_type' => 'individual',
            'full_name' => 'محظور شخص',
            'normalized_name' => $this->sanction->normalizeName('محظور شخص'),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create(['f_name' => 'محظور', 'l_name' => 'شخص']);
        $result = $this->sanction->screenUser($user);

        $this->assertEquals('confirmed_match', $result['result']);
        $user->refresh();
        $this->assertEquals('blocked', $user->sanction_status);
        $this->assertTrue($this->sanction->isBlocked($user));
    }
}
