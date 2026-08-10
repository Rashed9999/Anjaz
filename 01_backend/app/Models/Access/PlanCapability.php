<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-ENTITLEMENTS-001 — قرارُ المنصّة: باقة × قدرة.
 *
 * **والغيابُ ليس منعاً** (القاعدة ٧): صفٌّ غيرُ موجودٍ يعني «لم يُقرَّر
 * فليُعمَل بافتراضيّ السجلّ». ولو كان الغيابُ منعاً لانطفأت كلُّ قدرةٍ
 * جديدةٍ لحظةَ ولادتها.
 */
class PlanCapability extends Model
{
    protected $table = 'plan_capabilities';

    protected $fillable = [
        'plan', 'capability_code', 'is_enabled', 'reason', 'changed_by',
    ];

    protected $casts = ['is_enabled' => 'boolean'];

    /** @var array<string,array<string,bool>>|null */
    private static ?array $memo = null;

    /**
     * كلُّ القرارات مرّةً واحدة: `[plan][code] => bool`.
     *
     * **وتُقرأ مرّةً في الطلب** — و٥٩ قدرةً × نداءٍ لكلٍّ يعني ٥٩ استعلاماً
     * في فتح شاشةٍ واحدة.
     */
    public static function allDecisions(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $out = [];
        foreach (self::query()->get(['plan', 'capability_code', 'is_enabled']) as $row) {
            $out[$row->plan][$row->capability_code] = (bool) $row->is_enabled;
        }

        return self::$memo = $out;
    }

    public static function flush(): void
    {
        self::$memo = null;
    }
}
