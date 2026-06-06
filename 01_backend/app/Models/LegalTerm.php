<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-LEGAL-001
 *
 * @property int $id
 * @property string $version
 * @property string $locale
 * @property string $title
 * @property string $content
 * @property bool $is_current
 * @property \Carbon\Carbon $effective_at
 * @property \Carbon\Carbon|null $superseded_at
 * @property string|null $changelog
 * @property int|null $created_by
 */
class LegalTerm extends Model
{
    protected $table = 'legal_terms';

    protected $fillable = [
        'version',
        'locale',
        'title',
        'content',
        'is_current',
        'effective_at',
        'superseded_at',
        'changelog',
        'created_by',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'effective_at' => 'datetime',
        'superseded_at' => 'datetime',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function acceptances(): HasMany
    {
        return $this->hasMany(UserLegalAcceptance::class, 'legal_term_id', 'id');
    }

    /** الإصدارات السارية الآن (effective_at <= now & is_current=true) */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('is_current', true)
            ->where('effective_at', '<=', now())
            ->whereNull('superseded_at');
    }

    public function scopeForLocale(Builder $q, string $locale): Builder
    {
        return $q->where('locale', $locale);
    }

    /**
     * يعيد الإصدار الحالي للـ locale. يقع back على 'ar' لو لم يوجد لـ locale المطلوب.
     */
    public static function currentFor(string $locale = 'ar'): ?self
    {
        return self::current()
                ->forLocale($locale)
                ->orderByDesc('effective_at')
                ->first()
            ?? self::current()
                ->forLocale('ar')
                ->orderByDesc('effective_at')
                ->first();
    }
}
