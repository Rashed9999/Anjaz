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
        'merchant_user_id', 'branch_id', 'device_uuid_hash', 'device_hint',
        'display_name', 'platform', 'app_version',
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
     * **البصمةُ تُجزَّأ ولا تُخزَّن خاماً.**
     *
     * فهي تُقارَن ولا تُقرأ، وتسريبُ قاعدةٍ لا يُسلّم قائمةَ معرّفاتٍ
     * صالحة. و`APP_KEY` تُدخَل فيها فلا تُعاد بناؤها من قاموسٍ خارجيّ.
     */
    public static function hashUuid(string $raw): string
    {
        return hash_hmac('sha256', trim($raw), (string) config('app.key'));
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
