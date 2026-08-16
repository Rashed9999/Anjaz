<?php

namespace App\Services\Merchant;

use App\Models\Merchant\PosDevice;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-POS-DEVICES-001 — **تسجيلُ الجهاز ≠ مصادقةُ الموظّف.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحدُّ يُفرَض عند التسجيل وحدَه.**
 *
 * فرضُه عند كلّ دخولٍ يُقفل المتجرَ على موظّفٍ يبدّل ورديّتَه، والحدُّ
 * حدُّ **مقاعدَ** لا حدُّ جلسات. فمن سجّل جهازَه أمسِ يدخل اليوم بلا سؤال.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والسباقُ يُحسم في القاعدة لا في PHP.**
 *
 * طلبان متزامنان على آخر مقعد: كلاهما يقرأ «مشغولٌ ٢ من ٣» فيمرّان،
 * فيصير ٤. **والقراءةُ ثمّ الكتابةُ في خطوتين لا تنجو من التوازي مهما
 * دُقّق ترتيبُها** — وهذا مكتوبٌ في قواعد المشروع بثمنٍ دُفع.
 *
 * فالحدُّ داخل **معاملةٍ بقفلٍ على صفّ التاجر**: من أمسك القفلَ عدّ
 * وأدخل وأفلت، والثاني يعدّ بعده فيرى الحقيقةَ الجديدة.
 *
 * **ومعه قيدُ القاعدة الفريد** `UNIQUE(merchant_user_id, device_uuid_hash)`
 * — فطلبان بالبصمة نفسِها لا يُنتجان مقعدين ولو أفلت القفلُ يوماً.
 * (حزامان: أحدُهما يمنع الزيادة، والآخرُ يمنع التكرار.)
 */
class PosDeviceRegistrar
{
    /** أُعيد تسجيلُ جهازٍ معروف — لم يُستهلَك مقعدٌ جديد. */
    public const RESULT_EXISTING = 'existing';

    /** مقعدٌ جديدٌ شُغل. */
    public const RESULT_REGISTERED = 'registered';

    /** الحدُّ مستنفَد. */
    public const RESULT_LIMIT = 'limit_reached';

    /**
     * يُسجّل جهازاً أو يُعيد المعروفَ منها.
     *
     * @return array{result:string, device:?PosDevice, used:int, max:int}
     */
    public function register(
        User $merchant,
        string $deviceUuid,
        array $attributes = [],
    ): array {
        $hash = PosDevice::hashUuid($deviceUuid);
        $max = $this->maxSeats($merchant);

        return DB::transaction(function () use ($merchant, $deviceUuid, $hash, $max, $attributes) {
            // **القفلُ على صفّ التاجر** — لا على جدول الأجهزة: فالمقعدُ
            // مورِدُ التاجر، والمتنافسون عليه طلباتُه هو.
            DB::table('users')->where('id', $merchant->id)->lockForUpdate()->first();

            $existing = PosDevice::where('merchant_user_id', $merchant->id)
                ->where('device_uuid_hash', $hash)
                ->first();

            // ① **العودةُ بالبصمة نفسِها لا تستهلك مقعداً** — ولو كان
            //    الحدُّ مستنفَداً. فمن سجّل ثمّ أعاد فتحَ التطبيق يعمل.
            if ($existing !== null && $existing->revoked_at === null) {
                $existing->forceFill([
                    'last_seen_at' => now(),
                    'app_version' => $attributes['app_version'] ?? $existing->app_version,
                    'platform' => $attributes['platform'] ?? $existing->platform,
                ])->save();

                return [
                    'result' => self::RESULT_EXISTING,
                    'device' => $existing,
                    'used' => PosDevice::activeSeats($merchant->id),
                    'max' => $max,
                ];
            }

            // ② **والملغى يُحيا بمقعدٍ جديد** — فإلغاءُ جهازٍ ثمّ إعادتُه
            //    يمرّ بالحدّ كأيّ جهازٍ جديد، وإلّا صار الإلغاءُ بابَ تجاوز.
            $used = PosDevice::activeSeats($merchant->id);

            if ($max >= 0 && $used >= $max) {
                return [
                    'result' => self::RESULT_LIMIT,
                    'device' => null,
                    'used' => $used,
                    'max' => $max,
                ];
            }

            try {
                $device = PosDevice::create([
                    'merchant_user_id' => $merchant->id,
                    'branch_id' => $attributes['branch_id'] ?? null,
                    'device_uuid_hash' => $hash,
                    'device_hint' => PosDevice::hintOf($deviceUuid),
                    'display_name' => $attributes['display_name'] ?? null,
                    'platform' => $attributes['platform'] ?? null,
                    'app_version' => $attributes['app_version'] ?? null,
                    'registered_at' => now(),
                    'last_seen_at' => now(),
                    'is_active' => true,
                ]);
            } catch (QueryException $e) {
                // **تصادمُ القيد الفريد** — سبقنا طلبٌ بالبصمة نفسِها.
                // فيُقرأ ما أنشأه ولا يُنشأ ثانٍ. (والقفلُ يمنع هذا عادةً،
                // وهذا الحزامُ الثاني لِما لو أفلت.)
                if (! str_contains($e->getMessage(), '1062')) {
                    throw $e;
                }

                $device = PosDevice::where('merchant_user_id', $merchant->id)
                    ->where('device_uuid_hash', $hash)->firstOrFail();

                return [
                    'result' => self::RESULT_EXISTING,
                    'device' => $device,
                    'used' => PosDevice::activeSeats($merchant->id),
                    'max' => $max,
                ];
            }

            // **الملغى القديمُ يُطوى** — فلا يبقى صفّان بالبصمة نفسِها.
            if ($existing !== null) {
                $existing->forceFill(['is_active' => false])->save();
            }

            return [
                'result' => self::RESULT_REGISTERED,
                'device' => $device,
                'used' => PosDevice::activeSeats($merchant->id),
                'max' => $max,
            ];
        }, 3);   // ثلاثُ محاولاتٍ — المحرّكُ يطلب صراحةً إعادةَ المحاولة عند التصادم
    }

    /**
     * **الإلغاءُ يُخلي المقعدَ ولا يمحو الأثر.**
     *
     * وحذفُ الصفّ يمحو من أيّ جهازٍ خرجت عمليّاتُ أمس.
     */
    public function revoke(PosDevice $device, ?int $byUserId = null): void
    {
        $device->forceFill([
            'revoked_at' => now(),
            'revoked_by_user_id' => $byUserId,
            'is_active' => false,
        ])->save();
    }

    /** حدُّ الباقة — و`-1` بلا حدّ. */
    private function maxSeats(User $merchant): int
    {
        $profile = \App\Models\MerchantProfile::where('user_id', $merchant->id)->first();

        $plan = $profile?->subscription_plan ?? A::PLAN_FREE;

        // **الاشتراكُ المنتهي يعود مجّانيّاً** — كما في `FeatureAccessService`.
        if ($plan !== A::PLAN_FREE
            && $profile?->subscription_expires_at !== null
            && $profile->subscription_expires_at->isPast()) {
            $plan = A::PLAN_FREE;
        }

        return (int) (A::PLAN_LIMITS[$plan]['pos_devices'] ?? 0);
    }
}
