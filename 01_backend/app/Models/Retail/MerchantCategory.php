<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AMIAL-RETAIL-VERTICAL-001 · المرحلة ٢ — التصنيف **شجرةً لا قائمة**. */
class MerchantCategory extends Model
{
    use SoftDeletes;

    protected $table = 'merchant_categories';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'parent_id', 'name', 'code',
        'icon', 'sort_order', 'is_active', 'created_by',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'parent_id' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * معرّفاتُ هذا التصنيف وكلِّ ما تحته — **وبها يُجاب «كم بعتُ من المواد
     * الغذائية؟»**، وقائمةٌ مسطّحةٌ تجعله سؤالاً بلا جواب.
     */
    public function selfAndDescendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        // عمقٌ محدود: شجرةُ تصنيفاتٍ لا تتجاوز خمسةَ مستوياتٍ عملياً،
        // والحدُّ يمنع حلقةً لو أُفسدت البيانات بأبٍ يشير إلى ابنه.
        for ($depth = 0; $depth < 8 && $frontier !== []; $depth++) {
            $frontier = self::whereIn('parent_id', $frontier)
                ->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
