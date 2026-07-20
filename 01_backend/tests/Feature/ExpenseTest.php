<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** AMIAL-EXPENSES-001 — المصروفات والصندوق النثري. */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $plan): User
    {
        $m = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $m->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => $plan]);
        return $m;
    }

    /** @test المجّاني ممنوع → 402. */
    public function free_plan_blocked(): void
    {
        Passport::actingAs($this->merchant(A::PLAN_FREE)->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/expenses')->assertStatus(402);
    }

    /** @test تسجيل مصاريف ثم ملخّص بالإجمالي وحسب الفئة. */
    public function record_and_summarize(): void
    {
        Passport::actingAs($this->merchant(A::PLAN_BUSINESS)->fresh(), [], 'api');

        $this->postJson('/api/v1/amial/merchant/expenses',
            ['title' => 'إيجار', 'amount' => 30000, 'category' => 'rent'])->assertStatus(201);
        $this->postJson('/api/v1/amial/merchant/expenses',
            ['title' => 'كهرباء', 'amount' => 5000, 'category' => 'utilities'])->assertStatus(201);

        $this->getJson('/api/v1/amial/merchant/expenses')
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.total', '35000.0000')
            ->assertJsonPath('meta.by_category.rent', '30000.0000');
    }
}
