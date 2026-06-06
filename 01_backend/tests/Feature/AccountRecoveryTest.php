<?php

namespace Tests\Feature;

use App\Models\AccountRecoveryRequest;
use App\Models\AccountSecurityEvent;
use App\Models\User;
use App\Services\AccountRecoveryService;
use App\Services\TransactionPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-RECOVERY-001
 */
class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private AccountRecoveryService $svc;
    private TransactionPinService $pinSvc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(AccountRecoveryService::class);
        $this->pinSvc = app(TransactionPinService::class);
    }

    /** @test */
    public function customer_can_initiate_self_service_phone_change(): void
    {
        $user = User::factory()->create([
            'type' => 2,
            'phone' => '+967777111222',
            'zone_code' => 'SOUTH',
        ]);

        $req = $this->svc->initiateSelfServicePhoneChange(
            user: $user,
            newPhone: '+967777999888',
            ip: '1.2.3.4',
            userAgent: 'Test',
        );

        $this->assertSame('phone_change_self', $req->request_type);
        $this->assertSame('pending_otp', $req->status);
        $this->assertNotNull($req->otp_old_phone);
        $this->assertNotNull($req->otp_new_phone);
        $this->assertSame(6, strlen($req->otp_old_phone));
        $this->assertSame(6, strlen($req->otp_new_phone));
    }

    /** @test */
    public function agent_cannot_use_self_service(): void
    {
        $agent = User::factory()->create(['type' => 1, 'zone_code' => 'SOUTH']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('admin-mediated');
        $this->svc->initiateSelfServicePhoneChange(
            user: $agent,
            newPhone: '+967777999888',
            ip: '1.2.3.4',
            userAgent: 'Test',
        );
    }

    /** @test */
    public function duplicate_phone_is_rejected(): void
    {
        User::factory()->create(['phone' => '+967777999888', 'type' => 2]);
        $user = User::factory()->create(['phone' => '+967777111222', 'type' => 2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already in use');
        $this->svc->initiateSelfServicePhoneChange(
            user: $user,
            newPhone: '+967777999888',
            ip: '1.2.3.4',
            userAgent: 'Test',
        );
    }

    /** @test */
    public function verify_otp_success_marks_verified(): void
    {
        $user = User::factory()->create(['type' => 2, 'phone' => '+967777111', 'zone_code' => 'SOUTH']);
        $req = $this->svc->initiateSelfServicePhoneChange(
            $user, '+967777222', '1.2.3.4', 'Test'
        );

        // نأخذ القيم الحقيقية للـ OTPs (المُولّدة)
        $otpOld = $req->otp_old_phone;
        $otpNew = $req->otp_new_phone;

        $ok = $this->svc->verifyOtp($req, $otpOld, $otpNew);
        $this->assertTrue($ok);

        $req->refresh();
        $this->assertTrue($req->otp_old_verified);
        $this->assertTrue($req->otp_new_verified);
    }

    /** @test */
    public function verify_otp_fails_with_wrong_codes(): void
    {
        $user = User::factory()->create(['type' => 2, 'phone' => '+967777111', 'zone_code' => 'SOUTH']);
        $req = $this->svc->initiateSelfServicePhoneChange(
            $user, '+967777222', '1.2.3.4', 'Test'
        );

        $ok = $this->svc->verifyOtp($req, '000000', '000000');
        $this->assertFalse($ok);
    }

    /** @test */
    public function complete_requires_verified_otp_and_correct_pin(): void
    {
        $user = User::factory()->create(['type' => 2, 'phone' => '+967777111', 'zone_code' => 'SOUTH']);
        $this->pinSvc->setPin($user, '4827');
        $user->refresh();

        $req = $this->svc->initiateSelfServicePhoneChange(
            $user, '+967777222', '1.2.3.4', 'Test'
        );

        // محاولة إكمال قبل verify OTP — يفشل
        $ok = $this->svc->completeSelfServiceChange($req, '4827');
        $this->assertFalse($ok);

        // verify OTP
        $this->svc->verifyOtp($req, $req->otp_old_phone, $req->otp_new_phone);
        $req->refresh();

        // محاولة إكمال بـ PIN خاطئ — يفشل
        $ok = $this->svc->completeSelfServiceChange($req, '9999');
        $this->assertFalse($ok);
        $req->refresh();
        $this->assertSame('pending_otp', $req->status); // لم يتغير

        // محاولة إكمال بـ PIN صحيح — ينجح
        $ok = $this->svc->completeSelfServiceChange($req, '4827');
        $this->assertTrue($ok);
        $req->refresh();
        $this->assertSame('approved', $req->status);
    }

    /** @test */
    public function complete_applies_security_hold(): void
    {
        $user = User::factory()->create(['type' => 2, 'phone' => '+967777111', 'zone_code' => 'SOUTH']);
        $this->pinSvc->setPin($user, '4827');
        $user->refresh();

        $req = $this->svc->initiateSelfServicePhoneChange(
            $user, '+967777222', '1.2.3.4', 'Test'
        );
        $this->svc->verifyOtp($req, $req->otp_old_phone, $req->otp_new_phone);
        $req->refresh();

        $this->svc->completeSelfServiceChange($req, '4827');

        $user->refresh();
        $this->assertNotNull($user->security_hold_until);
        $this->assertTrue($user->security_hold_until->isFuture());
        $this->assertSame('phone_change_self', $user->security_hold_reason);
    }

    /** @test */
    public function complete_creates_security_event(): void
    {
        $user = User::factory()->create(['type' => 2, 'phone' => '+967777111', 'zone_code' => 'SOUTH']);
        $this->pinSvc->setPin($user, '4827');
        $user->refresh();

        $req = $this->svc->initiateSelfServicePhoneChange(
            $user, '+967777222', '1.2.3.4', 'Test'
        );
        $this->svc->verifyOtp($req, $req->otp_old_phone, $req->otp_new_phone);
        $req->refresh();
        $this->svc->completeSelfServiceChange($req, '4827');

        $events = AccountSecurityEvent::where('user_id', $user->id)->get();
        $this->assertGreaterThan(0, $events->count());
        $this->assertTrue($events->pluck('event_type')->contains('PHONE_CHANGED_PENDING'));
    }

    /** @test */
    public function lost_phone_initiates_pending_review_request(): void
    {
        $user = User::factory()->create(['type' => 2, 'phone' => '+967777111', 'zone_code' => 'SOUTH']);

        $req = $this->svc->initiateLostPhoneRecovery(
            user: $user,
            newPhone: '+967777222',
            identificationDocuments: ['doc1.jpg', 'doc2.jpg'],
            userNotes: 'فقدت هاتفي',
            ip: '1.2.3.4',
            userAgent: 'Test',
        );

        $this->assertSame('phone_change_lost_phone', $req->request_type);
        $this->assertSame('pending_review', $req->status);
        $this->assertNull($req->otp_old_phone); // لا OTP للقديم
    }

    /** @test */
    public function admin_can_approve_lost_phone_request(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2, 'phone' => '+967777111', 'zone_code' => 'SOUTH']);

        $req = $this->svc->initiateLostPhoneRecovery(
            $user, '+967777222', ['d.jpg'], 'note', '1.2.3.4', 'Test'
        );

        $ok = $this->svc->adminApprove($req, $admin->id, 'مراجعة ناجحة');

        $this->assertTrue($ok);
        $req->refresh();
        $user->refresh();

        $this->assertSame('approved', $req->status);
        $this->assertSame($admin->id, $req->reviewed_by);
        $this->assertNotNull($user->security_hold_until);
        $this->assertSame('phone_change_admin_approved', $user->security_hold_reason);
    }

    /** @test */
    public function admin_can_reject_lost_phone_request(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2, 'phone' => '+967777111', 'zone_code' => 'SOUTH']);

        $req = $this->svc->initiateLostPhoneRecovery(
            $user, '+967777222', ['d.jpg'], 'note', '1.2.3.4', 'Test'
        );

        $ok = $this->svc->adminReject($req, $admin->id, 'مستندات غير كافية');

        $this->assertTrue($ok);
        $req->refresh();
        $user->refresh();

        $this->assertSame('rejected', $req->status);
        $this->assertNull($user->security_hold_until); // لم يُفعَّل hold
    }

    /** @test */
    public function apply_approved_change_actually_changes_phone_after_hold(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2, 'phone' => '+967111', 'zone_code' => 'SOUTH']);

        $req = $this->svc->initiateLostPhoneRecovery(
            $user, '+967222', ['d.jpg'], null, '1.2.3.4', 'T'
        );
        $this->svc->adminApprove($req, $admin->id, null);

        // نضع hold في الماضي (يحاكي انتهاء الـ hold)
        $user->refresh();
        $user->update(['security_hold_until' => now()->subMinute()]);

        $applied = $this->svc->applyApprovedChange($req);
        $this->assertTrue($applied);

        $user->refresh();
        $this->assertSame('+967222', $user->phone);
        $this->assertNull($user->security_hold_until);
    }

    /** @test */
    public function apply_approved_change_fails_during_active_hold(): void
    {
        $admin = User::factory()->create(['type' => 0]);
        $user = User::factory()->create(['type' => 2, 'phone' => '+967111', 'zone_code' => 'SOUTH']);

        $req = $this->svc->initiateLostPhoneRecovery(
            $user, '+967222', ['d.jpg'], null, '1.2.3.4', 'T'
        );
        $this->svc->adminApprove($req, $admin->id, null);

        // الـ hold ساري بـ default
        $applied = $this->svc->applyApprovedChange($req);
        $this->assertFalse($applied);

        $user->refresh();
        $this->assertSame('+967111', $user->phone); // لم يتغير
    }

    /** @test */
    public function risk_score_is_higher_for_new_unverified_user(): void
    {
        $newUser = User::factory()->create([
            'type' => 2,
            'phone' => '+967111',
            'zone_code' => 'SOUTH',
            'created_at' => now()->subDays(5), // < 30 يوم
            'is_kyc_verified' => false,
        ]);
        $this->pinSvc->setPin($newUser, '4827');
        $newUser->refresh();

        $req = $this->svc->initiateSelfServicePhoneChange(
            $newUser, '+967222', '1.2.3.4', 'T'
        );
        $this->svc->verifyOtp($req, $req->otp_old_phone, $req->otp_new_phone);
        $req->refresh();
        $this->svc->completeSelfServiceChange($req, '4827');

        $req->refresh();
        // 30 (حديث) + 25 (لا KYC) = 55
        $this->assertGreaterThanOrEqual(55, $req->risk_score);
    }
}
