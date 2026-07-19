<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** AMIAL-BACKUP-001 — نسخة احتياطية لبيانات التاجر (تاجر برو فأعلى). */
class MerchantBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_FREE]);
    }

    private function upgrade(string $plan): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, $plan, $admin);
    }

    /** @test الباقة المجّانية ممنوعة → 402. */
    public function free_plan_cannot_backup(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/backup')->assertStatus(402);
    }

    /** @test تاجر برو ينزّل نسخة احتياطية فيها الأعداد الصحيحة. */
    public function merchant_pro_downloads_backup_with_counts(): void
    {
        $this->upgrade(A::PLAN_MERCHANT_PRO);
        MerchantSale::create([
            'sale_ulid' => \Illuminate\Support\Str::ulid(), 'merchant_user_id' => $this->merchant->id,
            'total_amount' => '5000', 'payment_method' => 'cash', 'status' => 'completed',
            'items' => [], 'zone_code' => 'SOUTH',
        ]);

        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $res = $this->getJson('/api/v1/amial/merchant/backup')
            ->assertOk()
            ->assertHeader('content-disposition')
            ->assertJsonPath('meta.counts.sales', 1)
            ->assertJsonPath('meta.schema_version', 1);

        $this->assertStringContainsString('attachment', $res->headers->get('content-disposition'));
    }
}
