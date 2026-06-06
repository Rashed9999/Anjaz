<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CharityCategory extends Model
{
    protected $table = 'charity_categories';
    protected $fillable = ['code', 'name_ar', 'icon', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function campaigns(): HasMany
    {
        return $this->hasMany(CharityCampaign::class, 'category_id');
    }
}
