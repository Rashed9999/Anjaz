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
        $max = $this->maxSeats($merchant);

        return DB::transaction(function () use ($merchant, $deviceUuid, $max, $attributes) {
            // **القفلُ على صفّ التاجر** — لا على جدول الأجهزة: فالمقعدُ
            // مورِدُ التاجر، والمتنافسون عليه طلباتُه هو.
            DB::table('users')->where('id', $merchant->id)->lockForUpdate()->first();

            // **البحثُ يمرّ بـ`locate` ولا يُجزّئ هنا** — فمصدرُ الهويّة
            // واحد. ولو جُزّئ في هذا الملفّ لعرف الجهازَ بالمفتاح الحاليّ
            // وحدَه، **فصار التدويرُ محواً لكلّ المقاعد** بينما الطراز
            // يَعِد بعكسه: تعريفان لمفهومٍ واحدٍ يفترقان — وهو العطلُ
            // الأكثرُ تكراراً في هذه الجولة.
            [$existing, $needsMigration] = PosDevice::locate($merchant->id, $deviceUuid);

            // **الترحيلُ عند أوّل ظهور** — الصفُّ المجزَّأ بمفتاحٍ سابقٍ
            // يُعاد تجزئتُه بالحاليّ، فيذوب المفتاحُ القديم بلا يومٍ
            // يُقفَل فيه متجرٌ واحد.
            if ($existing !== null && $needsMigration) {
                $existing->forceFill([
                    'device_uuid_hash' => PosDevice::hashUuid($deviceUuid),
                    'hash_key_version' => PosDevice::currentKeyVersion(),
                ])->save();
            }

            // ① **العودةُ بالبصمة نفسِها لا تستهلك مقعداً** — ولو كان
            //    الحدُّ مستنفَداً. فمن سجّل ثمّ أعاد فتحَ التطبيق يعمل.
            //
            //    **والشرطُ هو شرطُ `activeSeats` عينُه**: صفٌّ لا يُحتسَب
            //    مقعداً لا يُعاد «موجوداً» — وإلّا صار جهازٌ يعمل بلا أن
            //    يشغل مقعداً، وهو التفافٌ على الحدّ من الباب الخلفيّ.
            if ($existing !== null && $existing->revoked_at === null && $existing->is_active) {
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

            // ② **والملغى يمرّ بالحدّ كأيّ جهازٍ جديد** — وإلّا صار
            //    الإلغاءُ بابَ تجاوز: يُلغى جهازٌ فيُعاد بلا حساب.
            $used = PosDevice::activeSeats($merchant->id);

            if ($max >= 0 && $used >= $max) {
                return [
                    'result' => self::RESULT_LIMIT,
                    'device' => null,
                    'used' => $used,
                    'max' => $max,
                ];
            }

            // ③ **والملغى يُحيا في صفّه لا في صفٍّ ثانٍ.**
            //
            //    وكان يُنشأ صفٌّ جديد — **وهو ما يصطدم بالقيد الفريد
            //    حتماً**: البصمةُ نفسُها والتاجرُ نفسُه. فيقع في مُلتقِط
            //    1062 أدناه فيُعيد «موجودٌ» مشيراً إلى **الصفّ الملغى**،
            //    أي يُعلن النجاحَ ويُسلّم جهازاً ملغى.
            //
            //    والأثرُ لا يُمحى: تاريخُ الإلغاءات يُحفظ في `metadata`.
            if ($existing !== null) {
                $history = (array) ($existing->metadata['revocations'] ?? []);

                $history[] = [
                    'revoked_at' => $existing->revoked_at?->toIso8601String(),
                    'revoked_by_user_id' => $existing->revoked_by_user_id,
                    'restored_at' => now()->toIso8601String(),
                ];

                $existing->forceFill([
                    'branch_id' => $attributes['branch_id'] ?? $existing->branch_id,
                    'display_name' => $attributes['display_name'] ?? $existing->display_name,
                    'platform' => $attributes['platform'] ?? $existing->platform,
                    'app_version' => $attributes['app_version'] ?? $existing->app_version,
                    'registered_at' => now(),
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                    'revoked_by_user_id' => null,
                    'is_active' => true,
                    'metadata' => ['revocations' => $history] + (array) $existing->metadata,
                ])->save();

                return [
                    'result' => self::RESULT_REGISTERED,
                    'device' => $existing,
                    'used' => PosDevice::activeSeats($merchant->id),
                    'max' => $max,
                ];
            }

            try {
                $device = PosDevice::create([
                    'merchant_user_id' => $merchant->id,
                    'branch_id' => $attributes['branch_id'] ?? null,
                    'device_uuid_hash' => PosDevice::hashUuid($deviceUuid),
                    'hash_key_version' => PosDevice::currentKeyVersion(),
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

                [$device] = PosDevice::locate($merchant->id, $deviceUuid);

                return [
                    'result' => $device === null ? self::RESULT_LIMIT : self::RESULT_EXISTING,
                    'device' => $device,
                    'used' => PosDevice::activeSeats($merchant->id),
                    'max' => $max,
                ];
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

        // **والإلغاءُ يصل الجلساتِ القائمةَ لا التسجيلَ وحدَه.**
        //
        // بدون هذا يُخلي الإلغاءُ المقعدَ **ويترك الجهازَ يعمل** حتّى ينتهي
        // رمزُه من نفسِه — فتكون جملةُ «ألغيتُ الجهازَ المسروق» كاذبةً في
        // اللحظة التي يُحتاج فيها صدقُها. والبوّابةُ تفحص أيضاً في كلّ
        // طلب (حزامان: أحدُهما يختم، والآخرُ يمنع لو أفلت الختم).
        \App\Models\Merchant\PosDeviceSession::endAllForDevice((int) $device->id);
    }

    /**
     * حدُّ الباقة — و`-1` بلا حدّ.
     *
     * **عامٌّ عمداً**: الشاشةُ تعرض «مستهلَكٌ من الحدّ»، ولو حسبته بنفسها
     * لصار تعريفان للحدّ الواحد — وهو العطلُ الذي تكرّر في هذا المشروع.
     */
    public function maxSeats(User $merchant): int
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
