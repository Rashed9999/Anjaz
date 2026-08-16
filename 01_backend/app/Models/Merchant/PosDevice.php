<?php

namespace App\Models\Merchant;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-POS-DEVICES-001 — **مقعدُ ترخيصٍ يملكه التاجر، لا حسابُ موظّف.**
 *
 * والفرقُ يُنفَّذ لا يُوصَف: **لا عمودَ `user_id` هنا**. فلا يُربط الجهازُ
 * بموظّفٍ ربطاً دائماً، والموظّفون يتناوبون عليه بحساباتهم.
 */
class PosDevice extends Model
{
    protected $table = 'merchant_pos_devices';

    protected $fillable = [
        'merchant_user_id', 'branch_id', 'device_uuid_hash', 'hash_key_version',
        'device_hint', 'display_name', 'platform', 'app_version',
        'registered_at', 'last_seen_at', 'revoked_at', 'revoked_by_user_id',
        'is_active', 'metadata',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * **البصمةُ تُجزَّأ بسرٍّ مستقلٍّ عن `APP_KEY`.**
     *
     * `APP_KEY` يُبدَّل لأسبابٍ لا علاقةَ لها بالأجهزة، وتبديلُه لو كان
     * هو المفتاحَ **يمحو هويّةَ كلّ جهازٍ مسجَّل**: تعود بصماتُها مجهولةً،
     * فيستهلك كلُّ جهازٍ مقعداً جديداً، فتُقفل المتاجرُ صباحاً بلا سبب.
     *
     * **ومفتاحٌ فارغٌ يسقط ولا يُشتقّ صامتاً** — فاشتقاقٌ من `APP_KEY`
     * عند الغياب يُعيد العلّةَ نفسَها ويُخفيها.
     */
    public static function hashUuid(string $raw, ?string $key = null): string
    {
        $key ??= (string) config('amial.device_identity.hash_key', '');

        if ($key === '') {
            throw new \RuntimeException(
                'AMIAL_DEVICE_HASH_KEY غير مضبوط — ولا يُشتقّ من APP_KEY: '
                . 'تدويرُ الأخير يمحو هويّةَ كلّ الأجهزة');
        }

        return hash_hmac('sha256', trim($raw), $key);
    }

    public static function currentKeyVersion(): int
    {
        return (int) config('amial.device_identity.hash_key_version', 1);
    }

    /**
     * **يُعثر على الجهاز بالمفتاح الحاليّ أو بمفتاحٍ سابق.**
     *
     * فالتدويرُ لا يُبطل ما سبق: القديمُ يُقارَن بمفتاحه، **ويُرحَّل إلى
     * الجديد عند أوّل ظهور** — فيذوب المفتاحُ القديمُ بلا يومٍ يُقفل فيه
     * متجرٌ واحد.
     *
     * @return array{0:?self,1:bool}  الجهازُ · وهل يحتاج ترحيلاً
     */
    public static function locate(int $merchantUserId, string $raw): array
    {
        $current = static::where('merchant_user_id', $merchantUserId)
            ->where('device_uuid_hash', static::hashUuid($raw))
            ->first();

        if ($current !== null) {
            return [$current, false];
        }

        foreach (static::previousKeys() as $version => $key) {
            $found = static::where('merchant_user_id', $merchantUserId)
                ->where('device_uuid_hash', static::hashUuid($raw, $key))
                ->where('hash_key_version', $version)
                ->first();

            if ($found !== null) {
                return [$found, true];
            }
        }

        return [null, false];
    }

    /**
     * مفاتيحُ سابقةٌ تُقرأ ولا يُكتب بها.
     *
     * الصيغة: `1:oldkey,2:olderkey` — والإصدارُ يُطابق `hash_key_version`
     * المخزَّن في الصفّ.
     *
     * @return array<int,string>
     */
    public static function previousKeys(): array
    {
        $raw = (string) config('amial.device_identity.previous_keys', '');
        $out = [];

        foreach (array_filter(explode(',', $raw)) as $pair) {
            $parts = explode(':', trim($pair), 2);

            if (count($parts) === 2 && $parts[1] !== '') {
                $out[(int) $parts[0]] = $parts[1];
            }
        }

        return $out;
    }

    /** آخرُ أربعةِ محارفَ — للعرض وحدَه، فيميّز التاجرُ جهازَه. */
    public static function hintOf(string $raw): string
    {
        return mb_substr(trim($raw), -4);
    }

    /**
     * **المقاعدُ المشغولة** — والملغى لا يُحتسَب.
     *
     * وهذا هو التعريفُ الواحدُ للعدّ: يُستدعى من `EntitlementService`
     * ومن المُسجِّل سواءً، فلا يفترق تعريفان (وقد افترقا في هذا المشروع
     * أربعَ مرّاتٍ في جولةٍ واحدة).
     */
    public static function activeSeats(int $merchantUserId): int
    {
        return static::query()
            ->where('merchant_user_id', $merchantUserId)
            ->whereNull('revoked_at')
            ->where('is_active', true)
            ->count();
    }
}
