<?php

namespace App\Models\Merchant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * دورٌ **يملكه التاجر**.
 *
 * كانت الأدوارُ في `rbac_roles` على مستوى المنصّة، فلا يستطيع مالكُ
 * المحطّة تعريفَ «مشرف وردية» ولا «محاسب». وصلاحيّاتُ الكاشير مصفوفةٌ
 * مسطّحةٌ في `pos_users.permissions` بلا نطاقٍ ولا حدّ.
 *
 * **والدورُ حزمةُ صلاحيّاتٍ لا العكس** — ولذلك بُني بعدها.
 */
class MerchantRole extends Model
{
    protected $table = 'merchant_roles';

    protected $fillable = [
        'merchant_user_id', 'code', 'name_ar', 'description_ar',
        'is_system', 'is_active',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function permissions(): HasMany
    {
        return $this->hasMany(MerchantRolePermission::class, 'merchant_role_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MerchantUserRole::class, 'merchant_role_id');
    }
}
