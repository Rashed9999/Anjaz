<?php

namespace App\Services\Otp;

use App\Support\Phone;
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
        // الرقم يصل بصيغٍ عدّة (+967… · 967… · 00967… · 7…) فيُبحث بكلّها، وإلا رُفض رمزٌ صحيح أو أفلت عدّاد المحاولات.
        $variants = Phone::variants($phone);
        if ($variants === []) {
            return false;
        }

        return DB::transaction(function () use ($variants, $otp): bool {
            $row = DB::table('password_resets')->whereIn('phone', $variants)->orderByDesc('created_at')->lockForUpdate()->first();
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
                DB::table('password_resets')->whereIn('phone', $variants)->update([
                    'otp_hit_count' => $fails,
                    'is_temp_blocked' => $fails >= self::MAX_FAILS ? 1 : 0,
                    'temp_block_time' => $fails >= self::MAX_FAILS ? now() : null,
                ]);

                return false;
            }
            DB::table('password_resets')->whereIn('phone', $variants)->delete();

            return true;
        });
    }
}
