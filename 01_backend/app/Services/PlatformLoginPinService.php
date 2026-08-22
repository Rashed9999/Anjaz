<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * AMIAL-PLATFORM-LOGIN-PIN-001
 *
 * PIN دخول موظفي المنصّة منفصل تماماً عن transaction_pin الخاص بالعميل.
 * لا تُخزّن القيمة الصريحة أبداً، ولا تدخل في API responses.
 */
class PlatformLoginPinService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;

    public function __construct(private readonly PlatformRoleService $roles)
    {
    }

    public function generate(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function issue(
        User $user,
        string $pin,
        ?int $issuedByUserId,
        string $reason,
        bool $mustChange = false,
        string $deliveryStatus = 'pending',
    ): void {
        if ((int) $user->type !== ADMIN_TYPE) {
            throw new \InvalidArgumentException('Platform login PIN is only for admin/operator accounts');
        }
        if (! preg_match('/^\d{4}$/', $pin)) {
            throw new \InvalidArgumentException('Platform login PIN must contain exactly four digits');
        }

        $values = [
            'pin_hash' => Hash::make($pin),
            'must_change' => $mustChange,
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_verified_at' => null,
            'issued_by_user_id' => $issuedByUserId,
            'issued_reason' => mb_substr($reason, 0, 40),
            'delivery_status' => $deliveryStatus,
            'delivered_at' => $deliveryStatus === 'sent' ? now() : null,
            'delivery_failed_at' => $deliveryStatus === 'failed' ? now() : null,
            'updated_at' => now(),
        ];

        if ($this->exists($user->id)) {
            DB::table('platform_login_pins')->where('user_id', $user->id)->update($values);
            return;
        }

        DB::table('platform_login_pins')->insert($values + [
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
    }

    /**
     * fallback لحساب الجذر إذا شُغلت الهجرة قبل وجوده أو أُنشئ من بذرة لاحقة.
     * لا نعمّم 1234 على كل platform_admin: الموظف الجديد يأخذ PIN عشوائياً.
     */
    public function ensureBootstrapCredential(User $user): bool
    {
        if ($this->exists($user->id)) {
            return true;
        }

        $rootAdminId = (int) (User::query()->where('type', ADMIN_TYPE)->min('id') ?: 0);
        if ($rootAdminId <= 0 || (int) $user->id !== $rootAdminId) {
            return false;
        }

        if (! $this->roles->has($user, PlatformRoleService::ADMIN)) {
            return false;
        }

        $this->issue(
            $user,
            '1234',
            null,
            'bootstrap_admin_default',
            mustChange: true,
            deliveryStatus: 'not_required',
        );

        return true;
    }

    /**
     * @return array{ok:bool,reason:string,retry_after?:int}
     */
    public function verify(User $user, string $pin): array
    {
        if (! $this->exists($user->id) && ! $this->ensureBootstrapCredential($user)) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        return DB::transaction(function () use ($user, $pin) {
            $row = DB::table('platform_login_pins')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return ['ok' => false, 'reason' => 'not_configured'];
            }

            if ($row->locked_until) {
                $lockedUntil = \Illuminate\Support\Carbon::parse($row->locked_until);
                if (now()->lt($lockedUntil)) {
                    return [
                        'ok' => false,
                        'reason' => 'locked',
                        'retry_after' => max(1, (int) now()->diffInSeconds($lockedUntil)),
                    ];
                }
            }

            if (Hash::check($pin, (string) $row->pin_hash)) {
                DB::table('platform_login_pins')->where('user_id', $user->id)->update([
                    'failed_attempts' => 0,
                    'locked_until' => null,
                    'last_verified_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['ok' => true, 'reason' => 'ok'];
            }

            $attempts = min(255, ((int) $row->failed_attempts) + 1);
            $lockedUntil = $attempts >= self::MAX_ATTEMPTS
                ? now()->addMinutes(self::LOCK_MINUTES)
                : null;

            DB::table('platform_login_pins')->where('user_id', $user->id)->update([
                'failed_attempts' => $attempts,
                'locked_until' => $lockedUntil,
                'updated_at' => now(),
            ]);

            return [
                'ok' => false,
                'reason' => $lockedUntil ? 'locked' : 'invalid',
                'retry_after' => $lockedUntil ? self::LOCK_MINUTES * 60 : 0,
            ];
        });
    }

    public function markDelivered(int $userId): void
    {
        DB::table('platform_login_pins')->where('user_id', $userId)->update([
            'delivery_status' => 'sent',
            'delivered_at' => now(),
            'delivery_failed_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function markDeliveryFailed(int $userId): void
    {
        DB::table('platform_login_pins')->where('user_id', $userId)->update([
            'delivery_status' => 'failed',
            'delivery_failed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function clearMustChange(int $userId): void
    {
        DB::table('platform_login_pins')->where('user_id', $userId)->update([
            'must_change' => false,
            'updated_at' => now(),
        ]);
    }

    public function exists(int $userId): bool
    {
        return DB::table('platform_login_pins')->where('user_id', $userId)->exists();
    }

    /** @return array<int,object> keyed by user_id */
    public function statusForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('platform_login_pins')
            ->whereIn('user_id', array_values(array_unique(array_map('intval', $userIds))))
            ->get([
                'user_id', 'must_change', 'failed_attempts', 'locked_until',
                'delivery_status', 'delivered_at', 'delivery_failed_at', 'issued_reason',
            ])
            ->keyBy('user_id')
            ->all();
    }
}
