<?php

namespace Tests\Feature;

use App\Models\AdminCenter\MerchantAdminAction;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\PlatformTabAccessService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SAHER-SECUR-0033: reading merchant information must never grant note creation. */
class MerchantNoteAuthorizationTest extends TestCase
{
    use \Tests\Concerns\ExercisesArchivedMerchantAdmin;
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 3,
            'role' => A::ROLE_MERCHANT,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);
    }

    private function operatorWith(array $permissions): User
    {
        $operator = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'staff',
            'phone' => '967770009801',
            'is_active' => 1,
        ]);
        $granter = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'staff',
            'phone' => '967770009802',
            'is_active' => 1,
        ]);

        app(PlatformTabAccessService::class)->sync($operator, [], $granter->id, $permissions);

        return $operator->fresh();
    }

    private function endpoint(): string
    {
        return '/admin/amial/merchant-center/'.$this->merchant->id.'/note';
    }

    private function overview(): string
    {
        return '/admin/amial/merchant-center/'.$this->merchant->id.'/overview';
    }

    public function test_read_only_operator_cannot_add_note_or_get_a_note_button(): void
    {
        $operator = $this->operatorWith(['platform.customers.view']);

        $this->actingAs($operator, 'user')->getJson($this->overview())
            ->assertOk()->assertJsonPath('data.can.note', false);

        $this->actingAs($operator, 'user')->postJson($this->endpoint(), [
            'reason' => 'متابعة طلب التاجر رقم 123',
        ])->assertForbidden();

        $this->assertSame(0, MerchantAdminAction::query()
            ->where('merchant_user_id', $this->merchant->id)
            ->where('action', 'note.add')->count());
    }

    public function test_operator_with_read_and_note_write_can_add_audited_note(): void
    {
        $operator = $this->operatorWith([
            'platform.customers.view', 'platform.customers.notes.create',
        ]);

        $this->actingAs($operator, 'user')->getJson($this->overview())
            ->assertOk()->assertJsonPath('data.can.note', true);

        $this->actingAs($operator, 'user')->postJson($this->endpoint(), [
            'reason' => 'متابعة طلب التاجر رقم 456',
        ])->assertOk();

        $action = MerchantAdminAction::query()
            ->where('merchant_user_id', $this->merchant->id)
            ->where('action', 'note.add')->sole();

        $this->assertSame($operator->id, $action->actor_admin_id);
        $this->assertSame('متابعة طلب التاجر رقم 456', $action->reason);
        $this->assertSame('ok', $action->result);
    }

    public function test_note_write_permission_does_not_bypass_merchant_read_gate(): void
    {
        $operator = $this->operatorWith(['platform.customers.notes.create']);

        $this->actingAs($operator, 'user')->postJson($this->endpoint(), [
            'reason' => 'إضافة بدون إذن الاطلاع',
        ])->assertForbidden();

        $this->assertSame(0, MerchantAdminAction::query()
            ->where('merchant_user_id', $this->merchant->id)
            ->where('action', 'note.add')->count());
    }

    public function test_permissions_are_not_inferred_from_hiding_the_ui_button(): void
    {
        $operator = $this->operatorWith(['platform.customers.view']);

        $this->actingAs($operator, 'user')->postJson($this->endpoint(), [
            'reason' => 'طلب مباشر خارج الواجهة',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('merchant_admin_actions', [
            'merchant_user_id' => $this->merchant->id,
            'action' => 'note.add',
        ]);
    }
}
