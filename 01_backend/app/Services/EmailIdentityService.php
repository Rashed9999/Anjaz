<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * AMIAL-EMAIL-IDENTITY-001
 *
 * Central authority for email identity. An email is not merely profile text: it
 * authorizes password/PIN recovery. Therefore:
 *  - one canonical email belongs to at most one user;
 *  - recovery only trusts a verified canonical owner;
 *  - changing an existing email is allowed only inside an OTP-authorized flow;
 *  - the DB unique index is still the final race-condition guard.
 */
class EmailIdentityService
{
    /** @var array<string, true> */
    private array $authorizedMutations = [];

    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function isAvailable(string $email, ?int $excludeUserId = null): bool
    {
        $email = $this->normalize($email);
        if ($email === '') {
            return false;
        }

        $query = User::withTrashed();
        if ($excludeUserId !== null) {
            // Keep this intentionally explicit instead of relying on a framework
            // helper: this security invariant must behave identically across the
            // supported Laravel/Builder versions used by deployment and tests.
            $query->where('id', '!=', $excludeUserId);
        }

        $query->where(function ($q) use ($email): void {
            if (Schema::hasColumn('users', 'email_canonical')) {
                $q->where('email_canonical', $email)
                    ->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
            } else {
                $q->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
            }
        });

        return ! $query->exists();
    }

    public function assertAvailable(string $email, ?int $excludeUserId = null): void
    {
        if (! $this->isAvailable($email, $excludeUserId)) {
            throw ValidationException::withMessages([
                'email' => ['البريد الإلكتروني مرتبط بحساب آخر ولا يمكن استخدامه لهذا الحساب.'],
            ]);
        }
    }

    /**
     * Returns exactly one active, verified canonical owner. Legacy duplicate
     * rows are intentionally rejected rather than choosing "the first" user.
     */
    public function findVerifiedOwner(string $email): ?User
    {
        $email = $this->normalize($email);
        if ($email === '') {
            return null;
        }

        $query = User::query()
            ->whereNull('deleted_at')
            ->where('is_email_verified', 1);

        if (Schema::hasColumn('users', 'email_canonical')) {
            $query->where('email_canonical', $email);
        } else {
            $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
        }

        $matches = $query->limit(2)->get();
        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function matchesVerifiedUser(User $user, string $email): bool
    {
        if ($user->trashed() || (int) ($user->is_email_verified ?? 0) !== 1) {
            return false;
        }

        $canonical = $this->normalize($email);
        $stored = $this->normalize((string) ($user->email ?? ''));
        if ($canonical === '' || $canonical !== $stored) {
            return false;
        }

        if (Schema::hasColumn('users', 'email_canonical')) {
            $identity = $this->normalize((string) ($user->getRawOriginal('email_canonical') ?? $user->email_canonical ?? ''));
            if ($identity === '' || $identity !== $canonical) {
                return false;
            }
        }

        // This catches pre-migration or partially migrated duplicate legacy rows.
        return $this->isAvailable($canonical, (int) $user->id);
    }

    public function isMutationAuthorized(User $user): bool
    {
        return isset($this->authorizedMutations[$this->mutationKey($user)]);
    }

    /** @template T */
    public function authorizeMutation(User $user, Closure $callback): mixed
    {
        $key = $this->mutationKey($user);
        $this->authorizedMutations[$key] = true;

        try {
            return $callback();
        } finally {
            unset($this->authorizedMutations[$key]);
        }
    }

    /**
     * Marks the current email as verified after a cryptographically verified
     * registration challenge. No ownership transfer occurs here.
     */
    public function markCurrentEmailVerified(User $user): User
    {
        $email = $this->normalize((string) ($user->email ?? ''));
        if ($email === '') {
            throw ValidationException::withMessages(['email' => ['البريد الإلكتروني مطلوب.']]);
        }

        $this->assertAvailable($email, (int) $user->id);

        return DB::transaction(function () use ($user, $email): User {
            /** @var User $locked */
            $locked = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertAvailable($email, (int) $locked->id);

            $this->authorizeMutation($locked, function () use ($locked, $email): void {
                // Normalize the stored credential at the moment it becomes trusted.
                $locked->email = $email;
                if (Schema::hasColumn('users', 'email_canonical')) {
                    $locked->email_canonical = $email;
                }
                if (Schema::hasColumn('users', 'is_email_verified')) {
                    $locked->is_email_verified = 1;
                }
                if (Schema::hasColumn('users', 'email_verified_at')) {
                    $locked->email_verified_at = now();
                }
                $locked->save();
            });

            return $locked->refresh();
        });
    }

    /**
     * Transfers the account's recovery credential to a newly OTP-verified email.
     * The caller must already have verified the email-change challenge.
     */
    public function replaceVerifiedEmail(User $user, string $newEmail): User
    {
        $newEmail = $this->normalize($newEmail);
        if (! filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => ['صيغة البريد الإلكتروني غير صحيحة.']]);
        }

        $this->assertAvailable($newEmail, (int) $user->id);

        return DB::transaction(function () use ($user, $newEmail): User {
            /** @var User $locked */
            $locked = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->trashed()) {
                throw ValidationException::withMessages(['email' => ['الحساب غير نشط.']]);
            }

            $this->assertAvailable($newEmail, (int) $locked->id);

            $this->authorizeMutation($locked, function () use ($locked, $newEmail): void {
                $locked->email = $newEmail;
                if (Schema::hasColumn('users', 'email_canonical')) {
                    $locked->email_canonical = $newEmail;
                }
                if (Schema::hasColumn('users', 'is_email_verified')) {
                    $locked->is_email_verified = 1;
                }
                if (Schema::hasColumn('users', 'email_verified_at')) {
                    $locked->email_verified_at = now();
                }
                $locked->save();
            });

            return $locked->refresh();
        });
    }

    private function mutationKey(User $user): string
    {
        return $user->exists && $user->getKey() !== null
            ? 'user:' . (string) $user->getKey()
            : 'object:' . (string) spl_object_id($user);
    }
}
