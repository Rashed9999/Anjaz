<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-PRODUCT-ATTRIBUTES-001 — سمةٌ عامّة: «اللون» · «المقاس».
 *
 * تُعرَّف مرّةً للتاجر وتُستعمَل في كلّ منتج، فلا يُعاد كتابةُ القيم —
 * ولا يفترق إملاؤها فينقسم المخزون.
 */
class MerchantAttribute extends Model
{
    protected $table = 'merchant_attributes';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'name', 'slug', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function terms(): HasMany
    {
        return $this->hasMany(MerchantAttributeTerm::class, 'attribute_id')
            ->orderBy('sort_order')->orderBy('value');
    }
}
