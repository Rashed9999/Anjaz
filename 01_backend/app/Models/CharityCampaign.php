<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-DONATIONS-001 (v1.2)
 */
class CharityCampaign extends Model
{
    protected $table = 'charity_campaigns';

    protected $fillable = [
        'campaign_ulid', 'org_id', 'category_id',
        'title_ar', 'description_ar', 'story_md', 'location_ar',
        'target_amount', 'current_amount', 'platform_fee_collected',
        'beneficiary_count', 'beneficiary_description_ar',
        'cover_image_url', 'gallery_images',
        'start_at', 'deadline_at',
        'status', 'approved_by_admin_id', 'approved_at',
        'rejection_reason', 'cancellation_reason',
        // AMIAL-CHARITY-META-001 — «عاجل». وخارج `fillable` تُبتلع القيمةُ
        // بصمت: يُعلَّم المديرُ الحملةَ عاجلةً فتُحفظ عاديّة.
        'view_count', 'donor_count', 'is_featured', 'is_urgent',
        'zone_code', 'created_by_admin_id',
    ];

    protected $casts = [
        'target_amount' => 'decimal:4',
        'current_amount' => 'decimal:4',
        'platform_fee_collected' => 'decimal:4',
        'beneficiary_count' => 'integer',
        'gallery_images' => 'array',
        'start_at' => 'datetime',
        'deadline_at' => 'datetime',
        'approved_at' => 'datetime',
        'view_count' => 'integer',
        'donor_count' => 'integer',
        'is_featured' => 'boolean',
        'is_urgent' => 'boolean',
        'created_by_admin_id' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(CharityOrganization::class, 'org_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CharityCategory::class, 'category_id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'campaign_id');
    }

    // Scopes
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /**
     * AMIAL-CHARITY-META-001 — **البدايةُ تُطبَّق لا تُحفظ فقط.**
     *
     * أُضيف `start_at` إلى النموذج والحقلِ والتحقّق، **ونُسي هنا** — فكان
     * يُحفظ ولا يُقرأ، وحملةٌ تبدأ بعد شهرٍ تُعرض وتُقبل تبرّعاتُها فورَ
     * اعتمادها. أي أنّ الحقلَ كان زينةً تناقض تعليقَه نفسَه.
     * (كشفه محورُ المواصفة في مراجعةٍ آليّة.)
     */
    public function scopeAcceptingDonations(Builder $q): Builder
    {
        return $q->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('start_at')
                  ->orWhere('start_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('deadline_at')
                  ->orWhere('deadline_at', '>=', now());
            });
    }

    public function scopeFeatured(Builder $q): Builder
    {
        return $q->where('is_featured', true);
    }

    /** **والبابان يقولان الشيءَ نفسه** — القاعدة الرابعة. */
    public function isAccepting(): bool
    {
        return $this->status === 'active'
            && ($this->start_at === null || ! $this->start_at->isFuture())
            && ($this->deadline_at === null || $this->deadline_at->isFuture());
    }

    public function getProgressPercentageAttribute(): float
    {
        if (bccomp((string)$this->target_amount, '0', 4) <= 0) return 0;
        return min(100, (float)bcmul(
            bcdiv((string)$this->current_amount, (string)$this->target_amount, 6),
            '100',
            2,
        ));
    }

    public function isCompleted(): bool
    {
        return bccomp((string)$this->current_amount, (string)$this->target_amount, 4) >= 0;
    }
}
