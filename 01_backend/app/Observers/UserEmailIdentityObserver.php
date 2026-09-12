<?php

namespace App\Observers;

use App\Models\User;
use App\Services\EmailIdentityService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * AMIAL-EMAIL-IDENTITY-001
 *
 * Global guard: every User save path (customer, merchant, agent, admin, staff)
 * passes here, so legacy profile controllers cannot silently replace the email
 * recovery credential. Every new phone-backed account must have exactly one
 * unique email; changing an existing address requires the OTP-authorized flow.
 */
class UserEmailIdentityObserver
{
    public function __construct(private readonly EmailIdentityService $identities) {}

    public function creating(User $user): void
    {
        $email = $this->currentRawEmail($user);
        $attributes = $user->getAttributes();
        $phone = trim((string) ($attributes['phone'] ?? ''));

        if ($email === '') {
            if ($phone !== '') {
                throw ValidationException::withMessages([
                    'email' => ['البريد الإلكتروني مطلوب لكل حساب مرتبط برقم هاتف.'],
                ]);
            }

            if (Schema::hasColumn('users', 'email_canonical')) {
                $user->email_canonical = null;
            }
            return;
        }

        $this->assertValid($email);
        $this->identities->assertAvailable($email);

        if (Schema::hasColumn('users', 'email_canonical')) {
            $user->email_canonical = $this->identities->normalize($email);
        }
    }

    public function updating(User $user): void
    {
        $current = $this->currentRawEmail($user);
        $original = $this->identities->normalize((string) ($user->getRawOriginal('email') ?? ''));
        $normalized = $this->identities->normalize($current);
        $meaningfulChange = $normalized !== $original;

        if ($meaningfulChange && ! $this->identities->isMutationAuthorized($user)) {
            throw ValidationException::withMessages([
                'email' => [
                    'لا يمكن تغيير البريد الإلكتروني مباشرة. اطلب رمز تحقق للبريد الجديد ثم أكّد التغيير.',
                ],
            ]);
        }

        if ($current === '') {
            if ($meaningfulChange && Schema::hasColumn('users', 'email_canonical')) {
                $user->email_canonical = null;
            }
            return;
        }

        $this->assertValid($current);

        if ($meaningfulChange) {
            $this->identities->assertAvailable($current, (int) $user->id);
            if (Schema::hasColumn('users', 'email_canonical')) {
                $user->email_canonical = $normalized;
            }
            return;
        }

        // Gradually backfill a legacy unique account on any unrelated update.
        // If a historical duplicate exists, leave canonical NULL and revoke the
        // recovery trust flag rather than blocking all other account edits.
        if (Schema::hasColumn('users', 'email_canonical')
            && empty($user->getRawOriginal('email_canonical'))
        ) {
            if ($this->identities->isAvailable($current, (int) $user->id)) {
                $user->email_canonical = $normalized;
            } elseif (Schema::hasColumn('users', 'is_email_verified')) {
                $user->is_email_verified = 0;
            }
        }
    }

    private function currentRawEmail(User $user): string
    {
        $attributes = $user->getAttributes();
        return trim((string) ($attributes['email'] ?? ''));
    }

    private function assertValid(string $email): void
    {
        $normalized = $this->identities->normalize($email);
        if (mb_strlen($normalized) > 255 || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => ['صيغة البريد الإلكتروني غير صحيحة.'],
            ]);
        }
    }
}
