<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EmailIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AMIAL-EMAIL-IDENTITY-001
 *
 * Regression guard for the invariant: a real phone-backed account has one
 * canonical email, that email belongs to one account, and profile paths cannot
 * silently replace the recovery credential.
 */
class EmailIdentityGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function canonical_email_is_normalized_and_unique_across_accounts(): void
    {
        $first = User::factory()->create([
            'email' => '  Owner.One@Example.COM  ',
        ]);

        $this->assertSame(
            'owner.one@example.com',
            DB::table('users')->where('id', $first->id)->value('email_canonical'),
        );

        $this->expectException(ValidationException::class);

        User::factory()->create([
            'email' => 'owner.one@example.com',
        ]);
    }

    /** @test */
    public function direct_profile_email_replacement_is_blocked(): void
    {
        $user = User::factory()->create([
            'email' => 'original@example.com',
        ]);

        try {
            $user->email = 'attacker@example.com';
            $user->save();
            $this->fail('Direct email mutation must be rejected.');
        } catch (ValidationException) {
            $user->refresh();
            $this->assertSame('original@example.com', mb_strtolower(trim((string) $user->email)));
            $this->assertSame(
                'original@example.com',
                DB::table('users')->where('id', $user->id)->value('email_canonical'),
            );
        }
    }

    /** @test */
    public function otp_authorized_identity_service_can_replace_and_verify_email(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'is_email_verified' => 1,
        ]);

        $updated = app(EmailIdentityService::class)
            ->replaceVerifiedEmail($user, ' New.Address@Example.COM ');

        $this->assertSame('new.address@example.com', mb_strtolower(trim((string) $updated->email)));
        $this->assertSame('new.address@example.com', $updated->email_canonical);
        $this->assertSame(1, (int) $updated->is_email_verified);
    }

    /** @test */
    public function recovery_owner_must_be_verified_and_unique(): void
    {
        $identities = app(EmailIdentityService::class);

        $verified = User::factory()->create([
            'email' => 'verified@example.com',
            'is_email_verified' => 1,
        ]);
        $unverified = User::factory()->create([
            'email' => 'unverified@example.com',
            'is_email_verified' => 0,
        ]);

        $this->assertSame($verified->id, $identities->findVerifiedOwner('VERIFIED@example.com')?->id);
        $this->assertNull($identities->findVerifiedOwner('unverified@example.com'));
    }

    /** @test */
    public function real_phone_account_requires_email_but_internal_pos_identifier_does_not(): void
    {
        $this->expectException(ValidationException::class);

        $user = new User();
        $user->f_name = 'Real';
        $user->l_name = 'User';
        $user->phone = '967771234567';
        $user->password = bcrypt('password');
        $user->type = 2;
        $user->role = 'customer';
        $user->save();
    }

    /** @test */
    public function synthetic_pos_subaccount_can_exist_without_recovery_email(): void
    {
        $staff = new User();
        $staff->f_name = 'Cashier';
        $staff->l_name = '';
        $staff->phone = '9009000011234';
        $staff->password = bcrypt('password');
        $staff->type = 4;
        $staff->role = 'pos';
        $staff->is_active = 1;
        $staff->save();

        $this->assertNotNull($staff->id);
        $this->assertNull($staff->email_canonical);
    }
}
