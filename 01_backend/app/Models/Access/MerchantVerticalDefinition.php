<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-VERTICAL-COMPOSE-001 — قطاعٌ عرّفته الإدارة.
 *
 * **ولا يُقرَأ من هنا في منطق الوصول** — يُقرأ من `VerticalRegistry`،
 * فهو الموضعُ الواحدُ الذي يقول «ما هذا القطاع» للستّة المبنيّة ولِما
 * أنشأته الإدارةُ معاً. وقراءةُ الجدول رأساً في خدمةٍ تُنشئ مصدراً ثانياً.
 */
class MerchantVerticalDefinition extends Model
{
    protected $table = 'merchant_verticals';

    protected $fillable = [
        'code', 'name_ar', 'hint_ar', 'icon', 'color',
        'core_features', 'paid_depth', 'home_capability',
        'is_active', 'sort_order', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'core_features' => 'array',
        'paid_depth' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @var array<int,self>|null */
    private static ?array $memo = null;

    /**
     * القطاعاتُ العاملةُ مرّةً واحدةً في الطلب.
     *
     * **والجدولُ قد لا يكون موجوداً** — تُقرأ هذه في `AccessConstants`
     * ومشتقّاتِها، وهي تُنادى في أثناء الهجرات نفسِها وفي اختباراتٍ
     * تُنشئ القاعدةَ من الصفر. فغيابُ الجدول يعني «لا قطاعَ مُضافاً»
     * ولا يعني انهياراً.
     *
     * @return array<int,self>
     */
    public static function active(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            if (! Schema::hasTable('merchant_verticals')) {
                return self::$memo = [];
            }

            return self::$memo = self::query()
                ->where('is_active', true)
                ->orderBy('sort_order')->orderBy('id')
                ->get()->all();
        } catch (\Throwable) {
            // لا اتّصالَ بالقاعدة (أمرُ صيانةٍ · بناءُ صورة) — والستّةُ
            // المبنيّةُ تكفي لِما يُنادَى في تلك الحال.
            return self::$memo = [];
        }
    }

    public static function flush(): void
    {
        self::$memo = null;
    }
}
