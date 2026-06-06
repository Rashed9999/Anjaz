<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FamilyFund;
use App\Models\FamilyFundMember;
use App\Models\FamilyFundTransaction;
use App\Models\User;
use App\Services\FamilyFundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-FUND-FAMILY-001 (v0.9-B) — اختبارات.
 */
class FamilyFundServiceTest extends TestCase
{
    use RefreshDatabase;

    private FamilyFundService $service;
    private User $owner;
    private User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // PDF receipts عبر queue

        $this->service = app(FamilyFundService::class);

        $this->owner = User::factory()->create([
            'zone_code' => 'SOUTH',
            'phone' => '+967700000001',
        ]);
        EMoney::create(['user_id' => $this->owner->id, 'current_balance' => '1000.0000']);

        $this->memberUser = User::factory()->create([
            'zone_code' => 'SOUTH',
            'phone' => '+967700000002',
        ]);
        EMoney::create(['user_id' => $this->memberUser->id, 'current_balance' => '500.0000']);
    }

    /** @test */
    public function it_creates_fund_with_owner_as_active_member()
    {
        $fund = $this->service->create($this->owner, 'Family Pool', 'Test fund');

        $this->assertInstanceOf(FamilyFund::class, $fund);
        $this->assertEquals('Family Pool', $fund->name);
        $this->assertEquals($this->owner->id, $fund->owner_user_id);
        $this->assertEquals('0.0000', (string)$fund->balance);
        $this->assertEquals('SOUTH', $fund->zone_code);

        // Owner مضاف كعضو نشط تلقائياً
        $ownerMembership = FamilyFundMember::where('fund_id', $fund->id)
            ->where('user_id', $this->owner->id)
            ->first();
        $this->assertNotNull($ownerMembership);
        $this->assertEquals('owner', $ownerMembership->role);
        $this->assertEquals('active', $ownerMembership->status);
    }

    /** @test */
    public function it_rejects_creation_from_non_south_users()
    {
        $north = User::factory()->create(['zone_code' => 'NORTH']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOUTH');

        $this->service->create($north, 'Test');
    }

    /** @test */
    public function it_invites_a_registered_user_with_invited_status()
    {
        $fund = $this->service->create($this->owner, 'Test Fund');

        $membership = $this->service->inviteMember(
            $fund, $this->owner, $this->memberUser->phone, 'member',
        );

        $this->assertEquals($this->memberUser->id, $membership->user_id);
        $this->assertEquals('member', $membership->role);
        $this->assertEquals('invited', $membership->status);
    }

    /** @test */
    public function it_rejects_invitation_to_unregistered_phone()
    {
        $fund = $this->service->create($this->owner, 'Test Fund');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not registered');

        $this->service->inviteMember($fund, $this->owner, '+967700009999');
    }

    /** @test */
    public function it_rejects_invitation_from_non_owner_non_admin()
    {
        $fund = $this->service->create($this->owner, 'Test Fund');
        $member = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);
        $this->service->acceptInvitation($member, $this->memberUser);

        // member يحاول دعوة - يجب أن يفشل
        $thirdUser = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700000003']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only owner or admin');

        $this->service->inviteMember($fund, $this->memberUser, $thirdUser->phone);
    }

    /** @test */
    public function accept_invitation_moves_membership_to_active()
    {
        $fund = $this->service->create($this->owner, 'Test Fund');
        $membership = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);

        $accepted = $this->service->acceptInvitation($membership, $this->memberUser);

        $this->assertTrue($accepted);
        $membership->refresh();
        $this->assertEquals('active', $membership->status);
        $this->assertNotNull($membership->joined_at);
    }

    /** @test */
    public function contribute_debits_user_wallet_and_credits_fund()
    {
        $fund = $this->service->create($this->owner, 'Test Fund');
        $membership = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);
        $this->service->acceptInvitation($membership, $this->memberUser);

        $tx = $this->service->contribute($fund, $this->memberUser, '100.0000', 'Lunch money');

        // المحفظة خُصمت
        $wallet = EMoney::where('user_id', $this->memberUser->id)->first();
        $this->assertEquals('400.0000', (string)$wallet->current_balance); // 500 - 100

        // الصندوق ارتفع
        $fund->refresh();
        $this->assertEquals('100.0000', (string)$fund->balance);

        // tx مُسجَّل
        $this->assertInstanceOf(FamilyFundTransaction::class, $tx);
        $this->assertEquals('contribute', $tx->tx_type);
        $this->assertEquals('100.0000', (string)$tx->amount);
        $this->assertEquals('0.0000', (string)$tx->balance_before);
        $this->assertEquals('100.0000', (string)$tx->balance_after);
        $this->assertEquals('completed', $tx->status);
        $this->assertEquals('Lunch money', $tx->note);

        // إحصاء العضو محدَّث
        $membership->refresh();
        $this->assertEquals('100.0000', (string)$membership->total_contributed);
    }

    /** @test */
    public function contribute_rejects_non_active_member()
    {
        $fund = $this->service->create($this->owner, 'Test Fund');
        // memberUser لم يقبل بعد

        $this->expectException(\RuntimeException::class);

        $this->service->contribute($fund, $this->memberUser, '50.0000');
    }

    /** @test */
    public function contribute_rejects_insufficient_balance()
    {
        $fund = $this->service->create($this->owner, 'Test Fund');
        $membership = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);
        $this->service->acceptInvitation($membership, $this->memberUser);

        // memberUser رصيده 500 - حاول 600
        $this->expectException(\App\Exceptions\InsufficientBalanceException::class);
        $this->service->contribute($fund, $this->memberUser, '600.0000');

        // المحفظة لم تتأثر
        $wallet = EMoney::where('user_id', $this->memberUser->id)->first();
        $this->assertEquals('500.0000', (string)$wallet->current_balance);
    }

    /** @test */
    public function propose_disbursement_creates_pending_when_require_approval_true()
    {
        $fund = $this->service->create($this->owner, 'Test Fund', null, true); // require approval
        $membership = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);
        $this->service->acceptInvitation($membership, $this->memberUser);
        $this->service->contribute($fund, $this->memberUser, '300.0000');

        // member يقترح صرف لنفسه - يحتاج owner approval
        $tx = $this->service->proposeDisbursement(
            $fund, $this->memberUser, $this->memberUser, '50.0000', 'Emergency',
        );

        $this->assertEquals('pending_approval', $tx->status);

        // الصندوق لم يُخصم بعد
        $fund->refresh();
        $this->assertEquals('300.0000', (string)$fund->balance);

        // المحفظة لم تُضف بعد
        $wallet = EMoney::where('user_id', $this->memberUser->id)->first();
        $this->assertEquals('200.0000', (string)$wallet->current_balance); // 500 - 300
    }

    /** @test */
    public function approve_disbursement_actually_moves_money()
    {
        $fund = $this->service->create($this->owner, 'Test Fund', null, true);
        $membership = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);
        $this->service->acceptInvitation($membership, $this->memberUser);
        $this->service->contribute($fund, $this->memberUser, '300.0000');

        $tx = $this->service->proposeDisbursement(
            $fund, $this->memberUser, $this->memberUser, '50.0000',
        );

        // owner يوافق
        $result = $this->service->approveDisbursement($tx, $this->owner);

        $this->assertTrue($result);

        $tx->refresh();
        $this->assertEquals('completed', $tx->status);
        $this->assertEquals($this->owner->id, $tx->approved_by_user_id);

        // الصندوق خُصم
        $fund->refresh();
        $this->assertEquals('250.0000', (string)$fund->balance); // 300 - 50

        // المحفظة أُضيف لها
        $wallet = EMoney::where('user_id', $this->memberUser->id)->first();
        $this->assertEquals('250.0000', (string)$wallet->current_balance); // 200 + 50
    }

    /** @test */
    public function reject_disbursement_does_not_move_money()
    {
        $fund = $this->service->create($this->owner, 'Test Fund', null, true);
        $membership = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);
        $this->service->acceptInvitation($membership, $this->memberUser);
        $this->service->contribute($fund, $this->memberUser, '300.0000');

        $tx = $this->service->proposeDisbursement(
            $fund, $this->memberUser, $this->memberUser, '50.0000',
        );

        $this->service->rejectDisbursement($tx, $this->owner, 'Not justified');

        $tx->refresh();
        $this->assertEquals('rejected', $tx->status);

        // الصندوق لم يتغير
        $fund->refresh();
        $this->assertEquals('300.0000', (string)$fund->balance);

        // المحفظة لم تتغير
        $wallet = EMoney::where('user_id', $this->memberUser->id)->first();
        $this->assertEquals('200.0000', (string)$wallet->current_balance);
    }

    /** @test */
    public function non_owner_cannot_approve_disbursement()
    {
        $fund = $this->service->create($this->owner, 'Test Fund', null, true);
        $membership = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);
        $this->service->acceptInvitation($membership, $this->memberUser);
        $this->service->contribute($fund, $this->memberUser, '300.0000');

        $tx = $this->service->proposeDisbursement(
            $fund, $this->memberUser, $this->memberUser, '50.0000',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only owner can approve');

        // member يحاول الموافقة على اقتراحه - يجب أن يفشل
        $this->service->approveDisbursement($tx, $this->memberUser);
    }

    /** @test */
    public function family_fund_transaction_is_append_only()
    {
        $fund = $this->service->create($this->owner, 'Test Fund');
        $membership = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);
        $this->service->acceptInvitation($membership, $this->memberUser);
        $tx = $this->service->contribute($fund, $this->memberUser, '50.0000');

        // محاولة تعديل note مرفوضة
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $tx->update(['note' => 'Modified after the fact']);
    }

    /** @test */
    public function family_fund_transaction_cannot_be_deleted()
    {
        $fund = $this->service->create($this->owner, 'Test Fund');
        $membership = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);
        $this->service->acceptInvitation($membership, $this->memberUser);
        $tx = $this->service->contribute($fund, $this->memberUser, '50.0000');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $tx->delete();
    }

    /** @test */
    public function owner_disbursement_is_direct_when_require_approval_true_but_owner_self()
    {
        // قاعدة في الكود: needsApproval = require_owner_approval AND proposer != owner
        $fund = $this->service->create($this->owner, 'Test Fund', null, true);
        $membership = $this->service->inviteMember($fund, $this->owner, $this->memberUser->phone);
        $this->service->acceptInvitation($membership, $this->memberUser);
        $this->service->contribute($fund, $this->memberUser, '300.0000');

        // owner نفسه يقترح - تنفيذ مباشر
        $tx = $this->service->proposeDisbursement(
            $fund, $this->owner, $this->memberUser, '20.0000',
        );

        $this->assertEquals('completed', $tx->status);

        $fund->refresh();
        $this->assertEquals('280.0000', (string)$fund->balance);
    }
}
