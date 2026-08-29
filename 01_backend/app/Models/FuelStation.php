<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuelStation extends Model
{
    protected $table = 'fuel_stations';

    protected $fillable = [
        'merchant_user_id', 'station_name', 'license_number',
        'city', 'address', 'latitude', 'longitude',
        'is_active', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_active' => 'boolean',
    ];

    /**
     * AMIAL-PROD-DEFECTS-001 — **صاحبُ المحطّة، وكانت العلاقةُ مفقودة.**
     *
     * `FuelCenterController` يقرأ `$st->merchant->f_name` في موضعين
     * ليعرض اسمَ صاحب المحطّة وهاتفَه في رقابة المحطّات — **والنموذجُ لا
     * يُعلن العلاقة**. فتُلقي Eloquent `RelationNotFoundException`
     * وتسقط الصفحةُ كلُّها بـ٥٠٠.
     *
     * **ولم يمسكه اختبارٌ لأنّ قاعدةَ الاختبار كانت بلا محطّة**: تُفتح
     * الصفحةُ فتمرّ الحلقةُ على صفرِ صفوف، فلا تُقرأ العلاقةُ أصلاً.
     * وهو الفخُّ المكتوب في `CLAUDE.md`: «مسحٌ على قاعدةٍ فارغةٍ يُطمئن
     * ولا يفحص». وكشفه **مركزُ الأعطال من الاستعمال الحقيقيّ**.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    public function pumps(): HasMany
    {
        return $this->hasMany(FuelPump::class, 'station_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(FuelProduct::class, 'station_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(FuelSale::class, 'station_id');
    }
}
