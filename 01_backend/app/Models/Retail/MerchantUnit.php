<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٢ — وحدةُ القياس.
 *
 * **و`decimals` ليست تنسيقاً**: بيعُ نصف حبّةٍ خطأ، وبيعُ نصف كيلو صواب.
 */
class MerchantUnit extends Model
{
    protected $table = 'merchant_units';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'name', 'code', 'decimals',
        'base_unit_id', 'factor', 'is_active',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'decimals' => 'integer',
        'base_unit_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_unit_id');
    }

    /** هل هذه الكمّيّة مقبولةٌ بهذه الوحدة؟ */
    public function accepts(string $quantity): bool
    {
        $scaled = bcmul($quantity, bcpow('10', (string) $this->decimals), 6);

        // `bcadd(.., 0)` يقصّ الكسر — فإن ساوى المقصوصُ الأصلَ فلا كسرَ زائد.
        return bccomp($scaled, bcadd($scaled, '0', 0), 6) === 0;
    }

    /** التحويلُ إلى الوحدة الأساس — **والمخزونُ يُمسك بالأساس وحدَه**. */
    public function toBase(string $quantity): string
    {
        return bcmul($quantity, (string) ($this->factor ?: '1'), 3);
    }
}
