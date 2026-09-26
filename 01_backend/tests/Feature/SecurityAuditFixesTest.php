<?php

namespace Tests\Feature;

use App\Models\Ledger\LedgerAccount;
use App\Models\User;
use App\Traits\EnforcesFinancialPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-SECURITY-AUDIT-001 (v2.1) — اختبارات إصلاحات المراجعة الأمنية.
 */
class SecurityAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private object $policy;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('amial.encryption.pii_key', base64_encode(random_bytes(32)));
        config()->set('amial.encryption.blind_index_key', base64_encode(random_bytes(32)));

        // كائن يستخدم الـ trait للاختبار
        $this->policy = new class {
            use EnforcesFinancialPolicy {
                enforceZone as public;
                enforceSanction as public;
                enforceFinancialPolicy as public;
            }
        };
    }

    /** @test */
    public function enforce_zone_blocks_north_user()
    {
        $user = User::factory()->create(['zone_code' => 'NORTH']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('الجنوب فقط');
        $this->policy->enforceZone($user);
    }

    /** @test */
    public function enforce_zone_blocks_unknown_user()
    {
        $user = User::factory()->create(['zone_code' => 'UNKNOWN']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('غير مُعيَّن');
        $this->policy->enforceZone($user);
    }

    /** @test */
    public function enforce_zone_allows_south_user()
    {
        $user = User::factory()->create(['zone_code' => 'SOUTH']);
        $this->policy->enforceZone($user);
        $this->assertTrue(true); // لم يُرمَ exception
    }

    /** @test */
    public function enforce_sanction_blocks_flagged_user()
    {
        $user = User::factory()->create([
            'zone_code' => 'SOUTH',
            'sanction_status' => 'blocked',
            'sanction_checked' => true,
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('تقييد');
        $this->policy->enforceSanction($user);
    }

    /** @test */
    public function enforce_sanction_allows_clear_user()
    {
        $user = User::factory()->create([
            'zone_code' => 'SOUTH',
            'sanction_status' => 'clear',
            'sanction_checked' => true,
        ]);
        $this->policy->enforceSanction($user);
        $this->assertTrue(true);
    }

    /** @test */
    public function full_policy_blocks_north_user_even_with_valid_amount()
    {
        $user = User::factory()->create([
            'zone_code' => 'NORTH',
            'kyc_tier' => 3,
            'sanction_status' => 'clear',
            'sanction_checked' => true,
        ]);
        // رغم KYC tier 3 و sanction clear، الـ zone يمنعه
        $this->expectException(\RuntimeException::class);
        $this->policy->enforceFinancialPolicy($user, 'send_money', '100');
    }

    /** @test */
    public function pii_keys_have_no_source_code_fallback_and_production_refuses_missing_keys(): void
    {
        $config = (string) file_get_contents(config_path('amial.php'));
        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertMatchesRegularExpression(
            "/'pii_key'\\s*=>\\s*env\\('AMIAL_PII_ENCRYPTION_KEY'\\)/",
            $config,
            'مفتاح الهوية عاد بقيمة بديلة في المصدر؛ من يقرأ المستودع يفك البيانات',
        );
        $this->assertMatchesRegularExpression(
            "/'blind_index_key'\\s*=>\\s*env\\('AMIAL_PII_BLIND_INDEX_KEY'\\)/",
            $config,
            'مفتاح فهرس الهوية عاد بقيمة بديلة في المصدر',
        );
        $this->assertStringContainsString('AMIAL-PII-KEY-GATE-001', $entrypoint);
        $this->assertStringContainsString('AMIAL_PII_ENCRYPTION_KEY', $entrypoint);
        $this->assertStringContainsString('AMIAL_PII_BLIND_INDEX_KEY', $entrypoint);
    }
}
