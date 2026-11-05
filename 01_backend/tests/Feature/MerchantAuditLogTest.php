<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-AUDIT-001 — سجلّ التدقيق للتاجر محميّ بميزة audit_log
 * (باقة التاجر برو فأعلى) ويعرض القيود التي يكون التاجر طرفاً فيها.
 */
class MerchantAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create([
            'type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_FREE,
        ]);
    }

    /** @test الباقات الأدنى من برو لا ترى سجلّ التدقيق → 402. */
    public function below_pro_cannot_view_audit_log(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/audit-log')->assertStatus(402);
    }

    /** @test التاجر برو يرى قيود التدقيق الخاصّة به. */
    public function pro_merchant_sees_own_audit_entries(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_MERCHANT_PRO, $admin);

        app(AuditService::class)->record([
            'actor_type' => 'user',
            'actor_user_id' => $this->merchant->id,
            'subject_type' => 'user',
            'subject_id' => $this->merchant->id,
            'action' => 'MERCHANT_PAYMENT',
            'decision_code' => 'TX_OK',
            'reason' => 'دفعة اختبار',
            'severity' => 'info',
        ]);

        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/audit-log')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.entries.0.action', 'MERCHANT_PAYMENT');
    }

    /** @test سجل التاجر لا يعيد الرموز الإنجليزية التي يراها المالك في التطبيق. */
    public function merchant_audit_presents_known_action_reason_and_branch_in_arabic(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_MERCHANT_PRO, $admin);

        $branch = \App\Models\Branch::create([
            'merchant_user_id' => $this->merchant->id,
            'name' => 'فرع التحرير', 'is_active' => true, 'is_default' => true,
        ]);
        app(AuditService::class)->record([
            'actor_type' => 'merchant', 'actor_user_id' => $this->merchant->id,
            'subject_type' => 'user', 'subject_id' => $this->merchant->id,
            'action' => 'PAYMENT_REQUEST_CANCELLED_BY_REQUESTER',
            'decision_code' => 'CANCELLED',
            'reason' => 'requester cancelled pending money request',
            'context' => ['branch_id' => $branch->id, 'employee_code' => 'EMP-10'],
        ]);

        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/audit-log')
            ->assertOk()
            ->assertJsonPath('meta.entries.0.action_label', 'ألغى صاحبُ الطلب طلبَ المال')
            ->assertJsonPath('meta.entries.0.decision_label', 'ملغىً')
            ->assertJsonPath('meta.entries.0.reason', 'ألغى صاحب الطلب طلب المال المعلّق')
            ->assertJsonPath('meta.entries.0.branch.name', 'فرع التحرير')
            ->assertJsonPath('meta.entries.0.details.0.label', 'معرّف الفرع');
    }
}
