<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The source used by the bootstrap/seed commands to expose only known demo
 * identities to the fixed OTP path.
 *
 * This deliberately inserts missing rows only. A compliance operator may
 * disable a number, or close the demo door entirely, from the OTP centre;
 * restarting the application must never silently re-open that access.
 */
final class DemoNumberRegistry
{
    /**
     * @param array<int,array{phone:string,label:string}> $numbers
     */
    public static function register(array $numbers): void
    {
        try {
            if (! Schema::hasTable('otp_demo_numbers')) {
                return;
            }

            $now = now();
            foreach ($numbers as $number) {
                $phone = preg_replace('/\D+/', '', (string) ($number['phone'] ?? ''));
                if ($phone === '' || strlen($phone) < 7) {
                    continue;
                }

                // insertOrIgnore preserves an administrator's disabled row.
                DB::table('otp_demo_numbers')->insertOrIgnore([
                    'phone' => $phone,
                    'label' => trim((string) ($number['label'] ?? '')) ?: null,
                    'is_active' => true,
                    'added_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            OtpPolicy::forget();
        } catch (\Throwable) {
            // Bootstrap commands must still be able to create the accounts on
            // an old database before the OTP-centre migration is applied.
        }
    }
}
