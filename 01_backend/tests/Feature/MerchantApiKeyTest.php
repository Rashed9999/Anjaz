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

/** AMIAL-API-ACCESS-001 — مفاتيح API وواجهة الشركاء (الباقة المؤسسية). */
class MerchantApiKeyTest extends TestCase
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

    private function upgrade(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_ENTERPRISE, $admin);
    }

    /** @test غير المؤسسي ممنوع → 402. */
    public function non_enterprise_cannot_manage_keys(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/api-keys')->assertStatus(402);
    }

    /** @test توليد مفتاح ثم استخدامه في واجهة الشركاء لجلب المبيعات. */
    public function generate_key_and_use_partner_endpoint(): void
    {
        $this->upgrade();
        MerchantSale::create([
            'sale_ulid' => \Illuminate\Support\Str::ulid(), 'merchant_user_id' => $this->merchant->id,
            'total_amount' => '5000', 'payment_method' => 'cash', 'status' => 'completed',
            'items' => [], 'zone_code' => 'SOUTH',
        ]);

        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $key = $this->postJson('/api/v1/amial/merchant/api-keys', ['label' => 'تكامل'])
            ->assertStatus(201)->json('meta.api_key');
        $this->assertStringStartsWith('amk_', $key);

        // واجهة الشركاء بمفتاح صحيح
        $this->getJson('/api/v1/amial/partner/sales', ['X-Api-Key' => $key])
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.total_amount', '5000.0000');

        // مفتاح خاطئ يُرفض
        $this->getJson('/api/v1/amial/partner/sales', ['X-Api-Key' => 'amk_wrong'])
            ->assertStatus(401);
    }

    /** @test تعطيل المفتاح يمنع استخدامه. */
    public function toggling_off_blocks_the_key(): void
    {
        $this->upgrade();
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $create = $this->postJson('/api/v1/amial/merchant/api-keys', [])->assertStatus(201);
        $key = $create->json('meta.api_key');
        $id = $create->json('meta.id');

        $this->postJson("/api/v1/amial/merchant/api-keys/{$id}/toggle")->assertOk();
        $this->getJson('/api/v1/amial/partner/sales', ['X-Api-Key' => $key])->assertStatus(401);
    }
}
