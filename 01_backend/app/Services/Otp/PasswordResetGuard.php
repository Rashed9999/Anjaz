<?php

namespace App\Services\Otp;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SEC-2026-11: استهلاك رمز استعادة كلمة المرور.
 *
 * كان `reset-password` يقبل الرمز بلا عدّ محاولات ولا انتهاء صلاحية (العدّ كان في
 * `verify-token` فقط)، فيُخمَّن الرمز مباشرةً ويُستولى على الحساب. هنا باب واحد:
 * قفل الصف، صلاحية 10 دقائق، حظر مؤقت بعد تجاوز المحاولات، ورمز يُستهلك مرة واحدة.
 */
class PasswordResetGuard
{
    public const TTL_SECONDS = 600;
    public const MAX_FAILS = 5;

    public function consume(string $phone, string $otp): bool
    {
        return DB::transaction(function () use ($phone, $otp): bool {
            $row = DB::table('password_resets')->where('phone', $phone)->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $blocked = (int) ($row->is_temp_blocked ?? 0) === 1
                && $row->temp_block_time !== null
                && Carbon::parse($row->temp_block_time)->diffInSeconds(now(), true) <= self::TTL_SECONDS;
            $expired = $row->created_at === null || Carbon::parse($row->created_at)->diffInSeconds(now(), true) > self::TTL_SECONDS;
            if ($blocked || $expired) {
                return false;
            }
            if (!hash_equals((string) $row->token, $otp)) {
                $fails = (int) ($row->otp_hit_count ?? 0) + 1;
                DB::table('password_resets')->where('phone', $phone)->update([
                    'otp_hit_count' => $fails,
                    'is_temp_blocked' => $fails >= self::MAX_FAILS ? 1 : 0,
                    'temp_block_time' => $fails >= self::MAX_FAILS ? now() : null,
                ]);

                return false;
            }
            DB::table('password_resets')->where('phone', $phone)->delete();

            return true;
        });
    }
}
